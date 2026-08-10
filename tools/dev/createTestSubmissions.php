<?php

/**
 * @file tools/dev/createTestSubmissions.php
 *
 * Post45 — dev-only CLI tool to seed test submissions for browser-testing
 * the editorial workflow (particularly the Mark Published on WordPress
 * decision, which is one-way and burns through test articles quickly).
 *
 * Bypasses the submission wizard: creates a Submission + Publication +
 * one Author directly via the Repo API, then optionally shifts the
 * submission to a target stage. No intermediate edit_decisions are
 * recorded (i.e. no fake "accepted after review" history) — this is a
 * shortcut for dev testing of terminal actions, not a replay of a real
 * editorial cycle.
 *
 * Usage:
 *   php tools/dev/createTestSubmissions.php [--count=N] [--stage=STAGE_ID]
 *       [--journal=PATH] [--author-email=EMAIL] [--author-name=NAME]
 *
 *   php tools/dev/createTestSubmissions.php --suite [--reviewers=N]
 *
 * Defaults:
 *   --count=3            three test articles
 *   --stage=5            Production (fastest path to Mark Published)
 *   --journal=(first)    first enabled journal
 *   --author-email       aaron+test-author@post45.org
 *   --reviewers=2        reviewers assigned to each review-stage submission
 *
 * Author user must already exist. The script errors out with a clear
 * message if it doesn't — creating a user requires more state (roles,
 * password) than a dev-testing seed should be responsible for.
 *
 * ## --suite: one submission in every state
 *
 * Seeds a spread covering each stage plus the states that only exist after a
 * particular decision, so that "what can role X see at stage Y?" can be
 * answered by looking rather than reasoning:
 *
 *   submission        Stage 1, freshly submitted, no decisions
 *   desk-revision     Stage 1 after Post45's Request Desk Revision (998)
 *   review-pending    Stage 3, round 1, reviewers invited, none responded
 *   review-accepted   Stage 3, round 1, one reviewer has accepted the invite
 *   copyediting       Stage 4, reached via Accept
 *   production        Stage 5, reached via Send to Production
 *
 * ### Every state is reached by taking the real decision
 *
 * The suite does NOT set `stageId` and call it done. It records each decision
 * through `Repo::decision()->add()`, exactly as the workflow UI does, so the
 * seeded submissions carry real review rounds, real decision history, real
 * stage assignments and real editorial-state side effects (Post45's
 * SendCopyeditsToAuthor-style hooks included).
 *
 * That matters because a hand-built state can be one the UI cannot actually
 * produce, and testing against an unreachable state hides the bugs you were
 * looking for. Driving the decisions means anything the seed produces is
 * something a real editor could have produced.
 *
 * ### What the suite deliberately does NOT fabricate
 *
 *   - **Files.** Nothing is uploaded. Which file stages a role may write to is
 *     precisely what the author-visibility work needs to observe, so seeding
 *     files would beg the question. Upload through the UI as the relevant
 *     user instead.
 *   - **Completed reviews.** `review-accepted` stops at the reviewer having
 *     accepted the invitation (`dateConfirmed`). Marking a review *complete*
 *     means review-form responses and reviewer-uploaded attachments; writing
 *     those rows directly is exactly the kind of unreachable state above.
 *     Submit the review through the reviewer UI.
 *
 * Emails: local config uses `[email] default = log`, and decisions are
 * recorded with no `actions`, so no notify-author step runs at all.
 */

use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\submission\Submission;
use PKP\cliTool\CommandLineTool;
use PKP\controllers\grid\users\reviewer\form\traits\HasReviewDueDate;
use PKP\core\Core;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\submission\action\EditorAction;
use PKP\submission\reviewAssignment\ReviewAssignment;

require(dirname(__FILE__) . '/../bootstrap.php');

class CreateTestSubmissionsTool extends CommandLineTool
{
    // Same due-date defaults the Add Reviewer form uses (journal settings
    // numWeeksPerReview / numWeeksPerResponse, falling back to 4 and 3 weeks).
    use HasReviewDueDate;

    public int $count = 3;
    public int $targetStage = 5; // WORKFLOW_STAGE_ID_PRODUCTION
    public ?string $journalPath = null;
    public string $authorEmail = 'aaron+test-author@post45.org';
    public string $authorName = 'AuthorTest Wong';
    public bool $suite = false;
    public int $reviewerCount = 2;

