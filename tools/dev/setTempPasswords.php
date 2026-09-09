<?php

/**
 * @file tools/dev/setTempPasswords.php
 *
 * Bulk-assign a unique random temporary password to a set of users and force
 * them to change it on first login. Written for the site-migration
 * announcement: most user accounts on this OJS install were created fresh by
 * the Notion→OJS populate run and have never been logged into by their real
 * owner, so it's safe to (re)set their password and mail them a temp cred
 * alongside the "we're live" announcement.
 *
 * ## Why not raw SQL
 *
 * OJS 3.5 hashes passwords with bcrypt via Laravel's default cost (see
 * Validation::encryptCredentials at lib/pkp/classes/security/Validation.php:230).
 * Writing a hash by hand ties the tool to a specific hashing algorithm; going
 * through the pkp-lib helper keeps this working across future rehash tweaks.
 *
 * ## Confirmation flow
 *
 * Default is preview-only: the tool prints the target user list and exits,
 * printing nothing sensitive. Pass --commit to actually write. The CSV
 * (user_id, username, email, given_name, family_name, roles, temp_password)
 * is emitted to stdout ONLY on --commit — never in preview — so temp
 * passwords are generated at the same moment they're persisted, and there's
 * no "preview dump" of passwords that were never actually installed.
 *
 * Redirect stdout to a file for the mail-merge:
 *   php tools/dev/setTempPasswords.php --commit > temp-passwords.csv
 *
 * ## Role filter
 *
 * Default target = every user who holds ANY role other than Site Admin (1)
 * or Journal Manager (16). Those two are excluded by default because
 * clobbering your own admin/manager login is the main foot-gun here. Override
 * with --include-roles or --exclude-roles (comma-separated role_id ints, see
 * lib/pkp/classes/security/Role.php).
 *
 * A user with multiple roles is kept iff at least one of their roles passes
 * the filter — so a Reviewer-who-is-also-Manager stays excluded by default.
 *
 * Usage:
 *   php tools/dev/setTempPasswords.php                            # preview
 *   php tools/dev/setTempPasswords.php --include-roles=65536,4096 # authors + reviewers only
 *   php tools/dev/setTempPasswords.php --exclude=alice,bob        # hand-protect specific usernames
 *   php tools/dev/setTempPasswords.php --user-ids=42,57           # only these users
 *   php tools/dev/setTempPasswords.php --limit=3 --commit         # smoke-test on 3 users
 *   php tools/dev/setTempPasswords.php --commit --yes > out.csv   # full run
 */

use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;
use PKP\security\Role;
use PKP\security\Validation;

require(dirname(__FILE__) . '/../bootstrap.php');

class SetTempPasswordsTool extends CommandLineTool
{
    /**
     * Character alphabet for generated temp passwords: unambiguous
     * alphanumerics (no 0/O/o, 1/l/I). 30 chars × 10 positions ≈ 49 bits
     * entropy — plenty for a one-shot credential that must be changed on
     * first login.
     */
    private const PW_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const PW_LENGTH = 10;

    /** Default excluded role_ids: Site Admin + Journal Manager. */
    private const DEFAULT_EXCLUDE_ROLES = [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER];

