<?php

/**
 * @file tools/dev/recordBackdatedDecisions.php
 *
 * Post45 — records editorial decisions with a historical dateDecided that
 * Repo::decision()->add() would otherwise overwrite with today's date. Built
 * for the audit workflow where a "With Author" submission needs a real R&R
 * decision recorded in OJS before ReviewTrackResolver's populate-silence
 * removal ships (else sync clobbers Notion's `With Author` with today's
 * derived state) — but generalised so any historical-decision reconciliation
 * can use the same path.
 *
 * Why the tool exists: `Repo::decision()->add()` unconditionally rewrites
 * dateDecided to `Core::getCurrentDate()` inside pkp-lib's Repository.php:222,
 * silently discarding whatever value you passed in. Populate works around
 * this with a follow-up `UPDATE edit_decisions SET date_decided = ...` after
 * add(). This tool mirrors that pattern.
 *
 * The written date_decided propagates to Notion on the next sync tick via
 * ArticleMapper -> DecisionHistory::decisionDate() — no manual Notion touch
 * needed if you record the decision here rather than clicking through the
 * OJS UI first.
 *
 * Usage:
 *   php tools/dev/recordBackdatedDecisions.php --csv=<path> [--execute] [--verbose]
 *
 * CSV columns (header row required, exactly these three):
 *   submission_id   — OJS submission
 *   decision        — one of: accept, pending_revisions, decline, resubmit
 *                     (Post45 hides resubmit in the UI, but the DB constant
 *                      is accepted here for completeness)
 *   date_decided    — YYYY-MM-DD (the historical decision date)
 *
 * Defaults: dry-run. Add --execute to write. Matches populate /
 * reconcileReviewsFromNotion discipline.
 */

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;
use PKP\core\Core;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\decision\Decision;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;

require(dirname(__FILE__) . '/../bootstrap.php');

class RecordBackdatedDecisionsTool extends CommandLineTool
{
    private const CONTEXT_ID = 1;

    private const DECISION_LABELS = [
        'accept' => Decision::ACCEPT,
        'pending_revisions' => Decision::PENDING_REVISIONS,
        'decline' => Decision::DECLINE,
        'resubmit' => Decision::RESUBMIT,
    ];

    /**
     * Which stage each decision is recorded at. R&R happens at external
     * review (stage 3); Accept-out-of-review same. Decline can happen at
     * multiple stages but the most common audit case is stage 3 too.
     * Extend the map if a use case surfaces for stage 1 or elsewhere.
     */
    private const DECISION_STAGE = [
        Decision::ACCEPT => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
        Decision::PENDING_REVISIONS => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
        Decision::DECLINE => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
        Decision::RESUBMIT => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
    ];

    private ?string $csvPath = null;
    private bool $execute = false;
    private bool $verbose = false;

    private $context;
    private $editor;