    private array $titlePool = [
        'The Alliterative Aftermath of Anachronistic Anthologies',
        'Between Blueprint and Belated: A Brief Bibliography',
        'Contested Corridors: Contemporary Confessional Compositions',
        'Diagonal Dialogues of the Dispossessed',
        'Ephemeral Editions: Elegies for an Emptying Era',
        'Fabulated Futures and the Foreclosure of Form',
        'Grammars of Grief in the Global Gothic',
        'Habits of Habitat: Housing the Human in Hyperfiction',
    ];

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            if (preg_match('/^--count=(\d+)$/', $arg, $m)) {
                $this->count = (int) $m[1];
            } elseif (preg_match('/^--stage=(\d+)$/', $arg, $m)) {
                $this->targetStage = (int) $m[1];
            } elseif (preg_match('/^--journal=(.+)$/', $arg, $m)) {
                $this->journalPath = $m[1];
            } elseif (preg_match('/^--author-email=(.+)$/', $arg, $m)) {
                $this->authorEmail = $m[1];
            } elseif (preg_match('/^--author-name=(.+)$/', $arg, $m)) {
                $this->authorName = $m[1];
            } elseif ($arg === '--suite') {
                $this->suite = true;
            } elseif (preg_match('/^--reviewers=(\d+)$/', $arg, $m)) {
                $this->reviewerCount = (int) $m[1];
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

    public function usage()
    {
        echo "Seed dev test submissions. Post45 fork — dev only, do not run on prod.\n"
            . "Usage: {$this->scriptName}"
            . ' [--count=N] [--stage=STAGE_ID]'
            . " [--journal=PATH] [--author-email=EMAIL] [--author-name=NAME]\n"
            . "Stages: 1=Submission 3=External Review 4=Copy Editing 5=Production (default)\n"
            . "\n"
            . "  --suite [--reviewers=N]  seed one submission in every state:\n"
            . "      submission, desk-revision, review-pending, review-accepted,\n"
            . "      copyediting, production. Each is reached by recording the real\n"
            . "      decision, so no unreachable states. Seeds no files and no\n"
            . "      completed reviews — do those through the UI.\n";
    }

    public function execute()
    {
        if ($this->suite) {
            $this->executeSuite();
            return;
        }

        $context = $this->resolveContext();
        $user = $this->resolveAuthorUser();
        $section = $this->resolveSection($context);

        $locale = $context->getSupportedDefaultSubmissionLocale();
        $created = [];

        for ($i = 0; $i < $this->count; $i++) {
            $title = $this->titlePool[array_rand($this->titlePool)]
                . ' [' . date('Y-m-d H:i:s') . ' #' . ($i + 1) . ']';
            $submissionId = $this->createOne($context, $user, $section, $locale, $title);
            $created[] = $submissionId;
            echo "  submission_id={$submissionId}  \"{$title}\"\n";
        }

        echo "\nCreated " . count($created) . " test submission(s) in context '"
            . $context->getPath() . "' at stage {$this->targetStage}.\n";
        echo "Author: {$this->authorName} <{$this->authorEmail}> (user_id={$user->getId()})\n";
    }

    private function resolveContext()
    {
        /** @var \APP\journal\JournalDAO $journalDao */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        if ($this->journalPath) {
            $context = $journalDao->getByPath($this->journalPath);
            if (!$context) {
                fwrite(STDERR, "No journal found with path '{$this->journalPath}'.\n");
                exit(1);
            }
            return $context;
        }
        $all = $journalDao->getAll(true)->toArray();
        if (empty($all)) {
            fwrite(STDERR, "No enabled journals found in this installation.\n");
            exit(1);
        }
        return $all[0];
    }

    private function resolveAuthorUser()
    {
        $user = Repo::user()->getByEmail($this->authorEmail);
        if (!$user) {
            fwrite(
                STDERR,
                "No user with email '{$this->authorEmail}'. Create the user first (Admin → Users) or pass --author-email pointing at an existing account.\n"
            );
            exit(1);
        }
        return $user;
    }

    private function resolveSection($context)
    {
        // Publications require a valid sectionId — API validation (called from
        // any publication PUT, including the WordPress URL save) reads it via
        // Repo::section()->get() and type-errors on null. Real submissions
        // pick a section during the wizard; the dev tool grabs the first
        // section in the journal to stay simple.
        $section = Repo::section()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->getMany()
            ->first();
        if (!$section) {
            fwrite(STDERR, "Journal '" . $context->getPath() . "' has no sections; create one before seeding.\n");
            exit(1);
        }
        return $section;
    }

    private function createOne($context, $user, $section, string $locale, string $title): int
    {
        $submission = Repo::submission()->newDataObject([
            'contextId' => $context->getId(),
            'locale' => $locale,
            'stageId' => $this->targetStage,
            'status' => Submission::STATUS_QUEUED,
            'submissionProgress' => '',
            'dateSubmitted' => Core::getCurrentDate(),
        ]);

        $publication = Repo::publication()->newDataObject([
            'locale' => $locale,
            'title' => [$locale => $title],
            'abstract' => [$locale => '<p>Ride, Rise, Revise, Resubmit, Revolt!</p>'],
            'status' => Submission::STATUS_QUEUED,
            'sectionId' => $section->getId(),
        ]);

        $submissionId = Repo::submission()->add($submission, $publication, $context);
        $submission = Repo::submission()->get($submissionId);
        $publication = $submission->getCurrentPublication();

        // Attach an author to the publication. Pick any Author-role user
        // group in this context (any works for dev-testing).
        $authorGroup = Repo::userGroup()->getByRoleIds(
            [\PKP\security\Role::ROLE_ID_AUTHOR],
            $context->getId()
        )->first();

        if ($authorGroup) {
            $author = Repo::author()->newDataObject([
                'publicationId' => $publication->getId(),
                'userGroupId' => $authorGroup->id,
                'givenName' => [$locale => explode(' ', $this->authorName, 2)[0]],
                'familyName' => [$locale => explode(' ', $this->authorName, 2)[1] ?? ''],
                'email' => $this->authorEmail,
                'includeInBrowse' => true,
                'seq' => 1,
            ]);
            $authorId = Repo::author()->add($author);
            Repo::publication()->edit($publication, [
                'primaryContactId' => $authorId,
            ]);

            // Author stage assignment. Notify-author email steps (like
            // MarkPublished's) look up the author via stage_assignments, not
            // via publication.authors — without this the step's recipient
            // list is empty and the form renders blank.
            Repo::stageAssignment()->build(
                $submissionId,
                $authorGroup->id,
                $user->getId(),
                false,
                false
            );
        }

        return $submissionId;
    }
    // ------------------------------------------------------------------
    // --suite
    // ------------------------------------------------------------------

    /**
     * Seed one submission per interesting state, each reached by recording
     * the decision that really produces it.
     */
    private function executeSuite(): void
    {
        $context = $this->resolveContext();
        $author = $this->resolveAuthorUser();
        $section = $this->resolveSection($context);
        $editor = $this->resolveEditorUser($context);
        $reviewers = $this->resolveReviewers($context);

        // Every suite submission starts life at Stage 1 and is moved onward by
        // recording real decisions, so createOne() must not honour --stage.
        $this->targetStage = WORKFLOW_STAGE_ID_SUBMISSION;

        $this->bootstrapRequestContext($context, $editor);

        $locale = $context->getSupportedDefaultSubmissionLocale();
        $stamp = date('Y-m-d H:i');

        $plan = [
            'submission' => 'Stage 1 — freshly submitted, no decisions',
            'desk-revision' => 'Stage 1 — after Request Desk Revision (998)',
            'review-pending' => 'Stage 3 — round 1, reviewers invited, no responses',
            'review-accepted' => 'Stage 3 — round 1, one reviewer accepted the invite',
            'copyediting' => 'Stage 4 — reached via Accept',
            'production' => 'Stage 5 — reached via Send to Production',
        ];

        echo "Seeding suite in context '" . $context->getPath() . "'\n";
        echo '  editor:    ' . $editor->getUsername() . ' (id ' . $editor->getId() . ")\n";
        echo '  author:    ' . $author->getUsername() . ' (id ' . $author->getId() . ")\n";
        echo '  reviewers: ' . (count($reviewers)
            ? implode(', ', array_map(fn ($r) => $r->getUsername(), $reviewers))
            : '(none available — review states will have no assignments)') . "\n\n";

        foreach ($plan as $state => $description) {
            $title = sprintf('[%s] %s #%s', $state, $this->titlePool[array_rand($this->titlePool)], $stamp);
            $submissionId = $this->createOne($context, $author, $section, $locale, $title);
            $this->assignEditor($submissionId, $context, $editor);

            $this->driveToState($state, $submissionId, $context, $editor, $reviewers);

            $submission = Repo::submission()->get($submissionId);
            printf(
                "  %-16s submission_id=%-4d stage=%d  %s\n",
                $state,
                $submissionId,
                $submission->getData('stageId'),
                $description
            );
        }

        echo "\nDone. No files and no completed reviews were seeded — see the file docblock.\n";
    }

    /**
     * Record the decisions that move a freshly-created submission into the
     * requested state. Each call goes through Repo::decision()->add(), which
     * runs the decision type's own side effects (stage change, review-round
     * creation, Post45 hooks) exactly as the workflow UI does.
     */
    private function driveToState(string $state, int $submissionId, $context, $editor, array $reviewers): void
    {
        if ($state === 'submission') {
            return;
        }

        if ($state === 'desk-revision') {
            // Post45's own Stage 1 decision. Registered by the plugin, so it
            // only resolves once the plugin has been re-registered against
            // this context (see bootstrapRequestContext).
            $this->recordDecision($submissionId, 998, WORKFLOW_STAGE_ID_SUBMISSION, $editor);
            return;
        }

        // Everything below lives at Stage 3 or later, which is reached by
        // sending the submission for external review. That decision is also
        // what creates review round 1.
        $this->recordDecision($submissionId, Decision::EXTERNAL_REVIEW, WORKFLOW_STAGE_ID_SUBMISSION, $editor);

        $reviewRound = DAORegistry::getDAO('ReviewRoundDAO')
            ->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);

        if (in_array($state, ['review-pending', 'review-accepted'], true)) {
            $assignments = $this->assignReviewers($submissionId, $reviewRound, $reviewers);

            if ($state === 'review-accepted' && !empty($assignments)) {
                // These are exactly the four fields ReviewerAction::confirmReview
                // writes when a reviewer clicks Accept (`declined = 0` is what
                // separates accepting from declining). Stopping here is
                // deliberate: marking the review COMPLETE means review-form
                // responses, which cannot be fabricated without inventing a
                // state the UI could not produce.
                Repo::reviewAssignment()->edit($assignments[0], [
                    'dateReminded' => null,
                    'reminderWasAutomatic' => 0,
                    'declined' => 0,
                    'dateConfirmed' => Core::getCurrentDate(),
                ]);
            }
            return;
        }

        // copyediting / production: accept out of review.
        $this->recordDecision($submissionId, Decision::ACCEPT, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, $editor, $reviewRound?->getId());

        if ($state === 'production') {
            $this->recordDecision($submissionId, Decision::SEND_TO_PRODUCTION, WORKFLOW_STAGE_ID_EDITING, $editor);
        }
    }

