<?php

/**
 * @file tools/dev/stampBaselines.php
 *
 * Post45 — one-shot baseline-stamping tool. Runs between populateFromNotion
 * and cutover. For every entity populate created a ledger row for, runs the
 * real OJS -> Notion synchronizer once, which:
 *
 *   1. Fetches the current Notion page.
 *   2. Computes what the mapper would produce from the (fresh) OJS state.
 *   3. PATCHes any properties that differ — reconciles the round-trip loss
 *      between populate's Notion -> OJS mapping and sync's OJS -> Notion
 *      mapping. Those two are not guaranteed inverses, so absent this step
 *      the first post-cutover sync would emit ~40 benign but noisy PATCHes.
 *   4. Records `last_payload = mapper output` in the ledger.
 *
 * After this runs cleanly the invariant holds:
 *     mapper_output === Notion === ledger baseline
 * so the first real editorial sync post-cutover finds every property IN_SYNC
 * and skips the API call — see DriftDetector.
 *
 * Iterates in dependency order: users first, then submissions (whose
 * Author(s) relations resolve against the People ledger stamped above), then
 * review assignments (whose Article + Reader relations resolve against both).
 *
 * Not part of the ongoing sync loop. Re-runnable — every run is idempotent
 * against a stable OJS state (the synchronizer's own drift-detection makes
 * a second run a no-op). If OJS state changes after stamping (populate
 * re-run, bulk fix script, manual OJS edit), re-run this tool.
 *
 * Sequenced relative to cutover:
 *   1. Final `populateFromNotion.php --reset-and-repopulate` on prod
 *   2. `stampBaselines.php` (this tool)
 *   3. Dry-run sync verification (should report zero writes)
 *   4. Cutover flip
 *
 * Usage:
 *   php tools/dev/stampBaselines.php [--dry-run] [--entity=TYPE] [--id=N]
 *                                    [--verbose] [--journal=PATH]
 *
 * Defaults:
 *   --dry-run    off (writes are real)
 *   --entity     all three entity types (user, submission, review_assignment)
 *   --id         every ledger row for the selected entity type(s)
 *   --journal    first enabled journal
 *
 * Bypasses the plugin's `enableSync` kill switch deliberately — this is
 * exactly the deliberate write we need. Slack is disabled for the same
 * reason (no need to alarm the team on the reconciliation writes; any real
 * MANUAL_EDIT_OVERWRITE at this stage is diagnostic, reported to stdout).
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\post45NotionSync\classes\job\SyncArticleJob;
use APP\plugins\generic\post45NotionSync\classes\job\SyncPersonJob;
use APP\plugins\generic\post45NotionSync\classes\job\SyncReviewJob;
use APP\plugins\generic\post45NotionSync\classes\notification\SlackNotifier;
use APP\plugins\generic\post45NotionSync\classes\repository\SyncStateRepository;
use APP\plugins\generic\post45NotionSync\classes\sync\SyncOutcome;
use PKP\cliTool\CommandLineTool;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;

require(dirname(__FILE__) . '/../bootstrap.php');

/**
 * Trait: override the two protected NotionSyncJob hooks that this tool needs
 * to bend — `syncEnabled()` (always yes; the enable-sync setting is the
 * running-sync switch, and we deliberately run outside that) and
 * `slackNotifier()` (no-op; the reconciliation writes should not alarm the
 * team). Also holds the per-run dry-run flag so the synchronizers see it.
 *
 * Applied to StampSyncArticleJob / StampSyncPersonJob / StampSyncReviewJob
 * so each of the three job types keeps ALL its real production logic
 * (including author-page resolution, review-track resolution, ledger-based
 * article-relation lookup) and only the two hooks above differ.
 */
trait StampingJobOverrides
{
    /** @var bool set by the tool before dispatch */
    public static bool $dryRun = false;

    /** @var array<string, StampOutcome> collected outcomes keyed by "entityType:entityId" */
    public static array $outcomes = [];

    protected function syncEnabled(): bool
    {
        return true;
    }

