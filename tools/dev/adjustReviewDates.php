<?php

/**
 * @file tools/dev/adjustReviewDates.php
 *
 * Adjust the date fields on a single OJS review assignment. Written for the
 * post-cutover reconcile flow: when populate mis-categorised a submission and
 * an editor manually re-added the reviewer through the OJS UI, the new
 * `review_assignments` row carries today's date on every timestamp. This tool
 * corrects them.
 *
 * ## Why this exists (why not just UPDATE via SQL?)
 *
 * Because sync only wakes up when `Repo::reviewAssignment()->edit()` fires the
 * `ReviewAssignment::edit` hook — see
 * plugins/generic/post45NotionSync/classes/hook/ReviewAssignmentSyncTriggers.php.
 * A raw `UPDATE review_assignments SET ...` bypasses the repo layer, changes
 * land in the DB, and Notion never hears about it until the next full-sync
 * tick reads the row (which, depending on schedule, may be hours later).
 *
 * Using `Repo::reviewAssignment()->edit()` here queues a `SyncReviewJob`
 * immediately; running `php lib/pkp/tools/jobs.php run` drains it and Notion
 * catches up in seconds. The script prints that reminder at the end.
 *
 * ## What's editable in the OJS UI vs. only here
 *
 * The Edit Review dialog covers `date_response_due` and `date_due` only. The
 * other four (`date_assigned`, `date_notified`, `date_confirmed`,
 * `date_completed`) are set implicitly by the workflow — invite creation,
 * reviewer accept/decline, review submit — and are not user-editable through
 * any UI. This tool is the only place to correct them without touching SQL.
 *
 * ## Clearing vs. setting
 *
 * Some fields are legitimately null:
 *   - date_confirmed = NULL   → reviewer hasn't responded to the invite yet
 *   - date_completed = NULL   → review not yet submitted
 *   - date_notified  = NULL   → invite email hasn't been dispatched
 *
 * Use `--clear-<field>` to set a nullable field to NULL, and `--<field>=<date>`
 * to set it. Passing both for the same field is an error.
 *
 * Usage:
 *   php tools/dev/adjustReviewDates.php --review-id=<int> \
 *       [--date-assigned=YYYY-MM-DD] [--clear-notified] [--date-notified=YYYY-MM-DD] \
 *       [--date-confirmed=YYYY-MM-DD | --clear-confirmed] \
 *       [--response-due=YYYY-MM-DD] [--due=YYYY-MM-DD] \
 *       [--date-completed=YYYY-MM-DD | --clear-completed] \
 *       [--dry-run] [--yes]
 */

use APP\core\Application;
use APP\facades\Repo;
use PKP\cliTool\CommandLineTool;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\submission\reviewAssignment\ReviewAssignment;

require(dirname(__FILE__) . '/../bootstrap.php');

class AdjustReviewDatesTool extends CommandLineTool
{
    /**
     * The `--<flag>` name → the Repo::edit() property key. Order here is the
     * order the summary prints them in.
     */
    private const SETTABLE = [
        'date-assigned' => 'dateAssigned',
        'date-notified' => 'dateNotified',
        'date-confirmed' => 'dateConfirmed',
        'response-due' => 'dateResponseDue',
        'due' => 'dateDue',
        'date-completed' => 'dateCompleted',
    ];

    /** Fields OJS allows to be nullable and this tool allows clearing. */
    private const CLEARABLE = [
        'notified' => 'dateNotified',
        'confirmed' => 'dateConfirmed',
        'completed' => 'dateCompleted',
    ];

    private ?int $reviewId = null;
    private ?int $listForSubmissionId = null;
    private bool $dryRun = false;
    private bool $yes = false;

    /** @var array<string, ?string> propertyKey => 'YYYY-MM-DD HH:MM:SS' or null */
    private array $edits = [];

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
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

            if (preg_match('/^--review-id=(\d+)$/', $arg, $m)) {
                $this->reviewId = (int) $m[1];
                continue;
            }

            if (preg_match('/^--for-submission=(\d+)$/', $arg, $m)) {
                $this->listForSubmissionId = (int) $m[1];
                continue;
            }

            if (preg_match('/^--clear-(\w[\w-]*)$/', $arg, $m)) {
                $key = $m[1];
                if (!isset(self::CLEARABLE[$key])) {
                    $this->die("--clear-{$key} is not a clearable field. Allowed: "
                        . implode(', ', array_keys(self::CLEARABLE)));
                }
                $this->addEdit(self::CLEARABLE[$key], null);
                continue;
            }