    /** @var int[]|null role_ids to include; if null, include-all-except-excluded */
    private ?array $includeRoles = null;
    /** @var int[] role_ids to exclude */
    private array $excludeRoles = self::DEFAULT_EXCLUDE_ROLES;
    /** @var string[] usernames to hand-exclude */
    private array $excludeUsernames = [];
    /** @var int[]|null explicit user_id whitelist */
    private ?array $userIds = null;
    private ?int $limit = null;
    private bool $commit = false;
    private bool $yes = false;

    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $arg) {
            if ($arg === '--commit') {
                $this->commit = true;
                continue;
            }
            if ($arg === '--yes' || $arg === '-y') {
                $this->yes = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $this->usage();
                exit(0);
            }

            if (preg_match('/^--include-roles=(.+)$/', $arg, $m)) {
                $this->includeRoles = $this->parseIntList($m[1], '--include-roles');
                continue;
            }
            if (preg_match('/^--exclude-roles=(.+)$/', $arg, $m)) {
                $this->excludeRoles = $this->parseIntList($m[1], '--exclude-roles');
                continue;
            }
            if (preg_match('/^--exclude=(.+)$/', $arg, $m)) {
                $this->excludeUsernames = array_values(array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
                continue;
            }
            if (preg_match('/^--user-ids=(.+)$/', $arg, $m)) {
                $this->userIds = $this->parseIntList($m[1], '--user-ids');
                continue;
            }
            if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
                $this->limit = (int) $m[1];
                continue;
            }
            $this->die("Unrecognized argument: {$arg}\nSee --help.");
        }
    }

    public function usage(): void
    {
        $roleHint = 'Role IDs (lib/pkp/classes/security/Role.php): '
            . 'SITE_ADMIN=1, MANAGER=16, SUB_EDITOR=17, ASSISTANT=4097, '
            . 'REVIEWER=4096, AUTHOR=65536, READER=1048576.';

        echo <<<TXT
Bulk-assign a unique random temporary password to a set of users, forcing a
password change on their next login (users.must_change_password=1).

Preview by default. Pass --commit to write. CSV (user_id, username, email,
given_name, family_name, roles, temp_password) is emitted to stdout ONLY on
--commit — redirect to a file for the mail-merge.

Usage: {$this->scriptName} [filters] [--commit [--yes]]

Filters (all optional; combine freely):
  --include-roles=R1,R2   Only users with ANY of these role_ids.
                          Default: all roles except the excluded ones.
  --exclude-roles=R1,R2   Skip users who hold ANY of these role_ids.
                          Default: 1 (Site Admin), 16 (Journal Manager).
  --exclude=user1,user2   Skip specific usernames (comma-separated).
  --user-ids=1,2,3        Restrict to these user_ids exactly (bypasses
                          role filters).
  --limit=N               Cap the target set at N users (for smoke tests).

Actions:
  (no flag)   Preview: print the target user list and exit. No writes,
              no passwords generated.
  --commit    Generate + persist passwords, emit CSV to stdout.
  --yes       Skip the interactive confirmation on --commit.

{$roleHint}

TXT;
    }

    public function execute(): void
    {
        $targets = $this->collectTargets();

        if ($targets === []) {
            fwrite(STDERR, "No users matched the filters.\n");
            return;
        }

        if (!$this->commit) {
            $this->printPreview($targets);
            fwrite(STDERR, "\nPreview only — no changes written. Re-run with --commit to persist.\n");
            return;
        }

        if (!$this->yes) {
            $this->printPreview($targets, STDERR);
            fwrite(STDERR, "\nAbout to reset passwords for " . count($targets) . " user(s) and set must_change_password=1.\nType 'yes' to proceed: ");
            $answer = trim((string) fgets(STDIN));
            if (strtolower($answer) !== 'yes') {
                fwrite(STDERR, "Aborted.\n");
                return;
            }
        }

        $this->writePasswords($targets);
    }

    /**
     * @return array<int, object{user_id:int, username:string, email:string, given_name:string, family_name:string, roles:string}>
     */
    private function collectTargets(): array
    {
        $q = DB::table('users as u')
            ->leftJoin('user_settings as gn', function ($j) {
                $j->on('gn.user_id', '=', 'u.user_id')
                    ->where('gn.setting_name', '=', 'givenName')
                    ->where('gn.locale', '=', 'en');
            })
            ->leftJoin('user_settings as fn', function ($j) {
                $j->on('fn.user_id', '=', 'u.user_id')
                    ->where('fn.setting_name', '=', 'familyName')
                    ->where('fn.locale', '=', 'en');
            })
            ->select('u.user_id', 'u.username', 'u.email', 'u.date_last_login', 'gn.setting_value as given_name', 'fn.setting_value as family_name')
            ->orderBy('u.user_id');

        if ($this->userIds !== null) {
            $q->whereIn('u.user_id', $this->userIds);
        } else {
            if ($this->includeRoles !== null) {
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('user_user_groups as uug')
                        ->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
                        ->whereColumn('uug.user_id', 'u.user_id')
                        ->whereIn('ug.role_id', $this->includeRoles);
                });
            }
            if ($this->excludeRoles !== []) {
                $q->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('user_user_groups as uug')
                        ->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
                        ->whereColumn('uug.user_id', 'u.user_id')
                        ->whereIn('ug.role_id', $this->excludeRoles);
                });
            }
        }

        if ($this->excludeUsernames !== []) {
            $q->whereNotIn('u.username', $this->excludeUsernames);
        }

        if ($this->limit !== null) {
            $q->limit($this->limit);
        }

        $rows = $q->get()->all();
        if ($rows === []) {
            return [];
        }

        $userIds = array_map(fn ($r) => (int) $r->user_id, $rows);
        $roleMap = $this->loadRolesFor($userIds);

        $out = [];
        foreach ($rows as $r) {
            $r->given_name = $r->given_name ?? '';
            $r->family_name = $r->family_name ?? '';
            $r->roles = implode('|', $roleMap[(int) $r->user_id] ?? []);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * @param int[] $userIds
     *
     * @return array<int, string[]> user_id => list of role labels
     */
    private function loadRolesFor(array $userIds): array
    {
        $labels = [
            Role::ROLE_ID_SITE_ADMIN => 'SiteAdmin',
            Role::ROLE_ID_MANAGER => 'Manager',
            Role::ROLE_ID_SUB_EDITOR => 'SubEditor',
            Role::ROLE_ID_ASSISTANT => 'Assistant',
            Role::ROLE_ID_REVIEWER => 'Reviewer',
            Role::ROLE_ID_AUTHOR => 'Author',
            Role::ROLE_ID_READER => 'Reader',
            Role::ROLE_ID_SUBSCRIPTION_MANAGER => 'SubscriptionManager',
        ];

        $rows = DB::table('user_user_groups as uug')
            ->join('user_groups as ug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->whereIn('uug.user_id', $userIds)
            ->select('uug.user_id', 'ug.role_id')
            ->distinct()
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            $out[$uid][] = $labels[(int) $r->role_id] ?? ('Role' . (int) $r->role_id);
        }
        foreach ($out as $uid => $roles) {
            $out[$uid] = array_values(array_unique($roles));
            sort($out[$uid]);
        }
        return $out;
    }

    /**
     * @param array<int, object> $targets
     */
    private function printPreview(array $targets, mixed $stream = STDOUT): void
    {
        fwrite($stream, sprintf("%-8s  %-22s  %-34s  %-26s  %-19s  %s\n", 'user_id', 'username', 'email', 'name', 'last_login', 'roles'));
        fwrite($stream, sprintf("%-8s  %-22s  %-34s  %-26s  %-19s  %s\n", str_repeat('-', 8), str_repeat('-', 22), str_repeat('-', 34), str_repeat('-', 26), str_repeat('-', 19), str_repeat('-', 20)));
        foreach ($targets as $r) {
            $name = trim(($r->given_name ?? '') . ' ' . ($r->family_name ?? ''));
            $lastLogin = $r->date_last_login ?? '';
            fwrite($stream, sprintf(
                "%-8d  %-22s  %-34s  %-26s  %-19s  %s\n",
                (int) $r->user_id,
                substr($r->username, 0, 22),
                substr($r->email, 0, 34),
                substr($name, 0, 26),
                $lastLogin === '' ? '(never)' : substr($lastLogin, 0, 19),
                $r->roles
            ));
        }
        $neverCount = 0;
        foreach ($targets as $r) {
            if (($r->date_last_login ?? '') === '') {
                $neverCount++;
            }
        }
        fwrite($stream, "\nTotal: " . count($targets) . " user(s); {$neverCount} never logged in.\n");
    }

    /**
     * @param array<int, object> $targets
     */
    private function writePasswords(array $targets): void
    {
        $out = fopen('php://stdout', 'w');
        fputcsv($out, ['user_id', 'username', 'email', 'given_name', 'family_name', 'date_last_login', 'roles', 'temp_password']);

        DB::beginTransaction();
        try {
            foreach ($targets as $r) {
                $temp = $this->generatePassword();
                $hash = Validation::encryptCredentials($r->username, $temp);

                DB::table('users')
                    ->where('user_id', (int) $r->user_id)
                    ->update([
                        'password' => $hash,
                        'must_change_password' => 1,
                    ]);

                fputcsv($out, [
                    (int) $r->user_id,
                    $r->username,
                    $r->email,
                    $r->given_name ?? '',
                    $r->family_name ?? '',
                    $r->date_last_login ?? '',
                    $r->roles,
                    $temp,
                ]);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fwrite(STDERR, 'Aborted mid-run, no changes written: ' . $e->getMessage() . "\n");
            throw $e;
        }

        fclose($out);
        fwrite(STDERR, 'Done. ' . count($targets) . " user(s) updated; must_change_password=1 set on each.\n");
    }

    private function generatePassword(): string
    {
        $alpha = self::PW_ALPHABET;
        $n = strlen($alpha);
        $out = '';
        for ($i = 0; $i < self::PW_LENGTH; $i++) {
            $out .= $alpha[random_int(0, $n - 1)];
        }
        return $out;
    }

    /**
     * @return int[]
     */
    private function parseIntList(string $csv, string $flagName): array
    {
        $parts = array_filter(array_map('trim', explode(',', $csv)), 'strlen');
        $ints = [];
        foreach ($parts as $p) {
            if (!ctype_digit($p)) {
                $this->die("{$flagName}: '{$p}' is not a positive integer.");
            }
            $ints[] = (int) $p;
        }
        if ($ints === []) {
            $this->die("{$flagName}: empty list.");
        }
        return array_values(array_unique($ints));
    }

    private function die(string $msg): void
    {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
}

$tool = new SetTempPasswordsTool($argv);
$tool->execute();