    /**
     * Record one editorial decision with no notify steps.
     *
     * `actions` is left empty on purpose: every notify-author/reviewer step is
     * driven by an entry in that array, so an empty array means the decision's
     * stage/status side effects run while no email is composed or sent.
     */
    private function recordDecision(int $submissionId, int $decisionConstant, int $stageId, $editor, ?int $reviewRoundId = null): void
    {
        $decisionType = Repo::decision()->getDecisionType($decisionConstant);
        if (!$decisionType) {
            fwrite(STDERR, "  ! no decision type registered for constant {$decisionConstant}; skipping.\n");
            return;
        }

        $data = [
            'submissionId' => $submissionId,
            'decision' => $decisionConstant,
            'editorId' => $editor->getId(),
            'stageId' => $stageId,
            'dateDecided' => Core::getCurrentDate(),
        ];
        if ($reviewRoundId) {
            $data['reviewRoundId'] = $reviewRoundId;
        }

        Repo::decision()->add(Repo::decision()->newDataObject($data));
    }

    /**
     * Invite reviewers to a round via EditorAction::addReviewer — the same
     * call the Add Reviewer form makes, so due dates, the task notification
     * and the event-log entry all match a real invitation.
     *
     * @return ReviewAssignment[]
     */
    private function assignReviewers(int $submissionId, $reviewRound, array $reviewers): array
    {
        if (!$reviewRound || empty($reviewers)) {
            return [];
        }

        $request = Application::get()->getRequest();
        $submission = Repo::submission()->get($submissionId);
        $editorAction = new EditorAction();
        $assignments = [];

        // EditorAction::setDueDates writes exactly what it is handed — there is
        // no fallback to the journal defaults, so passing null leaves due dates
        // NULL and the dashboard renders the reviewer bubbles as "null". The
        // Add Reviewer form computes them itself via HasReviewDueDate; do the
        // same so a seeded assignment matches a real one.
        [$reviewDueDate, $responseDueDate] = $this->getDueDates($request->getContext());

        foreach (array_slice($reviewers, 0, $this->reviewerCount) as $reviewer) {
            $editorAction->addReviewer(
                $request,
                $submission,
                $reviewer->getId(),
                $reviewRound,
                Core::getCurrentDate($reviewDueDate),
                Core::getCurrentDate($responseDueDate),
                null    // reviewMethod — the journal default
            );

            $assignment = Repo::reviewAssignment()->getCollector()
                ->filterByReviewRoundIds([$reviewRound->getId()])
                ->filterByReviewerIds([$reviewer->getId()])
                ->getMany()
                ->first();
            if ($assignment) {
                // ReviewerForm::execute writes these three straight after
                // addReviewer(). dateNotified is what makes the assignment read
                // as "awaiting response" rather than an un-sent invitation, so
                // without it the dashboard cannot compute a reviewer status.
                Repo::reviewAssignment()->edit($assignment, [
                    'dateNotified' => Core::getCurrentDate(),
                    'reviewFormId' => null,
                    'considered' => ReviewAssignment::REVIEW_ASSIGNMENT_NEW,
                ]);
                $assignments[] = Repo::reviewAssignment()->get($assignment->getId());
            }
        }

        return $assignments;
    }