            if (preg_match('/^--([\w-]+)=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
                $flag = $m[1];
                if (!isset(self::SETTABLE[$flag])) {
                    $this->die("--{$flag} is not a settable date field. Allowed: "
                        . implode(', ', array_keys(self::SETTABLE)));
                }
                // Midnight — OJS stores datetimes; date-only is the honest
                // grain for editorial reconstruction, and matches how populate
                // writes historical dates.
                $this->addEdit(self::SETTABLE[$flag], "{$m[2]} 00:00:00");
                continue;
            }

            $this->die("Unrecognized argument: {$arg}\nSee --help.");
        }

        // Lookup mode: --for-submission is standalone; it prints and exits.
        if ($this->listForSubmissionId !== null) {
            if ($this->reviewId !== null || $this->edits !== []) {
                $this->die('--for-submission is a lookup mode; do not combine with --review-id or date flags.');
            }
            return;
        }

        if ($this->reviewId === null) {
            $this->die('--review-id=<int> is required (or --for-submission=<sid> to look one up). See --help.');
        }
        if ($this->edits === []) {
            $this->die('No date changes given. Pass at least one --<field>=YYYY-MM-DD or --clear-<field>.');
        }
    }

    public function usage(): void
    {
        $settable = '';
        foreach (array_keys(self::SETTABLE) as $flag) {
            $settable .= "  --{$flag}=YYYY-MM-DD\n";
        }
        $clearable = '';
        foreach (array_keys(self::CLEARABLE) as $flag) {
            $clearable .= "  --clear-{$flag}\n";
        }

        echo <<<TXT
Adjust date fields on one OJS review assignment. Fires the repo edit hook,
which queues a SyncReviewJob so Notion picks up the change on the next
`php lib/pkp/tools/jobs.php run`.

Usage: {$this->scriptName} --review-id=<int> [date flags] [--dry-run] [--yes]
       {$this->scriptName} --for-submission=<sid>

Modes:
  --review-id=<int>       Adjust one review assignment's dates.
  --for-submission=<sid>  List every review assignment for a submission
                          (id, round, reviewer, status) and exit. Use this
                          to find the review_id you need for --review-id.

Settable fields (YYYY-MM-DD; stored as midnight):
{$settable}
Clearable fields (set to NULL — valid where OJS allows null):
{$clearable}
Options:
  --dry-run          Show the before/after diff and exit.
  --yes | -y         Skip the interactive confirmation.

Setting and clearing the same field is an error.

TXT;
    }

    public function execute(): void
    {
        $this->installContext();

        if ($this->listForSubmissionId !== null) {
            $this->listForSubmission($this->listForSubmissionId);
            return;
        }

        $assignment = Repo::reviewAssignment()->get($this->reviewId);
        if ($assignment === null) {
            $this->die("No review assignment with review_id={$this->reviewId}.");
        }

        $before = $this->snapshot($assignment);
        $after = array_merge($before, $this->edits);

        $this->printContext($assignment);
        $this->printDiff($before, $after);

        if ($this->dryRun) {
            echo "\n(dry-run: no writes)\n";
            return;
        }

        if (!$this->yes && !$this->confirm('Apply these changes? [y/N] ')) {
            echo "Aborted.\n";
            return;
        }

        Repo::reviewAssignment()->edit($assignment, $this->edits);

        echo "\nUpdated review {$this->reviewId}. Sync job queued.\n";
        echo "Run: php lib/pkp/tools/jobs.php run\n";
    }

    /**
     * List every review assignment for a submission with the identifying data
     * an editor needs to pick the right one — the review_id (to pass back on
     * the real invocation), plus round + reviewer + status for disambiguation.
     */
    private function listForSubmission(int $submissionId): void
    {
        $submission = Repo::submission()->get($submissionId);
        if ($submission === null) {
            $this->die("No submission with submission_id={$submissionId}.");
        }
        $title = $submission->getCurrentPublication()?->getLocalizedFullTitle(null, 'text') ?? '(no title)';

        $assignments = Repo::reviewAssignment()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->getMany();

        echo "Submission {$submissionId} — {$title}\n\n";

        if (count($assignments) === 0) {
            echo "  (no review assignments)\n";
            return;
        }

        printf("  %-9s  %-5s  %-32s  %s\n", 'review_id', 'round', 'reviewer', 'status');
        printf("  %-9s  %-5s  %-32s  %s\n", str_repeat('-', 9), str_repeat('-', 5), str_repeat('-', 32), str_repeat('-', 22));

        foreach ($assignments as $a) {
            $reviewerId = (int) $a->getData('reviewerId');
            $reviewer = $reviewerId > 0 ? Repo::user()->get($reviewerId) : null;
            printf(
                "  %-9d  %-5d  %-32s  %s\n",
                (int) $a->getId(),
                (int) $a->getData('round'),
                substr($reviewer?->getFullName() ?? '(unknown user ' . $reviewerId . ')', 0, 32),
                $this->statusLabel($a->getStatus())
            );
        }
    }

    /** Human-readable label for a REVIEW_ASSIGNMENT_STATUS_* code. */
    private function statusLabel(int $status): string
    {
        return match ($status) {
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_AWAITING_RESPONSE => 'AWAITING_RESPONSE',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_DECLINED => 'DECLINED',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_RESPONSE_OVERDUE => 'RESPONSE_OVERDUE',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_ACCEPTED => 'ACCEPTED',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_REVIEW_OVERDUE => 'REVIEW_OVERDUE',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_RECEIVED => 'RECEIVED',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_COMPLETE => 'COMPLETE',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_THANKED => 'THANKED',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_CANCELLED => 'CANCELLED',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_REQUEST_RESEND => 'REQUEST_RESEND',
            ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_VIEWED => 'VIEWED',
            default => "STATUS_{$status}",
        };
    }

    /**
     * Generic plugins register per-context, and CLI tools have no request
     * context, so `post45NotionSync` skips its register() entirely — the
     * ReviewAssignment::edit hook that queues SyncReviewJob never binds and
     * the write is silently unmirrored. Same pattern populate uses: install
     * the context on the router, then re-register the plugin against it.
     * See OJS-DEV-NOTES.md worker-context gotcha.
     */
    private function installContext(): void
    {
        /** @var \APP\journal\JournalDAO $journalDao */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        $all = $journalDao->getAll(true)->toArray();
        if (empty($all)) {
            $this->die('No enabled journals found; cannot install a context.');
        }
        $context = $all[0];

        Application::get()->getRequest()->getRouter()->_context = $context;

        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if ($sync) {
            $sync->register('generic', $sync->getPluginPath(), $context->getId());
        }
    }

    /** Read the current values of every settable field, keyed by property name. */
    private function snapshot(ReviewAssignment $assignment): array
    {
        $snap = [];
        foreach (self::SETTABLE as $property) {
            $snap[$property] = $assignment->getData($property);
        }
        return $snap;
    }

    private function printContext(ReviewAssignment $assignment): void
    {
        $reviewerId = (int) $assignment->getData('reviewerId');
        $reviewer = $reviewerId > 0 ? Repo::user()->get($reviewerId) : null;
        $submissionId = (int) $assignment->getData('submissionId');
        $submission = $submissionId > 0 ? Repo::submission()->get($submissionId) : null;
        $title = $submission?->getCurrentPublication()?->getLocalizedFullTitle(null, 'text');

        echo "Review assignment #{$this->reviewId}\n";
        echo '  Reviewer:   ' . ($reviewer?->getFullName() ?? '(unknown user ' . $reviewerId . ')') . "\n";
        echo '  Submission: ' . ($submission?->getId() ?? '?') . ' — ' . ($title ?? '(no title)') . "\n";
        echo '  Round:      ' . (int) $assignment->getData('round') . "\n";
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
            printf(
                "%s%-18s  %-22s  %-22s\n",
                $marker,
                $property,
                $this->fmtDate($b),
                $this->fmtDate($a)
            );
        }
    }

    private function fmtDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(null)';
        }
        // Strip the ` 00:00:00` when the time is midnight — the tool only
        // writes midnight anyway; keep the noise out of the diff.
        return preg_replace('/ 00:00:00$/', '', $value) ?? $value;
    }

    private function addEdit(string $property, ?string $value): void
    {
        if (array_key_exists($property, $this->edits)) {
            $this->die("Conflicting flags for `{$property}`: cannot set AND clear the same field.");
        }
        $this->edits[$property] = $value;
    }

    private function confirm(string $prompt): bool
    {
        echo $prompt;
        $answer = trim((string) fgets(STDIN));
        return strtolower($answer) === 'y' || strtolower($answer) === 'yes';
    }

    private function die(string $msg): void
    {
        fwrite(STDERR, "FATAL: {$msg}\n");
        exit(1);
    }
}

(new AdjustReviewDatesTool($argv ?? []))->execute();