    protected function slackNotifier(): SlackNotifier
    {
        return new SlackNotifier('', []);
    }

    protected function setting(string $name): mixed
    {
        if ($name === 'dryRun') {
            return self::$dryRun;
        }
        return parent::setting($name);
    }

    protected function report(string $entityLabel, SyncOutcome $outcome): void
    {
        self::$outcomes[$this->entityType . ':' . $this->entityId] = new StampOutcome(
            entityLabel: $entityLabel,
            action: $outcome->action,
            overwriteCount: count($outcome->overwrites()),
            unknownDecisionCount: count($outcome->unknownDecisions),
            describe: $outcome->describe(),
        );
    }

    protected function reportFailure(string $entityLabel, \Throwable $error): void
    {
        self::$outcomes[$this->entityType . ':' . $this->entityId] = new StampOutcome(
            entityLabel: $entityLabel,
            action: 'FAILED',
            overwriteCount: 0,
            unknownDecisionCount: 0,
            describe: $error->getMessage(),
        );
    }
}

class StampOutcome
{
    public function __construct(
        public readonly string $entityLabel,
        public readonly string $action,
        public readonly int $overwriteCount,
        public readonly int $unknownDecisionCount,
        public readonly string $describe,
    ) {
    }
}

class StampSyncPersonJob extends SyncPersonJob
{
    use StampingJobOverrides;
}

class StampSyncArticleJob extends SyncArticleJob
{
    use StampingJobOverrides;
}

class StampSyncReviewJob extends SyncReviewJob
{
    use StampingJobOverrides;
}

class StampBaselinesTool extends CommandLineTool
{
    private const CONTEXT_ID = 1;

    private const ENTITY_ORDER = [
        SyncStateRepository::ENTITY_USER,
        SyncStateRepository::ENTITY_SUBMISSION,
        SyncStateRepository::ENTITY_REVIEW_ASSIGNMENT,
    ];

    private const ENTITY_LABELS = [
        SyncStateRepository::ENTITY_USER => 'users',
        SyncStateRepository::ENTITY_SUBMISSION => 'submissions',
        SyncStateRepository::ENTITY_REVIEW_ASSIGNMENT => 'review assignments',
    ];

    private bool $dryRun = false;
    private bool $verbose = false;
    private ?string $entityFilter = null;
    private ?int $idFilter = null;
    private ?string $journalPath = null;

