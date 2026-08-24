<?php

// Throwaway: count articles at Decision=Pre-PR Revision (currently)

use APP\plugins\generic\post45NotionSync\classes\notion\NotionClient;
use PKP\cliTool\CommandLineTool;
use PKP\plugins\PluginRegistry;

require(dirname(__FILE__) . '/../bootstrap.php');

class CountPrePr extends CommandLineTool
{
    public function execute(): void
    {
        $plugin = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        $token = (string) $plugin->getSetting(1, 'integrationToken');
        $dbId = (string) $plugin->getSetting(1, 'articlesDatabaseId');
        $client = new NotionClient($token);

        $response = $client->queryDatabase($dbId, [
            'filter' => [
                'and' => [
                    ['property' => 'Archived', 'checkbox' => ['equals' => false]],
                    ['property' => 'Decision', 'select' => ['equals' => 'Pre-PR Revision']],
                ],
            ],
            'page_size' => 100,
        ]);
        echo 'Pre-PR Revision articles: ', count($response['results']), "\n";
        foreach ($response['results'] as $p) {
            $title = '';
            foreach ($p['properties'] as $pName => $pVal) {
                if (($pVal['type'] ?? '') === 'title') {
                    $title = trim(implode('', array_map(fn ($t) => $t['plain_text'] ?? '', $pVal['title'] ?? [])));
                }
            }
            $review = $p['properties']['Review Status']['status']['name'] ?? '?';
            echo '  - ', substr($p['id'], 0, 8), ' [Review=', $review, '] ', substr($title, 0, 80), "\n";
        }
    }
}
(new CountPrePr($argv ?? []))->execute();
