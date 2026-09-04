<?php

/**
 * @file tools/dev/adjustSubmissionDates.php
 *
 * Adjust the `date_submitted` field on one OJS submission. Written for the
 * post-cutover audit workflow where populate stamped a run-time value
 * instead of Notion's historical `Submission Received` date (see
 * `populate_date_fidelity_audit` memory).
 *
 * ## Why this exists (why not just UPDATE via SQL?)
 *
 * `Repo::submission()->edit()` preserves `dateSubmitted` when it's already
 * set (lib/pkp/classes/submission/Repository.php:614) — no silent rewrite —
 * AND it fires `Submission::edit`, stamps last-activity, and keeps the
 * repository layer's invariants. A raw UPDATE bypasses all of that.
 *
 * ## What it does NOT trigger
 *
 * Notion sync for `date_submitted` is intentionally one-way at creation only:
 * ArticleSchema `Submission Received` is `OJS_ON_CREATE_ONLY`, so sync will
 * NOT propagate this fix to Notion. That's correct for the audit use case —
 * we're correcting OJS to match Notion's historical value, not the reverse.
 * If you need the fix to reach Notion, edit the `Submission Received` cell
 * by hand there instead.
 *
 * ## Only one settable field
 *
 * `date_last_activity` and `last_modified` are auto-derived on every edit
 * and not worth overriding. Publication dates (`date_published`) live on a
 * different entity and are driven by MarkPublished. So this tool only
 * exposes `--submitted`.
 *
 * Usage:
 *   php tools/dev/adjustSubmissionDates.php --submission-id=<int>
 *   php tools/dev/adjustSubmissionDates.php --submission-id=<int> \
 *       --submitted=YYYY-MM-DD [-s=YYYY-MM-DD] [--dry-run] [--yes]
 */

use APP\core\Application;
use APP\facades\Repo;
use PKP\cliTool\CommandLineTool;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\submission\PKPSubmission;

require(dirname(__FILE__) . '/../bootstrap.php');

class AdjustSubmissionDatesTool extends CommandLineTool
{
    /** @var array<string, string> --<flag> => Repo::edit() property key */
    private const SETTABLE = [
        'submitted' => 'dateSubmitted',
    ];

    /** Short-flag aliases mirroring adjustReviewDates' shape. */
    private const SHORT_ALIASES = [
        's' => 'submitted',
    ];

    private ?int $submissionId = null;
    private bool $dryRun = false;
    private bool $yes = false;

    /** @var array<string, ?string> propertyKey => 'YYYY-MM-DD HH:MM:SS' */
    private array $edits = [];

    private $context;

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            // Expand short flags (-s=YYYY-MM-DD → --submitted=YYYY-MM-DD).
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
            if (preg_match('/^--submission-id=(\d+)$/', $arg, $m)) {
                $this->submissionId = (int) $m[1];
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

        if ($this->submissionId === null) {
            $this->die('--submission-id=<int> is required. See --help.');
        }
        // No date flags = view mode (mirrors adjustReviewDates).
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
Adjust date fields on one OJS submission. Fires Submission::edit; sync does
NOT propagate — dateSubmitted maps to Notion's `Submission Received`, which
is OJS_ON_CREATE_ONLY (Notion is the historical authority for this field).

Usage: {$this->scriptName} --submission-id=<int> [date flags] [--dry-run] [--yes]
       {$this->scriptName} --submission-id=<int>

Modes:
  --submission-id=<int>  Adjust the submission's dates. With no date flags,
                         prints the submission's current dates and exits.

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

        $submission = Repo::submission()->get($this->submissionId);
        if ($submission === null) {
            $this->die("No submission with submission_id={$this->submissionId}.");
        }
        if ((int) $submission->getData('contextId') !== (int) $this->context->getId()) {
            $this->die(sprintf(
                'Submission %d belongs to context %d, not %d.',
                $this->submissionId,
                (int) $submission->getData('contextId'),
                (int) $this->context->getId()
            ));
        }

        $before = $this->snapshot($submission);
        $this->printContext($submission);

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

        Repo::submission()->edit($submission, $this->edits);

        echo "\nUpdated submission {$this->submissionId}.\n";
        echo "Note: sync does NOT propagate dateSubmitted to Notion (OJS_ON_CREATE_ONLY).\n";
        echo "If Notion needs the same value, edit `Submission Received` by hand.\n";
    }

    /**
     * Generic plugins register per-context; CLI tools have no request
     * context, so post45NotionSync would otherwise skip its register()
     * entirely. Not strictly necessary for a date_submitted edit (sync is
     * a no-op per the class docblock) but keeps the setup symmetrical
     * with the other reconciliation tools and correct in case a future
     * settable field IS synced.
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
    private function snapshot(PKPSubmission $submission): array
    {
        $snap = [];
        foreach (self::SETTABLE as $property) {
            $snap[$property] = $submission->getData($property);
        }
        return $snap;
    }

    private function printContext(PKPSubmission $submission): void
    {
        $title = $submission->getCurrentPublication()?->getLocalizedFullTitle(null, 'text') ?? '(no title)';
        echo "Submission #{$this->submissionId}\n";
        echo "  Title:  {$title}\n";
        echo '  Stage:  ' . (int) $submission->getData('stageId') . "\n";
        echo '  Status: ' . (int) $submission->getData('status') . "\n";
    }

    private function printDiff(array $before, array $after): void
    {
        echo "\nDates:\n";
        printf("  %-18s  %-22s  %-22s\n", 'field', 'before', 'after');
        printf("  %-18s  %-22s  %-22s\n", str_repeat('-', 18), str_repeat('-', 22), str_repeat('-', 22));
        foreach (self::SETTABLE as $property) {
            $b = $before[$property] ?? null;
            $a = $after[$property] ?? null;
            $marker = ($b !== $a) ? ' *' : '  ';
            printf("%s%-18s  %-22s  %-22s\n", $marker, $property, $this->fmtDate($b), $this->fmtDate($a));
        }
    }

    private function printSnapshot(array $snapshot): void
    {
        echo "\nCurrent dates:\n";
        printf("  %-18s  %-22s\n", 'field', 'value');
        printf("  %-18s  %-22s\n", str_repeat('-', 18), str_repeat('-', 22));
        foreach (self::SETTABLE as $property) {
            printf("  %-18s  %-22s\n", $property, $this->fmtDate($snapshot[$property] ?? null));
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

(new AdjustSubmissionDatesTool($argv ?? []))->execute();
