<?php

/**
 * @file tools/dev/reconcileReviewsFromNotion.php
 *
 * Post45 — batch-automates the per-article "reconcile OJS to reflect a Notion
 * Reader's Report" workflow the JM has been doing by hand: create the reviewer
 * user, add them to the submission, backdate every review-assignment date to
 * the Notion values, close the assignment out with a completion date +
 * recommendation, attach the report file, then stamp `OJS ID` on the Notion
 * page + seed the sync ledger so the queued SyncReviewJob writes back to the
 * canonical page instead of creating a duplicate.
 *
 * ## Why the strict ordering matters
 *
 * Sync's ReviewAssignment::add hook queues a SyncReviewJob the instant
 * EditorAction::addReviewer returns. When that job runs, it queries Notion for
 * an existing R.R. page for this OJS review — first via the ledger, then via
 * `OJS ID` — and creates a new page if neither hits. So this tool has to:
 *
 *   1. addReviewer → OJS row exists, sync job queued (but not yet running).
 *   2. edit(dates/recommendation) → hook fires again, ANOTHER sync job queued.
 *   3. Stamp `OJS ID` on the canonical Notion R.R. page.
 *   4. recordPageId() to seed the ledger with the canonical Notion page id.
 *
 * If step 4 (or 3) doesn't happen BEFORE `jobs.php run` drains the queue, the
 * sync job creates a fresh R.R. page in Notion — the exact duplication the JM
 * has been cleaning up by hand. So the tool completes ALL four steps per row
 * before moving on, and it prints the "now run jobs.php" reminder only at the
 * very end.
 *
 * ## Idempotency
 *
 * On rerun, a row whose Notion `OJS ID` already resolves to a real OJS review
 * assignment is skipped. That handles both partial-batch reruns and the case
 * where someone hand-reconciled a submission via the OJS UI in between.
 *
 * ## Silencing
 *
 * addReviewer normally fires an invitation email. This is a HISTORICAL
 * reconstruction of a review that already happened weeks/months ago in
 * Notion; the reviewer already sent the report. Emailing them a fresh "you've
 * been invited" would be surreal. Same silencing dance populate uses:
 * `skipEmail=1` on the request + array-transport swap for the downstream mail
 * cascade.
 *
 * Usage:
 *   php tools/dev/reconcileReviewsFromNotion.php --csv=<path> [--execute] [--verbose]
 *
 * Defaults:
 *   dry-run (opposite of adjustReviewDates.php — matches
 *   migrateReadersReportsDueDate.php's discipline: nothing happens unless
 *   --execute is passed explicitly)
 *
 * CSV shape:
 *   submission_id,notion_rr_page_id
 *   473,3c113c7d-...
 *
 * The reviewer's report Drive URL is NOT on the CSV row — it's read from the
 * Notion R.R. page's `Report` property (Notion `files` column carrying an
 * external Google Drive URL per the project rule "Notion file columns link
 * to Google Drive"). If the property is empty the row still reconciles;
 * only the file attachment is skipped.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\post45NotionSync\classes\mapping\NotionProperty;
use APP\plugins\generic\post45NotionSync\classes\mapping\ReadersReportsSchema;
use APP\plugins\generic\post45NotionSync\classes\mapping\ReviewReportStatus;
use APP\plugins\generic\post45NotionSync\classes\notion\NotionApiException;
use APP\plugins\generic\post45NotionSync\classes\notion\NotionClient;
use APP\plugins\generic\post45NotionSync\classes\repository\SyncStateRepository;
use PKP\cliTool\CommandLineTool;
use PKP\controllers\grid\users\reviewer\form\traits\HasReviewDueDate;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\submission\action\EditorAction;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

require(dirname(__FILE__) . '/../bootstrap.php');

class ReconcileReviewsFromNotionTool extends CommandLineTool
{
    use HasReviewDueDate;

    private const CONTEXT_ID = 1;

    /**
     * Notion Recommendation vocabulary -> OJS reviewer-recommendation constant.
     * Inverse of ReviewRecommendation::fromOjs(). Kept inline rather than
     * added to the plugin because this is the only caller — the plugin's
     * live mappers only need OJS -> Notion. See ReviewRecommendation.php's
     * docblock for the "Accept-with-…" variants only reachable via
     * pre-cutover Notion data.
     */
    private const NOTION_RECOMMENDATION_TO_OJS = [
        'Accept' => ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_ACCEPT,
        'Accept with Minor Revisions' => ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_PENDING_REVISIONS,
        'Accept with Major Revisions' => ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_PENDING_REVISIONS,
        'R&R' => ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_PENDING_REVISIONS,
        'Reject' => ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_DECLINE,
    ];

    private ?string $csvPath = null;
    private bool $execute = false;
    private bool $verbose = false;

    private $context;
    private $editor;
    private NotionClient $notion;
    private SyncStateRepository $peopleLedger;
    private SyncStateRepository $rrLedger;
    private string $readersReportsDatabaseId;
    private ?string $googleAccessToken = null;
    private ?string $googleTokenPath = null;

    /** Cached Google Drive Bearer token for the current run. */

    private array $summary = [
        'rows_seen' => 0,
        'reconciled' => 0,
        'skipped_already' => 0,
        'skipped_error' => 0,
        'files_uploaded' => 0,
        'files_failed' => 0,
        'users_created' => 0,
        'users_reused' => 0,
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
        echo <<<TXT
Batch-reconcile OJS review assignments from Notion Reader's Reports.

Usage: {$this->scriptName} --csv=<path> [--execute] [--verbose]

CSV columns (header row required, exactly these two):
  submission_id       — OJS submission this R.R. belongs to
  notion_rr_page_id   — the canonical Notion Reader's Report page

The reviewer's report file URL is read from the R.R. page's `Report`
property (Notion files column with a Google Drive external URL). Empty
Report property = row still reconciles, file attachment skipped.

Defaults:
  dry-run (nothing is written; no Notion writes; no OJS writes)
  --execute      opt in to real writes
  --verbose      per-row detail

After execute: run `php lib/pkp/tools/jobs.php run` to drain the queued
SyncReviewJob calls — that's what actually pushes the changes back to
Notion (populates baselines etc.). The `OJS ID` + ledger stamp done by
this tool ensures those jobs find the canonical page instead of
creating duplicates.

TXT;
    }

    public function execute(): void
    {
        $this->bootstrap();

        if (!$this->execute) {
            fwrite(STDERR, "\n=== DRY RUN — no writes will happen ===\n\n");
        }

        $rows = $this->loadCsv($this->csvPath);
        $rowNum = 1; // header row is 1
        foreach ($rows as $row) {
            $rowNum++;
            $this->summary['rows_seen']++;
            $this->processRow($rowNum, $row);
        }

        $this->printSummary();
    }

    // ---------------------------------------------------------------------
    // bootstrap
    // ---------------------------------------------------------------------

    private function bootstrap(): void
    {
        $this->context = $this->resolveContext();
        $this->editor = $this->resolveEditor();

        Registry::set('user', $this->editor);
        Application::get()->getRequest()->getRouter()->_context = $this->context;

        // Suppress reviewer-invite emails: EditorAction::addReviewer reads
        // skipEmail off the request. Same trick populate + createTestSubmissions
        // use; $_requestVars must be cleared so the merged bag re-reads $_GET.
        $_GET['skipEmail'] = 1;
        Application::get()->getRequest()->_requestVars = null;

        $this->silenceMailers();

        // Generic plugins register per-context; CLI tools have no request
        // context, so post45NotionSync would otherwise skip its register()
        // entirely and the ReviewAssignment::add / ::edit hooks that queue
        // SyncReviewJob never bind. See OJS-DEV-NOTES worker-context gotcha.
        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if (!$sync) {
            $this->die('post45NotionSync plugin not registered. Enable it before running this tool.');
        }
        $sync->register('generic', $sync->getPluginPath(), $this->context->getId());

        $token = (string) $sync->getSetting(self::CONTEXT_ID, 'integrationToken');
        $peopleDatabaseId = trim((string) $sync->getSetting(self::CONTEXT_ID, 'peopleDatabaseId'));
        $readersReportsDatabaseId = trim((string) $sync->getSetting(self::CONTEXT_ID, 'readersReportsDatabaseId'));

        foreach ([
            'integrationToken' => $token,
            'peopleDatabaseId' => $peopleDatabaseId,
            'readersReportsDatabaseId' => $readersReportsDatabaseId,
        ] as $name => $value) {
            if ($value === '') {
                $this->die("post45NotionSync setting '{$name}' is empty. Configure the plugin first.");
            }
        }

        $this->notion = new NotionClient($token);
        $this->peopleLedger = new SyncStateRepository($peopleDatabaseId);
        $this->rrLedger = new SyncStateRepository($readersReportsDatabaseId);
        $this->readersReportsDatabaseId = $readersReportsDatabaseId;

        // Default Google Drive OAuth token — mirrors populate's default so
        // Drive downloads Just Work when the notion_automations sibling is
        // present. Unauthenticated fallback covers publicly-shared files.
        $default = ($_SERVER['HOME'] ?? '') . '/dev/notion_automations/token.json';
        if (is_file($default)) {
            $this->googleTokenPath = $default;
        }
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

    /**
     * Swap the default mailer to an in-memory sink. addReviewer + downstream
     * notifications would otherwise flood a dev log with fake "you've been
     * invited" mails for reviewers who already delivered their report
     * pre-cutover. See populate::silenceMailers for the same pattern.
     */
    private function silenceMailers(): void
    {
        config([
            'mail.mailers.silent' => ['transport' => 'array'],
            'mail.default' => 'silent',
        ]);
        app('mail.manager')->forgetMailers();
    }

    // ---------------------------------------------------------------------
    // CSV
    // ---------------------------------------------------------------------

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
        $required = ['submission_id', 'notion_rr_page_id'];
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
                . ". (The Drive URL is read from the Notion R.R. page's `Report` property, not the CSV.)"
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

    // ---------------------------------------------------------------------
    // per-row pipeline
    // ---------------------------------------------------------------------

    private function processRow(int $rowNum, array $row): void
    {
        $submissionId = (int) ($row['submission_id'] ?? 0);
        $rrPageId = $row['notion_rr_page_id'] ?? '';

        if ($submissionId <= 0 || $rrPageId === '') {
            $this->rowLog($rowNum, $submissionId, '-', 'SKIP: empty submission_id or notion_rr_page_id');
            $this->summary['skipped_error']++;
            return;
        }

        try {
            $rr = $this->notion->retrievePage($rrPageId);
        } catch (NotionApiException $e) {
            $this->rowLog($rowNum, $submissionId, '-', "ERROR: Notion fetch failed: {$e->getMessage()}");
            $this->summary['skipped_error']++;
            return;
        }

        // Wrong-workspace guardrail. A Notion integration granted access to
        // both prod and test workspaces (or a CSV built for prod but run
        // against dev — the 2026-09-03 misfire) will happily retrieve+update
        // pages in a database this run isn't configured for. Assert the
        // page's parent DB matches the plugin's readersReportsDatabaseId
        // before any downstream write can land on the wrong workspace.
        $parentDbId = $rr['parent']['database_id'] ?? '';
        if (self::normaliseNotionId($parentDbId) !== self::normaliseNotionId($this->readersReportsDatabaseId)) {
            $this->die(
                "Row {$rowNum} notion_rr_page_id {$rrPageId} lives in Notion DB {$parentDbId}, "
                . "but this run is configured for {$this->readersReportsDatabaseId}. "
                . 'Aborting the entire run — the CSV is almost certainly pointed at the wrong workspace.'
            );
        }

        // Drive URL for the reviewer's report: read from the R.R.'s Report
        // property (Notion files column with a Google Drive external URL).
        // Empty is fine — row still reconciles, just no file attached.
        $driveUrl = $this->readFirstFileUrl($rr, ReadersReportsSchema::REPORT) ?? '';

        // Idempotency: if the R.R. already carries an OJS ID pointing at a
        // live review assignment, this row's already been reconciled.
        $existingOjsId = $this->readOjsIdRichText($rr);
        if ($existingOjsId !== null && $existingOjsId > 0) {
            $existingAssignment = Repo::reviewAssignment()->get($existingOjsId);
            if ($existingAssignment) {
                $this->rowLog($rowNum, $submissionId, '-', "SKIP: R.R. already carries OJS ID={$existingOjsId} (assignment exists)");
                $this->summary['skipped_already']++;
                return;
            }
        }

        // Reader relation → People page id.
        $readerIds = $this->readRelation($rr, ReadersReportsSchema::READER);
        if (empty($readerIds)) {
            $this->rowLog($rowNum, $submissionId, '-', 'ERROR: R.R. has no Reader relation');
            $this->summary['skipped_error']++;
            return;
        }
        $peoplePageId = $readerIds[0];

        // Verify submission exists AND is at stage 3.
        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            $this->rowLog($rowNum, $submissionId, '-', "ERROR: no submission with id={$submissionId}");
            $this->summary['skipped_error']++;
            return;
        }
        $stageId = (int) $submission->getData('stageId');
        if ($stageId !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
            $this->rowLog($rowNum, $submissionId, '-', "ERROR: submission at stage {$stageId}, not external review (3)");
            $this->summary['skipped_error']++;
            return;
        }

        $reviewRound = DAORegistry::getDAO('ReviewRoundDAO')
            ->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
        if (!$reviewRound) {
            $this->rowLog($rowNum, $submissionId, '-', 'ERROR: no review round on this submission (Send for External Review was never recorded)');
            $this->summary['skipped_error']++;
            return;
        }
        $reviewRoundId = $reviewRound->getId();

        // Read Notion state we need for the OJS write.
        $status = $this->readSelect($rr, ReadersReportsSchema::STATUS);
        $recommendationLabel = $this->readSelect($rr, ReadersReportsSchema::RECOMMENDATION);
        $requestedDate = $this->readDate($rr, ReadersReportsSchema::DATE_REQUESTED);
        $acceptedDate = $this->readDate($rr, ReadersReportsSchema::DATE_ACCEPTED)
                     ?? $requestedDate; // pre-2026-08-19 R.R.s: Date Requested IS accepted
        $receivedDate = $this->readDate($rr, ReadersReportsSchema::DATE_RECEIVED);
        $dueDate = $this->readDate($rr, ReadersReportsSchema::DUE_DATE);

        // Upsert the OJS user for this reviewer.
        $reviewerUser = $this->upsertUserFromPeoplePage($peoplePageId, [Role::ROLE_ID_REVIEWER]);
        if (!$reviewerUser) {
            $this->rowLog($rowNum, $submissionId, '-', "ERROR: could not resolve reviewer user for People page {$peoplePageId}");
            $this->summary['skipped_error']++;
            return;
        }
        $reviewerLabel = $reviewerUser->getFullName();

        if (!$this->execute) {
            $this->rowLog(
                $rowNum,
                $submissionId,
                $reviewerLabel,
                "DRY: would addReviewer + edit(status={$status}, requested={$requestedDate}, accepted={$acceptedDate}, received={$receivedDate}, due={$dueDate}, rec={$recommendationLabel})"
                . ($driveUrl !== '' ? ' + attach report' : '')
            );
            return;
        }

        // --- WRITE PATH -------------------------------------------------
        try {
            $assignment = $this->addReviewerAndBackdate(
                $submission,
                $reviewRound,
                $reviewerUser,
                $requestedDate,
                $acceptedDate,
                $receivedDate,
                $dueDate,
                $status,
                $recommendationLabel
            );
        } catch (\Throwable $e) {
            $this->rowLog($rowNum, $submissionId, $reviewerLabel, "ERROR: addReviewer/edit failed: {$e->getMessage()}");
            $this->summary['skipped_error']++;
            return;
        }

        if (!$assignment) {
            $this->rowLog($rowNum, $submissionId, $reviewerLabel, 'ERROR: assignment not found post-invite');
            $this->summary['skipped_error']++;
            return;
        }
        $reviewAssignmentId = (int) $assignment->getId();

        // Attach the report file (best-effort; failure is non-fatal).
        if ($driveUrl !== '') {
            try {
                $this->attachReportFile($submission, $reviewRoundId, $reviewAssignmentId, $reviewerUser, $driveUrl);
            } catch (\Throwable $e) {
                $this->summary['files_failed']++;
                fwrite(STDERR, "         REPORT FILE FAILED ({$driveUrl}): {$e->getMessage()}\n");
            }
        }

        // Stamp OJS ID on the canonical Notion page (rich_text). Precedes
        // ledger record: even if the ledger write fails, the OJS ID column
        // is the sync's fallback identity lookup, so the queued job still
        // finds this page instead of duplicating.
        try {
            $this->notion->updatePage($rrPageId, [
                ReadersReportsSchema::OJS_ID => NotionProperty::richText((string) $reviewAssignmentId),
            ]);
        } catch (NotionApiException $e) {
            fwrite(STDERR, "         WARN: could not stamp OJS ID on {$rrPageId}: {$e->getMessage()}\n");
        }

        // Seed the ledger so the queued SyncReviewJob writes to the canonical
        // page. recordPageId (NOT recordSync(id, page, [])) — see the empty-
        // baseline gotcha in the memory bank.
        $this->rrLedger->recordPageId(
            SyncStateRepository::ENTITY_REVIEW_ASSIGNMENT,
            $reviewAssignmentId,
            $rrPageId
        );

        $this->summary['reconciled']++;
        $this->rowLog($rowNum, $submissionId, $reviewerLabel, "OK: review_id={$reviewAssignmentId}");
    }

    /**
     * Steps 1 (addReviewer) + 2 (edit backdated dates + status + recommendation).
     */
    private function addReviewerAndBackdate(
        \APP\submission\Submission $submission,
        $reviewRound,
        \PKP\user\User $reviewerUser,
        ?string $requestedDate,
        ?string $acceptedDate,
        ?string $receivedDate,
        ?string $dueDate,
        ?string $status,
        ?string $recommendationLabel
    ): ?ReviewAssignment {
        $request = Application::get()->getRequest();
        $editorAction = new EditorAction();

        [$defaultReviewDueTs, $defaultResponseDueTs] = $this->getDueDates($this->context);
        $defaultReviewDue = Core::getCurrentDate($defaultReviewDueTs);
        $responseDue = Core::getCurrentDate($defaultResponseDueTs);
        $reviewDue = $dueDate ?? $defaultReviewDue;

        $editorAction->addReviewer(
            $request,
            $submission,
            $reviewerUser->getId(),
            $reviewRound,
            $reviewDue,
            $responseDue,
            // Post45 is always double-anonymous — matching populate's rationale.
            ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS
        );

        $assignment = Repo::reviewAssignment()->getCollector()
            ->filterByReviewRoundIds([$reviewRound->getId()])
            ->filterByReviewerIds([$reviewerUser->getId()])
            ->getMany()
            ->first();
        if (!$assignment) {
            return null;
        }

        $edits = [
            'dateAssigned' => $requestedDate ?? Core::getCurrentDate(),
            'dateNotified' => $requestedDate ?? Core::getCurrentDate(),
            'reviewFormId' => null,
            'considered' => ReviewAssignment::REVIEW_ASSIGNMENT_NEW,
        ];
        if ($acceptedDate) {
            $edits['dateConfirmed'] = $acceptedDate;
            $edits['declined'] = 0;
        }
        if ($dueDate) {
            $edits['dateDue'] = $dueDate;
        }

        // Notion Status = Done → mark the review complete on OJS side.
        if ($status === ReviewReportStatus::DONE) {
            if ($receivedDate) {
                $edits['dateCompleted'] = $receivedDate;
            } else {
                $edits['dateCompleted'] = Core::getCurrentDate();
                fwrite(STDERR, "         WARN: Notion Status=Done but Date Received empty; falling back to now() for dateCompleted\n");
            }

            if ($recommendationLabel !== null && $recommendationLabel !== '') {
                $ojsRec = self::NOTION_RECOMMENDATION_TO_OJS[$recommendationLabel] ?? null;
                if ($ojsRec !== null) {
                    $edits['recommendation'] = $ojsRec;
                } else {
                    fwrite(STDERR, "         WARN: unknown Notion recommendation '{$recommendationLabel}'; leaving OJS recommendation unset\n");
                }
            }
        }

        Repo::reviewAssignment()->edit($assignment, $edits);

        return Repo::reviewAssignment()->get($assignment->getId());
    }

    // ---------------------------------------------------------------------
    // User upsert — copied verbatim from populate (with reduced ledger
    // surface — this tool only touches the People ledger for the reviewer).
    // Kept inline rather than extracted per the tool brief: throwaway CLI,
    // single caller, extraction cost > reuse benefit.
    // ---------------------------------------------------------------------

    private function upsertUserFromPeoplePage(string $peoplePageId, array $additionalRoleIds): ?\PKP\user\User
    {
        try {
            $page = $this->notion->retrievePage($peoplePageId);
        } catch (NotionApiException $e) {
            fwrite(STDERR, "         WARN: People page fetch failed ({$peoplePageId}): {$e->getMessage()}\n");
            return null;
        }
        $name = $this->readTitle($page);
        $email = $this->readEmail($page, 'Email');

        if ($email === null || $email === '') {
            $shortId = substr($peoplePageId, 0, 8);
            fwrite(STDERR, "         WARN: People page [{$shortId}] '{$name}' has no email; skipping.\n");
            return null;
        }

        [$given, $family] = $this->splitName($name ?? $email);

        $existing = Repo::user()->getByEmail($email);
        if ($existing) {
            $this->summary['users_reused']++;
            // Sanity check: warn if given/family differ from what Notion has.
            $primaryLocale = $this->context->getPrimaryLocale();
            $existingGiven = trim((string) ($existing->getGivenName($primaryLocale) ?? ''));
            $existingFamily = trim((string) ($existing->getFamilyName($primaryLocale) ?? ''));
            if ($existingGiven !== $given || $existingFamily !== $family) {
                fwrite(STDERR, sprintf(
                    "         WARN: OJS user %s has name '%s %s' but Notion has '%s %s'; keeping OJS values\n",
                    $email,
                    $existingGiven,
                    $existingFamily,
                    $given,
                    $family
                ));
            }
            if ($this->execute) {
                $this->assignRolesIfMissing($existing, $additionalRoleIds);
                $this->peopleLedger->recordPageId(SyncStateRepository::ENTITY_USER, $existing->getId(), $peoplePageId);
            }
            return $existing;
        }

        if (!$this->execute) {
            // In dry-run we can't actually mint a user (no DB write). Return
            // a synthetic user-like object so the caller can proceed with
            // logging. But populate/addReviewer both need a real user id, so
            // dry-run terminates the row before reaching addReviewer anyway
            // — see processRow. Return null here to be safe; caller uses the
            // name/email pulled from the page for its DRY log line if
            // needed.
            $synth = Repo::user()->newDataObject();
            $primaryLocale = $this->context->getPrimaryLocale();
            $synth->setGivenName($given, $primaryLocale);
            $synth->setFamilyName($family, $primaryLocale);
            $synth->setEmail($email);
            return $synth;
        }

        $user = Repo::user()->newDataObject();
        $primaryLocale = $this->context->getPrimaryLocale();
        $user->setUsername($this->uniqueUsername($given, $family, $email));
        $user->setGivenName($given, $primaryLocale);
        $user->setFamilyName($family, $primaryLocale);
        $user->setEmail($email);
        $user->setDateRegistered(Core::getCurrentDate());
        $user->setInlineHelp(1);
        $user->setPassword(Validation::encryptCredentials(
            $user->getUsername(),
            bin2hex(random_bytes(16))
        ));
        $user->setMustChangePassword(true);
        Repo::user()->add($user);

        foreach ($additionalRoleIds as $roleId) {
            $group = Repo::userGroup()->getByRoleIds([$roleId], $this->context->getId())->first();
            if ($group) {
                Repo::userGroup()->assignUserToGroup($user->getId(), $group->id);
            }
        }

        $this->summary['users_created']++;
        $this->peopleLedger->recordPageId(SyncStateRepository::ENTITY_USER, $user->getId(), $peoplePageId);
        return $user;
    }

    private function assignRolesIfMissing(\PKP\user\User $user, array $roleIds): void
    {
        foreach ($roleIds as $roleId) {
            $has = Repo::userGroup()
                ->userUserGroups($user->getId(), $this->context->getId())
                ->first(fn ($g) => (int) $g->roleId === $roleId);
            if ($has) {
                continue;
            }
            $group = Repo::userGroup()->getByRoleIds([$roleId], $this->context->getId())->first();
            if ($group) {
                Repo::userGroup()->assignUserToGroup($user->getId(), $group->id);
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['Unknown', 'Reviewer'];
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $family = array_pop($parts);
        $given = implode(' ', $parts);
        return [$given, $family];
    }

    private function uniqueUsername(string $given, string $family, string $email): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $given . $family));
        if ($base === '') {
            $base = strtolower(explode('@', $email)[0]);
        }
        $base = substr($base, 0, 24);
        $candidate = $base;
        $suffix = 0;
        while (Repo::user()->getByUsername($candidate)) {
            $suffix++;
            $candidate = $base . $suffix;
        }
        return $candidate;
    }

    // ---------------------------------------------------------------------
    // File attach (Drive URL → SUBMISSION_FILE_REVIEW_ATTACHMENT)
    // ---------------------------------------------------------------------

    private function attachReportFile(
        \APP\submission\Submission $submission,
        int $reviewRoundId,
        int $reviewAssignmentId,
        \PKP\user\User $reviewer,
        string $driveUrl
    ): void {
        $source = $this->downloadUrl($driveUrl);
        if ($source === null) {
            $this->summary['files_failed']++;
            return; // downloadUrl logged the specific failure
        }

        $fileManager = new FileManager();
        $originalName = $source['originalName'];
        $extension = $fileManager->parseFileExtension($originalName) ?: 'bin';

        $submissionDir = Repo::submissionFile()->getSubmissionDir(
            $this->context->getId(),
            $submission->getId()
        );

        $fileId = app()->get('file')->add(
            $source['abs'],
            $submissionDir . '/' . uniqid() . '.' . $extension
        );

        if ($source['temp']) {
            @unlink($source['abs']);
        }

        $submissionFile = Repo::submissionFile()->dao->newDataObject();
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT);
        $submissionFile->setData('name', $originalName, $submission->getData('locale'));
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('uploaderUserId', $reviewer->getId());
        $submissionFile->setData('genreId', $this->resolveOtherGenreId());
        $submissionFile->setData('assocType', PKPApplication::ASSOC_TYPE_REVIEW_ASSIGNMENT);
        $submissionFile->setData('assocId', $reviewAssignmentId);

        Repo::submissionFile()->add($submissionFile);
        $this->summary['files_uploaded']++;
        if ($this->verbose) {
            fwrite(STDERR, "         attached report: {$originalName}\n");
        }
    }

    private function resolveOtherGenreId(): int
    {
        /** @var \PKP\submission\GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genres = $genreDao->getByContextId($this->context->getId())->toArray();
        foreach ($genres as $genre) {
            if ($genre->getKey() === 'OTHER') {
                return (int) $genre->getId();
            }
        }
        // Fallback: first genre. Unlikely to fire (OTHER ships in every OJS
        // install), but better than a crash if a journal ripped it out.
        if (!empty($genres)) {
            return (int) reset($genres)->getId();
        }
        throw new \RuntimeException('No genres registered on this journal; cannot attach the report file.');
    }

    // ---------------------------------------------------------------------
    // Google Drive download (adapted from populate::downloadUrl)
    // ---------------------------------------------------------------------

    private function downloadUrl(string $url): ?array
    {
        $id = self::extractGoogleDriveId($url);
        if ($id === null) {
            fwrite(STDERR, "         URL ERROR: cannot extract a Google Drive/Docs file id from '{$url}'\n");
            return null;
        }

        $isDoc = str_contains($url, 'docs.google.com/document/');
        $downloadUrl = $isDoc
            ? "https://docs.google.com/document/d/{$id}/export?format=docx"
            : "https://www.googleapis.com/drive/v3/files/{$id}?alt=media";
        $fallbackExt = $isDoc ? 'docx' : 'bin';

        $tempPath = tempnam(sys_get_temp_dir(), 'reconcile-') . '.' . $fallbackExt;
        $fp = fopen($tempPath, 'wb');
        if (!$fp) {
            fwrite(STDERR, "         DOWNLOAD ERROR: cannot open temp file {$tempPath}\n");
            return null;
        }

        $ch = curl_init($downloadUrl);
        $originalName = null;
        $bearer = $this->getGoogleAccessToken();
        $httpHeaders = $bearer !== null ? ["Authorization: Bearer {$bearer}"] : [];
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'post45-reconcile/1.0',
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$originalName) {
                if (preg_match('/^Content-Disposition:.*filename="?([^";\r\n]+)"?/i', $header, $m)) {
                    $originalName = trim($m[1]);
                }
                return strlen($header);
            },
        ]);
        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $httpCode >= 400) {
            @unlink($tempPath);
            fwrite(STDERR, "         DOWNLOAD ERROR: HTTP {$httpCode} on {$downloadUrl}\n");
            return null;
        }
        $size = filesize($tempPath);
        if ($size === 0 || $size === false) {
            @unlink($tempPath);
            fwrite(STDERR, "         DOWNLOAD ERROR: empty file body from {$downloadUrl}\n");
            return null;
        }
        if (str_contains((string) $contentType, 'text/html')) {
            @unlink($tempPath);
            fwrite(STDERR, "         DOWNLOAD ERROR: got HTML instead of file bytes from {$downloadUrl}\n");
            fwrite(STDERR, "         Likely the file is not shared 'anyone with the link' (or the auth token is wrong).\n");
            return null;
        }

        if ($originalName === null && !$isDoc && $bearer !== null) {
            $originalName = $this->fetchDriveFileName($id, $bearer);
        }
        if ($originalName === null) {
            $originalName = $isDoc ? "google-doc-{$id}.docx" : "google-file-{$id}";
        }

        return ['abs' => $tempPath, 'originalName' => $originalName, 'temp' => true];
    }

    private function fetchDriveFileName(string $fileId, string $bearer): ?string
    {
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=name,mimeType&supportsAllDrives=true";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            return null;
        }
        $meta = json_decode((string) $response, true);
        return $meta['name'] ?? null;
    }

    private function getGoogleAccessToken(): ?string
    {
        if ($this->googleAccessToken !== null) {
            return $this->googleAccessToken;
        }
        if ($this->googleTokenPath === null || !is_file($this->googleTokenPath)) {
            return null;
        }
        $raw = file_get_contents($this->googleTokenPath);
        $token = json_decode($raw, true);
        if (!is_array($token) || !isset($token['refresh_token'], $token['client_id'], $token['client_secret'])) {
            fwrite(STDERR, "         GOOGLE AUTH WARN: token file missing refresh_token/client_id/client_secret; unauthenticated fallback in use.\n");
            return null;
        }
        $tokenUri = $token['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $ch = curl_init($tokenUri);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $token['refresh_token'],
                'client_id' => $token['client_id'],
                'client_secret' => $token['client_secret'],
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            fwrite(STDERR, "         GOOGLE AUTH WARN: refresh failed (HTTP {$httpCode}); unauthenticated fallback.\n");
            return null;
        }
        $decoded = json_decode((string) $response, true);
        if (!isset($decoded['access_token'])) {
            return null;
        }
        return $this->googleAccessToken = $decoded['access_token'];
    }

    private static function normaliseNotionId(string $id): string
    {
        return strtolower(str_replace('-', '', trim($id)));
    }

    private static function extractGoogleDriveId(string $url): ?string
    {
        if (preg_match('#docs\.google\.com/document/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#drive\.google\.com/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Notion property readers (same shape as populate)
    // ---------------------------------------------------------------------

    private function readTitle(array $page): string
    {
        $props = $page['properties'] ?? [];
        foreach ($props as $prop) {
            if (($prop['type'] ?? '') === 'title') {
                return trim(implode('', array_map(
                    fn ($run) => $run['plain_text'] ?? '',
                    $prop['title'] ?? []
                )));
            }
        }
        return '(untitled)';
    }

    private function readSelect(array $page, string $name): ?string
    {
        $prop = $page['properties'][$name] ?? null;
        if (!$prop) {
            return null;
        }
        foreach (['status', 'select'] as $t) {
            if (isset($prop[$t]['name'])) {
                return $prop[$t]['name'];
            }
        }
        return null;
    }

    private function readDate(array $page, string $name): ?string
    {
        $prop = $page['properties'][$name] ?? null;
        $iso = $prop['date']['start']
            ?? $prop['formula']['date']['start']
            ?? null;
        if (!$iso) {
            return null;
        }
        return strlen($iso) === 10 ? "{$iso} 00:00:00" : str_replace('T', ' ', substr($iso, 0, 19));
    }

    private function readEmail(array $page, string $name): ?string
    {
        $prop = $page['properties'][$name] ?? null;
        return $prop['email'] ?? null;
    }

    private function readRelation(array $page, string $name): array
    {
        $prop = $page['properties'][$name] ?? null;
        $items = $prop['relation'] ?? [];
        return array_column($items, 'id');
    }

    /**
     * Read a Notion `files` property's first entry, returning the URL that
     * lives in either the `external` (Drive-hosted link, the Post45 default
     * per project rule) or the `file` (native Notion upload) sub-object.
     * Returns null when the property is absent or empty.
     */
    private function readFirstFileUrl(array $page, string $name): ?string
    {
        $prop = $page['properties'][$name] ?? null;
        if (!$prop) {
            return null;
        }
        $files = $prop['files'] ?? [];
        if (empty($files)) {
            return null;
        }
        $first = $files[0];
        return $first['external']['url']
            ?? $first['file']['url']
            ?? null;
    }

    /**
     * Read the OJS ID rich_text column and coerce to int, or null when the
     * column is empty. Used for the idempotency check at the top of each row.
     */
    private function readOjsIdRichText(array $page): ?int
    {
        $prop = $page['properties'][ReadersReportsSchema::OJS_ID] ?? null;
        if (!$prop) {
            return null;
        }
        $runs = $prop['rich_text'] ?? [];
        $text = trim(implode('', array_map(
            fn ($run) => $run['plain_text'] ?? '',
            $runs
        )));
        if ($text === '' || !ctype_digit($text)) {
            return null;
        }
        return (int) $text;
    }

    // ---------------------------------------------------------------------
    // Output
    // ---------------------------------------------------------------------

    private function rowLog(int $rowNum, int $submissionId, string $reviewer, string $message): void
    {
        fwrite(STDERR, sprintf(
            "  row %2d  sub=%-5d  reviewer=%-32s  %s\n",
            $rowNum,
            $submissionId,
            substr($reviewer, 0, 32),
            $message
        ));
    }

    private function printSummary(): void
    {
        $s = $this->summary;
        $mode = $this->execute ? 'WROTE TO DATABASE' : 'DRY RUN — nothing written';
        fwrite(STDERR, "\n=== reconcile summary ({$mode}) ===\n");
        fwrite(STDERR, sprintf("  rows seen:        %d\n", $s['rows_seen']));
        fwrite(STDERR, sprintf("  reconciled:       %d\n", $s['reconciled']));
        fwrite(STDERR, sprintf("  skipped already:  %d\n", $s['skipped_already']));
        fwrite(STDERR, sprintf("  skipped error:    %d\n", $s['skipped_error']));
        fwrite(STDERR, sprintf("  users created:    %d\n", $s['users_created']));
        fwrite(STDERR, sprintf("  users reused:     %d\n", $s['users_reused']));
        fwrite(STDERR, sprintf("  files uploaded:   %d\n", $s['files_uploaded']));
        fwrite(STDERR, sprintf("  files failed:     %d\n", $s['files_failed']));

        if ($this->execute && $s['reconciled'] > 0) {
            fwrite(STDERR, "\nNext step: drain the queued SyncReviewJob calls so Notion catches up:\n");
            fwrite(STDERR, "  php lib/pkp/tools/jobs.php run\n");
        }
    }

    private function die(string $msg): never
    {
        fwrite(STDERR, "ERROR: {$msg}\n");
        exit(1);
    }
}

(new ReconcileReviewsFromNotionTool($argv ?? []))->execute();
