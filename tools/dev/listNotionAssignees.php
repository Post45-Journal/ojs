<?php

/**
 * @file tools/dev/listNotionAssignees.php
 *
 * Enumerate every distinct Notion `Assigned to` member across the Articles DB,
 * resolve each to a real Notion user (name + email via GET /v1/users/{id}),
 * try to email-match against OJS, and print a starter pairings JSON for the
 * post45NotionSync plugin's "OJS user → Notion member pairings" setting.
 *
 * WHY THIS EXISTS. `NotionClient::listAllUsers` (GET /v1/users) returns only
 * workspace MEMBERS, not guests. Post45's workspace is one member + everyone
 * else guest, so AssignedToResolver's email-match path is effectively dead —
 * every non-primary editor needs an explicit pairing. Getting those pairings
 * by hand ("find each Notion id, paste in JSON") is tedious enough to be
 * error-prone; this script does the enumerate + retrieve + suggest step so
 * the JM can eyeball a table and paste.
 *
 * Read-only. No writes to OJS, no writes to Notion, no side effects beyond
 * the Notion GETs.
 *
 * Usage:
 *   php tools/dev/listNotionAssignees.php [--journal=path]
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

class ListNotionAssigneesTool extends CommandLineTool
{
    private const CONTEXT_ID = 1;

    public ?string $journalPath = null;

    private $context;
    private NotionClient $notion;
    private string $articlesDatabaseId;
    /** @var array<int, string> OJS user id => Notion user id (already-configured pairings) */
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
Enumerate distinct Notion `Assigned to` members across the Articles DB and
resolve each to a Notion user + potential OJS user match. Prints a starter
pairings JSON for the post45NotionSync plugin settings.

Usage: {$this->scriptName} [--journal=path]

