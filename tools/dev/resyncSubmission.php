<?php

/**
 * @file tools/dev/resyncSubmission.php
 *
 * Force a Notion sync for one submission by dispatching SyncArticleJob
 * directly. Written for the post-cutover audit workflow: after fixing a
 * derivation bug on the sync side (or after a manual correction on the OJS
 * side that no natural editorial trigger caught), you want the Notion page
 * to catch up NOW rather than waiting for the next editorial gesture.
 *
 * ## What ordinarily triggers a sync
 *
 * Editorial actions fire the sync — see ArticleSyncTriggers::onSubmissionSubmitted /
 * onDecisionAdded / onPublicationEdited / onEditorialStateChanged. Most
 * bug-fix reruns are covered by re-saving a synced Publication field (title,
 * sectionId, etc.) in the OJS UI. This tool is for the case where you'd
 * rather not touch editorial state to trigger a mechanical resync — e.g.,
 * verifying a resolver derivation change without appearing in the audit log
 * as an editor edit.
 *
 * ## Not a bulk tool
 *
 * One submission per invocation, deliberately. Bulk resync (every populated
 * article, every ledgered entity) is G4's "full-scan drift reconciliation"
 * territory — its scope + failure handling belong in a dedicated utility,
 * not a piggyback flag here.
 *
 * ## What sync does with a redundant dispatch
 *
 * DriftDetector compares desired vs. actual vs. baseline per property. If
 * nothing changed, every property classifies IN_SYNC and the synchronizer
 * skips the API call. So a resync on an up-to-date submission is cheap and
 * a resync on a drifted one writes only the changed properties + journals
 * any MANUAL_EDIT_OVERWRITE. Safe to run repeatedly.
 *
 * Usage:
 *   php tools/dev/resyncSubmission.php --submission-id=<int>
 *
 * Then drain jobs so Notion actually catches up:
 *   php lib/pkp/tools/jobs.php run
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\post45NotionSync\classes\job\SyncArticleJob;
use PKP\cliTool\CommandLineTool;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;

require(dirname(__FILE__) . '/../bootstrap.php');

class ResyncSubmissionTool extends CommandLineTool
{
    private ?int $submissionId = null;

    private $context;

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $this->usage();
                exit(0);
            }
            if (preg_match('/^--submission-id=(\d+)$/', $arg, $m)) {
                $this->submissionId = (int) $m[1];
                continue;
            }
            $this->die("Unrecognized argument: {$arg}\nSee --help.");
        }

        if ($this->submissionId === null) {
            $this->die('--submission-id=<int> is required. See --help.');
        }
    }

    public function usage(): void
    {
        echo <<<TXT
Force-dispatch a Notion sync for one submission.

Usage: {$this->scriptName} --submission-id=<int>

Then drain the queue so Notion catches up:
  php lib/pkp/tools/jobs.php run

TXT;
    }

    public function execute(): void
    {
        $this->installContext();

        $submission = Repo::submission()->get($this->submissionId);
        if (!$submission) {
            $this->die("No submission with id={$this->submissionId}.");
        }
        if ((int) $submission->getData('contextId') !== (int) $this->context->getId()) {
            $this->die(sprintf(
                'Submission %d belongs to context %d, not %d.',
                $this->submissionId,
                (int) $submission->getData('contextId'),
                (int) $this->context->getId()
            ));
        }

        $title = $submission->getCurrentPublication()?->getLocalizedFullTitle(null, 'text');
        echo "Submission #{$this->submissionId}\n";
        echo '  Title:   ' . ($title ?: '(no title)') . "\n";
        echo '  Stage:   ' . (int) $submission->getData('stageId') . "\n";
        echo '  Status:  ' . (int) $submission->getData('status') . "\n";

        dispatch(new SyncArticleJob(
            (int) $this->context->getId(),
            $this->submissionId
        ));

        echo "\nQueued SyncArticleJob. Drain with:\n";
        echo "  php lib/pkp/tools/jobs.php run\n";
    }

    /**
     * Generic plugins register per-context, and CLI tools have no request
     * context, so post45NotionSync would otherwise skip its register()
     * entirely. Not strictly necessary for a direct dispatch() (we bypass
     * the trigger hooks), but the queue worker will need the plugin's
     * bindings when the job runs — installing the context here keeps the
     * setup symmetrical with the other reconciliation tools and safe if
     * the job is drained in the same process. See OJS-DEV-NOTES worker-
     * context gotcha.
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
        if (!$sync) {
            $this->die('post45NotionSync plugin not registered. Enable it before running this tool.');
        }
        $sync->register('generic', $sync->getPluginPath(), $this->context->getId());
    }

    private function die(string $msg): never
    {
        fwrite(STDERR, "ERROR: {$msg}\n");
        exit(1);
    }
}

(new ResyncSubmissionTool($argv ?? []))->execute();
