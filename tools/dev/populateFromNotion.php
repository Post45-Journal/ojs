<?php

/**
 * @file tools/dev/populateFromNotion.php
 *
 * Post45 — one-shot migration tool that populates an empty OJS instance
 * with the in-progress articles currently living in the consolidated
 * Notion Articles database.
 *
 * NOT a sync. This script only runs during the cutover window; once OJS is
 * the source of truth and post45NotionSync is enabled, running this again
 * would either be a no-op (idempotency) or a data-integrity hazard
 * (--reset-and-repopulate wiping post-cutover editorial work). Safety
 * checks below enforce both conditions.
 *
 * See [[project_sequenced_backlog]] G3b for the design context.
 *
 * Usage:
 *   php tools/dev/populateFromNotion.php [--dry-run] [--limit=N]
 *       [--article=<notion-page-id>] [--verbose] [--journal=PATH]
 *
 * Defaults:
 *   --dry-run           off (writes are real)
 *   no --limit          every article passing filters is processed
 *   no --article        every article passing filters (obeys --limit)
 *   --journal=(first)   first enabled journal
 *
 * Reads Notion connection settings (integration token + database IDs) from
 * the post45NotionSync plugin's context-scoped settings, so the target
 * workspace + boards are whatever the plugin is configured for. Point the
 * plugin at a scratch workspace for practice runs.
 *
 * ## What this creates
 *
 * Per Notion article passing the per-article filter:
 *   - One OJS Submission + Publication in the correct section
 *   - Author records + user accounts (never notifying; signup mail suppressed)
 *   - Editorial decisions to drive the submission to its target workflow stage
 *   - Review assignments for each non-Done Reader's Report
 *   - Ledger entries in post45_notion_sync_state pointing OJS -> Notion, so
 *     the first sync post-cutover finds the pages instead of duplicating them
 *
 * ## What this DELIBERATELY does not do (yet — G3b open items)
 *
 * See the TODO(g3b-*) markers throughout for the details.
 *
 *   - Upload manuscript files. User is undecided on file source + Notion/Drive
 *     backup pattern. Every populated submission arrives file-less.
 *   - Create special-issue sections + invite guest editors. Detected but
 *     currently routed to the default section with a warning.
 *   - Full ledger baseline (payload + hash). Only the page id is stamped; the
 *     first sync post-cutover computes the payload, writes it (harmless: OJS
 *     state was derived from the same Notion page), and records the baseline.
 *   - --reset-and-repopulate. Design is safe-refuse-if-sync-was-ever-enabled;
 *     mechanics TODO.
 */

use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\plugins\generic\post45Editorial\classes\settings\Post45SubmissionSettingsRepository;
use APP\plugins\generic\post45NotionSync\classes\mapping\ArticleSchema;
use APP\plugins\generic\post45NotionSync\classes\mapping\PeopleSchema;
use APP\plugins\generic\post45NotionSync\classes\mapping\ReadersReportsSchema;
use APP\plugins\generic\post45NotionSync\classes\notion\NotionApiException;
use APP\plugins\generic\post45NotionSync\classes\notion\NotionClient;
use APP\plugins\generic\post45NotionSync\classes\repository\SyncStateRepository;
use APP\plugins\generic\post45NotionSync\classes\settings\Post45NotionSyncSettingsForm;
use APP\submission\Submission;
use PKP\cliTool\CommandLineTool;
use PKP\controllers\grid\users\reviewer\form\traits\HasReviewDueDate;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\note\Note;
use PKP\plugins\PluginRegistry;
use PKP\query\Query;
use PKP\query\QueryParticipant;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\submission\action\EditorAction;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

require(dirname(__FILE__) . '/../bootstrap.php');

class PopulateFromNotionTool extends CommandLineTool
{
    use HasReviewDueDate;

    private const CONTEXT_ID = 1;

    // Notion consolidated `Review Status` enum -> target OJS workflow stage.
    // Terminal statuses + copy-editing states are handled outside this table
    // (see deriveStage()).
    private const REVIEW_STATUS_STAGE = [
        'Received' => WORKFLOW_STAGE_ID_SUBMISSION,
        'Desk Review in Progress' => WORKFLOW_STAGE_ID_SUBMISSION,
        'Finding Reviewers' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
        'Out for Review' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
        'Reports Received' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
        'Revision Back with Reviewer' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
    ];

    // Notion `Copy Editing & Production Status` values that imply Stage 5.
    // Everything else in the CE_IN_PROGRESS set implies Stage 4.
    private const CE_STAGE_5 = ['Preparing Proofs', 'Proofs with Author', 'Ready for Publication'];

    private const CE_STAGE_4 = [
        'Received',
        'First Edit in Progress',
        'Second Edit in Progress',
        'Copyedits Ready to Send',
        'Edits With Author',
    ];

    // Notion Copy Editing Status -> OJS copyeditingSubstatus enum
    // (SchemaHook::COPYEDITING_SUBSTATUS_VALUES). Sync's write direction turns
    // this back into Notion's CE Status column, so setting it right here means
    // the first post-cutover sync is a no-op instead of blanking the column.
    //
    // "Copyedits Ready to Send" has no direct OJS equivalent — Post45's OJS
    // model transitions second_edit_in_progress -> copyedits_with_author on the
    // "send to author" action, no intermediate "ready but not sent" state.
    // Mapped to second_edit_in_progress (still working, about to send) rather
    // than copyedits_with_author (which would falsely claim it's been sent);
    // editor can flip to copyedits_with_author in the OJS panel once they do
    // send. The reverse-mapping loss on sync-back is acceptable — Notion
    // becomes the second-order historical record post-cutover.
    private const NOTION_CE_STATUS_TO_SUBSTATUS = [
        'Received' => 'manuscript_received',
        'First Edit in Progress' => 'first_edit_in_progress',
        'Second Edit in Progress' => 'second_edit_in_progress',
        'Copyedits Ready to Send' => 'second_edit_in_progress',
        'Edits With Author' => 'copyedits_with_author',
        'Preparing Proofs' => 'preparing_proofs',
        'Proofs with Author' => 'proofs_with_author',
        'Ready for Publication' => 'ready_for_publication',
    ];

    // Statuses that mean "already on WordPress" — always skip.
    private const CE_SKIP = ['Published'];

    // Decision cell values that mean the article should NOT be migrated
    // (legacy Notion stays live as `LEGACY — DO NOT USE`).
    private const DECISION_SKIP = ['Reject', 'Withdrawn'];

    // R.R. Status values that mean the reviewer's work is complete — no
    // OJS account + no review assignment needed.
    private const RR_SKIP = ['Done'];

    // Manifest `file_kind` vocabulary — the vocabulary the user fills in the
    // CSV with — mapped to (OJS fileStage, OJS genre key, notes-for-humans).
    //
    // Genre keys come from registry/genres.xml — SUBMISSION is the main
    // article text; STYLE is a style guide/sheet; OTHER covers everything
    // else that carries text (cover letters, revision responses).
    //
    // For `reviewer_report` the fileStage is REVIEW_ATTACHMENT and the file
    // is associated with a specific review assignment via `notion_rr_id`.
    // See populateReviewerReportFiles().
    private const FILE_KIND_MAP = [
        // Stage 1 — Submission Files
        'initial_manuscript' => [SubmissionFile::SUBMISSION_FILE_SUBMISSION, 'SUBMISSION', "Author's initial submitted manuscript."],
        'cover_letter' => [SubmissionFile::SUBMISSION_FILE_SUBMISSION, 'OTHER',      "Author's cover letter with the initial submission."],
        'desk_revision' => [SubmissionFile::SUBMISSION_FILE_SUBMISSION, 'SUBMISSION', "Author's revised manuscript uploaded during a desk-revision window."],

        // Stage 3 — Review Revision (author-supplied files during review)
        'revision_manuscript' => [SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION, 'SUBMISSION', "Author's revised manuscript during peer review (uses `round`)."],
        'revision_response' => [SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION, 'OTHER',      "Author's response to reviewer comments (uses `round`)."],

        // Stage 3 — Review Attachment (reviewer's uploaded report; requires notion_rr_id)
        'reviewer_report' => [SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT, 'OTHER',    "Reviewer's uploaded R.R. file. REQUIRES notion_rr_id."],

        // Stage 4 — Copyedited Files (author⇄editor round-trip; see
        // Post45SubmissionFileRepository docblock)
        'author_copyedit_input' => [SubmissionFile::SUBMISSION_FILE_COPYEDIT, 'SUBMISSION', "Author's post-accept manuscript sent for copyediting."],
        'copyedited_manuscript' => [SubmissionFile::SUBMISSION_FILE_COPYEDIT, 'SUBMISSION', "Editor's copyedited manuscript sent to author for review."],
        'author_approved' => [SubmissionFile::SUBMISSION_FILE_COPYEDIT, 'SUBMISSION', "Author's clean copy — approved edits, tracked changes resolved."],

        // Stage 4 — Draft Files (editors-only working area)
        'copyeditor_working' => [SubmissionFile::SUBMISSION_FILE_FINAL, 'SUBMISSION', "Editor/copyeditor's internal working draft (invisible to author)."],

        // Correspondence & context — for waiting-for-something articles
        // where OJS is empty of files but editors need history to evaluate
        // whatever comes next. See uploadOneFile()'s special-case for
        // decision_letter (creates a Discussion thread with the file attached
        // to the head note; participants = editor + assigned author).
        'decision_letter' => [SubmissionFile::SUBMISSION_FILE_QUERY, 'OTHER', "Editor's decision letter (R&R, accept, etc.). Creates a Discussion at the current stage with the letter body in the note."],
        'desk_review_letter' => [SubmissionFile::SUBMISSION_FILE_QUERY, 'OTHER', "Editor's desk-review letter (Pre-PR Revision). Creates a Pre-Review Discussion at Stage 1 with the letter body in the note. Same handling as decision_letter — different label for clarity."],
        'reader_reports' => [SubmissionFile::SUBMISSION_FILE_ATTACHMENT, 'OTHER', "Bundled reader's reports file. Post45 sends a single doc with both R.R.s combined; use one row per article, not per reviewer."],

        // Stage 5 (Production) is deliberately absent. Post45 repurposes it
        // as proof coordination — the actual proofs live on WordPress and
        // authors get a preview link + login, not a file in OJS. See
        // CLAUDE.md "Active Editorial Scope" for the reasoning. If a proof
        // file ever DOES belong in OJS, use `other` and attach it as an
        // unassociated attachment.

        // Catch-all for anything else — lands as an unassociated attachment
        // (visible to editors, out of the main workflow buckets).
        'other' => [SubmissionFile::SUBMISSION_FILE_ATTACHMENT, 'OTHER', 'Miscellaneous file — lands as unassociated attachment.'],
    ];

    public bool $dryRun = false;
    public ?int $limit = null;
    public ?string $singleArticleId = null;
    public bool $verbose = false;
    public ?string $journalPath = null;
    public ?string $filesManifest = null;
    public ?string $filesRoot = null;
    public ?string $generateManifest = null;
    public ?string $googleTokenPath = null;

    /** Cached Bearer token for the current run, refreshed on demand. */
    private ?string $googleAccessToken = null;

    private $context;
    private $editor;
    private $defaultSection;
    private NotionClient $notion;
    private string $articlesDatabaseId;
    private string $peopleDatabaseId;
    private string $readersReportsDatabaseId;
    private SyncStateRepository $articleLedger;
    private SyncStateRepository $peopleLedger;
    private SyncStateRepository $rrLedger;

    private array $summary = [
        'articles_seen' => 0,
        'articles_skipped_filter' => 0,
        'articles_created' => 0,
        'articles_failed' => 0,
        'users_created' => 0,
        'users_reused' => 0,
        'reviews_created' => 0,
        'files_uploaded' => 0,
        'files_missing' => 0,
        'files_failed' => 0,
    ];

    /** @var array<string, array<int, array<string, string>>> notion_page_id -> rows */
    private array $manifestByArticle = [];
    /** @var array<string, array<int, array<string, string>>> notion_rr_id -> rows */
    private array $manifestByRr = [];
    private ?array $genreCache = null;

