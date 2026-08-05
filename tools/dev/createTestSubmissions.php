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
 * Defaults:
 *   --count=3            three test articles
 *   --stage=5            Production (fastest path to Mark Published)
 *   --journal=(first)    first enabled journal
 *   --author-email       aaron+test-author@post45.org
 *
 * Author user must already exist. The script errors out with a clear
 * message if it doesn't — creating a user requires more state (roles,
 * password) than a dev-testing seed should be responsible for.
 */

use APP\facades\Repo;
use APP\submission\Submission;
use PKP\cliTool\CommandLineTool;
use PKP\core\Core;
use PKP\db\DAORegistry;

require(dirname(__FILE__) . '/../bootstrap.php');

class CreateTestSubmissionsTool extends CommandLineTool
{
    public int $count = 3;
    public int $targetStage = 5; // WORKFLOW_STAGE_ID_PRODUCTION
    public ?string $journalPath = null;
    public string $authorEmail = 'aaron+test-author@post45.org';
    public string $authorName = 'AuthorTest Wong';

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
            . "Stages: 1=Submission 3=External Review 4=Copy Editing 5=Production (default)\n";
    }

    public function execute()
    {
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
}

$tool = new CreateTestSubmissionsTool($argv ?? []);
$tool->execute();