    /**
     * Decisions and reviewer invitations both read the "current" user and
     * journal off the request. A CLI tool has neither, so install them:
     *
     *   - Registry 'user' is what PKPRequest::getUser() consults first, and
     *     EditorAction/Repo::decision() use it for the event-log actor.
     *   - The router's context is what decision notifications resolve against.
     *   - Generic plugins register per-context, and CLI has no context at
     *     load time, so post45Editorial must be re-registered or its custom
     *     decision (998) will not resolve. See OJS-DEV-NOTES.md.
     */
    private function bootstrapRequestContext($context, $editor): void
    {
        Registry::set('user', $editor);
        Application::get()->getRequest()->getRouter()->_context = $context;

        // EditorAction::addReviewer emails the reviewer unless `skipEmail` is
        // set, reading the outgoing template from `template` — a request var a
        // CLI run has no way to supply, so the send dies on a null key. Suppress
        // it: "Do not send email to reviewer" is a real checkbox on the Add
        // Reviewer form, so a seed that skips it is still a reachable state.
        // PKPRequest memoizes $_GET + $_POST into $_requestVars on first access,
        // and bootstrapping has already read one, so setting $_GET alone is too
        // late — clear the memo so it is rebuilt.
        $_GET['skipEmail'] = 1;
        Application::get()->getRequest()->_requestVars = null;

        $plugin = PluginRegistry::getPlugin('generic', 'post45editorialplugin');
        if ($plugin) {
            $plugin->register('generic', $plugin->getPluginPath(), $context->getId());
        } else {
            fwrite(STDERR, "NOTE: post45Editorial is not enabled; the desk-revision state will be skipped.\n");
        }
    }

