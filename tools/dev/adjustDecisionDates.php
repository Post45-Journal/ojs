<?php

/**
 * @file tools/dev/adjustDecisionDates.php
 *
 * Adjust the `date_decided` field on one row in `edit_decisions`. Written for
 * the post-cutover audit workflow when an editor retroactively records a
 * decision that wasn't captured at the time — the OJS UI stamps today's date,
 * but the editorial-events sync (post-launch) will read `date_decided`
 * verbatim off this table, so every "time to desk review / time to first
 * decision" metric for the article depends on the value being historically
 * correct.
 *
 * ## Why this exists (why not just UPDATE via SQL?)
 *
 * Two reasons over a raw psql `UPDATE`:
 *
 * 1. `Repo::decision()` has no `edit()` method (decisions are append-only in
 *    pkp-lib's model), so the "prefer the repo layer" pattern that
 *    adjustSubmissionDates / adjustReviewDates use doesn't apply here. That
 *    leaves a raw UPDATE as the only path — the same one populate uses in its
 *    add-then-UPDATE dance (populateFromNotion.php:1740-1744) and that
 *    recordBackdatedDecisions inherits verbatim.
 *
 * 2. A raw UPDATE bypasses Notion sync entirely. There's no `Decision::edit`
 *    hook (nothing to fire), so this tool dispatches a `SyncArticleJob` for
 *    the affected submission directly — mirroring resyncSubmission. Without
 *    that, the corrected date sits in OJS until the next natural editorial
 *    action on the submission (a decision, a publication re-save) triggers a
 *    sync. Force the dispatch here so `php lib/pkp/tools/jobs.php run`
 *    catches Notion up in seconds.
 *
 * ## Only one settable field
 *
 * `edit_decisions.date_decided` is the only column carrying editorial timing.
 * The other row fields (`decision`, `stage_id`, `editor_id`, `review_round_id`,
 * `round`) are structural — if any of those are wrong, delete + re-record via
 * the OJS UI or `recordBackdatedDecisions.php`, don't in-place patch them.
 *
 * `date_decided` is NOT NULL in the schema (SubmissionsMigration.php:150), so
 * there is no clear-mode analogous to adjustReviewDates.
 *
 * ## Sync-side note
 *
 * The current ArticleSchema pushes `Decision Date` and `Decision History` to
 * Notion; both derive off the last decision that maps to a notionDecision
 * (see DecisionHistory::decisionDate). Editing an earlier / intermediate
 * decision's date changes the History payload but usually not `Decision Date`
 * — that's fine, the History carries the timeline value we care about for
 * the editorial-events roll-up.
 *
 * Usage:
 *   php tools/dev/adjustDecisionDates.php --decision-id=<int>
 *   php tools/dev/adjustDecisionDates.php --decision-id=<int> \
 *       --decided=YYYY-MM-DD [-d=YYYY-MM-DD] [--dry-run] [--yes]
 *   php tools/dev/adjustDecisionDates.php --for-submission=<sid>
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\post45NotionSync\classes\job\SyncArticleJob;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;
use PKP\db\DAORegistry;
use PKP\decision\Decision;
use PKP\plugins\PluginRegistry;

require(dirname(__FILE__) . '/../bootstrap.php');

class AdjustDecisionDatesTool extends CommandLineTool
{
    /** @var array<string, string> --<flag> => column name on edit_decisions */
    private const SETTABLE = [
        'decided' => 'date_decided',
    ];

    /** Short-flag aliases mirroring adjustReviewDates' shape. */
    private const SHORT_ALIASES = [
        'd' => 'decided',
    ];

    /**
     * Human-readable label for every decision constant this codebase records.
     * Includes pkp-lib native constants (Decision::*) and Post45 custom
     * decisions (993-999, defined in each plugin decision class). Used for
     * both the single-decision view and the --for-submission listing.
     */
    private const DECISION_LABELS = [
        Decision::INTERNAL_REVIEW => 'INTERNAL_REVIEW',
        Decision::ACCEPT => 'ACCEPT',
        Decision::EXTERNAL_REVIEW => 'EXTERNAL_REVIEW',
        Decision::PENDING_REVISIONS => 'PENDING_REVISIONS',
        Decision::RESUBMIT => 'RESUBMIT',
        Decision::DECLINE => 'DECLINE',
        Decision::SEND_TO_PRODUCTION => 'SEND_TO_PRODUCTION',
        Decision::INITIAL_DECLINE => 'INITIAL_DECLINE',
        Decision::RECOMMEND_ACCEPT => 'RECOMMEND_ACCEPT',
        Decision::RECOMMEND_PENDING_REVISIONS => 'RECOMMEND_PENDING_REVISIONS',
        Decision::RECOMMEND_RESUBMIT => 'RECOMMEND_RESUBMIT',
        Decision::RECOMMEND_DECLINE => 'RECOMMEND_DECLINE',
        Decision::NEW_EXTERNAL_ROUND => 'NEW_EXTERNAL_ROUND',
        Decision::REVERT_DECLINE => 'REVERT_DECLINE',
        Decision::REVERT_INITIAL_DECLINE => 'REVERT_INITIAL_DECLINE',
        Decision::SKIP_EXTERNAL_REVIEW => 'SKIP_EXTERNAL_REVIEW',
        Decision::BACK_FROM_PRODUCTION => 'BACK_FROM_PRODUCTION',
        Decision::BACK_FROM_COPYEDITING => 'BACK_FROM_COPYEDITING',
        Decision::CANCEL_REVIEW_ROUND => 'CANCEL_REVIEW_ROUND',
        993 => 'MARK_PROOFS_APPROVED',
        994 => 'ASSIGN_SECOND_EDIT',
        995 => 'ASSIGN_FIRST_EDIT',
        996 => 'SEND_PROOFS_TO_AUTHOR',
        997 => 'SEND_COPYEDITS_TO_AUTHOR',
        998 => 'REQUEST_DESK_REVISION',
        999 => 'MARK_PUBLISHED',
    ];

    private ?int $decisionId = null;
    private ?int $listForSubmissionId = null;
    private bool $dryRun = false;
    private bool $yes = false;

    /** @var array<string, ?string> columnName => 'YYYY-MM-DD HH:MM:SS' */
    private array $edits = [];

    private $context;

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            if (preg_match('/^-([a-zA-Z])(=.*)?$/', $arg, $m) && isset(self::SHORT_ALIASES[$m[1]])) {
                $arg = '--' . self::SHORT_ALIASES[$m[1]] . ($m[2] ?? '');
            }

            if ($arg === '--dry-run') {
                $this->dryRun = true;
                continue;
            }
            if ($arg === '--yes' || $arg === '-y') {
                $this->yes = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $this->usage();
                exit(0);
            }
            if (preg_match('/^--decision-id=(\d+)$/', $arg, $m)) {
                $this->decisionId = (int) $m[1];
                continue;
            }
            if (preg_match('/^--for-submission=(\d+)$/', $arg, $m)) {
                $this->listForSubmissionId = (int) $m[1];
                continue;
            }
            if (preg_match('/^--([\w-]+)=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
                $flag = $m[1];
                if (!isset(self::SETTABLE[$flag])) {
                    $this->die("--{$flag} is not a settable date field. Allowed: "
                        . implode(', ', array_keys(self::SETTABLE)));
                }
                $this->edits[self::SETTABLE[$flag]] = "{$m[2]} 00:00:00";
                continue;
            }
            $this->die("Unrecognized argument: {$arg}\nSee --help.");
        }

        if ($this->listForSubmissionId !== null) {
            if ($this->decisionId !== null || $this->edits !== []) {
                $this->die('--for-submission is a lookup mode; do not combine with --decision-id or date flags.');
            }
            return;
        }

        if ($this->decisionId === null) {
            $this->die('--decision-id=<int> is required (or --for-submission=<sid> to look one up). See --help.');
        }
    }

    public function usage(): void
    {
        $shortByLong = array_flip(self::SHORT_ALIASES);
        $settable = '';
        foreach (array_keys(self::SETTABLE) as $flag) {
            $short = isset($shortByLong[$flag]) ? "  (-{$shortByLong[$flag]})" : '';
            $settable .= "  --{$flag}=YYYY-MM-DD{$short}\n";
        }

        echo <<<TXT
Adjust the date_decided field on one row in edit_decisions. Dispatches a
SyncArticleJob so Notion picks up the change on the next
`php lib/pkp/tools/jobs.php run`.

Decisions are append-only in pkp-lib (no Repo::decision()->edit()), so this
tool uses a raw UPDATE — the same pattern populate and
recordBackdatedDecisions use.

Usage: {$this->scriptName} --decision-id=<int> [date flags] [--dry-run] [--yes]
       {$this->scriptName} --decision-id=<int>
       {$this->scriptName} --for-submission=<sid>

Modes:
  --decision-id=<int>     Adjust one decision's date. With no date flags,
                          prints the decision's current values and exits.
  --for-submission=<sid>  List every decision recorded on a submission
                          (id, decision, stage, round, editor, date) and
                          exit. Use this to find the decision_id you need
                          for --decision-id.

Settable fields (YYYY-MM-DD; stored as midnight):
{$settable}
Options:
  --dry-run          Show the before/after diff and exit.
  --yes | -y         Skip the interactive confirmation.

TXT;
    }

    public function execute(): void
    {
        $this->installContext();

        if ($this->listForSubmissionId !== null) {
            $this->listForSubmission($this->listForSubmissionId);
            return;
        }

        $row = DB::table('edit_decisions')
            ->where('edit_decision_id', $this->decisionId)
            ->first();
        if ($row === null) {
            $this->die("No edit_decision with edit_decision_id={$this->decisionId}.");
        }

        $submissionId = (int) $row->submission_id;
        $submission = Repo::submission()->get($submissionId);
        if ($submission === null) {
            $this->die("Decision {$this->decisionId} references submission_id={$submissionId}, which no longer exists.");
        }
        if ((int) $submission->getData('contextId') !== (int) $this->context->getId()) {
            $this->die(sprintf(
                'Decision %d belongs to submission %d in context %d, not %d.',
                $this->decisionId,
                $submissionId,
                (int) $submission->getData('contextId'),
                (int) $this->context->getId()
            ));
        }

        $before = $this->snapshot($row);
        $this->printContext($row, $submission);

        if ($this->edits === []) {
            $this->printSnapshot($before);
            return;
        }

        $after = array_merge($before, $this->edits);
        $this->printDiff($before, $after);

        if ($this->dryRun) {
            echo "\n(dry-run: no writes)\n";
            return;
        }

        if (!$this->yes && !$this->confirm('Apply these changes? [y/N] ')) {
            echo "Aborted.\n";
            return;
        }

        DB::table('edit_decisions')
            ->where('edit_decision_id', $this->decisionId)
            ->update($this->edits);

        // No Decision::edit hook exists — dispatch the sync job directly so
        // Notion catches up. Same pattern as resyncSubmission.
        dispatch(new SyncArticleJob(
            (int) $this->context->getId(),
            $submissionId
        ));

        echo "\nUpdated edit_decision {$this->decisionId}. SyncArticleJob queued for submission {$submissionId}.\n";
        echo "Run: php lib/pkp/tools/jobs.php run\n";
    }

    /**
     * List every decision recorded on a submission with the identifying data
     * an editor needs to pick the right one — the decision_id (to pass back
     * on the real invocation), plus decision label + stage + round + editor
     * + current date_decided for disambiguation.
     */
    private function listForSubmission(int $submissionId): void
    {
        $submission = Repo::submission()->get($submissionId);
        if ($submission === null) {
            $this->die("No submission with submission_id={$submissionId}.");
        }
        $title = $submission->getCurrentPublication()?->getLocalizedFullTitle(null, 'text') ?? '(no title)';

        $rows = DB::table('edit_decisions')
            ->where('submission_id', $submissionId)
            ->orderBy('date_decided')
            ->orderBy('edit_decision_id')
            ->get();

        echo "Submission {$submissionId} — {$title}\n\n";

        if (count($rows) === 0) {
            echo "  (no decisions recorded)\n";
            return;
        }

        printf("  %-11s  %-27s  %-5s  %-5s  %-24s  %s\n", 'decision_id', 'decision', 'stage', 'round', 'editor', 'date_decided');
        printf(
            "  %-11s  %-27s  %-5s  %-5s  %-24s  %s\n",
            str_repeat('-', 11),
            str_repeat('-', 27),
            str_repeat('-', 5),
            str_repeat('-', 5),
            str_repeat('-', 24),
            str_repeat('-', 22)
        );

        foreach ($rows as $r) {
            $editorId = (int) $r->editor_id;
            $editor = $editorId > 0 ? Repo::user()->get($editorId) : null;
            $editorName = substr($editor?->getFullName() ?? '(user ' . $editorId . ')', 0, 24);
            printf(
                "  %-11d  %-27s  %-5d  %-5s  %-24s  %s\n",
                (int) $r->edit_decision_id,
                self::DECISION_LABELS[(int) $r->decision] ?? "DECISION_{$r->decision}",
                (int) $r->stage_id,
                $r->round === null ? '-' : (string) (int) $r->round,
                $editorName,
                $this->fmtDate($r->date_decided ?? null)
            );
        }
    }

    /**
     * Generic plugins register per-context, and CLI tools have no request
     * context, so post45NotionSync would otherwise skip its register()
     * entirely — the SyncArticleJob dispatched below would run against a
     * plugin that never bound its bindings. Same setup adjustReviewDates
     * and resyncSubmission use. See OJS-DEV-NOTES worker-context gotcha.
     */
    private function installContext(): void
    {
        /** @var \APP\journal\JournalDAO $journalDao */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        $all = $journalDao->getAll(true)->toArray();
        if (empty($all)) {
            $this->die('No enabled journals found; cannot install a context.');
        }
        $this->context = $all[0];

        Application::get()->getRequest()->getRouter()->_context = $this->context;

        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if ($sync) {
            $sync->register('generic', $sync->getPluginPath(), $this->context->getId());
        }
    }

    /** @return array<string, ?string> */
    private function snapshot(object $row): array
    {
        $snap = [];
        foreach (self::SETTABLE as $column) {
            $snap[$column] = $row->{$column} ?? null;
        }
        return $snap;
    }

    private function printContext(object $row, $submission): void
    {
        $title = $submission->getCurrentPublication()?->getLocalizedFullTitle(null, 'text') ?? '(no title)';
        $decisionConstant = (int) $row->decision;
        $editorId = (int) $row->editor_id;
        $editor = $editorId > 0 ? Repo::user()->get($editorId) : null;

        echo "Decision #{$this->decisionId}\n";
        echo '  Decision:   ' . (self::DECISION_LABELS[$decisionConstant] ?? "DECISION_{$decisionConstant}") . " ({$decisionConstant})\n";
        echo '  Submission: ' . (int) $row->submission_id . ' — ' . $title . "\n";
        echo '  Stage:      ' . (int) $row->stage_id . "\n";
        echo '  Round:      ' . ($row->round === null ? '(null)' : (int) $row->round) . "\n";
        echo '  Editor:     ' . ($editor?->getFullName() ?? '(user ' . $editorId . ')') . "\n";
    }

    private function printDiff(array $before, array $after): void
    {
        echo "\nDates:\n";
        printf("  %-18s  %-22s  %-22s\n", 'field', 'before', 'after');
        printf("  %-18s  %-22s  %-22s\n", str_repeat('-', 18), str_repeat('-', 22), str_repeat('-', 22));
        foreach (self::SETTABLE as $column) {
            $b = $before[$column] ?? null;
            $a = $after[$column] ?? null;
            $marker = ($b !== $a) ? ' *' : '  ';
            printf("%s%-18s  %-22s  %-22s\n", $marker, $column, $this->fmtDate($b), $this->fmtDate($a));
        }
    }

    private function printSnapshot(array $snapshot): void
    {
        echo "\nCurrent dates:\n";
        printf("  %-18s  %-22s\n", 'field', 'value');
        printf("  %-18s  %-22s\n", str_repeat('-', 18), str_repeat('-', 22));
        foreach (self::SETTABLE as $column) {
            printf("  %-18s  %-22s\n", $column, $this->fmtDate($snapshot[$column] ?? null));
        }
    }

    private function fmtDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(null)';
        }
        return preg_replace('/ 00:00:00$/', '', $value) ?? $value;
    }

    private function confirm(string $prompt): bool
    {
        echo $prompt;
        $line = trim((string) fgets(STDIN));
        return strtolower($line) === 'y' || strtolower($line) === 'yes';
    }

    private function die(string $msg): never
    {
        fwrite(STDERR, "ERROR: {$msg}\n");
        exit(1);
    }
}

(new AdjustDecisionDatesTool($argv ?? []))->execute();