TXT;
    }

    public function execute(): void
    {
        $this->bootstrap();

        $this->info('Enumerating Articles DB (non-archived)...');
        $articles = $this->fetchArticles();
        $this->info('  ' . count($articles) . ' articles seen.');

        // notionUserId => ['articles' => [shortId, shortId, ...]]
        $assigneesByArticles = [];
        foreach ($articles as $article) {
            $shortId = substr($article['id'], 0, 8);
            $ids = $this->readPeople($article, ArticleSchema::ASSIGNED_TO);
            foreach ($ids as $notionUserId) {
                $assigneesByArticles[$notionUserId]['articles'][] = $shortId;
            }
        }

        $this->info('  ' . count($assigneesByArticles) . ' distinct assignees across articles.');

        if (empty($assigneesByArticles)) {
            $this->info('Nothing to resolve. Done.');
            return;
        }

        $this->info("\nResolving each assignee via GET /v1/users/{id}...");
        $rows = [];
        foreach (array_keys($assigneesByArticles) as $notionUserId) {
            $rows[$notionUserId] = $this->resolveOne($notionUserId, $assigneesByArticles[$notionUserId]['articles']);
        }

        $this->printTable($rows);
        $this->printSuggestedPairings($rows);
        $this->printUnresolved($rows);
    }

    // -----------------------------------------------------------------
    // bootstrap
    // -----------------------------------------------------------------

    private function bootstrap(): void
    {
        $this->context = $this->resolveContext();
        // Install request context for parity with populate + prodCleanup —
        // this script only reads, but installing keeps every code path
        // predictable if we later call a Repo method that touches it.
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

        $this->existingPairings = (new Post45NotionSyncSettingsForm($sync, self::CONTEXT_ID))->assignedToPairings();
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
    private function readPeople(array $page, string $name): array
    {
        $prop = $page['properties'][$name] ?? null;
        $items = $prop['people'] ?? [];
        return array_values(array_filter(array_column($items, 'id')));
    }

    // -----------------------------------------------------------------
    // resolution
    // -----------------------------------------------------------------

    /**
     * @param string[] $articleShortIds
     *
     * @return array{
     *   notionId: string,
     *   name: string,
     *   email: ?string,
     *   type: string,
     *   ojsUserId: ?int,
     *   matchNote: string,
     *   alreadyPaired: bool,
     *   articleCount: int,
     * }
     */
    private function resolveOne(string $notionUserId, array $articleShortIds): array
    {
        $existingOjsId = array_search($notionUserId, $this->existingPairings, true);
        $alreadyPaired = $existingOjsId !== false;

        try {
            $member = $this->notion->retrieveUser($notionUserId);
        } catch (NotionApiException $e) {
            return [
                'notionId' => $notionUserId,
                'name' => '(retrieve failed)',
                'email' => null,
                'type' => 'unknown',
                'ojsUserId' => $alreadyPaired ? (int) $existingOjsId : null,
                'matchNote' => 'GET /v1/users/{id} failed: ' . $e->getMessage(),
                'alreadyPaired' => $alreadyPaired,
                'articleCount' => count($articleShortIds),
            ];
        }

        $name = trim((string) ($member['name'] ?? '')) ?: '(unnamed)';
        $type = (string) ($member['type'] ?? 'unknown');
        // `person.email` only present when the integration has the "read user
        // information including email addresses" capability granted AND the
        // user has an email at all (bots don't).
        $email = trim((string) ($member['person']['email'] ?? '')) ?: null;

        $ojsUserId = null;
        $matchNote = '';
        if ($alreadyPaired) {
            $ojsUserId = (int) $existingOjsId;
            $matchNote = 'already in pairings';
        } elseif ($email !== null) {
            $user = Repo::user()->getByEmail($email);
            if ($user) {
                $ojsUserId = (int) $user->getId();
                $matchNote = 'email match';
            } else {
                $matchNote = 'no OJS user with this email';
            }
        } else {
            $matchNote = ($type === 'bot')
                ? 'bot user — skip'
                : 'guest with no email visible to integration; pair manually';
        }

        return [
            'notionId' => $notionUserId,
            'name' => $name,
            'email' => $email,
            'type' => $type,
            'ojsUserId' => $ojsUserId,
            'matchNote' => $matchNote,
            'alreadyPaired' => $alreadyPaired,
            'articleCount' => count($articleShortIds),
        ];
    }

    // -----------------------------------------------------------------
    // output
    // -----------------------------------------------------------------

    private function printTable(array $rows): void
    {
        echo "\n";
        printf(
            "%-38s | %-25s | %-32s | %-10s | %-4s | %s\n",
            'notion_user_id',
            'name',
            'email',
            'ojs_user',
            '#art',
            'notes'
        );
        echo str_repeat('-', 140) . "\n";
        // Sort: unresolved first (surface the work), then already-paired.
        uasort($rows, fn ($a, $b) => ($a['ojsUserId'] !== null) <=> ($b['ojsUserId'] !== null));
        foreach ($rows as $r) {
            printf(
                "%-38s | %-25s | %-32s | %-10s | %-4d | %s\n",
                $r['notionId'],
                mb_substr($r['name'], 0, 25),
                mb_substr($r['email'] ?? '—', 0, 32),
                $r['ojsUserId'] !== null ? (string) $r['ojsUserId'] : '—',
                $r['articleCount'],
                $r['matchNote'],
            );
        }
    }

    private function printSuggestedPairings(array $rows): void
    {
        // Merge existing pairings with newly-resolved ones. Existing wins on
        // conflict (a JM explicitly paired something; don't overwrite via
        // email match).
        $pairings = $this->existingPairings;
        foreach ($rows as $r) {
            if ($r['ojsUserId'] === null || $r['alreadyPaired']) {
                continue;
            }
            if (!isset($pairings[$r['ojsUserId']])) {
                $pairings[$r['ojsUserId']] = $r['notionId'];
            }
        }
        // Stable key order for diff-friendly copy-paste.
        ksort($pairings);

        echo "\n=== Suggested pairings JSON (paste into plugin settings) ===\n";
        echo json_encode(
            array_map('strval', $pairings),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    private function printUnresolved(array $rows): void
    {
        $unresolved = array_filter($rows, fn ($r) => $r['ojsUserId'] === null && $r['type'] !== 'bot');
        if (empty($unresolved)) {
            echo "\nAll non-bot assignees resolved.\n";
            return;
        }
        echo "\n=== Unresolved — need manual pairing ===\n";
        foreach ($unresolved as $r) {
            printf(
                "  %s (%s) — %s. Find their OJS user_id, add \"<id>\": \"%s\" to the pairings JSON above.\n",
                $r['notionId'],
                $r['name'],
                $r['matchNote'],
                $r['notionId'],
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

$tool = new ListNotionAssigneesTool($argv ?? []);
$tool->execute();