    /** Notion workspace member id => OJS user id, inverted from plugin settings. */
    private ?array $copyeditorPairingsCache = null;
    /** Notion user id => user array from GET /v1/users/{id}, or null when the retrieve failed. */
    private array $notionUserByIdCache = [];

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            if ($arg === '--dry-run') {
                $this->dryRun = true;
            } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
                $this->limit = (int) $m[1];
            } elseif (preg_match('/^--article=(.+)$/', $arg, $m)) {
                $this->singleArticleId = trim($m[1]);
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $this->verbose = true;
            } elseif (preg_match('/^--journal=(.+)$/', $arg, $m)) {
                $this->journalPath = $m[1];
            } elseif (preg_match('/^--files-manifest=(.+)$/', $arg, $m)) {
                $this->filesManifest = self::expandPath($m[1]);
            } elseif (preg_match('/^--files-root=(.+)$/', $arg, $m)) {
                $this->filesRoot = rtrim(self::expandPath($m[1]), '/');
            } elseif (preg_match('/^--generate-manifest=(.+)$/', $arg, $m)) {
                $this->generateManifest = self::expandPath($m[1]);
            } elseif (preg_match('/^--google-token=(.+)$/', $arg, $m)) {
                $this->googleTokenPath = self::expandPath($m[1]);
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
        $kinds = implode(', ', array_keys(self::FILE_KIND_MAP));
        echo <<<TXT
Populate an empty OJS instance from the consolidated Notion Articles DB.
One-shot migration tool; see the file docblock for full context.

Usage: {$this->scriptName} [--dry-run] [--limit=N] [--article=<page-id>]
                          [--verbose] [--journal=PATH]
                          [--files-manifest=CSV --files-root=DIR]
                          [--generate-manifest=OUT.csv]

Options:
  --dry-run                    Resolve + log every intended write, do nothing.
  --limit=N                    Process at most N articles (after filter).
  --article=<page-id>          Only process this one Notion article page.
  --verbose | -v               Per-property logging.
  --journal=PATH               Journal path (default: first enabled journal).
  --files-manifest=CSV         Path to the file-upload manifest (see below).
  --files-root=DIR             Base directory for local paths in the manifest.
                               Optional if every row uses a URL instead.
  --google-token=PATH          Google OAuth token.json (from google-auth's
                               Credentials.to_json()). Enables authenticated
                               Drive/Docs downloads. Defaults to
                               ~/dev/notion_automations/token.json if it
                               exists; unauthenticated fallback otherwise.
  --generate-manifest=OUT.csv  Special mode: scan Notion + write a starter
                               manifest with one placeholder row per (article,
                               kind) and (R.R., 'reviewer_report'). No OJS
                               writes. User fills file_path for files that
                               exist and deletes rows for files that don't.

File-manifest CSV shape:
  Columns:  notion_page_id, notion_rr_id, file_path, file_kind, round, notes
  file_kind vocabulary:
    {$kinds}
  Rows with an empty file_path are ignored (safe to leave placeholders in).
  `notion_rr_id` is required only for kind='reviewer_report'.
  `round` is optional; used to associate revision files with a specific
  review round (defaults to the last round).
  `file_path` accepts either:
    - a local path (resolved against --files-root)
    - a Google Drive URL (drive.google.com/file/d/{ID}/...) — downloaded on
      the fly, requires the file to be shared "anyone with the link"
    - a Google Docs URL (docs.google.com/document/d/{ID}/...) — exported as
      .docx on the fly, same sharing requirement

Safety:
  * Refuses to run if post45NotionSync sync is currently enabled.
  * Refuses to run if the ledger already contains rows (would collide with
    a prior population — use the (TODO) --reset-and-repopulate flag).

TXT;
    }

    public function execute(): void
    {
        if ($this->generateManifest !== null) {
            $this->bootstrap();
            $this->executeGenerateManifest();
            return;
        }

        $this->validateFilesArgs();
        $this->bootstrap();
        $this->preflight();
        $this->loadFilesManifest();

        $this->info("Fetching in-progress articles from Notion database {$this->articlesDatabaseId}");
        $articles = $this->fetchArticles();
        $this->info(sprintf('Fetched %d article(s) from Notion', count($articles)));

        $processed = 0;
        foreach ($articles as $article) {
            $this->summary['articles_seen']++;
            $title = $this->readTitle($article);
            $notionId = $article['id'];
            $shortId = substr($notionId, 0, 8);

            try {
                $result = $this->processArticle($article);
            } catch (\Throwable $e) {
                $this->summary['articles_failed']++;
                fwrite(STDERR, "[{$shortId}] FAILED: {$title}\n");
                fwrite(STDERR, '         ' . $e->getMessage() . "\n");
                if ($this->verbose) {
                    fwrite(STDERR, $e->getTraceAsString() . "\n");
                }
                continue;
            }

            if ($result === 'skipped') {
                $this->summary['articles_skipped_filter']++;
                continue;
            }

            $this->summary['articles_created']++;
            $processed++;
            if ($this->limit !== null && $processed >= $this->limit) {
                $this->info("Reached --limit={$this->limit}; stopping.");
                break;
            }
        }

        $this->printSummary();
    }

    // ---------------------------------------------------------------------
    // bootstrap + preflight
    // ---------------------------------------------------------------------

    private function bootstrap(): void
    {
        $this->context = $this->resolveContext();
        $this->editor = $this->resolveEditor();

        Registry::set('user', $this->editor);
        Application::get()->getRequest()->getRouter()->_context = $this->context;

        // Suppress reviewer-invite emails: EditorAction::addReviewer reads
        // skipEmail off the request. See createTestSubmissions.php for the
        // same pattern + why $_requestVars must be cleared.
        $_GET['skipEmail'] = 1;
        Application::get()->getRequest()->_requestVars = null;

        $this->silenceMailers();

        // Generic plugins register per-context. Populating goes through
        // post45Editorial decisions (Request Desk Revision id 998, tagged
        // upload hooks, etc.), so re-register the plugin against this
        // context. See OJS-DEV-NOTES.md worker-context gotcha.
        $editorial = PluginRegistry::getPlugin('generic', 'post45editorialplugin');
        if ($editorial) {
            $editorial->register('generic', $editorial->getPluginPath(), $this->context->getId());
        }

        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if (!$sync) {
            $this->die('post45NotionSync plugin not registered. Enable it before running populate.');
        }

        $token = (string) $sync->getSetting(self::CONTEXT_ID, 'integrationToken');
        $this->articlesDatabaseId = trim((string) $sync->getSetting(self::CONTEXT_ID, 'articlesDatabaseId'));
        $this->peopleDatabaseId = trim((string) $sync->getSetting(self::CONTEXT_ID, 'peopleDatabaseId'));
        $this->readersReportsDatabaseId = trim((string) $sync->getSetting(self::CONTEXT_ID, 'readersReportsDatabaseId'));

        foreach ([
            'integrationToken' => $token,
            'articlesDatabaseId' => $this->articlesDatabaseId,
            'peopleDatabaseId' => $this->peopleDatabaseId,
            'readersReportsDatabaseId' => $this->readersReportsDatabaseId,
        ] as $name => $value) {
            if ($value === '') {
                $this->die("post45NotionSync setting '{$name}' is empty. Configure the plugin first.");
            }
        }

        $this->notion = new NotionClient($token);
        $this->articleLedger = new SyncStateRepository($this->articlesDatabaseId);
        $this->peopleLedger = new SyncStateRepository($this->peopleDatabaseId);
        $this->rrLedger = new SyncStateRepository($this->readersReportsDatabaseId);

        $this->defaultSection = Repo::section()->getCollector()
            ->filterByContextIds([$this->context->getId()])
            ->getMany()
            ->first();
        if (!$this->defaultSection) {
            $this->die("Journal '{$this->context->getPath()}' has no sections. Create one before populating.");
        }
    }

    /**
     * Swap the default mailer to an in-memory sink for this process. Populate
     * creates users, adds reviewers, records decisions and uploads files —
     * every one of which can cascade into a notification email. On dev with
     * `[email] default = log`, those get dumped to the terminal via errorlog
     * and flood scrollback past the useful populate output. `skipEmail=1`
     * covers the reviewer-invite path explicitly but not every downstream
     * cascade; the array-transport swap catches the rest.
     *
     * The `array` transport captures messages in memory without delivering
     * or logging. Scoped to this process — a real request restores the
     * config-driven default from PKPContainer.
     */
    private function silenceMailers(): void
    {
        config([
            'mail.mailers.silent' => ['transport' => 'array'],
            'mail.default' => 'silent',
        ]);
        // Drop any mailer instance the container may already have resolved
        // so subsequent Mail::* calls pick up the new default. Cheap even
        // if nothing has been resolved yet.
        app('mail.manager')->forgetMailers();
    }

    /**
     * Safety gates BEFORE any writes happen. Refuse to run if a prior
     * population left ledger rows behind (would create duplicates or write
     * against the wrong Notion pages), or if sync has ever been enabled
     * (post-cutover data would be corrupted by another population pass).
     */
    private function preflight(): void
    {
        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');

        if ((bool) $sync->getSetting(self::CONTEXT_ID, 'enableSync')) {
            $this->die(
                'post45NotionSync is currently ENABLED. Populate refuses to run '
                . 'against a live-sync environment — it would collide with real '
                . 'editorial work. Disable sync in the plugin settings first.'
            );
        }

        // TODO(g3b-reset): once --reset-and-repopulate lands, allow existing
        // ledger rows when the flag is present.
        $existing = \Illuminate\Support\Facades\DB::table(SyncStateRepository::TABLE)->count();
        if ($existing > 0) {
            $this->die(
                "Ledger table post45_notion_sync_state already has {$existing} row(s). "
                . 'Populate assumes an empty ledger to avoid collisions with a prior '
                . 'population run. Truncate the table (or use --reset-and-repopulate '
                . 'once implemented) before rerunning.'
            );
        }

        if ($this->dryRun) {
            fwrite(STDERR, "\n=== DRY RUN — no writes will happen ===\n\n");
        }
    }

    private function resolveContext()
    {
        /** @var \APP\journal\JournalDAO $journalDao */
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
            $this->die("No editorial user in journal '{$this->context->getPath()}'. Populate needs one to record decisions as.");
        }
        return $editor;
    }

    // ---------------------------------------------------------------------
    // Notion fetch
    // ---------------------------------------------------------------------

    /**
     * Fetch every non-archived article (or the one --article=... points at).
     * Notion query is paginated; walk cursor to completion.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchArticles(): array
    {
        if ($this->singleArticleId !== null) {
            return [$this->notion->retrievePage($this->singleArticleId)];
        }

        $results = [];
        $cursor = null;
        do {
            $body = [
                'filter' => [
                    'property' => ArticleSchema::ARCHIVED,
                    'checkbox' => ['equals' => false],
                ],
                'page_size' => 100,
            ];
            if ($cursor !== null) {
                $body['start_cursor'] = $cursor;
            }
            $response = $this->notion->queryDatabase($this->articlesDatabaseId, $body);
            foreach ($response['results'] ?? [] as $page) {
                $results[] = $page;
            }
            $cursor = ($response['has_more'] ?? false) ? ($response['next_cursor'] ?? null) : null;
        } while ($cursor !== null);
        return $results;
    }

    // ---------------------------------------------------------------------
    // Per-article processing
    // ---------------------------------------------------------------------

    /**
     * @return string 'created' or 'skipped'
     */
    private function processArticle(array $article): string
    {
        $notionId = $article['id'];
        $shortId = substr($notionId, 0, 8);
        $title = $this->readTitle($article);
        $reviewStatus = $this->readSelect($article, ArticleSchema::REVIEW_STATUS);
        $ceStatus = $this->readSelect($article, ArticleSchema::COPY_EDITING_STATUS);
        $decision = $this->readSelect($article, ArticleSchema::DECISION);

        // ---- filter ----
        $skipReason = $this->shouldSkip($ceStatus, $decision, $reviewStatus, $article);
        if ($skipReason !== null) {
            $this->info("[{$shortId}] SKIP ({$skipReason}): {$title}");
            return 'skipped';
        }

        $targetStage = $this->deriveStage($reviewStatus, $ceStatus, $decision);
        if ($targetStage === null) {
            $this->info("[{$shortId}] SKIP (unmappable state Review={$reviewStatus} CE={$ceStatus} Decision={$decision}): {$title}");
            return 'skipped';
        }

        $this->info("[{$shortId}] {$title}");
        $this->info("         Review={$reviewStatus} CE={$ceStatus} Decision={$decision} -> Stage {$targetStage}");

        if ($this->dryRun) {
            $authorIds = $this->readRelation($article, ArticleSchema::AUTHORS);
            $reviewIds = $this->readRelation($article, ArticleSchema::REVIEWS);
            $specialIssueIds = $this->readRelation($article, ArticleSchema::SPECIAL_ISSUE);
            $this->info('         would create: submission + ' . count($authorIds) . ' author(s), '
                . count($reviewIds) . ' R.R. link(s), ' . count($specialIssueIds) . ' SI link(s)');
            return 'created';
        }

        // ---- create submission ----
        $section = $this->resolveSection($article);
        $authorPageIds = $this->readRelation($article, ArticleSchema::AUTHORS);
        $authorUsers = $this->resolveAuthorUsers($authorPageIds);

        if (empty($authorUsers)) {
            throw new \RuntimeException('Article has no resolvable author People pages; cannot create submission.');
        }
        $primaryAuthor = $authorUsers[0];

        $submissionId = $this->createSubmission($section, $primaryAuthor, $title, $article);
        $this->attachAuthors($submissionId, $authorUsers);
        $this->assignEditorToSubmission($submissionId);

        // Drive the submission to its target stage via real decisions, so
        // downstream state (review rounds, stage assignments) matches what
        // the editorial UI would produce.
        $reviewRoundId = $this->driveToStage($submissionId, $targetStage, $decision);

        // Review assignments: only for articles STILL IN REVIEW. A CE-stage
        // article's non-Done R.R.s are ghosts (reviewer never submitted, editor
        // accepted anyway). Under "OJS operational, Notion historical": no OJS
        // account for those reviewers; the record stays in Notion.
        $reviewIds = $this->readRelation($article, ArticleSchema::REVIEWS);
        if (!empty($reviewIds) && $targetStage === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW && $reviewRoundId) {
            $this->populateReviews($submissionId, $reviewRoundId, $reviewIds);
        }

        // Copyeditor assignment for stage-4+ articles. `Assigned to` at these
        // stages is the copyeditor (post-AssignFirstEdit currentOwner);
        // resolveCopyeditorFromNotion reverses the Notion-user -> OJS-user
        // gap AssignedToResolver bridges the other way. Silent no-op when the
        // Notion cell is empty, or when the resolver can't match a Notion
        // member to an OJS user (WARNING emitted inside).
        if ($targetStage >= WORKFLOW_STAGE_ID_EDITING) {
            $copyeditor = $this->resolveCopyeditorFromNotion($article);
            if ($copyeditor) {
                $this->assignCopyeditorToSubmission($submissionId, $copyeditor);
                if ($this->verbose) {
                    $this->info('         copyeditor assigned: ' . $copyeditor->getEmail() . " (user_id={$copyeditor->getId()})");
                }
            }
            $this->writeCopyeditingSubstatus($submissionId, $ceStatus);
        }

        // Manifest-driven file uploads (submission-level; reviewer reports
        // are handled inside populateReviews). No-op when no manifest is
        // supplied — every populated submission then arrives file-less.
        $this->populateSubmissionFiles($submissionId, $notionId, $reviewRoundId, $primaryAuthor);

        // Stamp the ledger so post-cutover sync finds the pages instead of
        // duplicating them. Only page id is stamped — the baseline payload
        // gets written by stampBaselines.php between populate and cutover,
        // which runs the real synchronizer and reconciles round-trip loss
        // between populate's Notion -> OJS mapping and sync's OJS -> Notion
        // mapping. See tools/dev/stampBaselines.php.
        $this->articleLedger->recordPageId(
            SyncStateRepository::ENTITY_SUBMISSION,
            $submissionId,
            $notionId
        );

        $this->info("         submission_id={$submissionId} authors=" . count($authorUsers)
            . ' reviews=' . count($reviewIds));
        return 'created';
    }

    /**
     * Filter: return a human-readable skip reason or null to proceed.
     */
    private function shouldSkip(?string $ceStatus, ?string $decision, ?string $reviewStatus, array $article): ?string
    {
        if ($ceStatus !== null && in_array($ceStatus, self::CE_SKIP, true)) {
            return "CE status = {$ceStatus} (already on WordPress)";
        }
        if ($decision !== null && in_array($decision, self::DECISION_SKIP, true)) {
            return "Decision = {$decision} (legacy record stays in Notion)";
        }
        // Wrong-shape safety valve (per backlog): a page missing basic signal
        // is not something we should attempt best-effort. Fail loud, skip.
        if ($reviewStatus === null && $ceStatus === null) {
            return 'no Review Status or Copy Editing Status set';
        }

        return null;
    }

    /**
     * Precedence: CE status > accepted-into-CE > R&R/Pre-PR > review status.
     * Returns null if the state isn't mappable (caller logs + skips).
     */
    private function deriveStage(?string $reviewStatus, ?string $ceStatus, ?string $decision): ?int
    {
        if ($ceStatus !== null) {
            if (in_array($ceStatus, self::CE_STAGE_5, true)) {
                return WORKFLOW_STAGE_ID_PRODUCTION;
            }
            if (in_array($ceStatus, self::CE_STAGE_4, true)) {
                return WORKFLOW_STAGE_ID_EDITING;
            }
        }

        if ($decision === 'Accept') {
            return WORKFLOW_STAGE_ID_EDITING;
        }

        // R&R is post-peer-review — article is back at Stage 3 with the
        // author revising for a next round. Pre-PR Revision is pre-peer-
        // review — article stays at Stage 1 (desk-review-level revision).
        // Their decision letters land in different Discussion panels
        // (Discussions vs Pre-Review Discussions) because Discussions
        // follow the submission's stage.
        // TODO(g3b-decision): also RECORD the intermediate decision on the
        // OJS side so decision history matches Notion. Deferred until the
        // Decision History sync (G4.5) lands.
        if ($decision === 'R&R') {
            return WORKFLOW_STAGE_ID_EXTERNAL_REVIEW;
        }
        if ($decision === 'Pre-PR Revision') {
            return WORKFLOW_STAGE_ID_SUBMISSION;
        }

        if ($reviewStatus !== null && isset(self::REVIEW_STATUS_STAGE[$reviewStatus])) {
            return self::REVIEW_STATUS_STAGE[$reviewStatus];
        }

        // "Decision Returned" with no CE signal + no recognized decision:
        // truly ambiguous, caller skips.
        return null;
    }

    /**
     * The single file placeholder to emit for this article's current state,
     * per the "OJS operational, Notion historical" principle. Reviewer-report
     * placeholders are emitted separately (they're per-R.R., not per-article).
     *
     * Historical files (initial manuscript when in CE, prior revision rounds,
     * cover letters, superseded drafts) intentionally get NO placeholder —
     * they live in Notion/Drive as history. If a specific article needs an
     * exception, add a row by hand.
     *
     * @return array<int, array{0: string, 1: string}> list of [file_kind, round] pairs
     */
    private function minimalPlaceholdersForState(int $stage, ?string $reviewStatus, ?string $ceStatus, ?string $decision): array
    {
        // Stage 5 (Production / proofs): nothing in OJS — proofs live on WP.
        if ($stage === WORKFLOW_STAGE_ID_PRODUCTION) {
            return [];
        }

        // Stage 4 (Copy Editing): the file currently in the round-trip.
        if ($stage === WORKFLOW_STAGE_ID_EDITING) {
            // Editor-side turn (they're working on the file, author is
            // waiting): the file to seed is what the editor will edit.
            if (in_array($ceStatus, ['Received', 'First Edit in Progress', 'Second Edit in Progress'], true)) {
                return [['author_copyedit_input', '']];
            }
            // Author-side turn (file has been sent to author, or is ready
            // to send): the file to seed is what the author has (or will
            // shortly have).
            if (in_array($ceStatus, ['Copyedits Ready to Send', 'Edits With Author'], true)) {
                return [['copyedited_manuscript', '']];
            }
            // Fell through Accept → CE-not-started-yet or an unknown CE
            // state. Seed the accepted manuscript ready for the editor.
            return [['author_copyedit_input', '']];
        }

        // Stage 3 (Review): the manuscript version reviewers/author are
        // currently working with. R&R means a revision round is in progress —
        // seed the revision manuscript slot, plus operational context so
        // editors can evaluate the revision when it arrives: the decision
        // letter that went out (as a Discussion) and the combined reader's
        // reports file.
        if ($stage === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
            if ($decision === 'R&R' || $reviewStatus === 'Revision Back with Reviewer') {
                return [
                    ['revision_manuscript', '2'],
                    ['decision_letter', ''],
                    ['reader_reports', ''],
                ];
            }
            // Round 1 review: the initial manuscript is what reviewers see.
            return [['initial_manuscript', '']];
        }

        // Stage 1 (Submission / Desk Review): the initial manuscript, plus
        // the desk-review letter for articles at Pre-PR Revision. Emitted as
        // desk_review_letter kind (routed through same code as decision_letter,
        // just clearer semantics for editors reading the manifest).
        if ($decision === 'Pre-PR Revision') {
            return [
                ['initial_manuscript', ''],
                ['desk_review_letter', ''],
            ];
        }
        return [['initial_manuscript', '']];
    }

    // ---------------------------------------------------------------------
    // Section resolution
    // ---------------------------------------------------------------------

    /**
     * Which OJS section this submission belongs in. Regular articles land in
     * the default (first) section; special-issue articles SHOULD land in a
     * per-SI section with the guest editor assigned to it.
     *
     * TODO(g3b-special-issue): create the SI section on demand (read SI page
     * title from Notion, upsert an OJS section, assign guest editors). Design
     * discussion pending — see backlog G4.5 "Special-issue handling
     * verification". For now, SI articles land in the default section with a
     * loud warning.
     */
    private function resolveSection(array $article)
    {
        $specialIssueIds = $this->readRelation($article, ArticleSchema::SPECIAL_ISSUE);
        if (!empty($specialIssueIds)) {
            $siShort = substr($specialIssueIds[0], 0, 8);
            fwrite(STDERR, "         WARNING: article belongs to special issue [{$siShort}] but SI section "
                . "creation is TODO(g3b-special-issue); routing to default section.\n");
        }
        return $this->defaultSection;
    }

    // ---------------------------------------------------------------------
    // Author + user resolution
    // ---------------------------------------------------------------------

    /**
     * Resolve or create an OJS User for each author People page. Returns
     * every author in order (primary first). Skips People pages that fail to
     * yield a usable email — the caller falls back to "no author" and
     * probably fails, which is what we want on a badly-shaped record.
     *
     * @return array<int, \PKP\user\User>
     */
    private function resolveAuthorUsers(array $peoplePageIds): array
    {
        $users = [];
        foreach ($peoplePageIds as $pageId) {
            $user = $this->upsertUserFromPeoplePage($pageId, [Role::ROLE_ID_AUTHOR]);
            if ($user) {
                $users[] = $user;
            }
        }
        return $users;
    }

    /**
     * Fetch a People page, extract name+email, upsert an OJS User + role
     * assignment. Returns the User, or null if the page is missing an email
     * (unrecoverable — an OJS account needs one).
     *
     * Ledger: stamps the People page id against the created User so the
     * first post-cutover Person sync finds the page instead of duplicating it.
     */
    private function upsertUserFromPeoplePage(string $peoplePageId, array $additionalRoleIds): ?\PKP\user\User
    {
        // Ledger check first: if we already made a user for this People page
        // in this run, reuse it.
        $existingLedger = $this->peopleLedger->find(SyncStateRepository::ENTITY_USER, 0);
        // find() takes a specific entity_id. Fall through to Notion fetch +
        // OJS-side email lookup — the ledger-by-notion-page-id inverse lookup
        // is TODO(g3b-ledger-inverse): a tiny helper on the repo. For now
        // this is O(n) per author but n is <100.

        $page = $this->notion->retrievePage($peoplePageId);
        $name = $this->readTitle($page);
        $email = $this->readEmail($page, PeopleSchema::EMAIL);

        if ($email === null || $email === '') {
            $shortId = substr($peoplePageId, 0, 8);
            fwrite(STDERR, "         WARNING: People page [{$shortId}] '{$name}' has no email; skipping.\n");
            return null;
        }

        $existing = Repo::user()->getByEmail($email);
        if ($existing) {
            $this->summary['users_reused']++;
            $this->assignRolesIfMissing($existing, $additionalRoleIds);
            $this->stampPeopleLedger($existing->getId(), $peoplePageId);
            return $existing;
        }

        // Split name into given/family. Notion doesn't split it for us — the
        // People page Name is a single string. Best-effort: last whitespace-
        // separated token is family, everything before is given.
        [$given, $family] = $this->splitName($name ?? $email);

        $user = Repo::user()->newDataObject();
        $primaryLocale = $this->context->getPrimaryLocale();
        $user->setUsername($this->uniqueUsername($given, $family, $email));
        $user->setGivenName($given, $primaryLocale);
        $user->setFamilyName($family, $primaryLocale);
        $user->setEmail($email);
        $user->setDateRegistered(Core::getCurrentDate());
        $user->setInlineHelp(1);
        // Random password — user resets via forgot-password flow at cutover
        // (part of G3d announcement mail-merge).
        $user->setPassword(Validation::encryptCredentials(
            $user->getUsername(),
            bin2hex(random_bytes(16))
        ));
        $user->setMustChangePassword(true);
        Repo::user()->add($user);

        // Role assignments in this context.
        foreach ($additionalRoleIds as $roleId) {
            $group = Repo::userGroup()->getByRoleIds([$roleId], $this->context->getId())->first();
            if ($group) {
                Repo::userGroup()->assignUserToGroup($user->getId(), $group->id);
            }
        }

        $this->summary['users_created']++;
        $this->stampPeopleLedger($user->getId(), $peoplePageId);
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

    private function stampPeopleLedger(int $userId, string $peoplePageId): void
    {
        $this->peopleLedger->recordPageId(
            SyncStateRepository::ENTITY_USER,
            $userId,
            $peoplePageId
        );
    }

    /**
     * @return array{0: string, 1: string} given, family
     */
    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['Unknown', 'Author'];
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
    // Submission + Publication + Author records
    // ---------------------------------------------------------------------

    private function createSubmission($section, \PKP\user\User $primaryAuthor, string $title, array $article): int
    {
        $locale = $this->context->getSupportedDefaultSubmissionLocale();
        $submittedAt = $this->readDate($article, ArticleSchema::RECEIVED) ?? Core::getCurrentDate();

        $submission = Repo::submission()->newDataObject([
            'contextId' => $this->context->getId(),
            'locale' => $locale,
            'stageId' => WORKFLOW_STAGE_ID_SUBMISSION,
            'status' => Submission::STATUS_QUEUED,
            'submissionProgress' => '',
            'dateSubmitted' => $submittedAt,
        ]);

        $publication = Repo::publication()->newDataObject([
            'locale' => $locale,
            'title' => [$locale => $title],
            'abstract' => [$locale => ''],
            'status' => Submission::STATUS_QUEUED,
            'sectionId' => $section->getId(),
        ]);

        $submissionId = Repo::submission()->add($submission, $publication, $this->context);
        return $submissionId;
    }

    private function attachAuthors(int $submissionId, array $authorUsers): void
    {
        $submission = Repo::submission()->get($submissionId);
        $publication = $submission->getCurrentPublication();
        $locale = $this->context->getSupportedDefaultSubmissionLocale();

        $authorGroup = Repo::userGroup()->getByRoleIds(
            [Role::ROLE_ID_AUTHOR],
            $this->context->getId()
        )->first();
        if (!$authorGroup) {
            throw new \RuntimeException('No Author user group in this journal.');
        }

        $primaryAuthorId = null;
        $seq = 1;
        foreach ($authorUsers as $user) {
            $given = method_exists($user, 'getLocalizedGivenName')
                ? $user->getLocalizedGivenName()
                : ($user->getGivenName($locale) ?? '');
            $family = method_exists($user, 'getLocalizedFamilyName')
                ? $user->getLocalizedFamilyName()
                : ($user->getFamilyName($locale) ?? '');
            $author = Repo::author()->newDataObject([
                'publicationId' => $publication->getId(),
                'userGroupId' => $authorGroup->id,
                'givenName' => [$locale => $given],
                'familyName' => [$locale => $family],
                'email' => $user->getEmail(),
                'includeInBrowse' => true,
                'seq' => $seq++,
            ]);
            $authorId = Repo::author()->add($author);
            if ($primaryAuthorId === null) {
                $primaryAuthorId = $authorId;
            }

            // Stage assignment gives the author a real presence in the workflow
            // (author-facing decisions like Mark Published look this up rather
            // than reading publication.authors).
            Repo::stageAssignment()->build(
                $submissionId,
                $authorGroup->id,
                $user->getId(),
                false,
                false
            );
        }

        if ($primaryAuthorId !== null) {
            Repo::publication()->edit($publication, [
                'primaryContactId' => $primaryAuthorId,
            ]);
        }
    }

    private function assignEditorToSubmission(int $submissionId): void
    {
        // 1. Auto-assign the section's configured editors (mirrors what OJS's
        //    AssignEditors event listener does when a submission is submitted
        //    through the wizard — we bypass the wizard so the listener
        //    doesn't fire).
        $submission = Repo::submission()->get($submissionId);
        $subEditorsDao = DAORegistry::getDAO('SubEditorsDAO');
        $subEditorsDao->assignEditors($submission, $this->context);

        // 2. Also assign the populate-runner editor as a fallback (in case
        //    the section has no editors configured, and because decisions
        //    populate records need a real editor on the submission).
        $group = Repo::userGroup()
            ->userUserGroups($this->editor->getId(), $this->context->getId())
            ->first(fn ($g) => in_array(
                (int) $g->roleId,
                [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
                true
            ));
        if (!$group) {
            return;
        }
        // Skip if the runner is already assigned (avoid duplicate stage_assignments).
        $already = \PKP\stageAssignment\StageAssignment::withSubmissionIds([$submissionId])
            ->withUserId($this->editor->getId())
            ->get()
            ->isNotEmpty();
        if ($already) {
            return;
        }
        Repo::stageAssignment()->build(
            $submissionId,
            $group->id,
            $this->editor->getId(),
            false,
            true
        );
    }

    /**
     * Inverted plugin-settings pairings: `{notionUserId: ojsUserId}`. Cached
     * for the run. A single OJS user paired to two Notion members is a config
     * error but flip is deterministic (last-write-wins), so the caller gets a
     * consistent (if potentially wrong) answer rather than a crash. Users
     * would notice the wrong copyeditor on the wrong article at verification.
     *
     * @return array<string, int>
     */
    private function copyeditorPairings(): array
    {
        if ($this->copyeditorPairingsCache !== null) {
            return $this->copyeditorPairingsCache;
        }
        $plugin = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        $forward = (new Post45NotionSyncSettingsForm($plugin, self::CONTEXT_ID))->assignedToPairings();
        $inverted = [];
        foreach ($forward as $ojsId => $notionId) {
            $inverted[$notionId] = (int) $ojsId;
        }
        return $this->copyeditorPairingsCache = $inverted;
    }

    /**
     * Retrieve a single Notion user by id, cached per run. Uses GET /v1/users/{id}
     * rather than the workspace list because Post45's workspace is one member +
     * everyone-else-guest; the bulk `listAllUsers` endpoint only returns members,
     * so a guest with a matching OJS email would never resolve via the bulk list
     * even though `retrieveUser` returns their profile happily. Null result is
     * cached too — a 404 or 403 shouldn't refire on every subsequent article.
     */
    private function notionUserById(string $notionUserId): ?array
    {
        if (array_key_exists($notionUserId, $this->notionUserByIdCache)) {
            return $this->notionUserByIdCache[$notionUserId];
        }
        try {
            return $this->notionUserByIdCache[$notionUserId] = $this->notion->retrieveUser($notionUserId);
        } catch (NotionApiException $e) {
            return $this->notionUserByIdCache[$notionUserId] = null;
        }
    }

    /**
     * Reverse-lookup for the Notion `Assigned to` cell → OJS user, mirroring
     * AssignedToResolver's resolution order but going the other direction.
     *
     * Only the FIRST Notion member id in the cell is consulted; Post45 practice
     * is a single owner per article at any moment. If a cell has more than one
     * name the extra ones are ignored (would surface in the audit as data-shape
     * to fix, not a populate bug).
     *
     * Resolution:
     *   1. Stored pairings (inverted `{notionUserId: ojsUserId}` from the plugin
     *      settings). Wins over the auto-match so a JM's explicit pairing beats
     *      an unlucky email collision.
     *   2. Auto-match by email: workspace member's email → OJS user by email.
     *   3. Give up (WARNING). The article still gets populated; it just has no
     *      copyeditor assignment until an editor picks one in the OJS UI.
     */
    private function resolveCopyeditorFromNotion(array $article): ?\PKP\user\User
    {
        $assignedIds = $this->readPeople($article, ArticleSchema::ASSIGNED_TO);
        if (empty($assignedIds)) {
            return null;
        }
        $notionUserId = $assignedIds[0];

        $pairings = $this->copyeditorPairings();
        if (isset($pairings[$notionUserId])) {
            return Repo::user()->get($pairings[$notionUserId]);
        }

        $member = $this->notionUserById($notionUserId);
        if (!$member || ($member['type'] ?? '') !== 'person') {
            fwrite(STDERR, "         WARNING: Assigned to member {$notionUserId} could not be retrieved (or is a bot); skipping copyeditor assignment.\n");
            return null;
        }
        $email = trim((string) ($member['person']['email'] ?? ''));
        if ($email === '') {
            fwrite(STDERR, "         WARNING: Assigned to member {$notionUserId} has no email; skipping copyeditor assignment.\n");
            return null;
        }
        $user = Repo::user()->getByEmail($email);
        if (!$user) {
            fwrite(STDERR, "         WARNING: no OJS user with email {$email} for Notion member {$notionUserId}; skipping copyeditor assignment.\n");
            return null;
        }
        return $user;
    }

    /**
     * Assign the resolved user as the submission's copyeditor at stage 4.
     *
     * Copyediting is permission-driven, NOT role-locked: any user_group the JM
     * has authorized on the Copyediting stage (Users & Roles → Roles) is a
     * legitimate copyeditor. Post45 practice is that Managing Editors,
     * Co-Editors, Section Editors and Copyeditors all pitch in — see
     * AssignsCopyeditorForEdit's docblock for the design principle. Populate
     * therefore uses whatever stage-4-authorized group the resolved user
     * already has, without inventing a new role membership.
     *
     * If the user has no stage-4-authorized group, that is a real JM setup
     * gap (they can't be a copyeditor in OJS's model): WARNING and skip the
     * assignment. Silently promoting them to Assistant would paper over the
     * gap and hide it from the editorial team at cutover.
     *
     * Sets `currentOwner` via Post45SubmissionSettingsRepository so the first
     * post-cutover sync writes the correct `Assigned to` back to Notion (a
     * null currentOwner would blank the cell — CLEAR outcome in
     * AssignedToResolver terms). Skipped when no assignment lands.
     *
     * Idempotent: a duplicate stage_assignment is skipped, and
     * setCurrentOwner is a no-op when the value already matches.
     */
    private function assignCopyeditorToSubmission(int $submissionId, \PKP\user\User $copyeditor): void
    {
        // Stage-4-authorized user_group ids in this context, minus Author —
        // exactly what AssignsCopyeditorForEdit::getEditorialCandidateIds uses
        // when the editor picks a copyeditor from the UI.
        $authorizedGroupIds = Repo::userGroup()
            ->getUserGroupsByStage($this->context->getId(), WORKFLOW_STAGE_ID_EDITING)
            ->reject(fn ($group) => (int) $group->roleId === Role::ROLE_ID_AUTHOR)
            ->map(fn ($group) => (int) $group->id)
            ->values()
            ->all();
        if ($authorizedGroupIds === []) {
            fwrite(STDERR, "         WARNING: no user_groups authorized on Copyediting stage in this context; skipping copyeditor assignment.\n");
            return;
        }

        // Which of the resolved user's groups is authorized on stage 4? Any
        // one is fine — the stage_assignment just needs to be under a group
        // the JM has said can operate there.
        $userGroup = Repo::userGroup()
            ->userUserGroups($copyeditor->getId(), $this->context->getId())
            ->first(fn ($g) => in_array((int) $g->id, $authorizedGroupIds, true));
        if (!$userGroup) {
            fwrite(STDERR, sprintf(
                "         WARNING: user %s (id=%d) has no user_group authorized on Copyediting stage; fix in Users & Roles before OJS can list them as a copyeditor. Skipping copyeditor assignment for submission %d.\n",
                $copyeditor->getEmail(),
                $copyeditor->getId(),
                $submissionId,
            ));
            return;
        }

        $already = \PKP\stageAssignment\StageAssignment::withSubmissionIds([$submissionId])
            ->withUserId($copyeditor->getId())
            ->withUserGroupId($userGroup->id)
            ->get()
            ->isNotEmpty();
        if (!$already) {
            Repo::stageAssignment()->build(
                $submissionId,
                $userGroup->id,
                $copyeditor->getId(),
                false,
                false
            );
        }

        // Set currentOwner so post-cutover sync's OJS -> Notion write for
        // `Assigned to` matches what Notion already shows. Without this the
        // first sync would write CLEAR (empty people list) over the current
        // Notion value.
        $submission = Repo::submission()->get($submissionId);
        (new Post45SubmissionSettingsRepository())
            ->setCurrentOwner($submission, (int) $copyeditor->getId());
    }

    /**
     * Write copyeditingSubstatus from the article's Notion CE Status. Without
     * this the post45Editorial state panel shows the "Awaiting the
     * copyediting-ready manuscript from the author." fallback on every
     * populated stage-4/5 article regardless of actual state, AND the first
     * post-cutover sync would write null into Notion's CE Status column.
     *
     * Silent no-op when the status has no OJS equivalent (unknown values or
     * pre-stage-4 values that pre-date entry into the copyediting subflow).
     */
    private function writeCopyeditingSubstatus(int $submissionId, ?string $ceStatus): void
    {
        if ($ceStatus === null || !isset(self::NOTION_CE_STATUS_TO_SUBSTATUS[$ceStatus])) {
            return;
        }
        $substatus = self::NOTION_CE_STATUS_TO_SUBSTATUS[$ceStatus];
        $submission = Repo::submission()->get($submissionId);
        (new Post45SubmissionSettingsRepository())
            ->setCopyeditingSubstatus($submission, $substatus);
        if ($this->verbose) {
            $this->info("         copyediting substatus: {$substatus} (from Notion CE={$ceStatus})");
        }
    }

    // ---------------------------------------------------------------------
    // Drive submission to target stage via decisions
    // ---------------------------------------------------------------------

    /**
     * Move the freshly-created (Stage 1) submission to $targetStage by
     * recording the decisions that produce it. Returns the round-1
     * reviewRoundId if the submission ended at stage 3+, else null (for the
     * reviewer-assignment step to hang R.R. pages on).
     */
    private function driveToStage(int $submissionId, int $targetStage, ?string $decision): ?int
    {
        if ($targetStage === WORKFLOW_STAGE_ID_SUBMISSION) {
            return null;
        }

        // Every path stage-3+ starts with Send for External Review, which
        // also creates review round 1.
        $this->recordDecision($submissionId, Decision::EXTERNAL_REVIEW, WORKFLOW_STAGE_ID_SUBMISSION);
        $reviewRound = DAORegistry::getDAO('ReviewRoundDAO')
            ->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
        $reviewRoundId = $reviewRound?->getId();

        if ($targetStage === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
            return $reviewRoundId;
        }

        // Accept out of review -> stage 4.
        $this->recordDecision($submissionId, Decision::ACCEPT, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, $reviewRoundId);

        if ($targetStage === WORKFLOW_STAGE_ID_EDITING) {
            return $reviewRoundId;
        }

        // Send to production -> stage 5.
        $this->recordDecision($submissionId, Decision::SEND_TO_PRODUCTION, WORKFLOW_STAGE_ID_EDITING);
        return $reviewRoundId;
    }

    private function recordDecision(int $submissionId, int $decisionConstant, int $stageId, ?int $reviewRoundId = null): void
    {
        $decisionType = Repo::decision()->getDecisionType($decisionConstant);
        if (!$decisionType) {
            throw new \RuntimeException("No decision type registered for constant {$decisionConstant}");
        }
        $data = [
            'submissionId' => $submissionId,
            'decision' => $decisionConstant,
            'editorId' => $this->editor->getId(),
            'stageId' => $stageId,
            'dateDecided' => Core::getCurrentDate(),
        ];
        if ($reviewRoundId) {
            $data['reviewRoundId'] = $reviewRoundId;
        }
        Repo::decision()->add(Repo::decision()->newDataObject($data));
    }

    // ---------------------------------------------------------------------
    // Reader's Report -> Review Assignment population
    // ---------------------------------------------------------------------

    private function populateReviews(int $submissionId, int $reviewRoundId, array $rrPageIds): void
    {
        $submission = Repo::submission()->get($submissionId);
        $reviewRound = DAORegistry::getDAO('ReviewRoundDAO')->getById($reviewRoundId);
        $request = Application::get()->getRequest();
        $editorAction = new EditorAction();
        // Fallback deadlines when the R.R. page has no Due Date (unlikely — it's
        // a formula on Date Requested — but a reviewer created outside the
        // normal flow could have Date Requested blank). Response due has no
        // Notion analogue; it stays at the journal default regardless.
        [$defaultReviewDueTs, $defaultResponseDueTs] = $this->getDueDates($this->context);
        $defaultReviewDue = Core::getCurrentDate($defaultReviewDueTs);
        $responseDue = Core::getCurrentDate($defaultResponseDueTs);

        foreach ($rrPageIds as $rrPageId) {
            try {
                $rr = $this->notion->retrievePage($rrPageId);
            } catch (NotionApiException $e) {
                fwrite(STDERR, "         WARNING: R.R. [{$rrPageId}] fetch failed: {$e->getMessage()}\n");
                continue;
            }

            // ROUTING: manifest file presence is authoritative for "is this
            // reviewer's work complete", overriding Notion status (which can
            // lag reality — a report may have arrived by email since the last
            // Notion update). See EDITOR-NOTES / [[project_ojs_operational_notion_historical]].
            //
            //   manifest has file  -> upload file, NO reviewer account (they're done)
            //   manifest blank + Notion Done  -> skip (nothing to do)
            //   manifest blank + Notion not-Done -> create reviewer + assignment (they're active)
            $manifestRow = null;
            foreach ($this->manifestByRr[$rrPageId] ?? [] as $r) {
                if (!empty($r['file_path'])) {
                    $manifestRow = $r;
                    break;
                }
            }
            $status = $this->readSelect($rr, ReadersReportsSchema::STATUS);

            if ($manifestRow !== null) {
                // Have file → attach as generic submission attachment, no
                // reviewer created. Same shape as reader_reports uploads.
                $this->attachCompletedReviewerReport($submission, $manifestRow, $rrPageId);
                continue;
            }

            if ($status !== null && in_array($status, self::RR_SKIP, true)) {
                if ($this->verbose) {
                    $this->info('         R.R. [' . substr($rrPageId, 0, 8) . "] status={$status} + no manifest file; skipping.");
                }
                continue;
            }

            // Reader relation: the People page for this reviewer.
            $readerIds = $this->readRelation($rr, ReadersReportsSchema::READER);
            if (empty($readerIds)) {
                fwrite(STDERR, "         WARNING: R.R. [{$rrPageId}] has no Reader relation; skipping.\n");
                continue;
            }
            $reviewerUser = $this->upsertUserFromPeoplePage($readerIds[0], [Role::ROLE_ID_REVIEWER]);
            if (!$reviewerUser) {
                continue;
            }

            // Carry the deadline the reviewer was actually shown in Notion so
            // overdue reviewers surface as overdue in OJS. `Due Date` is a
            // Notion formula, blank only if Date Requested is missing.
            $reviewDue = $this->readDate($rr, ReadersReportsSchema::DUE_DATE) ?? $defaultReviewDue;

            $editorAction->addReviewer(
                $request,
                $submission,
                $reviewerUser->getId(),
                $reviewRound,
                $reviewDue,
                $responseDue,
                // Post45 is always double-anonymous. Not relying on journal
                // default because populate has run against wrongly-configured
                // journal settings before; explicit avoids the trap.
                ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS
            );

            $assignment = Repo::reviewAssignment()->getCollector()
                ->filterByReviewRoundIds([$reviewRoundId])
                ->filterByReviewerIds([$reviewerUser->getId()])
                ->getMany()
                ->first();
            if (!$assignment) {
                fwrite(STDERR, "         WARNING: reviewer assignment for user {$reviewerUser->getId()} not found post-invite.\n");
                continue;
            }

            // Mirror the R.R. state onto the assignment. The Notion status
            // semantics map onto OJS review-assignment dates:
            //   - dateNotified: always (invitation was sent, in Notion terms)
            //   - dateConfirmed: reviewer accepted (Notion "In Progress" or later)
            //   - dateCompleted: reviewer submitted (Notion "Received" / equivalents)
            $edits = [
                'dateNotified' => $this->readDate($rr, ReadersReportsSchema::DATE_REQUESTED) ?? Core::getCurrentDate(),
                'reviewFormId' => null,
                'considered' => ReviewAssignment::REVIEW_ASSIGNMENT_NEW,
            ];
            // Notion's `Date Accepted` column was added 2026-08-19. Before
            // that, Post45 used `Date Requested` to mean "when the reviewer
            // agreed" (invitation-vs-acceptance wasn't distinguished). So for
            // any pre-cutover R.R. with a populated Date Requested but empty
            // Date Accepted, Date Requested IS the acceptance date. This
            // fallback is migration-only; post-cutover R.R.s originate in
            // OJS and don't hit this code path.
            $accepted = $this->readDate($rr, ReadersReportsSchema::DATE_ACCEPTED)
                     ?? $this->readDate($rr, ReadersReportsSchema::DATE_REQUESTED);
            if ($accepted) {
                $edits['dateConfirmed'] = $accepted;
                $edits['declined'] = 0;
            }
            $received = $this->readDate($rr, ReadersReportsSchema::DATE_RECEIVED);
            if ($received) {
                $edits['dateCompleted'] = $received;
            }
            Repo::reviewAssignment()->edit($assignment, $edits);

            $this->summary['reviews_created']++;

            // Stamp the R.R. ledger so the first post-cutover Review sync
            // finds the page instead of duplicating it. Baseline payload gets
            // written by stampBaselines.php between populate and cutover.
            $this->rrLedger->recordPageId(
                SyncStateRepository::ENTITY_REVIEW_ASSIGNMENT,
                $assignment->getId(),
                $rrPageId
            );

            // Manifest-driven reviewer-report file upload. No-op when no
            // manifest is supplied.
            $this->populateReviewerReportFile(
                $submissionId,
                $reviewRoundId,
                $assignment->getId(),
                $reviewerUser,
                $rrPageId
            );

            // TODO(g3b-review-content): if the Notion R.R. page body carries
            // freeform review text (not just a file link), populating it into
            // the OJS review submission form is worth doing. Deferred until
            // we've eyeballed a sample of R.R. pages to see what shape the
            // text actually takes.
        }
    }

    /**
     * A reviewer's report we've received but where we DON'T want to create
     * an OJS account for the reviewer (their work is done — see file-presence
     * routing in populateReviews).
     *
     * Piggybacks on uploadOneFile by coercing the kind to `other`: same
     * fileStage (SUBMISSION_FILE_ATTACHMENT), same genre (OTHER), same
     * download + storage path. Editor is the uploader.
     */
    private function attachCompletedReviewerReport(
        \APP\submission\Submission $submission,
        array $manifestRow,
        string $rrPageId
    ): void {
        $modified = $manifestRow;
        $modified['file_kind'] = 'other';
        $this->uploadOneFile($modified, $submission, null, $this->editor, null);
        if ($this->verbose) {
            $this->info('         reviewer report for R.R. ' . substr($rrPageId, 0, 8)
                . ' attached without creating reviewer account (file present in manifest)');
        }
    }

    // ---------------------------------------------------------------------
    // File-manifest driven uploads
    // ---------------------------------------------------------------------

    /**
     * If --files-manifest was supplied, --files-root and manifest existence
     * must be reasonable BEFORE we start writing anything to the DB.
     */
    private function validateFilesArgs(): void
    {
        if ($this->filesManifest === null) {
            return;
        }
        // Default Google token path: the notion_automations Drive OAuth
        // token, if the sibling repo is present. Unauthenticated downloads
        // (public-share-only) are the fallback if no token is available.
        if ($this->googleTokenPath === null) {
            $default = ($_SERVER['HOME'] ?? '') . '/dev/notion_automations/token.json';
            if (is_file($default)) {
                $this->googleTokenPath = $default;
            }
        }
        if (!is_file($this->filesManifest)) {
            $this->die("--files-manifest '{$this->filesManifest}' not found.");
        }
        // --files-root is only required when the manifest has local paths.
        // A manifest that uses only Google Drive/Docs URLs can skip it. The
        // resolveSourceFile() call at upload time reports a specific error if
        // a local path shows up without a root set.
        if ($this->filesRoot !== null && !is_dir($this->filesRoot)) {
            $this->die("--files-root '{$this->filesRoot}' is not a directory.");
        }
    }

    /**
     * Parse the CSV into two indexes: rows per notion_page_id (article-level)
     * and rows per notion_rr_id (reviewer_report rows only). Empty file_path
     * rows are silently dropped so the user can leave placeholders in place.
     */
    private function loadFilesManifest(): void
    {
        if ($this->filesManifest === null) {
            $this->info('No --files-manifest supplied; submissions will be file-less.');
            return;
        }

        $fh = fopen($this->filesManifest, 'r');
        if (!$fh) {
            $this->die("Could not open --files-manifest '{$this->filesManifest}'.");
        }

        // First non-comment, non-empty row is the header.
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
            $this->die('Manifest CSV has no header row.');
        }
        $required = ['notion_page_id', 'notion_rr_id', 'file_path', 'file_kind', 'round', 'notes'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                $this->die("Manifest CSV missing required column '{$col}'. Got: " . implode(',', $header));
            }
        }

        $rowNum = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $rowNum++;
            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), '');
            }
            $assoc = array_combine($header, array_slice($row, 0, count($header)));
            $assoc = array_map(fn ($v) => trim((string) $v), $assoc);

            if ($assoc['file_path'] === '') {
                continue; // placeholder row, silently ignore
            }
            if ($assoc['notion_page_id'] === '') {
                fwrite(STDERR, "Manifest row {$rowNum}: file_path set but notion_page_id empty; skipping.\n");
                continue;
            }
            if (!isset(self::FILE_KIND_MAP[$assoc['file_kind']])) {
                fwrite(STDERR, "Manifest row {$rowNum}: unknown file_kind '{$assoc['file_kind']}'; skipping.\n");
                continue;
            }
            if ($assoc['file_kind'] === 'reviewer_report') {
                if ($assoc['notion_rr_id'] === '') {
                    fwrite(STDERR, "Manifest row {$rowNum}: file_kind=reviewer_report requires notion_rr_id; skipping.\n");
                    continue;
                }
                $this->manifestByRr[$assoc['notion_rr_id']][] = $assoc;
                continue;
            }
            $this->manifestByArticle[$assoc['notion_page_id']][] = $assoc;
        }
        fclose($fh);

        $articleFiles = array_sum(array_map('count', $this->manifestByArticle));
        $rrFiles = array_sum(array_map('count', $this->manifestByRr));
        $this->info("Manifest loaded: {$articleFiles} article-level file(s), {$rrFiles} reviewer-report(s).");
    }

    /**
     * Upload every submission-level file the manifest lists for this article.
     * Reviewer reports are handled separately (they need the review-assignment
     * id, which only exists inside populateReviews).
     */
    private function populateSubmissionFiles(int $submissionId, string $notionPageId, ?int $reviewRoundId, \PKP\user\User $primaryAuthor): void
    {
        $rows = $this->manifestByArticle[$notionPageId] ?? [];
        if (empty($rows)) {
            return;
        }

        $submission = Repo::submission()->get($submissionId);
        foreach ($rows as $row) {
            try {
                $this->uploadOneFile($row, $submission, $reviewRoundId, $primaryAuthor);
            } catch (\Throwable $e) {
                $this->summary['files_failed']++;
                fwrite(STDERR, "         FILE FAILED ({$row['file_path']}): {$e->getMessage()}\n");
            }
        }
    }

    /**
     * Upload the reviewer's report file for one R.R. — associated with the
     * specific review-assignment so the reviewer can see it on their side.
     */
    private function populateReviewerReportFile(
        int $submissionId,
        int $reviewRoundId,
        int $reviewAssignmentId,
        \PKP\user\User $reviewer,
        string $rrPageId
    ): void {
        $rows = $this->manifestByRr[$rrPageId] ?? [];
        if (empty($rows)) {
            return;
        }
        $submission = Repo::submission()->get($submissionId);
        foreach ($rows as $row) {
            try {
                $this->uploadOneFile(
                    $row,
                    $submission,
                    $reviewRoundId,
                    $reviewer,
                    reviewAssignmentId: $reviewAssignmentId
                );
            } catch (\Throwable $e) {
                $this->summary['files_failed']++;
                fwrite(STDERR, "         R.R. FILE FAILED ({$row['file_path']}): {$e->getMessage()}\n");
            }
        }
    }

    /**
     * Copy a local file into OJS's file storage, insert the SubmissionFile
     * record at the right stage/genre/association. Shared by both submission-
     * level and reviewer-report uploads.
     */
    private function uploadOneFile(
        array $row,
        \APP\submission\Submission $submission,
        ?int $reviewRoundId,
        \PKP\user\User $uploader,
        ?int $reviewAssignmentId = null
    ): void {
        $kind = $row['file_kind'];
        [$fileStage, $genreKey, $_notes] = self::FILE_KIND_MAP[$kind];

        // file_path can be either a local path (relative to --files-root) or
        // a Google Drive / Docs URL (downloaded to a temp file on demand).
        // See resolveSourceFile() for the URL handling.
        $source = $this->resolveSourceFile($row['file_path']);
        if ($source === null) {
            $this->summary['files_missing']++;
            return; // resolveSourceFile logged the specific failure
        }

        if ($this->dryRun) {
            $this->info("         [dry-run] would upload {$row['file_path']} as {$kind} (fileStage={$fileStage})");
            // Clean up any temp file we downloaded just for the dry-run check.
            if ($source['temp']) {
                @unlink($source['abs']);
            }
            return;
        }

        $fileManager = new FileManager();
        $originalName = $source['originalName'];
        $extension = $fileManager->parseFileExtension($originalName) ?: 'bin';

        // decision_letter: prefer text-in-body over file-attachment. Extract
        // the docx to plain text and put it in the Discussion's head note,
        // no file storage. Falls back to the file-attachment path if
        // extraction fails (unknown format, corrupted docx, etc.) — better
        // to have the letter as an attachment than to lose it entirely.
        if ($kind === 'decision_letter' || $kind === 'desk_review_letter') {
            $bodyText = $this->extractDocxText($source['abs']);
            if ($bodyText !== null) {
                if ($this->dryRun) {
                    $this->info(sprintf('         [dry-run] would create Discussion with letter body (%d chars extracted from %s)', strlen($bodyText), $originalName));
                } else {
                    $this->createDecisionLetterDiscussion(
                        $submission,
                        $row['notes'] ?? 'Decision letter',
                        $bodyText
                    );
                    $this->summary['files_uploaded']++;
                    if ($this->verbose) {
                        $this->info(sprintf('         created Discussion with letter body (%d chars from %s)', strlen($bodyText), $originalName));
                    }
                }
                if ($source['temp']) {
                    @unlink($source['abs']);
                }
                return;
            }
            fwrite(STDERR, "         WARN: could not extract text from {$originalName}; falling back to file attachment\n");
            // Fall through to the file-upload path below, which will call
            // uploadDecisionLetter to attach the file to a Discussion note.
        }

        $submissionDir = Repo::submissionFile()->getSubmissionDir(
            $this->context->getId(),
            $submission->getId()
        );

        // app('file')->add copies the source into ojs-files storage and
        // returns the new file id — same call the upload wizard uses.
        $fileId = app()->get('file')->add(
            $source['abs'],
            $submissionDir . '/' . uniqid() . '.' . $extension
        );

        if ($source['temp']) {
            @unlink($source['abs']);
        }

        // decision_letter fallback path (text extraction failed above).
        if ($kind === 'decision_letter' || $kind === 'desk_review_letter') {
            $this->uploadDecisionLetter(
                $submission,
                $fileId,
                $originalName,
                $row['notes'] ?? 'Decision letter',
            );
            $this->summary['files_uploaded']++;
            if ($this->verbose) {
                $this->info("         uploaded {$originalName} as {$kind} (Discussion + attached file, extraction fallback)");
            }
            return;
        }

        $submissionFile = Repo::submissionFile()->dao->newDataObject();
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', $fileStage);
        $submissionFile->setData('name', $originalName, $submission->getData('locale'));
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('uploaderUserId', $uploader->getId());
        $submissionFile->setData('genreId', $this->resolveGenreId($genreKey));

        // Association: review attachment -> the review assignment; anything
        // in the review-round buckets -> the round; everything else -> none.
        if ($fileStage === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT && $reviewAssignmentId) {
            $submissionFile->setData('assocType', PKPApplication::ASSOC_TYPE_REVIEW_ASSIGNMENT);
            $submissionFile->setData('assocId', $reviewAssignmentId);
        } elseif (in_array($fileStage, [
            SubmissionFile::SUBMISSION_FILE_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
        ], true) && $reviewRoundId) {
            // Manifest can override the round; default is the last round.
            $round = $reviewRoundId;
            if (isset($row['round']) && $row['round'] !== '') {
                $override = $this->reviewRoundIdForNumber((int) $submission->getId(), (int) $row['round']);
                if ($override) {
                    $round = $override;
                }
            }
            $submissionFile->setData('assocType', PKPApplication::ASSOC_TYPE_REVIEW_ROUND);
            $submissionFile->setData('assocId', $round);
        }

        Repo::submissionFile()->add($submissionFile);
        $this->summary['files_uploaded']++;
        if ($this->verbose) {
            $this->info("         uploaded {$originalName} as {$kind}");
        }
    }

    /**
     * Create a Discussion (Query) thread at the submission's current stage
     * with a head note titled by the manifest `notes` column, and attach the
     * downloaded letter to it — mirroring how OJS handles editor↔author
     * correspondence natively.
     *
     * Participants: the populating editor + every author with a stage
     * assignment at the current stage. Not adding the copyeditor / reviewers
     * — the letter is between editor and author.
     *
     * Author sees a NEW_QUERY notification (fires from Repo::query()->add-
     * style call path). post45DevEmailGuard rewrites the outgoing email so
     * nothing hits the real author during migration.
     *
     * File attachment shape matches OJS's own decision-recommendation trait
     * (SubmissionFile with fileStage=SUBMISSION_FILE_QUERY, assocType=NOTE,
     * assocId=note_id). Rendering in the Discussion UI is identical to a
     * hand-attached-in-OJS letter.
     */
    /**
     * Pull plain text out of a .docx file by unzipping + walking document.xml
     * for `<w:p>` (paragraph) → `<w:t>` (text run) nodes. Paragraph breaks
     * preserved as blank lines; inline formatting (bold, italics, lists) is
     * flattened.
     *
     * Returns null (not empty string) if the file isn't a valid docx or
     * yields no text — caller falls back to file-attachment path.
     */
    private function extractDocxText(string $path): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return null;
        }

        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $paragraphs = [];
        foreach ($doc->getElementsByTagNameNS($ns, 'p') as $p) {
            $paraText = '';
            foreach ($p->getElementsByTagNameNS($ns, 't') as $t) {
                $paraText .= $t->nodeValue;
            }
            $paraText = trim($paraText);
            if ($paraText !== '') {
                $paragraphs[] = $paraText;
            }
        }
        $text = trim(implode("\n\n", $paragraphs));
        return $text === '' ? null : $text;
    }

    /**
     * Create a Discussion (Query) with the letter TEXT as the head-note body
     * (no file attached). Same participant + query shape as
     * uploadDecisionLetter, but body carries the letter directly for
     * skim-in-place reading — no download-to-read step.
     */
    private function createDecisionLetterDiscussion(
        \APP\submission\Submission $submission,
        string $title,
        string $bodyText
    ): void {
        $stageId = (int) $submission->getData('stageId');

        $editorId = $this->editor->getId();
        $authorAssignments = \PKP\stageAssignment\StageAssignment::withSubmissionIds([$submission->getId()])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->withStageIds([$stageId])
            ->get();
        $authorUserIds = $authorAssignments->pluck('userId')->all();
        $participantIds = array_values(array_unique(array_merge([$editorId], $authorUserIds)));

        $maxSeq = Query::withAssoc(\PKP\core\PKPApplication::ASSOC_TYPE_SUBMISSION, $submission->getId())
            ->max('seq') ?? 0;

        $query = Query::create([
            'assocType' => \PKP\core\PKPApplication::ASSOC_TYPE_SUBMISSION,
            'assocId' => $submission->getId(),
            'stageId' => $stageId,
            'seq' => $maxSeq + 1,
        ]);

        foreach ($participantIds as $userId) {
            QueryParticipant::insert([
                'query_id' => $query->id,
                'user_id' => $userId,
            ]);
        }

        // Escape HTML in the extracted text, then join paragraphs with
        // <br/><br/> (visible blank line between them). NOT <p> tags —
        // OJS's discussion-note CSS zeroes out paragraph margins, so <p>
        // renders as packed-together text with no visual separation between
        // paragraphs. Double <br/> forces the blank line regardless.
        // Single-newline breaks within a paragraph become <br/> for
        // preserved line-wrapping (e.g., signature lines).
        $escaped = htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8');
        $paragraphs = array_map(
            fn ($p) => str_replace("\n", '<br/>', trim($p)),
            array_filter(explode("\n\n", $escaped), fn ($p) => trim($p) !== '')
        );
        $htmlBody = implode('<br/><br/>', $paragraphs);

        Note::create([
            'assocType' => \PKP\core\PKPApplication::ASSOC_TYPE_QUERY,
            'assocId' => $query->id,
            'title' => $title,
            'contents' => $htmlBody,
            'userId' => $editorId,
        ]);
    }

    private function uploadDecisionLetter(
        \APP\submission\Submission $submission,
        int $fileId,
        string $originalName,
        string $title,
    ): void {
        $stageId = (int) $submission->getData('stageId');

        // Editor + assigned authors as participants; dedupe just in case.
        $editorId = $this->editor->getId();
        $authorAssignments = \PKP\stageAssignment\StageAssignment::withSubmissionIds([$submission->getId()])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->withStageIds([$stageId])
            ->get();
        $authorUserIds = $authorAssignments->pluck('userId')->all();
        $participantIds = array_values(array_unique(array_merge([$editorId], $authorUserIds)));

        // Sequence: append after any existing queries on this submission.
        $maxSeq = Query::withAssoc(\PKP\core\PKPApplication::ASSOC_TYPE_SUBMISSION, $submission->getId())
            ->max('seq') ?? 0;

        $query = Query::create([
            'assocType' => \PKP\core\PKPApplication::ASSOC_TYPE_SUBMISSION,
            'assocId' => $submission->getId(),
            'stageId' => $stageId,
            'seq' => $maxSeq + 1,
        ]);

        foreach ($participantIds as $userId) {
            QueryParticipant::insert([
                'query_id' => $query->id,
                'user_id' => $userId,
            ]);
        }

        // Head note: title is the manifest's `notes` (e.g. "R&R letter from
        // Annie 2024-10-07"). Body is a placeholder pointer — the letter
        // itself is the attached file, so a long body would be redundant.
        $note = Note::create([
            'assocType' => \PKP\core\PKPApplication::ASSOC_TYPE_QUERY,
            'assocId' => $query->id,
            'title' => $title,
            'contents' => 'See attached decision letter.',
            'userId' => $editorId,
        ]);

        // Attach the file to the head note. Same shape as OJS's own
        // decision-recommendation code path (see IsRecommendation trait).
        $submissionFile = Repo::submissionFile()->dao->newDataObject();
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_QUERY);
        $submissionFile->setData('name', $originalName, $submission->getData('locale'));
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('uploaderUserId', $editorId);
        $submissionFile->setData('genreId', $this->resolveGenreId('OTHER'));
        $submissionFile->setData('assocType', \PKP\core\PKPApplication::ASSOC_TYPE_NOTE);
        $submissionFile->setData('assocId', $note->id);
        Repo::submissionFile()->add($submissionFile);
    }

    private function reviewRoundIdForNumber(int $submissionId, int $roundNumber): ?int
    {
        $round = DAORegistry::getDAO('ReviewRoundDAO')
            ->getReviewRound($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, $roundNumber);
        return $round?->getId();
    }

    /**
     * Turn a manifest `file_path` into a concrete local file the uploader can
     * read. Two shapes:
     *   - Google Drive / Docs URL → download to a temp file, return that path
     *     with a `temp=true` flag so the caller can unlink after upload.
     *   - Anything else → treat as a path relative to --files-root.
     *
     * Returns null (and logs) when the source can't be resolved.
     *
     * @return array{abs: string, originalName: string, temp: bool}|null
     */
    private function resolveSourceFile(string $manifestPath): ?array
    {
        if (str_starts_with($manifestPath, 'http://') || str_starts_with($manifestPath, 'https://')) {
            return $this->downloadUrl($manifestPath);
        }

        if ($this->filesRoot === null) {
            fwrite(STDERR, "         PATH ERROR: '{$manifestPath}' is a local path but --files-root was not supplied.\n");
            return null;
        }
        $abs = $this->filesRoot . '/' . ltrim($manifestPath, '/');
        if (!is_file($abs)) {
            fwrite(STDERR, "         FILE MISSING: {$manifestPath} (looked for {$abs})\n");
            return null;
        }
        return ['abs' => $abs, 'originalName' => basename($manifestPath), 'temp' => false];
    }

    /**
     * Download a Google Drive or Google Docs URL to a temp file. Returns the
     * resolved (temp path + guessed original name) or null on failure.
     *
     * Handles two shapes we see in the wild:
     *   - `docs.google.com/document/d/{ID}/...` → Google Doc. Exported as .docx
     *     via `docs.google.com/document/d/{ID}/export?format=docx`. Best
     *     editorial format (Word-compatible, tracked-changes-friendly).
     *   - `drive.google.com/file/d/{ID}/...` (or `open?id={ID}`) → raw file.
     *     Downloaded via `drive.google.com/uc?export=download&id={ID}` with
     *     redirect following. Original filename comes from Content-Disposition.
     *
     * REQUIRES the file to be shared "anyone with the link can view" or with
     * an account the caller is authenticated as. This code path is
     * unauthenticated HTTP, so a private file returns HTML (a login page) not
     * bytes — the size check catches that.
     *
     * @return array{abs: string, originalName: string, temp: bool}|null
     */
    private function downloadUrl(string $url): ?array
    {
        $id = self::extractGoogleDriveId($url);
        if ($id === null) {
            fwrite(STDERR, "         URL ERROR: cannot extract a Google Drive/Docs file id from '{$url}'\n");
            return null;
        }

        $isDoc = str_contains($url, 'docs.google.com/document/');
        // Google Docs: use the docs.google.com export endpoint (supports OAuth
        // and gives us .docx). Regular Drive files: use the Drive API
        // /files/{id}?alt=media endpoint — the public /uc?export=download
        // endpoint doesn't honor Bearer tokens properly (returns an HTML
        // permission page for private files even with auth).
        $downloadUrl = $isDoc
            ? "https://docs.google.com/document/d/{$id}/export?format=docx"
            : "https://www.googleapis.com/drive/v3/files/{$id}?alt=media";
        $fallbackExt = $isDoc ? 'docx' : 'bin';

        $tempPath = tempnam(sys_get_temp_dir(), 'populate-') . '.' . $fallbackExt;

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
            CURLOPT_USERAGENT => 'post45-populate/1.0',
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

        // Google returns an HTML login page for private files, not bytes.
        // Size + content-type check catches this.
        $size = filesize($tempPath);
        if ($size === 0 || $size === false) {
            @unlink($tempPath);
            fwrite(STDERR, "         DOWNLOAD ERROR: empty file body from {$downloadUrl}\n");
            return null;
        }
        if (str_contains((string) $contentType, 'text/html')) {
            @unlink($tempPath);
            fwrite(STDERR, "         DOWNLOAD ERROR: got HTML instead of file bytes from {$downloadUrl}\n");
            fwrite(STDERR, "         Likely the file is not shared 'anyone with the link'.\n");
            return null;
        }

        // Prefer server-supplied filename. Drive API's /files/{id}?alt=media
        // endpoint doesn't send Content-Disposition, so for Drive files we
        // need a separate metadata call to get the real name + extension.
        // (Google Docs export DOES send Content-Disposition, so this only
        // fires for the Drive path.)
        if ($originalName === null && !$isDoc && $bearer !== null) {
            $originalName = $this->fetchDriveFileName($id, $bearer);
        }
        if ($originalName === null) {
            $originalName = $isDoc ? "google-doc-{$id}.docx" : "google-file-{$id}";
        }

        return ['abs' => $tempPath, 'originalName' => $originalName, 'temp' => true];
    }

    /**
     * One-shot metadata lookup to get a Drive file's `name` field (which
     * includes the extension). Called after downloading via /alt=media when
     * Content-Disposition wasn't provided. Returns null on any failure —
     * caller falls back to a synthesized `google-file-{id}` name.
     */
    private function fetchDriveFileName(string $fileId, string $bearer): ?string
    {
        // supportsAllDrives=true is required for files in Shared Drives (as
        // opposed to the caller's personal My Drive). Post45's article files
        // live in a Shared Drive, so without this flag every metadata lookup
        // returns 404 — which is exactly the trap that produced
        // `google-file-{id}` filenames for many uploads.
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

    /**
     * The current Google OAuth access token, refreshing if necessary. Returns
     * null when no token file was supplied — callers then fall back to
     * unauthenticated download (which only works for publicly-shared files).
     *
     * Cached per run: first call loads/refreshes, subsequent calls reuse.
     * Given a typical migration run takes <5 min and Google access tokens
     * live an hour, one refresh per run is enough.
     */
    private function getGoogleAccessToken(): ?string
    {
        if ($this->googleAccessToken !== null) {
            return $this->googleAccessToken;
        }
        if ($this->googleTokenPath === null) {
            return null;
        }
        if (!is_file($this->googleTokenPath)) {
            fwrite(STDERR, "         GOOGLE AUTH WARN: --google-token file not found at {$this->googleTokenPath}; downloads will use unauthenticated fallback.\n");
            return null;
        }

        $raw = file_get_contents($this->googleTokenPath);
        $token = json_decode($raw, true);
        if (!is_array($token) || !isset($token['refresh_token'], $token['client_id'], $token['client_secret'])) {
            fwrite(STDERR, "         GOOGLE AUTH WARN: token file at {$this->googleTokenPath} is missing refresh_token/client_id/client_secret; downloads will use unauthenticated fallback.\n");
            return null;
        }

        // Simplest correct behavior: always refresh at start of run. The stored
        // access_token from a prior run is usually expired anyway (1-hour
        // lifetime), and Google's refresh endpoint is cheap.
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
            fwrite(STDERR, "         GOOGLE AUTH WARN: refresh returned HTTP {$httpCode}; downloads will use unauthenticated fallback.\n");
            return null;
        }
        $refreshed = json_decode($response, true);
        if (!isset($refreshed['access_token'])) {
            fwrite(STDERR, "         GOOGLE AUTH WARN: refresh response missing access_token; downloads will use unauthenticated fallback.\n");
            return null;
        }
        $this->googleAccessToken = $refreshed['access_token'];
        if ($this->verbose) {
            $this->info("Refreshed Google Drive access token (expires in {$refreshed['expires_in']}s)");
        }
        return $this->googleAccessToken;
    }

    /**
     * Pull a Google file id out of any of the several URL shapes we see:
     *   docs.google.com/document/d/{ID}/edit?...
     *   drive.google.com/file/d/{ID}/view?...
     *   drive.google.com/open?id={ID}
     *   drive.google.com/uc?id={ID}&...
     */
    private static function extractGoogleDriveId(string $url): ?string
    {
        if (preg_match('#/d/([a-zA-Z0-9_-]{20,})#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]{20,})#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function resolveGenreId(string $key): ?int
    {
        if ($this->genreCache === null) {
            $this->genreCache = [];
            /** @var \PKP\submission\GenreDAO $genreDao */
            $genreDao = DAORegistry::getDAO('GenreDAO');
            $iterator = $genreDao->getByContextId($this->context->getId());
            while ($genre = $iterator->next()) {
                $this->genreCache[$genre->getKey()] = $genre->getId();
            }
        }
        return $this->genreCache[$key] ?? null;
    }

    // ---------------------------------------------------------------------
    // --generate-manifest — write a starter CSV for the user to fill in
    // ---------------------------------------------------------------------

    /**
     * Walk Notion in the same order populate does and emit a placeholder
     * CSV row per (article, likely file_kind) and per (R.R., 'reviewer_report').
     * The user then fills file_path for files that exist and deletes rows
     * for files that don't.
     *
     * Which kinds are emitted per article is determined by the article's
     * current Review + CE status — a stage-1 article gets initial_manuscript
     * + cover_letter placeholders, a stage-4 article additionally gets
     * copyedited_manuscript + author_approved, etc. This is a heuristic
     * lower bound: the user should add rows for anything else that exists
     * (extra revisions, multiple reviewer_reports, etc.).
     */
    private function executeGenerateManifest(): void
    {
        // Sanity-check the output target BEFORE hitting Notion so a bad path
        // fails fast, but don't create the file yet — a Notion failure after
        // opening would leave an empty stub behind.
        $outDir = dirname($this->generateManifest);
        if (!is_dir($outDir) || !is_writable($outDir)) {
            $this->die("--generate-manifest directory '{$outDir}' is not writable.");
        }

        $this->info("Fetching in-progress articles from Notion database {$this->articlesDatabaseId}");
        $articles = $this->fetchArticles();
        $this->info(sprintf('Fetched %d article(s) from Notion; emitting placeholder rows.', count($articles)));

        $out = fopen($this->generateManifest, 'w');
        if (!$out) {
            $this->die("Could not open --generate-manifest target '{$this->generateManifest}' for writing.");
        }
        // Vocabulary reference block, as CSV comment rows (leading `#` in the
        // first column — the loader skips them).
        $kinds = self::FILE_KIND_MAP;
        fputcsv($out, ['# Post45 populate-from-notion file manifest — generated ' . date('Y-m-d H:i:s')]);
        fputcsv($out, ['# ---']);
        fputcsv($out, ['# One row per file. Delete rows for files that do not exist.']);
        fputcsv($out, ['# file_path is relative to --files-root; empty file_path = row ignored.']);
        fputcsv($out, ['# `notion_rr_id` is required only for file_kind=reviewer_report.']);
        fputcsv($out, ['# `round` names a review round for revision_* kinds (default: latest).']);
        fputcsv($out, ['#']);
        fputcsv($out, ['# file_kind vocabulary:']);
        foreach ($kinds as $k => [$_stage, $_genre, $note]) {
            fputcsv($out, ["#   {$k} — {$note}"]);
        }
        fputcsv($out, ['# author_first / author_last / special_issue are informational only — the loader ignores them.']);
        fputcsv($out, ['# They exist to make the spreadsheet sortable by author or issue while you fill it in.']);
        fputcsv($out, ['#']);
        fputcsv($out, ['notion_page_id', 'notion_rr_id', 'file_path', 'file_kind', 'round', 'notes', 'author_first', 'author_last', 'special_issue']);

        $rowCount = 0;
        foreach ($articles as $article) {
            $notionId = $article['id'];
            $title = $this->readTitle($article);
            $reviewStatus = $this->readSelect($article, ArticleSchema::REVIEW_STATUS);
            $ceStatus = $this->readSelect($article, ArticleSchema::COPY_EDITING_STATUS);
            $decision = $this->readSelect($article, ArticleSchema::DECISION);
            $skip = $this->shouldSkip($ceStatus, $decision, $reviewStatus, $article);
            if ($skip !== null) {
                continue;
            }
            $stage = $this->deriveStage($reviewStatus, $ceStatus, $decision);
            if ($stage === null) {
                continue;
            }

            // Informational columns — one lookup per unique People/SI page,
            // cached across articles that share authors or issues.
            $authorPageIds = $this->readRelation($article, ArticleSchema::AUTHORS);
            [$authorFirst, $authorLast] = $this->resolveAuthorNameForManifest($authorPageIds[0] ?? null);
            $specialIssueIds = $this->readRelation($article, ArticleSchema::SPECIAL_ISSUE);
            $specialIssue = $this->resolveSpecialIssueTitleForManifest($specialIssueIds[0] ?? null);

            // Minimal placeholder per the "OJS operational, Notion historical"
            // principle: emit only the file being ACTIVELY WORKED ON at the
            // current state. Prior revisions, cover letters, superseded
            // drafts, author-approved copies of prior CE rounds — all stay in
            // Notion/Drive. See [[project_ojs_operational_notion_historical]].
            $placeholders = $this->minimalPlaceholdersForState($stage, $reviewStatus, $ceStatus, $decision);

            // The `notes` cell doubles as the display label in some contexts:
            // for decision_letter it becomes the Discussion thread title in
            // OJS (see uploadDecisionLetter), so use a shape that reads well
            // as a title. Other kinds just get the article title as a
            // human-readable pointer while filling in the manifest.
            $noteText = self::singleLine($title);
            foreach ($placeholders as [$kind, $round]) {
                $notesForRow = match ($kind) {
                    'decision_letter' => 'Decision Letter, [fill in date]',
                    'reader_reports' => "Reader's Reports",
                    default => $noteText,
                };
                fputcsv($out, [$notionId, '', '', $kind, $round, $notesForRow, $authorFirst, $authorLast, $specialIssue]);
                $rowCount++;
            }

            // Reviewer-report placeholders: only for articles STILL IN
            // review. Once at CE or later, any non-Done R.R. is a ghosted
            // reviewer or an abandoned pre-accept report — creating an OJS
            // account for them serves nothing (article won't return to
            // review). Per the "OJS operational, Notion historical" principle,
            // those R.R.s stay in Notion as history.
            if ($stage !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
                continue;
            }
            $rrIds = $this->readRelation($article, ArticleSchema::REVIEWS);
            if ($this->verbose) {
                $this->info(sprintf('  [%s] %s: Reviews relation has %d R.R.(s)', substr($notionId, 0, 8), $noteText, count($rrIds)));
            }
            foreach ($rrIds as $rrId) {
                try {
                    $rr = $this->notion->retrievePage($rrId);
                } catch (NotionApiException $e) {
                    if ($this->verbose) {
                        $this->info(sprintf('    R.R. [%s] fetch failed: %s', substr($rrId, 0, 8), $e->getMessage()));
                    }
                    continue;
                }
                $status = $this->readSelect($rr, ReadersReportsSchema::STATUS);
                if ($this->verbose) {
                    $rrTitleV = $this->readTitle($rr) ?: $rrId;
                    $this->info(sprintf('    R.R. [%s] status=%s title=%s', substr($rrId, 0, 8), $status ?? '(none)', self::singleLine($rrTitleV)));
                }
                // Emit placeholder for EVERY R.R., regardless of Notion
                // status. populateReviews uses manifest file presence — not
                // Notion status — to decide account-vs-attachment routing;
                // a Done R.R. with a file we've received still needs a
                // placeholder so the file can be attached. Notion status can
                // lag reality (reports received but not yet marked Done).
                $rrTitle = $this->readTitle($rr) ?: $rrId;
                fputcsv($out, [$notionId, $rrId, '', 'reviewer_report', '', self::singleLine("R.R.: {$rrTitle}"), $authorFirst, $authorLast, $specialIssue]);
                $rowCount++;
            }
        }

        fclose($out);
        $this->info("Wrote {$rowCount} placeholder row(s) to {$this->generateManifest}.");
        $this->info('Edit the file: fill file_path for files that exist, delete rows for files that do not, add rows for anything else (e.g. more than one revision round).');
    }

    // ---------------------------------------------------------------------
    // Notion property readers
    // ---------------------------------------------------------------------

    private function readTitle(array $page): string
    {
        // Article title lives in the schema's TITLE property (a `title` type
        // on the Notion side, which returns a rich_text-like array).
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
        // Notion returns status as {status: {name: ...}} and select as {select: {name: ...}}.
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
        // Notion date columns expose `date.start`; formula columns whose result
        // is a date wrap the same shape under `formula.date.start` (e.g. R.R.
        // Due Date, computed from Date Requested).
        $iso = $prop['date']['start']
            ?? $prop['formula']['date']['start']
            ?? null;
        if (!$iso) {
            return null;
        }
        // OJS date columns are Y-m-d H:i:s; a Notion date-only string still
        // needs to be widened.
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
     * Read a Notion `people`-typed property, returning the workspace member ids
     * in order. Used for `Assigned to` — a different identity space from OJS
     * users (see AssignedToResolver's docblock).
     *
     * @return string[]
     */
    private function readPeople(array $page, string $name): array
    {
        $prop = $page['properties'][$name] ?? null;
        $items = $prop['people'] ?? [];
        return array_values(array_filter(array_column($items, 'id')));
    }

    /**
     * Split a People page's Name into (first, last) for the manifest's
     * informational author columns. Cached per page id so a person authoring
     * multiple articles costs one API call.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveAuthorNameForManifest(?string $peoplePageId): array
    {
        static $cache = [];
        if ($peoplePageId === null) {
            return ['', ''];
        }
        if (!array_key_exists($peoplePageId, $cache)) {
            try {
                $page = $this->notion->retrievePage($peoplePageId);
                $name = $this->readTitle($page);
                $cache[$peoplePageId] = $this->splitName($name);
            } catch (NotionApiException $e) {
                $cache[$peoplePageId] = ['', ''];
            }
        }
        return $cache[$peoplePageId];
    }

    /**
     * Title of a Special Issue page for the manifest's informational column.
     * Cached — usually a small set of SIs, most articles reuse.
     */
    private function resolveSpecialIssueTitleForManifest(?string $siPageId): string
    {
        static $cache = [];
        if ($siPageId === null) {
            return '';
        }
        if (!array_key_exists($siPageId, $cache)) {
            try {
                $page = $this->notion->retrievePage($siPageId);
                $cache[$siPageId] = $this->readTitle($page);
            } catch (NotionApiException $e) {
                $cache[$siPageId] = '';
            }
        }
        return $cache[$siPageId];
    }

    // ---------------------------------------------------------------------
    // Output helpers
    // ---------------------------------------------------------------------

    /**
     * Collapse any run of whitespace (including embedded newlines) to a
     * single space, for informational CSV cells. `fputcsv` correctly quotes
     * multi-line values, but many editors render the wrapped-quoted-string
     * as two visual lines — a stray-newline appearance in something meant
     * as a one-line label.
     */
    private static function singleLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Expand a leading `~/` to the invoking user's home directory. The shell
     * usually does this before args reach us, but a quoted arg or a scripted
     * call bypasses that — PHP's fopen() takes `~` literally.
     */
    private static function expandPath(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            if ($home) {
                return $home . substr($path, 1);
            }
        }
        return $path;
    }

    private function info(string $message): void
    {
        fwrite(STDERR, $message . "\n");
    }

    private function die(string $message): never
    {
        fwrite(STDERR, "ERROR: {$message}\n");
        exit(1);
    }

    private function printSummary(): void
    {
        $s = $this->summary;
        $mode = $this->dryRun ? 'DRY RUN — nothing written' : 'WROTE TO DATABASE';
        fwrite(STDERR, "\n=== populate summary ({$mode}) ===\n");
        fwrite(STDERR, sprintf("  articles seen:            %d\n", $s['articles_seen']));
        fwrite(STDERR, sprintf("  articles skipped:         %d\n", $s['articles_skipped_filter']));
        fwrite(STDERR, sprintf("  articles created:         %d\n", $s['articles_created']));
        fwrite(STDERR, sprintf("  articles failed:          %d\n", $s['articles_failed']));
        fwrite(STDERR, sprintf("  users created:            %d\n", $s['users_created']));
        fwrite(STDERR, sprintf("  users reused:             %d\n", $s['users_reused']));
        fwrite(STDERR, sprintf("  review assignments:       %d\n", $s['reviews_created']));
    }
}

(new PopulateFromNotionTool($argv ?? []))->execute();