    /**
     * Give the editor a stage assignment on the submission, the way the
     * "Assign" action in the workflow does.
     *
     * Without this a seeded submission sits in the Unassigned queue. That IS a
     * reachable state, but it is the wrong default for a fixture: most
     * editor-facing questions are about a submission somebody owns, and a few
     * panels key off the viewing editor being assigned.
     */
    private function assignEditor(int $submissionId, $context, $editor): void
    {
        // Use a group the editor actually belongs to, rather than any
        // editorial group in the journal — a stage assignment naming a group
        // the user isn't a member of is not a state the UI can produce.
        $group = Repo::userGroup()
            ->userUserGroups($editor->getId(), $context->getId())
            ->first(fn ($g) => in_array(
                (int) $g->roleId,
                [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
                true
            ));
        if (!$group) {
            return;
        }

        Repo::stageAssignment()->build(
            $submissionId,
            $group->id,
            $editor->getId(),
            false,  // recommendOnly  — a full decision-maker
            true    // canChangeMetadata
        );
    }

    /**
     * Any user holding an editorial role in this journal. Decisions need an
     * actor, and the seeded history reads more honestly with a real editor
     * than with the author.
     */
    private function resolveEditorUser($context)
    {
        $editor = Repo::user()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
            ->getMany()
            ->first();

        if (!$editor) {
            fwrite(STDERR, "No user with an editorial role in '" . $context->getPath() . "'. Create one first.\n");
            exit(1);
        }
        return $editor;
    }

    /** Users holding the Reviewer role in this journal. */
    private function resolveReviewers($context): array
    {
        return Repo::user()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByRoleIds([Role::ROLE_ID_REVIEWER])
            ->getMany()
            ->values()
            ->all();
    }

}

$tool = new CreateTestSubmissionsTool($argv ?? []);
$tool->execute();
