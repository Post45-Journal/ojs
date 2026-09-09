<?php

/**
 * @file tools/dev/checkNotificationList.php
 *
 * Cross-check a launch-announcement/onboarding CSV against OJS state so the
 * mail-merge doesn't (a) address rows for authors/reviewers who don't
 * actually have OJS accounts, or (b) silently miss OJS people who should
 * have been on the list.
 *
 * Written for the pre-launch pass where the operator has curated a
 * spreadsheet in Notion of who needs to be onboarded to submissions.post45,
 * and needs to know: does OJS's view of active-authors + active-reviewers
 * agree, and if not, where's the drift.
 *
 * ## Input CSV
 *
 * Header row required. Column names are matched case-insensitively; column
 * order doesn't matter; extra columns are preserved verbatim in the output
 * so the operator can round-trip through this tool without losing sheet
 * state (like a Notes or OJS Password column already filled in).
 *
 * Recognized columns (all optional except Article + one of Email/Name):
 *   Article       Title of the article — sheet's version may carry Notion's
 *                 " (https://app.notion.com/…)" URL suffix from the Notion
 *                 export; the normalizer strips it before matching.
 *   Category      "Author" or "Reviewer" (case-insensitive). Restricts the
 *                 OJS lookup to the matching role — if omitted, both roles
 *                 are searched.
 *   Name          Free-form. Used as a fallback join key when Email doesn't
 *                 match; match is case-insensitive substring on the family
 *                 name (last whitespace-separated token), which is more
 *                 robust to given-name variants than full-name compare.
 *   Email         Strongest join key. Normalized (lowercase, `mailto:` prefix
 *                 stripped, whitespace trimmed) before matching.
 *
 * ## Output
 *
 * Emits an enriched CSV to stdout — every input row, unmodified, followed
 * by five OJS-side columns:
 *   submission_id, ojs_user_id, ojs_email, ojs_last_login, match_status
 *
 * `match_status` is one of:
 *   MATCH             Article + email found and mapped to a real OJS user.
 *   MATCH_BY_NAME     Article matched; email didn't but the family name did
 *                     (likely: sheet has a different email than OJS —
 *                     eyeball before trusting).
 *   NO_SUBMISSION     Article title didn't match any OJS submission.
 *   NO_PERSON         Article matched but no author/reviewer in the row's
 *                     Category corresponds to the given email or name.
 *   NO_OJS_USER       Author record exists on the submission but has no
 *                     `users` row with a matching email — real hole; that
 *                     author can't receive an OJS-side notification at all.
 *
 * Then a trailing section emitted to stdout (blank line + header row):
 *
 *   --- OJS PEOPLE NOT IN SHEET ---
 *
 * followed by every OJS author + active reviewer whose (article, email) was
 * NOT touched by any sheet row. Fresh accounts (last_login IS NULL) here are
 * the ones most likely to be actual misses — non-null last_login could mean
 * admin login-as (per the setTempPasswords memory), so don't panic-add them.
 *
 * A one-line summary is printed to stderr so a quick `| wc -l` on the file
 * isn't the only status signal.
 *
 * ## Output location
 *
 * Redirect stdout and --missing-out= to files under `temp/` — that path is
 * the repo's gitignored scratch directory, so the enriched CSV (real
 * emails; live temp credentials if paired with a setTempPasswords dump)
 * never risks a stray `git add`.
 *
 * Usage:
 *   php tools/dev/checkNotificationList.php <input.csv> > temp/enriched.csv
 *   php tools/dev/checkNotificationList.php <input.csv> \
 *       --missing-out=temp/missing.csv > temp/enriched.csv
 */

use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;

require(dirname(__FILE__) . '/../bootstrap.php');

