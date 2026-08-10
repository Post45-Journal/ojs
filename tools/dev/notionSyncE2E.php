<?php

/**
 * @file tools/dev/notionSyncE2E.php
 *
 * Post45 — dev-only CLI driver for exercising post45NotionSync against the
 * REAL Notion workspace, one entity at a time.
 *
 * Why this exists rather than `jobs.php run`: draining the queue runs whatever
 * happens to be in it, and the People database is the team's accumulated
 * reviewer record. This runs exactly one job for exactly one id, so a live test
 * cannot spill into people nobody chose to touch.
 *
 * It deliberately does NOT change any setting. Flip `dryRun` yourself, through
 * $plugin->updateSetting() — plugin settings are cached (PluginSettingsDAO uses
 * Cache::remember), so raw SQL will not take effect.
 *
 * Usage:
 *   php tools/dev/notionSyncE2E.php person <userId>
 *   php tools/dev/notionSyncE2E.php article <submissionId>
 *   php tools/dev/notionSyncE2E.php settings
 */

use APP\plugins\generic\post45NotionSync\classes\job\SyncArticleJob;
use APP\plugins\generic\post45NotionSync\classes\job\SyncPersonJob;
use PKP\cliTool\CommandLineTool;
use PKP\plugins\PluginRegistry;

require(dirname(__FILE__) . '/../bootstrap.php');

class NotionSyncE2ETool extends CommandLineTool
{
    private const CONTEXT_ID = 1;

    public function execute(): void
    {
        $plugin = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if (!$plugin) {
            exit("post45NotionSync is not registered.\n");
        }

        $mode = $this->argv[0] ?? 'settings';

        $this->showSettings($plugin);

        if ($mode === 'settings') {
            return;
        }

        $id = (int) ($this->argv[1] ?? 0);
        if ($id <= 0) {
            exit("Usage: notionSyncE2E.php person|article <id>\n");
        }

        $job = match ($mode) {
            'person' => new SyncPersonJob(self::CONTEXT_ID, $id),
            'article' => new SyncArticleJob(self::CONTEXT_ID, $id),
            default => exit("Unknown mode '{$mode}'.\n"),
        };

        fwrite(STDERR, "\n--- running " . get_class($job) . " for id {$id} ---\n");
        $job->handle();
        fwrite(STDERR, "--- done ---\n");
    }

    private function showSettings($plugin): void
    {
        fwrite(STDERR, 'post45NotionSync settings (context ' . self::CONTEXT_ID . "):\n");
        foreach (['enableSync', 'dryRun', 'articlesDatabaseId', 'peopleDatabaseId'] as $name) {
            $value = $plugin->getSetting(self::CONTEXT_ID, $name);
            fwrite(STDERR, sprintf("  %-20s %s\n", $name, var_export($value, true)));
        }
        $token = (string) $plugin->getSetting(self::CONTEXT_ID, 'integrationToken');
        fwrite(STDERR, sprintf("  %-20s %s\n", 'integrationToken', $token === '' ? 'MISSING' : 'set'));
    }
}

(new NotionSyncE2ETool($argv ?? []))->execute();