    private $context;
    private $editor;

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--dry-run') {
                $this->dryRun = true;
            } elseif ($arg === '--verbose') {
                $this->verbose = true;
            } elseif (str_starts_with($arg, '--entity=')) {
                $this->entityFilter = substr($arg, 9);
                if (!in_array($this->entityFilter, self::ENTITY_ORDER, true)) {
                    $this->die("Invalid --entity value: {$this->entityFilter}. Expected one of: "
                        . implode(', ', self::ENTITY_ORDER));
                }
            } elseif (str_starts_with($arg, '--id=')) {
                $this->idFilter = (int) substr($arg, 5);
            } elseif (str_starts_with($arg, '--journal=')) {
                $this->journalPath = substr($arg, 10);
            } elseif ($arg === '--help' || $arg === '-h') {
                $this->usage();
                exit(0);
            }
        }

        if ($this->idFilter !== null && $this->entityFilter === null) {
            $this->die('--id requires --entity=TYPE (which entity table to look up in).');
        }
    }

    public function usage(): void
    {
        fwrite(STDERR, <<<TXT
Stamp Notion sync-state baselines by running the real synchronizer once per
populated entity, so the first post-cutover sync is a true no-op.

Usage:
  php tools/dev/stampBaselines.php [--dry-run] [--entity=TYPE] [--id=N]
                                   [--verbose] [--journal=PATH]

Options:
  --dry-run          Report what would change without writing to Notion or
                     stamping the ledger. Synchronizers run in dry-run mode.
  --entity=TYPE      Restrict to one entity type. One of: user, submission,
                     review_assignment. Default: all three, in dependency
                     order (user -> submission -> review_assignment).
  --id=N             Restrict to a single OJS entity id. Requires --entity.
                     Useful for re-stamping after a manual OJS-side fix.
  --verbose          Print per-entity outcome lines.
  --journal=PATH     Journal path. Default: first enabled journal.

Preconditions:
  * post45NotionSync plugin must be configured (integration token +
    database ids). This tool bypasses the plugin's enableSync kill switch
    deliberately.
  * Ledger rows must exist for the entities to be stamped. Populate first.


TXT);
    }

    public function execute(): void
    {
        $this->bootstrap();
        $this->preflight();

        StampSyncArticleJob::$dryRun = $this->dryRun;
        StampSyncPersonJob::$dryRun = $this->dryRun;
        StampSyncReviewJob::$dryRun = $this->dryRun;

        if ($this->dryRun) {
            fwrite(STDERR, "\n=== DRY RUN — no writes will happen ===\n\n");
        }

        $entityTypes = $this->entityFilter === null
            ? self::ENTITY_ORDER
            : [$this->entityFilter];

        foreach ($entityTypes as $entityType) {
            $this->stampEntityType($entityType);
        }

        $this->printSummary();
    }

    private function bootstrap(): void
    {
        $this->context = $this->resolveContext();
        $this->editor = $this->resolveEditor();

        Registry::set('user', $this->editor);
        Application::get()->getRequest()->getRouter()->_context = $this->context;

        // Same worker-context precaution populateFromNotion takes: generic
        // plugins register per-context, so re-register post45Editorial +
        // post45NotionSync against the target context. Without this the
        // submission schema extensions (publicationUrl + Post45 settings)
        // aren't hydrated and syncs would push null over real values.
        foreach (['post45editorialplugin', 'post45notionsyncplugin'] as $name) {
            $plugin = PluginRegistry::getPlugin('generic', $name);
            if ($plugin) {
                $plugin->register('generic', $plugin->getPluginPath(), $this->context->getId());
            }
        }

        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if (!$sync) {
            $this->die('post45NotionSync plugin not registered. Enable it before running stampBaselines.');
        }

        foreach (['integrationToken', 'articlesDatabaseId', 'peopleDatabaseId', 'readersReportsDatabaseId'] as $name) {
            $value = trim((string) $sync->getSetting(self::CONTEXT_ID, $name));
            if ($value === '') {
                $this->die("post45NotionSync setting '{$name}' is empty. Configure the plugin first.");
            }
        }
    }

    private function preflight(): void
    {
        $count = \Illuminate\Support\Facades\DB::table(SyncStateRepository::TABLE)->count();
        if ($count === 0) {
            $this->die(
                'Ledger table post45_notion_sync_state is empty. Run populateFromNotion.php first.'
            );
        }

        // Older populate runs stamped `last_payload = '[]'` — an empty JSON
        // array — which DriftDetector reads as "we synced but every property
        // was null." Every existing Notion value then classifies as a
        // manual-edit overwrite, firing the page-body OverwriteJournalist for
        // what's actually first-baseline reconciliation. Normalize those rows
        // to NULL so drift falls back to UNKNOWN_BASELINE (no journalist, no
        // false overwrite alerts). Populate itself now uses recordPageId()
        // which never writes this shape; this is defensive for stragglers.
        $emptyBaselineCount = \Illuminate\Support\Facades\DB::table(SyncStateRepository::TABLE)
            ->where('last_payload', '[]')
            ->count();
        if ($emptyBaselineCount > 0) {
            \Illuminate\Support\Facades\DB::table(SyncStateRepository::TABLE)
                ->where('last_payload', '[]')
                ->update([
                    'last_payload' => null,
                    'last_payload_hash' => null,
                ]);
            $this->info("Normalized {$emptyBaselineCount} empty-baseline ledger row(s) to NULL.");
        }
    }

    private function stampEntityType(string $entityType): void
    {
        $rows = $this->fetchLedgerRows($entityType);

        $label = self::ENTITY_LABELS[$entityType];
        $this->info(sprintf('Stamping %d %s', count($rows), $label));

        foreach ($rows as $row) {
            $entityId = (int) $row->entity_id;
            try {
                $this->dispatchStampingJob($entityType, $entityId);
            } catch (\Throwable $e) {
                fwrite(STDERR, "  [{$entityType}#{$entityId}] FAILED: " . $e->getMessage() . "\n");
                if ($this->verbose) {
                    fwrite(STDERR, $e->getTraceAsString() . "\n");
                }
                continue;
            }

            if ($this->verbose) {
                $key = "{$entityType}:{$entityId}";
                $outcome = StampSyncArticleJob::$outcomes[$key] ?? null;
                if ($outcome) {
                    fwrite(
                        STDERR,
                        "  [{$outcome->entityLabel}] {$outcome->action} — {$outcome->describe}\n"
                    );
                }
            }
        }
    }

    /**
     * @return array<int, \stdClass>
     */
    private function fetchLedgerRows(string $entityType): array
    {
        $query = \Illuminate\Support\Facades\DB::table(SyncStateRepository::TABLE)
            ->where('entity_type', $entityType);
        if ($this->idFilter !== null) {
            $query = $query->where('entity_id', $this->idFilter);
        }
        return $query->orderBy('entity_id')->get()->all();
    }

    private function dispatchStampingJob(string $entityType, int $entityId): void
    {
        $job = match ($entityType) {
            SyncStateRepository::ENTITY_USER =>
                new StampSyncPersonJob($this->context->getId(), $entityId),
            SyncStateRepository::ENTITY_SUBMISSION =>
                new StampSyncArticleJob($this->context->getId(), $entityId),
            SyncStateRepository::ENTITY_REVIEW_ASSIGNMENT =>
                new StampSyncReviewJob($this->context->getId(), $entityId),
        };
        $job->handle();
    }

    private function printSummary(): void
    {
        $counts = [];
        $overwrites = 0;
        $failures = 0;
        foreach (self::allOutcomes() as $outcome) {
            $counts[$outcome->action] = ($counts[$outcome->action] ?? 0) + 1;
            $overwrites += $outcome->overwriteCount;
            if ($outcome->action === 'FAILED') {
                $failures++;
            }
        }

        fwrite(STDERR, "\n===== Summary =====\n");
        foreach ($counts as $action => $count) {
            fwrite(STDERR, sprintf("  %-16s %d\n", $action, $count));
        }
        fwrite(STDERR, sprintf("  overwrites       %d\n", $overwrites));
        fwrite(STDERR, sprintf("  failures         %d\n", $failures));

        if ($overwrites > 0) {
            fwrite(STDERR, "\nOverwrites are properties Notion held a value for that OJS didn't produce.\n");
            fwrite(STDERR, "During baseline stamping they mean either round-trip loss (mapper -> OJS -> mapper)\n");
            fwrite(STDERR, "or an editor edit landing on Notion between populate and stamp. Re-run --verbose\n");
            fwrite(STDERR, "to see which entities + properties are affected.\n");
        }
    }

    /** @return iterable<StampOutcome> */
    private static function allOutcomes(): iterable
    {
        yield from StampSyncPersonJob::$outcomes;
        yield from StampSyncArticleJob::$outcomes;
        yield from StampSyncReviewJob::$outcomes;
    }

    private function resolveContext()
    {
        $journalDao = DAORegistry::getDAO('JournalDAO');
        if ($this->journalPath) {
            $ctx = $journalDao->getByPath($this->journalPath);
            if (!$ctx) {
                $this->die("No journal with path '{$this->journalPath}'.");
            }
            return $ctx;
        }
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

    private function info(string $message): void
    {
        fwrite(STDERR, $message . "\n");
    }

    private function die(string $message): void
    {
        fwrite(STDERR, "ERROR: {$message}\n");
        exit(1);
    }
}

$tool = new StampBaselinesTool($argv ?? []);
$tool->execute();
