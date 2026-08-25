<?php

/**
 * @file tools/dev/prodCleanup.php
 *
 * Bulk-delete submissions and/or users from an OJS instance. Built for the
 * pre-launch cleanup of test data (test submissions + spam-bot registrations),
 * but designed to stay useful post-launch for spam-user cleanup passes.
 *
 * Operations are opt-in (no flags → does nothing) and dry-run by default
 * (nothing writes without --confirm). Ordering when both are requested:
 * submissions first → sync-ledger cleanup → users, because deleting a user
 * who is still an author of a live submission fails on FK constraint.
 *
 * Usage:
 *   php tools/dev/prodCleanup.php [--confirm]
 *                                 [--delete-submissions]
 *                                 [--delete-users=ID1,ID2,...]
 *                                 [--truncate-sync-ledger]
 *
 * Safety rails:
 *   - Dry-run by default. Real deletes need --confirm.
 *   - Aborts if any user in --delete-users has ROLE_ID_SITE_ADMIN. Same
 *     defensive check for the last remaining Manager (if deleting the
 *     user would drop the context's Manager count to zero, abort).
 *   - Per-entity ledger cleanup: when a submission or user is deleted,
 *     the tool also `forget()`s that entity's post45_notion_sync_state
 *     row so orphans never accumulate across ongoing spam-cleanup passes.
 *   - --truncate-sync-ledger is the additional nuke for the pre-launch
 *     case where populate's preflight requires an empty ledger. It is
 *     NOT safe post-launch (would blow away real sync state); opt-in.
 *
 * Ledger cascade note: `Repo::submission()->delete()` and
 * `Repo::user()->delete()` handle their own cascade (publications,
 * authors, stage assignments, review assignments, files on disk + DB,
 * decisions, discussions, event log, notifications, role assignments,
 * sessions). We add the sync-ledger cleanup on top because that table
 * lives in a plugin's schema and isn't part of core's cascade.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\post45NotionSync\classes\repository\SyncStateRepository;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\security\Role;

require(dirname(__FILE__) . '/../bootstrap.php');

class ProdCleanupTool extends CommandLineTool
{
    private const CONTEXT_ID = 1;

    public bool $confirm = false;
    public bool $deleteSubmissions = false;
    public bool $truncateSyncLedger = false;
    /** @var int[] */
    public array $userIdsToDelete = [];

    private array $summary = [
        'submissions_deleted' => 0,
        'submissions_failed' => 0,
        'users_deleted' => 0,
        'users_failed' => 0,
        'ledger_rows_forgotten' => 0,
        'ledger_rows_truncated' => 0,
    ];

    public function __construct($argv = [])
    {
        parent::__construct($argv);
        foreach ($this->argv as $arg) {
            if ($arg === '--confirm') {
                $this->confirm = true;
            } elseif ($arg === '--delete-submissions') {
                $this->deleteSubmissions = true;
            } elseif ($arg === '--truncate-sync-ledger') {
                $this->truncateSyncLedger = true;
            } elseif (preg_match('/^--delete-users=(.+)$/', $arg, $m)) {
                $this->userIdsToDelete = array_map('intval', array_filter(explode(',', $m[1]), 'strlen'));
            } elseif ($arg === '--help' || $arg === '-h') {
                $this->usage();
                exit(0);
            } else {
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                $this->usage();
                exit(1);
            }
        }
    }

    public function usage(): void
    {
        echo <<<TXT
Bulk-delete submissions and/or users from OJS. See the file docblock for
context. Dry-run by default; add --confirm to actually delete.

Usage: {$this->scriptName} [--confirm]
                          [--delete-submissions]
                          [--delete-users=ID1,ID2,...]
                          [--truncate-sync-ledger]

Common invocations:

  # Pre-launch full cleanup (all test data)
  {$this->scriptName} --delete-submissions --delete-users=3,5,6 --truncate-sync-ledger --confirm

  # Ongoing spam-user cleanup (does NOT touch submissions or ledger)
  {$this->scriptName} --delete-users=45,52,63 --confirm

  # Preview a spam cleanup (no writes)
  {$this->scriptName} --delete-users=45,52,63

TXT;
    }

    public function execute(): void
    {
        if (!$this->deleteSubmissions && empty($this->userIdsToDelete) && !$this->truncateSyncLedger) {
            $this->info('Nothing to do. Pass --delete-submissions, --delete-users=..., or --truncate-sync-ledger.');
            $this->usage();
            return;
        }

        $this->info($this->confirm ? '=== LIVE RUN (writes are real) ===' : '=== DRY RUN (nothing will be written; add --confirm to actually delete) ===');

        // CLI scripts run without a request context. Repo::submission()->delete()
        // cascades into submission-file deletes, which fire
        // PendingRevisionsNotificationManager::updateNotification — that
        // manager calls $request->getContext()->getId() and blows up on null.
        // Same worker-context gotcha jobs hit; see OJS-DEV-NOTES.md.
        $this->installRequestContext();

        // Safety pass BEFORE any writes.
        $this->safetyChecks();

        if ($this->deleteSubmissions) {
            $this->doDeleteSubmissions();
        }
        if ($this->truncateSyncLedger) {
            $this->doTruncateSyncLedger();
        }
        if (!empty($this->userIdsToDelete)) {
            $this->doDeleteUsers();
        }

        $this->printSummary();
    }

    private function safetyChecks(): void
    {
        if (empty($this->userIdsToDelete)) {
            return;
        }
        // Refuse to delete a site admin.
        foreach ($this->userIdsToDelete as $userId) {
            $user = Repo::user()->get($userId);
            if (!$user) {
                continue;
            }
            $groups = Repo::userGroup()->userUserGroups($userId)->all();
            foreach ($groups as $g) {
                if ((int) $g->roleId === Role::ROLE_ID_SITE_ADMIN) {
                    $this->die("REFUSING: user id={$userId} ({$user->getUsername()}) is a Site Admin. Deleting site admins requires manual intervention.");
                }
            }
        }
        // Count remaining Managers post-delete; abort if that would be zero.
        $currentManagers = 0;
        $doomedManagers = 0;
        $doomedSet = array_flip($this->userIdsToDelete);
        foreach (Repo::user()->getCollector()->getMany() as $u) {
            $groups = Repo::userGroup()->userUserGroups($u->getId(), self::CONTEXT_ID)->all();
            foreach ($groups as $g) {
                if ((int) $g->roleId === Role::ROLE_ID_MANAGER) {
                    $currentManagers++;
                    if (isset($doomedSet[$u->getId()])) {
                        $doomedManagers++;
                    }
                    break;
                }
            }
        }
        $remaining = $currentManagers - $doomedManagers;
        if ($remaining < 1) {
            $this->die("REFUSING: this would leave 0 Managers in context {$this->contextIdLabel()}. Add a Manager account first, or remove the Manager-role user from --delete-users.");
        }
        $this->info("Safety check: {$currentManagers} Manager(s) currently, {$doomedManagers} in delete list, {$remaining} would remain after cleanup.");
    }

    // ---------- Submissions ----------

    private function doDeleteSubmissions(): void
    {
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([self::CONTEXT_ID])
            ->getMany();
        $this->info(sprintf("\n=== Submissions: %d found ===", count($submissions)));

        foreach ($submissions as $submission) {
            $submissionId = (int) $submission->getId();
            $pub = $submission->getCurrentPublication();
            $title = $pub?->getLocalizedFullTitle(null, 'text') ?? '(no title)';
            $this->info(sprintf('  %s submission id=%d  %s', $this->prefix(), $submissionId, mb_substr($title, 0, 60)));

            if (!$this->confirm) {
                continue;
            }

            try {
                // Per-entity ledger cleanup FIRST (so if delete fails we don't leave the ledger inconsistent — a re-run finds the entity, tries again).
                $this->forgetLedgerRow(SyncStateRepository::ENTITY_SUBMISSION, $submissionId);
                Repo::submission()->delete($submission);
                $this->summary['submissions_deleted']++;
            } catch (\Throwable $e) {
                $this->summary['submissions_failed']++;
                fwrite(STDERR, "    FAILED: {$e->getMessage()}\n");
                // Full trace so we can diagnose the intermittent "Call to a
                // member function getId() on null" that keeps hitting a few
                // submissions. Frame with the actual null lookup is what we
                // need to identify the plugin/hook responsible.
                fwrite(STDERR, "    Trace:\n" . self::indentTrace($e->getTraceAsString()) . "\n");
            }
        }
    }

    private static function indentTrace(string $trace): string
    {
        return '      ' . str_replace("\n", "\n      ", $trace);
    }

    // ---------- Sync ledger truncate ----------

    private function doTruncateSyncLedger(): void
    {
        $count = DB::table(SyncStateRepository::TABLE)->count();
        $this->info(sprintf("\n=== Sync ledger: %d row(s) ===", $count));
        $this->info(sprintf('  %s TRUNCATE post45_notion_sync_state (%d rows)', $this->prefix(), $count));

        if (!$this->confirm) {
            return;
        }

        DB::table(SyncStateRepository::TABLE)->delete();
        $this->summary['ledger_rows_truncated'] = $count;
    }

    // ---------- Users ----------

    private function doDeleteUsers(): void
    {
        $this->info(sprintf("\n=== Users: %d requested ===", count($this->userIdsToDelete)));

        foreach ($this->userIdsToDelete as $userId) {
            $user = Repo::user()->get($userId);
            if (!$user) {
                $this->info("  ! skip id={$userId} (not found)");
                continue;
            }
            $this->info(sprintf(
                '  %s user id=%d  %s <%s>',
                $this->prefix(),
                $userId,
                mb_substr((string) $user->getUsername(), 0, 30),
                (string) $user->getEmail()
            ));

            if (!$this->confirm) {
                continue;
            }

            try {
                $this->forgetLedgerRow(SyncStateRepository::ENTITY_USER, $userId);
                Repo::user()->delete($user);
                $this->summary['users_deleted']++;
            } catch (\Throwable $e) {
                // If the user is still referenced by a live submission (author,
                // stage assignment, etc.), the delete will fail on FK. Log +
                // continue; the caller can look at the specific issue.
                $this->summary['users_failed']++;
                fwrite(STDERR, "    FAILED: {$e->getMessage()}\n");
            }
        }
    }

    // ---------- Helpers ----------

    private function installRequestContext(): void
    {
        /** @var \APP\journal\JournalDAO $journalDao */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        $context = $journalDao->getById(self::CONTEXT_ID);
        if (!$context instanceof Context) {
            $this->die(sprintf('Context id=%d not found; cannot install request context.', self::CONTEXT_ID));
        }
        Application::get()->getRequest()->getRouter()->_context = $context;
    }

    private function forgetLedgerRow(string $entityType, int $entityId): void
    {
        // Delete every ledger row for this (entity_type, entity_id) pair,
        // across all notion_database_id scopes (in case an entity has been
        // repointed across DBs historically). Not using SyncStateRepository
        // because it's database-scoped; here we want to clear everything.
        $deleted = DB::table(SyncStateRepository::TABLE)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->delete();
        $this->summary['ledger_rows_forgotten'] += $deleted;
    }

    private function prefix(): string
    {
        return $this->confirm ? 'DELETE' : 'would delete';
    }

    private function contextIdLabel(): string
    {
        return (string) self::CONTEXT_ID;
    }

    private function info(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    private function die(string $message): never
    {
        fwrite(STDERR, "ERROR: {$message}\n");
        exit(1);
    }

    private function printSummary(): void
    {
        $mode = $this->confirm ? 'LIVE RUN' : 'DRY RUN';
        fwrite(STDOUT, "\n=== summary ({$mode}) ===\n");
        foreach ($this->summary as $k => $v) {
            fwrite(STDOUT, sprintf("  %-28s %d\n", $k, $v));
        }
    }
}

(new ProdCleanupTool($argv ?? []))->execute();
