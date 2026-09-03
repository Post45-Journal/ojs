<?php

/**
 * @file tools/dev/migrateReadersReportsDueDate.php
 *
 * One-shot Notion schema migration: convert the Reader's Reports database's
 * `Due Date` column from a Notion FORMULA (`Date Requested + 2 months`) into
 * a real editable DATE property, preserving every page's currently-shown
 * value so no reviewer sees their deadline change.
 *
 * Why: the formula is a floor, not a policy. Editors legitimately grant
 * extensions and set case-specific deadlines that the formula can't express.
 * Once `Due Date` is a real date, OJS becomes the seed (via ReviewSyncData's
 * new `dueDate` field, mapped as OJS_FILL_ONLY) and the team owns any
 * subsequent extension.
 *
 * ## The four-step dance
 *
 * Notion's API cannot change a property's type in place. So:
 *
 *   1. PATCH database schema: add a new date property `Review Due`.
 *   2. For each page: read the current `Due Date` formula result, PATCH
 *      the page with `Review Due = <that value>`.
 *   3. PATCH database schema: delete the formula `Due Date` (set the
 *      property to null in the update payload).
 *   4. PATCH database schema: rename `Review Due` -> `Due Date`.
 *
 * Between step 3 and step 4 the database has NO `Due Date` property for
 * a few seconds. Sync doesn't write it during this migration window (the
 * plugin-side OJS_FILL_ONLY wiring lands AFTER this script runs — see the
 * paired brief), so no writer collides.
 *
 * ## Recoverability
 *
 * The script logs the current step to stdout before every API call and
 * before every page carry-forward, so a mid-run crash leaves a readable
 * trail. Restart behaviour by step:
 *
 *   - Crash before step 1 completes: rerun; step 1 is idempotent (aborts
 *     safely if `Review Due` already exists).
 *   - Crash during step 2: rerun. The script skips pages whose `Review Due`
 *     already holds the same start value as `Due Date` — a resumed run
 *     picks up where it left off without redoing work.
 *   - Crash between step 2 and step 3: rerun; carry-forward is a no-op
 *     for already-populated pages, and step 3/4 continue.
 *   - Crash after step 3 (formula deleted) but before step 4 (rename):
 *     the DB temporarily has both `Review Due` (date) and no `Due Date`.
 *     Rerun with `--resume-rename` to only do step 4.
 *
 * ## Idempotency
 *
 * If the migration has already completed (`Due Date` is a date property
 * and no `Review Due` exists), the script aborts with a clear message
 * rather than doing anything.
 *
 * Usage:
 *   php tools/dev/migrateReadersReportsDueDate.php [--dry-run] [--execute]
 *       [--resume-rename] [--verbose]
 *
 * Defaults:
 *   --dry-run       ON (safe default; logs every intended write, does nothing)
 *   --execute       flip off dry-run and actually PATCH Notion
 *   --resume-rename skip steps 1-3, only perform step 4 (post-crash recovery)
 */

use APP\plugins\generic\post45NotionSync\classes\notion\NotionApiException;
use APP\plugins\generic\post45NotionSync\classes\notion\NotionClient;
use PKP\cliTool\CommandLineTool;
use PKP\plugins\PluginRegistry;

require(dirname(__FILE__) . '/../bootstrap.php');

class MigrateReadersReportsDueDateTool extends CommandLineTool
{
    private const CONTEXT_ID = 1;

    // The property names involved in the dance.
    private const FORMULA_NAME = 'Due Date';
    private const TEMP_DATE_NAME = 'Review Due';

    private NotionClient $notion;
    private string $databaseId;

    private bool $dryRun = true;
    private bool $resumeRename = false;
    private bool $verbose = false;

    /** Stats surfaced in the final summary. */
    private array $summary = [
        'pages_seen' => 0,
        'pages_carried_forward' => 0,
        'pages_skipped_empty_formula' => 0,
        'pages_skipped_already_populated' => 0,
        'pages_failed' => 0,
    ];

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            if ($arg === '--dry-run') {
                $this->dryRun = true;
            } elseif ($arg === '--execute') {
                $this->dryRun = false;
            } elseif ($arg === '--resume-rename') {
                $this->resumeRename = true;
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $this->verbose = true;
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
Convert the Notion Reader's Reports `Due Date` from a formula into a real
editable date property, carrying every page's current value forward.

Usage: {$this->scriptName} [--dry-run] [--execute] [--resume-rename] [--verbose]

Options:
  --dry-run        Log every intended write, do nothing. (Default.)
  --execute        Actually PATCH Notion. Overrides --dry-run.
  --resume-rename  Skip steps 1-3; only perform the final rename
                   (Review Due -> Due Date). Use after a mid-migration
                   crash left the DB with a `Review Due` date column and
                   no `Due Date` column.
  --verbose | -v   Per-page log detail.

Safety:
  * Aborts if the migration has already completed (`Due Date` is a date
    column and no `Review Due` column exists).
  * On every write, logs the step name + affected page id first so a
    crashed run leaves a readable trail.

TXT;
    }