    private array $summary = [
        'rows_seen' => 0,
        'recorded' => 0,
        'skipped_error' => 0,
    ];

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            if ($arg === '--execute') {
                $this->execute = true;
                continue;
            }
            if ($arg === '--verbose') {
                $this->verbose = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $this->usage();
                exit(0);
            }
            if (preg_match('/^--csv=(.+)$/', $arg, $m)) {
                $this->csvPath = $m[1];
                continue;
            }
            $this->die("Unrecognized argument: {$arg}\nSee --help.");
        }

        if ($this->csvPath === null) {
            $this->die('--csv=<path> is required. See --help.');
        }
        if (!is_file($this->csvPath)) {
            $this->die("--csv '{$this->csvPath}' not found.");
        }
    }

    public function usage(): void
    {
        $labels = implode(', ', array_keys(self::DECISION_LABELS));
        echo <<<TXT
Record editorial decisions with a historical dateDecided.

Usage: {$this->scriptName} --csv=<path> [--execute] [--verbose]

CSV columns (header row required, exactly these three):
  submission_id   — OJS submission
  decision        — one of: {$labels}
  date_decided    — YYYY-MM-DD

Defaults:
  dry-run (nothing is written)
  --execute      opt in to real writes
  --verbose      per-row detail

TXT;
    }

    public function execute(): void
    {
        $this->bootstrap();

        if (!$this->execute) {
            fwrite(STDERR, "\n=== DRY RUN — no writes will happen ===\n\n");
        }

        $rows = $this->loadCsv($this->csvPath);
        $rowNum = 1;
        foreach ($rows as $row) {
            $rowNum++;
            $this->summary['rows_seen']++;
            $this->processRow($rowNum, $row);
        }

        $this->printSummary();
    }

    private function bootstrap(): void
    {
        $this->context = $this->resolveContext();
        $this->editor = $this->resolveEditor();

        Registry::set('user', $this->editor);
        Application::get()->getRequest()->getRouter()->_context = $this->context;

        // Generic plugins register per-context; CLI tools have no request
        // context, so post45NotionSync would otherwise skip its register()
        // entirely and the Decision::add hook that queues SyncArticleJob
        // never binds. See OJS-DEV-NOTES worker-context gotcha.
        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if (!$sync) {
            $this->die('post45NotionSync plugin not registered. Enable it before running this tool.');
        }
        $sync->register('generic', $sync->getPluginPath(), $this->context->getId());
    }

    private function resolveContext()
    {
        /** @var \APP\journal\JournalDAO $journalDao */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        $all = $journalDao->getAll(true)->toArray();
        if (empty($all)) {
            $this->die('No enabled journals found.');
        }
        return $all[0];
    }

    private function resolveEditor()
    {
        $editor = Repo::user()->getCollector()
            ->filterByContextIds([$this->context->getId()])
            ->filterByRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
            ->getMany()
            ->first();
        if (!$editor) {
            $this->die("No editorial user in journal '{$this->context->getPath()}'.");
        }
        return $editor;
    }

    /** @return array<int, array<string, string>> */
    private function loadCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            $this->die("Cannot open CSV '{$path}'.");
        }

        $header = null;
        while (($row = fgetcsv($fh)) !== false) {
            $first = trim((string) ($row[0] ?? ''));
            if ($first === '' || str_starts_with($first, '#')) {
                continue;
            }
            $header = array_map(fn ($h) => trim(strtolower((string) $h)), $row);
            break;
        }
        if (!$header) {
            $this->die('CSV has no header row.');
        }
        $required = ['submission_id', 'decision', 'date_decided'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                $this->die("CSV missing required column '{$col}'. Got: " . implode(',', $header));
            }
        }
        $extra = array_values(array_diff($header, $required));
        if ($extra !== []) {
            $this->die(
                'CSV has unexpected extra columns: ' . implode(',', $extra)
                . '. Expected exactly: ' . implode(',', $required)
            );
        }

        $out = [];
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), '');
            }
            $assoc = array_combine($header, array_slice($row, 0, count($header)));
            $out[] = array_map(fn ($v) => trim((string) $v), $assoc);
        }
        fclose($fh);
        return $out;
    }

    private function processRow(int $rowNum, array $row): void
    {
        $submissionId = (int) ($row['submission_id'] ?? 0);
        $decisionLabel = strtolower($row['decision'] ?? '');
        $dateDecided = $row['date_decided'] ?? '';

        if ($submissionId <= 0 || $decisionLabel === '' || $dateDecided === '') {
            $this->rowLog($rowNum, $submissionId, 'SKIP: empty submission_id, decision, or date_decided');
            $this->summary['skipped_error']++;
            return;
        }

        $decisionConstant = self::DECISION_LABELS[$decisionLabel] ?? null;
        if ($decisionConstant === null) {
            $this->rowLog($rowNum, $submissionId, "SKIP: unknown decision label '{$decisionLabel}'. Valid: " . implode(', ', array_keys(self::DECISION_LABELS)));
            $this->summary['skipped_error']++;
            return;
        }

        // Accept YYYY-MM-DD (padded to midnight for the DB write) or a full
        // YYYY-MM-DD HH:MM:SS. Anything else fails visibly rather than silently
        // storing garbage.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $dateDecided)) {
            $this->rowLog($rowNum, $submissionId, "SKIP: date_decided '{$dateDecided}' not in YYYY-MM-DD form");
            $this->summary['skipped_error']++;
            return;
        }
        if (strlen($dateDecided) === 10) {
            $dateDecided .= ' 00:00:00';
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            $this->rowLog($rowNum, $submissionId, "ERROR: no submission with id={$submissionId}");
            $this->summary['skipped_error']++;
            return;
        }

        $stageId = self::DECISION_STAGE[$decisionConstant] ?? WORKFLOW_STAGE_ID_EXTERNAL_REVIEW;

        $reviewRoundId = null;
        if ($stageId === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
            $reviewRound = DAORegistry::getDAO('ReviewRoundDAO')
                ->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
            if (!$reviewRound) {
                $this->rowLog($rowNum, $submissionId, 'ERROR: no review round on this submission (Send for External Review was never recorded)');
                $this->summary['skipped_error']++;
                return;
            }
            $reviewRoundId = $reviewRound->getId();
        }

        if (!$this->execute) {
            $this->rowLog(
                $rowNum,
                $submissionId,
                "DRY: would record decision={$decisionLabel} at stage={$stageId} round={$reviewRoundId} dateDecided={$dateDecided}"
            );
            return;
        }

        try {
            $decisionId = $this->recordDecision($submissionId, $decisionConstant, $stageId, $reviewRoundId, $dateDecided);
        } catch (\Throwable $e) {
            $this->rowLog($rowNum, $submissionId, "ERROR: recordDecision failed: {$e->getMessage()}");
            $this->summary['skipped_error']++;
            return;
        }

        $this->summary['recorded']++;
        $this->rowLog($rowNum, $submissionId, "OK: decision_id={$decisionId} ({$decisionLabel} at {$dateDecided})");
    }

    /**
     * Copied verbatim from populate::recordDecision — same
     * add-then-UPDATE dance to survive Repo::decision()->add()'s silent
     * dateDecided rewrite. See populate for the pkp-lib line reference.
     */
    private function recordDecision(
        int $submissionId,
        int $decisionConstant,
        int $stageId,
        ?int $reviewRoundId,
        string $dateDecided
    ): int {
        $decisionType = Repo::decision()->getDecisionType($decisionConstant);
        if (!$decisionType) {
            throw new \RuntimeException("No decision type registered for constant {$decisionConstant}");
        }
        $data = [
            'submissionId' => $submissionId,
            'decision' => $decisionConstant,
            'editorId' => $this->editor->getId(),
            'stageId' => $stageId,
            'dateDecided' => $dateDecided,
        ];
        if ($reviewRoundId) {
            $data['reviewRoundId'] = $reviewRoundId;
        }
        $decisionId = Repo::decision()->add(Repo::decision()->newDataObject($data));

        DB::table('edit_decisions')
            ->where('edit_decision_id', $decisionId)
            ->update(['date_decided' => $dateDecided]);

        return $decisionId;
    }

    private function rowLog(int $rowNum, int $submissionId, string $message): void
    {
        fwrite(STDERR, sprintf(
            "  row %2d  sub=%-5d  %s\n",
            $rowNum,
            $submissionId,
            $message
        ));
    }

    private function printSummary(): void
    {
        $s = $this->summary;
        $mode = $this->execute ? 'WROTE TO DATABASE' : 'DRY RUN — nothing written';
        fwrite(STDERR, "\n=== recordBackdatedDecisions summary ({$mode}) ===\n");
        fwrite(STDERR, sprintf("  rows seen:        %d\n", $s['rows_seen']));
        fwrite(STDERR, sprintf("  recorded:         %d\n", $s['recorded']));
        fwrite(STDERR, sprintf("  skipped error:    %d\n", $s['skipped_error']));

        if ($this->execute && $s['recorded'] > 0) {
            fwrite(STDERR, "\nNext step: drain the queued SyncArticleJob calls so Notion catches up:\n");
            fwrite(STDERR, "  php lib/pkp/tools/jobs.php run\n");
        }
    }

    private function die(string $msg): never
    {
        fwrite(STDERR, "ERROR: {$msg}\n");
        exit(1);
    }
}

(new RecordBackdatedDecisionsTool($argv ?? []))->execute();
