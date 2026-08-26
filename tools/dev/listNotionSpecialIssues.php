<?php

/**
 * @file tools/dev/listNotionSpecialIssues.php
 *
 * Enumerate every distinct Notion Special Issues page referenced from the
 * consolidated Articles DB, retrieve each page's title, and print a starter
 * pairings JSON for the post45NotionSync plugin's "Notion Special Issue → OJS
 * section pairings" setting.
 *
 * WHY THIS EXISTS. There is no auto-match between an OJS section and a Notion
 * Special Issues page — the two identity spaces are entirely disjoint. The JM
 * curates the pairing by hand once at cutover (and adds one entry per new SI
 * thereafter). This script surfaces which Notion SI pages are actually
 * referenced by non-archived articles and pre-computes the JSON shape, so the
 * JM just fills in the section id for each row and pastes.
 *
 * OJS-side match note. If a pairing is already configured for a Notion SI
 * page, this script reports the paired OJS section id + title alongside the
 * Notion page title so the JM can double-check that the section names line up.
 * When they don't, it's a hint that the pairing points at the wrong section
 * (typo, or a duplicate section was created).
 *
 * Read-only. No writes to OJS, no writes to Notion, no side effects beyond
 * the Notion GETs.
 *
 * Usage:
 *   php tools/dev/listNotionSpecialIssues.php [--journal=path]
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\post45NotionSync\classes\mapping\ArticleSchema;
use APP\plugins\generic\post45NotionSync\classes\notion\NotionApiException;
use APP\plugins\generic\post45NotionSync\classes\notion\NotionClient;
use APP\plugins\generic\post45NotionSync\classes\settings\Post45NotionSyncSettingsForm;
use PKP\cliTool\CommandLineTool;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;

require(dirname(__FILE__) . '/../bootstrap.php');

class ListNotionSpecialIssuesTool extends CommandLineTool
{
    private const CONTEXT_ID = 1;

    public ?string $journalPath = null;

    private $context;
    private NotionClient $notion;
    private string $articlesDatabaseId;
    /** @var array<string, int> Notion SI page id => OJS section id (already-configured) */
    private array $existingPairings = [];

    public function __construct($argv = [])
    {
        parent::__construct($argv);
        foreach ($this->argv as $arg) {
            if (preg_match('/^--journal=(.+)$/', $arg, $m)) {
                $this->journalPath = $m[1];
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
Enumerate distinct Notion Special Issue pages referenced by non-archived
Articles and print a starter pairings JSON for the post45NotionSync plugin
settings.

Usage: {$this->scriptName} [--journal=path]

TXT;
    }

    public function execute(): void
    {
        $this->bootstrap();

        $this->info('Enumerating Articles DB (non-archived)...');
        $articles = $this->fetchArticles();
        $this->info('  ' . count($articles) . ' articles seen.');

        // notionSiPageId => ['articles' => [shortId, ...]]
        $siByArticles = [];
        foreach ($articles as $article) {
            $shortId = substr($article['id'], 0, 8);
            $ids = $this->readRelation($article, ArticleSchema::SPECIAL_ISSUE);
            foreach ($ids as $siPageId) {
                $siByArticles[$siPageId]['articles'][] = $shortId;
            }
        }

        $this->info('  ' . count($siByArticles) . ' distinct Special Issues referenced.');

        if (empty($siByArticles)) {
            $this->info('Nothing to resolve. Done.');
            return;
        }

        $this->info("\nResolving each Special Issue via GET /v1/pages/{id}...");
        $rows = [];
        foreach (array_keys($siByArticles) as $siPageId) {
            $rows[$siPageId] = $this->resolveOne($siPageId, $siByArticles[$siPageId]['articles']);
        }

        $this->printTable($rows);
        $this->printOjsSections();
        $this->printSuggestedPairings($rows);
        $this->printUnpaired($rows);
    }

    // -----------------------------------------------------------------
    // bootstrap
    // -----------------------------------------------------------------

    private function bootstrap(): void
    {
        $this->context = $this->resolveContext();
        Application::get()->getRequest()->getRouter()->_context = $this->context;

        $sync = PluginRegistry::getPlugin('generic', 'post45notionsyncplugin');
        if (!$sync) {
            $this->die('post45NotionSync plugin not registered. Enable it before running this script.');
        }
        $token = (string) $sync->getSetting(self::CONTEXT_ID, 'integrationToken');
        $this->articlesDatabaseId = trim((string) $sync->getSetting(self::CONTEXT_ID, 'articlesDatabaseId'));
        if ($token === '' || $this->articlesDatabaseId === '') {
            $this->die('post45NotionSync integrationToken / articlesDatabaseId not configured.');
        }

        $this->existingPairings = (new Post45NotionSyncSettingsForm($sync, self::CONTEXT_ID))->specialIssuePairings();
        $this->notion = new NotionClient($token);
    }

    private function resolveContext()
    {
        /** @var \APP\journal\JournalDAO $journalDao */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        if ($this->journalPath !== null) {
            $journal = $journalDao->getByPath($this->journalPath);
            if (!$journal) {
                $this->die("Journal '{$this->journalPath}' not found.");
            }
            return $journal;
        }
        $context = $journalDao->getById(self::CONTEXT_ID);
        if (!$context instanceof Context) {
            $this->die(sprintf('Context id=%d not found.', self::CONTEXT_ID));
        }
        return $context;
    }

    // -----------------------------------------------------------------
    // Notion fetch
    // -----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function fetchArticles(): array
    {
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

    /** @return string[] */
    private function readRelation(array $page, string $name): array
    {
        $prop = $page['properties'][$name] ?? null;
        $items = $prop['relation'] ?? [];
        return array_values(array_filter(array_column($items, 'id')));
    }

    private function readTitle(array $page): string
    {
        foreach ($page['properties'] ?? [] as $prop) {
            if (($prop['type'] ?? '') === 'title') {
                $parts = array_column($prop['title'] ?? [], 'plain_text');
                return trim(implode('', $parts));
            }
        }
        return '';
    }

    // -----------------------------------------------------------------
    // resolution
    // -----------------------------------------------------------------

    /**
     * @param string[] $articleShortIds
     *
     * @return array{
     *   notionId: string,
     *   title: string,
     *   sectionId: ?int,
     *   sectionTitle: ?string,
     *   note: string,
     *   alreadyPaired: bool,
     *   articleCount: int,
     * }
     */
    private function resolveOne(string $notionSiPageId, array $articleShortIds): array
    {
        $existingSectionId = $this->existingPairings[$notionSiPageId] ?? null;
        $alreadyPaired = $existingSectionId !== null;

        try {
            $page = $this->notion->retrievePage($notionSiPageId);
        } catch (NotionApiException $e) {
            return [
                'notionId' => $notionSiPageId,
                'title' => '(retrieve failed)',
                'sectionId' => $existingSectionId,
                'sectionTitle' => $alreadyPaired ? $this->sectionTitle($existingSectionId) : null,
                'note' => 'GET /v1/pages/{id} failed: ' . $e->getMessage(),
                'alreadyPaired' => $alreadyPaired,
                'articleCount' => count($articleShortIds),
            ];
        }

        $title = $this->readTitle($page) ?: '(untitled)';

        if ($alreadyPaired) {
            $sectionTitle = $this->sectionTitle($existingSectionId);
            return [
                'notionId' => $notionSiPageId,
                'title' => $title,
                'sectionId' => $existingSectionId,
                'sectionTitle' => $sectionTitle,
                'note' => $sectionTitle === null
                    ? "paired to section {$existingSectionId} BUT SECTION DOES NOT EXIST — fix"
                    : 'already paired',
                'alreadyPaired' => true,
                'articleCount' => count($articleShortIds),
            ];
        }

        return [
            'notionId' => $notionSiPageId,
            'title' => $title,
            'sectionId' => null,
            'sectionTitle' => null,
            'note' => 'no pairing — create the OJS section (with guest editors) and add the pairing',
            'alreadyPaired' => false,
            'articleCount' => count($articleShortIds),
        ];
    }

    private function sectionTitle(int $sectionId): ?string
    {
        $section = Repo::section()->get($sectionId, $this->context->getId());
        return $section ? (string) $section->getLocalizedTitle() : null;
    }

    // -----------------------------------------------------------------
    // output
    // -----------------------------------------------------------------

    private function printTable(array $rows): void
    {
        echo "\n";
        printf(
            "%-38s | %-35s | %-10s | %-25s | %-4s | %s\n",
            'notion_si_page_id',
            'notion_title',
            'section_id',
            'ojs_section_title',
            '#art',
            'notes'
        );
        echo str_repeat('-', 160) . "\n";
        // Sort: unpaired first (surface the work).
        uasort($rows, fn ($a, $b) => ($a['sectionId'] !== null) <=> ($b['sectionId'] !== null));
        foreach ($rows as $r) {
            printf(
                "%-38s | %-35s | %-10s | %-25s | %-4d | %s\n",
                $r['notionId'],
                mb_substr($r['title'], 0, 35),
                $r['sectionId'] !== null ? (string) $r['sectionId'] : '—',
                mb_substr($r['sectionTitle'] ?? '—', 0, 25),
                $r['articleCount'],
                $r['note'],
            );
        }
    }

    /**
     * List every OJS section in this journal with its numeric id, so the JM
     * has the right-hand side of the pairing to hand while editing the JSON
     * below. The section id isn't surfaced in the OJS admin UI anywhere
     * obvious — unlike users, whose edit URL carries the id — so printing
     * it here saves a DB peek.
     */
    private function printOjsSections(): void
    {
        $sections = Repo::section()->getCollector()
            ->filterByContextIds([$this->context->getId()])
            ->getMany();

        echo "\n=== OJS sections in '{$this->context->getPath()}' (id → title) ===\n";
        foreach ($sections as $section) {
            $marker = $section->getIsInactive() ? ' [inactive]' : '';
            printf(
                "  %5d  %s%s\n",
                (int) $section->getId(),
                $section->getLocalizedTitle(),
                $marker
            );
        }
    }

    private function printSuggestedPairings(array $rows): void
    {
        // Existing wins on conflict: never overwrite a JM's explicit pairing.
        $pairings = $this->existingPairings;
        foreach ($rows as $r) {
            if (!$r['alreadyPaired']) {
                // Emit a placeholder 0 so the JM sees which lines need a real
                // section id typed in. The settings-form validator rejects
                // non-positive-int values with a `badEntries` message naming
                // each key, so pasting this as-is and hitting save gives the
                // JM a checklist rather than a silent save with dead entries.
                $pairings[$r['notionId']] = 0;
            }
        }
        ksort($pairings);

        echo "\n=== Suggested pairings JSON (paste into plugin settings; replace any 0s with real section ids) ===\n";
        echo json_encode($pairings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function printUnpaired(array $rows): void
    {
        $unpaired = array_filter($rows, fn ($r) => !$r['alreadyPaired']);
        if (empty($unpaired)) {
            echo "\nAll referenced Special Issues are paired.\n";
            return;
        }
        echo "\n=== Unpaired — need an OJS section id ===\n";
        foreach ($unpaired as $r) {
            printf(
                "  %s (\"%s\") — %d article(s). Create the OJS section + guest editors, then replace 0 with the section id in the JSON above.\n",
                $r['notionId'],
                $r['title'],
                $r['articleCount'],
            );
        }
    }

    // -----------------------------------------------------------------
    // logging
    // -----------------------------------------------------------------

    private function info(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    private function die(string $message): never
    {
        fwrite(STDERR, "ERROR: {$message}\n");
        exit(1);
    }
}

$tool = new ListNotionSpecialIssuesTool($argv ?? []);
$tool->execute();