    public function execute(): void
    {
        $this->bootstrap();

        if ($this->dryRun) {
            $this->info('=== DRY RUN — no writes will happen. Use --execute to migrate. ===');
        }

        $schema = $this->fetchSchema();
        $state = $this->classifySchemaState($schema);
        $this->info("Schema state: {$state}");

        if ($this->resumeRename) {
            if ($state !== 'temp_present_formula_gone') {
                $this->die(
                    "--resume-rename expects the DB in state 'temp_present_formula_gone' "
                    . "(step 3 done, step 4 pending), but current state is '{$state}'. Refusing to run."
                );
            }
            $this->stepRenameTempToFinal();
            $this->info('Done. Migration complete.');
            return;
        }

        if ($state === 'already_migrated') {
            $this->die('Nothing to do: `Due Date` is already a date property and no `Review Due` column exists.');
        }

        // Full four-step migration.
        if ($state === 'pristine') {
            $this->stepAddTempDate();
        } elseif ($state === 'temp_present_formula_present') {
            $this->info('Step 1 already done in a prior run — `Review Due` exists. Skipping add.');
        } else {
            $this->die("Unexpected schema state '{$state}'. Aborting for safety.");
        }

        $this->stepCarryForward();
        $this->stepDeleteFormula();
        $this->stepRenameTempToFinal();

        $this->printSummary();
        $this->info('Done. Migration complete.');
    }

    // ---------------------------------------------------------------------
    // bootstrap
    // ---------------------------------------------------------------------

    private function bootstrap(): void
    {
        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if (!$sync) {
            $this->die('post45NotionSync plugin not registered. Enable it before running this migration.');
        }

        $token = (string) $sync->getSetting(self::CONTEXT_ID, 'integrationToken');
        $this->databaseId = trim((string) $sync->getSetting(self::CONTEXT_ID, 'readersReportsDatabaseId'));

        foreach ([
            'integrationToken' => $token,
            'readersReportsDatabaseId' => $this->databaseId,
        ] as $name => $value) {
            if ($value === '') {
                $this->die("post45NotionSync setting '{$name}' is empty. Configure the plugin first.");
            }
        }

        $this->notion = new NotionClient($token);
    }

    // ---------------------------------------------------------------------
    // schema inspection
    // ---------------------------------------------------------------------

    private function fetchSchema(): array
    {
        return $this->notion->retrieveDatabase($this->databaseId);
    }

    /**
     * Classify what state the DB is in so we know which steps to run.
     *
     *   - pristine:                       `Due Date` is a formula, no `Review Due`.
     *   - temp_present_formula_present:   `Due Date` is a formula, `Review Due` exists (mid step 2).
     *   - temp_present_formula_gone:      no `Due Date`, `Review Due` exists (mid step 4).
     *   - already_migrated:               `Due Date` is a date property, no `Review Due`.
     *   - unknown:                        anything else — bail.
     */
    private function classifySchemaState(array $schema): string
    {
        $properties = $schema['properties'] ?? [];
        $formula = $properties[self::FORMULA_NAME] ?? null;
        $temp = $properties[self::TEMP_DATE_NAME] ?? null;

        $formulaKind = $formula['type'] ?? null;
        $tempKind = $temp['type'] ?? null;

        if ($formulaKind === 'formula' && $tempKind === null) {
            return 'pristine';
        }
        if ($formulaKind === 'formula' && $tempKind === 'date') {
            return 'temp_present_formula_present';
        }
        if ($formulaKind === null && $tempKind === 'date') {
            return 'temp_present_formula_gone';
        }
        if ($formulaKind === 'date' && $tempKind === null) {
            return 'already_migrated';
        }

        return sprintf('unknown (Due Date=%s, Review Due=%s)', $formulaKind ?? 'absent', $tempKind ?? 'absent');
    }

    // ---------------------------------------------------------------------
    // Step 1 — add `Review Due` date property
    // ---------------------------------------------------------------------