class CheckNotificationListTool extends CommandLineTool
{
    private ?string $inputPath = null;
    private ?string $missingOutPath = null;

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $this->usage();
                exit(0);
            }
            if (preg_match('/^--missing-out=(.+)$/', $arg, $m)) {
                $this->missingOutPath = $m[1];
                continue;
            }
            if (str_starts_with($arg, '--')) {
                $this->die("Unrecognized flag: {$arg}\nSee --help.");
            }
            if ($this->inputPath === null) {
                $this->inputPath = $arg;
                continue;
            }
            $this->die("Extra positional argument: {$arg}\nSee --help.");
        }

        if ($this->inputPath === null) {
            $this->die("Missing input CSV path.\nSee --help.");
        }
        if (!is_readable($this->inputPath)) {
            $this->die("Cannot read input CSV: {$this->inputPath}");
        }
    }

    public function usage(): void
    {
        echo <<<TXT
Cross-check an announcement/onboarding CSV against OJS. See file docblock
for full column semantics + output format.

Usage: {$this->scriptName} <input.csv> [--missing-out=PATH] > temp/enriched.csv

Positional:
  <input.csv>           CSV with a header row. Recognized columns
                        (case-insensitive, any order): Article, Category,
                        Name, Email. Extra columns are preserved verbatim.

Options:
  --missing-out=PATH    Also write "OJS people not in sheet" section as its
                        own CSV to PATH (in addition to appending it to
                        stdout). Convenient for isolating that pass.

Redirect stdout and --missing-out= into `temp/` — that path is the repo's
gitignored scratch directory, so the enriched CSV (real emails; live temp
credentials if paired with a setTempPasswords dump) can't be accidentally
`git add`-ed.

TXT;
    }

    public function execute(): void
    {
        $sheet = $this->readSheet($this->inputPath);
        $ojs = $this->buildOjsIndex();

        $out = fopen('php://stdout', 'w');
        $missingOut = $this->missingOutPath !== null ? fopen($this->missingOutPath, 'w') : null;

        $enrichedHeader = array_merge(
            $sheet['header'],
            ['submission_id', 'ojs_user_id', 'ojs_email', 'ojs_last_login', 'match_status']
        );
        fputcsv($out, $enrichedHeader);

        $matchedOjsKeys = [];
        $counts = ['MATCH' => 0, 'MATCH_BY_NAME' => 0, 'NO_SUBMISSION' => 0, 'NO_PERSON' => 0, 'NO_OJS_USER' => 0];

        foreach ($sheet['rows'] as $row) {
            $result = $this->lookup($row, $sheet['colIdx'], $ojs);
            $counts[$result['status']]++;
            if ($result['ojs_key'] !== null) {
                $matchedOjsKeys[$result['ojs_key']] = true;
            }
            fputcsv($out, array_merge($row, [
                $result['submission_id'] ?? '',
                $result['ojs_user_id'] ?? '',
                $result['ojs_email'] ?? '',
                $result['ojs_last_login'] ?? '',
                $result['status'],
            ]));
        }

        // Reverse pass: OJS entries the sheet didn't touch.
        $missingHeader = ['article', 'submission_id', 'category', 'name', 'email', 'ojs_user_id', 'ojs_last_login', 'notes'];
        fputcsv($out, []);
        fputcsv($out, ['--- OJS PEOPLE NOT IN SHEET ---']);
        fputcsv($out, $missingHeader);
        if ($missingOut !== null) {
            fputcsv($missingOut, $missingHeader);
        }

        $missingCount = 0;
        foreach ($ojs['rows'] as $key => $r) {
            if (isset($matchedOjsKeys[$key])) {
                continue;
            }
            $row = [
                $r['article'],
                $r['submission_id'],
                $r['category'],
                $r['name'],
                $r['email'] ?? '',
                $r['ojs_user_id'] ?? '',
                $r['ojs_last_login'] ?? '',
                $r['notes'],
            ];
            fputcsv($out, $row);
            if ($missingOut !== null) {
                fputcsv($missingOut, $row);
            }
            $missingCount++;
        }

        fclose($out);
        if ($missingOut !== null) {
            fclose($missingOut);
        }

        fwrite(STDERR, sprintf(
            "\nSheet rows: %d  |  MATCH %d, MATCH_BY_NAME %d, NO_SUBMISSION %d, NO_PERSON %d, NO_OJS_USER %d\n"
                . "OJS people not in sheet: %d\n",
            count($sheet['rows']),
            $counts['MATCH'],
            $counts['MATCH_BY_NAME'],
            $counts['NO_SUBMISSION'],
            $counts['NO_PERSON'],
            $counts['NO_OJS_USER'],
            $missingCount
        ));
    }

    // ---------------------------------------------------------------------
    // Sheet reading
    // ---------------------------------------------------------------------

    /**
     * @return array{header: string[], colIdx: array<string,int>, rows: array<int, string[]>}
     */
    private function readSheet(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->die("Cannot open input CSV: {$path}");
        }

        $header = fgetcsv($fh);
        if ($header === false || $header === null) {
            $this->die('Input CSV has no header row.');
        }
        // Trim BOM off the first cell — Excel/Sheets exports often prepend one.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        $colIdx = [];
        foreach ($header as $i => $name) {
            $key = strtolower(trim((string) $name));
            if ($key !== '') {
                $colIdx[$key] = $i;
            }
        }

        // Article + at least one of Email/Name is required to do any
        // matching at all. Fail early with a useful message.
        if (!isset($colIdx['article'])) {
            $this->die("Input CSV missing required 'Article' column. Got: " . implode(', ', $header));
        }
        if (!isset($colIdx['email']) && !isset($colIdx['name'])) {
            $this->die("Input CSV must have at least one of 'Email' or 'Name' to match against OJS.");
        }

        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            // Pad short rows so column indexing never OOBs.
            while (count($row) < count($header)) {
                $row[] = '';
            }
            $rows[] = $row;
        }
        fclose($fh);

        return ['header' => $header, 'colIdx' => $colIdx, 'rows' => $rows];
    }

    // ---------------------------------------------------------------------
    // OJS-side index
    // ---------------------------------------------------------------------

    /**
     * Pulls every author + every reviewer on an active submission and builds
     * lookup tables keyed for the sheet-to-OJS match.
     *
     * @return array{
     *   rows: array<string, array<string,mixed>>,
     *   byArticleEmailAuthor: array<string, string>,
     *   byArticleEmailReviewer: array<string, string>,
     *   byArticleFamilyAuthor: array<string, string>,
     *   byArticleFamilyReviewer: array<string, string>,
     * }
     */
    private function buildOjsIndex(): array
    {
        $out = [
            'rows' => [],
            'byArticleEmailAuthor' => [],
            'byArticleEmailReviewer' => [],
            'byArticleFamilyAuthor' => [],
            'byArticleFamilyReviewer' => [],
        ];

        $authors = DB::select("
            SELECT
              ptit.setting_value AS article,
              s.submission_id AS submission_id,
              TRIM(CONCAT(COALESCE(gn.setting_value,''), ' ', COALESCE(fn.setting_value,''))) AS name,
              fn.setting_value AS family_name,
              a.email AS author_email,
              u.user_id AS ojs_user_id,
              u.email AS ojs_email,
              u.date_last_login AS ojs_last_login
            FROM submissions s
            JOIN publications p ON p.publication_id = s.current_publication_id
            JOIN publication_settings ptit ON ptit.publication_id = p.publication_id
                                          AND ptit.setting_name = 'title' AND ptit.locale = 'en'
            JOIN authors a ON a.publication_id = p.publication_id
            LEFT JOIN author_settings gn ON gn.author_id = a.author_id
                                        AND gn.setting_name = 'givenName' AND gn.locale = 'en'
            LEFT JOIN author_settings fn ON fn.author_id = a.author_id
                                        AND fn.setting_name = 'familyName' AND fn.locale = 'en'
            LEFT JOIN users u ON LOWER(u.email) = LOWER(a.email)
            WHERE s.status = 1
        ");

        foreach ($authors as $r) {
            $article = $this->normalizeTitle($r->article);
            $family = $this->familyToken($r->name, $r->family_name);
            $email = $this->normalizeEmail($r->ojs_email ?? $r->author_email);

            $key = 'author|' . $r->submission_id . '|' . ($r->ojs_user_id ?? 'noUser') . '|' . $email;
            $out['rows'][$key] = [
                'article' => $r->article,
                'submission_id' => (int) $r->submission_id,
                'category' => 'Author',
                'name' => $r->name,
                'email' => $r->ojs_email ?? $r->author_email,
                'ojs_user_id' => $r->ojs_user_id !== null ? (int) $r->ojs_user_id : null,
                'ojs_last_login' => $r->ojs_last_login,
                'notes' => $r->ojs_user_id === null ? 'author has no OJS user with matching email' : '',
            ];

            if ($email !== '') {
                $out['byArticleEmailAuthor'][$article . '|' . $email] = $key;
            }
            if ($family !== '') {
                $out['byArticleFamilyAuthor'][$article . '|' . $family] = $key;
            }
        }

        $reviewers = DB::select("
            SELECT
              ptit.setting_value AS article,
              s.submission_id AS submission_id,
              TRIM(CONCAT(COALESCE(gn.setting_value,''), ' ', COALESCE(fn.setting_value,''))) AS name,
              fn.setting_value AS family_name,
              u.user_id AS ojs_user_id,
              u.email AS ojs_email,
              u.date_last_login AS ojs_last_login,
              ra.round AS review_round,
              ra.cancelled,
              ra.declined,
              ra.date_completed
            FROM submissions s
            JOIN publications p ON p.publication_id = s.current_publication_id
            JOIN publication_settings ptit ON ptit.publication_id = p.publication_id
                                          AND ptit.setting_name = 'title' AND ptit.locale = 'en'
            JOIN review_assignments ra ON ra.submission_id = s.submission_id
            JOIN users u ON u.user_id = ra.reviewer_id
            LEFT JOIN user_settings gn ON gn.user_id = u.user_id
                                      AND gn.setting_name = 'givenName' AND gn.locale = 'en'
            LEFT JOIN user_settings fn ON fn.user_id = u.user_id
                                      AND fn.setting_name = 'familyName' AND fn.locale = 'en'
            WHERE s.status = 1
        ");

        foreach ($reviewers as $r) {
            $article = $this->normalizeTitle($r->article);
            $family = $this->familyToken($r->name, $r->family_name);
            $email = $this->normalizeEmail($r->ojs_email);

            $key = 'reviewer|' . $r->submission_id . '|' . $r->ojs_user_id;
            // A reviewer may have multiple review_assignments (rounds); collapse
            // to one OJS row per (submission, user) and note the state span.
            if (isset($out['rows'][$key])) {
                $out['rows'][$key]['notes'] = trim($out['rows'][$key]['notes'] . ' + '
                    . $this->reviewStateNote($r), ' +');
                continue;
            }
            $out['rows'][$key] = [
                'article' => $r->article,
                'submission_id' => (int) $r->submission_id,
                'category' => 'Reviewer',
                'name' => $r->name,
                'email' => $r->ojs_email,
                'ojs_user_id' => (int) $r->ojs_user_id,
                'ojs_last_login' => $r->ojs_last_login,
                'notes' => $this->reviewStateNote($r),
            ];

            if ($email !== '') {
                $out['byArticleEmailReviewer'][$article . '|' . $email] = $key;
            }
            if ($family !== '') {
                $out['byArticleFamilyReviewer'][$article . '|' . $family] = $key;
            }
        }

        return $out;
    }

    private function reviewStateNote(object $r): string
    {
        $bits = [];
        if ((int) $r->cancelled === 1) {
            $bits[] = '[cancelled]';
        }
        if ((int) $r->declined === 1) {
            $bits[] = '[declined]';
        }
        $bits[] = $r->date_completed !== null ? '[completed]' : '[in-progress]';
        $bits[] = 'round ' . (int) $r->review_round;
        return implode(' ', $bits);
    }

    // ---------------------------------------------------------------------
    // Row lookup
    // ---------------------------------------------------------------------

    /**
     * @param string[] $row
     * @param array<string,int> $colIdx
     * @param array<string,mixed> $ojs
     *
     * @return array{
     *   status: string,
     *   ojs_key: ?string,
     *   submission_id?: int,
     *   ojs_user_id?: int,
     *   ojs_email?: string,
     *   ojs_last_login?: ?string,
     * }
     */
    private function lookup(array $row, array $colIdx, array $ojs): array
    {
        $rawArticle = $row[$colIdx['article']] ?? '';
        $article = $this->normalizeTitle($rawArticle);
        $email = isset($colIdx['email']) ? $this->normalizeEmail($row[$colIdx['email']] ?? '') : '';
        $name = isset($colIdx['name']) ? (string) ($row[$colIdx['name']] ?? '') : '';
        $family = $this->familyToken($name, null);
        $category = isset($colIdx['category']) ? strtolower(trim((string) ($row[$colIdx['category']] ?? ''))) : '';

        if ($article === '') {
            return ['status' => 'NO_SUBMISSION', 'ojs_key' => null];
        }

        // Determine which lookup tables to try, in what order.
        $emailTables = [];
        $familyTables = [];
        if ($category === 'author') {
            $emailTables = ['byArticleEmailAuthor'];
            $familyTables = ['byArticleFamilyAuthor'];
        } elseif ($category === 'reviewer') {
            $emailTables = ['byArticleEmailReviewer'];
            $familyTables = ['byArticleFamilyReviewer'];
        } else {
            // Unknown/empty category: search both, author-first (author is more
            // common in sheets).
            $emailTables = ['byArticleEmailAuthor', 'byArticleEmailReviewer'];
            $familyTables = ['byArticleFamilyAuthor', 'byArticleFamilyReviewer'];
        }

        // Prove the article exists at all so we can distinguish "wrong title"
        // from "right title, wrong person" — both branches of the OR need only
        // one non-empty entry to prove it.
        $articleExists = $this->articleExists($article, $ojs);

        if ($email !== '') {
            foreach ($emailTables as $t) {
                if (isset($ojs[$t][$article . '|' . $email])) {
                    $key = $ojs[$t][$article . '|' . $email];
                    return $this->hitFromKey($ojs, $key, 'MATCH');
                }
            }
        }

        if ($family !== '') {
            foreach ($familyTables as $t) {
                if (isset($ojs[$t][$article . '|' . $family])) {
                    $key = $ojs[$t][$article . '|' . $family];
                    return $this->hitFromKey($ojs, $key, 'MATCH_BY_NAME');
                }
            }
        }

        if (!$articleExists) {
            return ['status' => 'NO_SUBMISSION', 'ojs_key' => null];
        }
        return ['status' => 'NO_PERSON', 'ojs_key' => null];
    }

    /**
     * @param array<string,mixed> $ojs
     */
    private function articleExists(string $normalizedArticle, array $ojs): bool
    {
        foreach (['byArticleEmailAuthor', 'byArticleEmailReviewer', 'byArticleFamilyAuthor', 'byArticleFamilyReviewer'] as $t) {
            foreach ($ojs[$t] as $k => $_) {
                if (str_starts_with($k, $normalizedArticle . '|')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $ojs
     *
     * @return array{status:string, ojs_key:string, submission_id:int, ojs_user_id:?int, ojs_email:string, ojs_last_login:?string}
     */
    private function hitFromKey(array $ojs, string $key, string $status): array
    {
        $r = $ojs['rows'][$key];
        // If the sheet says "author" and the joined OJS row has no user
        // (author record exists but no matching users.email), that's the
        // "hole" case — surface it as NO_OJS_USER even though we matched
        // on the author record.
        if ($status === 'MATCH' && $r['category'] === 'Author' && $r['ojs_user_id'] === null) {
            $status = 'NO_OJS_USER';
        }
        return [
            'status' => $status,
            'ojs_key' => $key,
            'submission_id' => $r['submission_id'],
            'ojs_user_id' => $r['ojs_user_id'],
            'ojs_email' => (string) ($r['email'] ?? ''),
            'ojs_last_login' => $r['ojs_last_login'],
        ];
    }

    // ---------------------------------------------------------------------
    // Normalizers
    // ---------------------------------------------------------------------

    /**
     * Reduce a title from either the sheet (may carry a trailing
     * "(https://app.notion.com/...)" URL) or OJS (clean) to a stable key:
     * lowercased, whitespace-collapsed, curly quotes flattened, URL suffix
     * stripped.
     */
    private function normalizeTitle(string $s): string
    {
        $s = trim($s);
        // Strip a trailing " (https://…)" that Notion's plain-text export
        // appends to article-title cells.
        $s = preg_replace('/\s*\((?:https?:\/\/)[^)]*\)\s*$/u', '', $s);
        // Flatten curly quotes → straight, so "Jackson's" == "Jackson's".
        $s = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"],
            ["'", "'", '"', '"', '-', '-'],
            $s
        );
        $s = mb_strtolower($s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private function normalizeEmail(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/^mailto:/i', '', $s);
        return strtolower($s);
    }

    /**
     * The reliable-across-format token for name matching: last whitespace-
     * separated word of the "family_name if we have it, else full name".
     * Given-name variants (initials, middle names, hyphenated) are noisier;
     * family names are more stable across data sources.
     */
    private function familyToken(?string $fullName, ?string $familyName): string
    {
        $s = trim((string) ($familyName !== null && $familyName !== '' ? $familyName : $fullName));
        if ($s === '') {
            return '';
        }
        $s = str_replace(["\u{2019}", "\u{2018}"], "'", $s);
        $parts = preg_split('/\s+/u', $s);
        $last = end($parts);
        return mb_strtolower(trim((string) $last));
    }

    private function die(string $msg): void
    {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
}

$tool = new CheckNotificationListTool($argv);
$tool->execute();