    private function stepAddTempDate(): void
    {
        $this->info('STEP 1: PATCH schema — add date property `' . self::TEMP_DATE_NAME . '`');
        if ($this->dryRun) {
            return;
        }
        $this->notion->updateDatabase($this->databaseId, [
            'properties' => [
                self::TEMP_DATE_NAME => ['date' => new \stdClass()],
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // Step 2 — carry the formula value forward onto `Review Due`
    // ---------------------------------------------------------------------

    private function stepCarryForward(): void
    {
        $this->info('STEP 2: carry `' . self::FORMULA_NAME . '` (formula) -> `' . self::TEMP_DATE_NAME . '` (date) on every page');

        $pages = $this->fetchAllPages();
        $this->info('  walking ' . count($pages) . ' page(s) ...');

        foreach ($pages as $page) {
            $this->summary['pages_seen']++;
            $pageId = $page['id'];
            $shortId = substr($pageId, 0, 8);

            $formulaValue = $this->readFormulaDate($page);
            $existingTemp = $this->readDateStart($page, self::TEMP_DATE_NAME);

            if ($formulaValue === null) {
                if ($this->verbose) {
                    $this->info("  [{$shortId}] formula empty; nothing to carry");
                }
                $this->summary['pages_skipped_empty_formula']++;
                continue;
            }

            if ($existingTemp === $formulaValue) {
                if ($this->verbose) {
                    $this->info("  [{$shortId}] `Review Due` already = {$formulaValue}; skipping (resume-safe)");
                }
                $this->summary['pages_skipped_already_populated']++;
                continue;
            }

            $this->info("  [{$shortId}] set `" . self::TEMP_DATE_NAME . "` = {$formulaValue}");
            if ($this->dryRun) {
                $this->summary['pages_carried_forward']++;
                continue;
            }

            try {
                $this->notion->updatePage($pageId, [
                    self::TEMP_DATE_NAME => ['date' => ['start' => $formulaValue]],
                ]);
                $this->summary['pages_carried_forward']++;
            } catch (NotionApiException $e) {
                fwrite(STDERR, "  [{$shortId}] UPDATE FAILED: {$e->getMessage()}\n");
                $this->summary['pages_failed']++;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Step 3 — delete the formula `Due Date`
    // ---------------------------------------------------------------------

    private function stepDeleteFormula(): void
    {
        $this->info('STEP 3: PATCH schema — delete formula `' . self::FORMULA_NAME . '`');
        if ($this->dryRun) {
            return;
        }
        // Notion deletes a database property by setting it to null in the
        // update payload.
        $this->notion->updateDatabase($this->databaseId, [
            'properties' => [
                self::FORMULA_NAME => null,
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // Step 4 — rename `Review Due` -> `Due Date`
    // ---------------------------------------------------------------------

    private function stepRenameTempToFinal(): void
    {
        $this->info('STEP 4: PATCH schema — rename `' . self::TEMP_DATE_NAME . '` -> `' . self::FORMULA_NAME . '`');
        if ($this->dryRun) {
            return;
        }
        $this->notion->updateDatabase($this->databaseId, [
            'properties' => [
                self::TEMP_DATE_NAME => ['name' => self::FORMULA_NAME],
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // page fetch + property reads
    // ---------------------------------------------------------------------

    /**
     * Fetch every page in the Reader's Reports database. Notion pages 100 at
     * a time; follow the cursor to exhaustion — same pattern as populate's
     * fetchArticles() and wireTestDbRelations' fetchAllPages().
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllPages(): array
    {
        $all = [];
        $cursor = null;
        do {
            $body = ['page_size' => 100];
            if ($cursor !== null) {
                $body['start_cursor'] = $cursor;
            }
            $response = $this->notion->queryDatabase($this->databaseId, $body);
            foreach ($response['results'] ?? [] as $page) {
                $all[] = $page;
            }
            $cursor = ($response['has_more'] ?? false) ? ($response['next_cursor'] ?? null) : null;
        } while ($cursor !== null);
        return $all;
    }

    /**
     * The formula on `Due Date` resolves to a Notion `date` result. Read
     * shape: properties.<name>.formula.type == 'date', formula.date.start
     * is the ISO string (may be null if inputs to the formula are null).
     */
    private function readFormulaDate(array $page): ?string
    {
        $p = $page['properties'][self::FORMULA_NAME] ?? null;
        if (!is_array($p) || ($p['type'] ?? null) !== 'formula') {
            return null;
        }
        $formula = $p['formula'] ?? null;
        if (!is_array($formula) || ($formula['type'] ?? null) !== 'date') {
            return null;
        }
        $date = $formula['date'] ?? null;
        if (!is_array($date)) {
            return null;
        }
        $start = $date['start'] ?? null;
        return is_string($start) && $start !== '' ? $start : null;
    }

    /**
     * Read a plain-date property's `start` value (used to check whether a
     * page already carries a value in `Review Due` — makes the carry-forward
     * step resume-safe).
     */
    private function readDateStart(array $page, string $prop): ?string
    {
        $p = $page['properties'][$prop] ?? null;
        if (!is_array($p) || ($p['type'] ?? null) !== 'date') {
            return null;
        }
        $date = $p['date'] ?? null;
        if (!is_array($date)) {
            return null;
        }
        $start = $date['start'] ?? null;
        return is_string($start) && $start !== '' ? $start : null;
    }

    // ---------------------------------------------------------------------
    // reporting
    // ---------------------------------------------------------------------

    private function printSummary(): void
    {
        echo "\n=== Summary ===\n";
        if ($this->dryRun) {
            echo "(--dry-run: no writes performed)\n";
        }
        foreach ($this->summary as $key => $value) {
            printf("  %-32s %d\n", $key, $value);
        }
        echo "\n";
    }

    private function info(string $line): void
    {
        echo $line . "\n";
    }

    private function die(string $msg): void
    {
        fwrite(STDERR, "FATAL: {$msg}\n");
        exit(1);
    }
}

(new MigrateReadersReportsDueDateTool($argv ?? []))->execute();
