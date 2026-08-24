<?php

/**
 * @file tools/dev/prodInventory.php
 *
 * Read-only dump of what's in the current OJS instance. Use before a cleanup
 * pass so you know what you're about to delete.
 *
 * Prints:
 *   - submission counts by stage
 *   - a per-submission line (id, stage, title, author email)
 *   - user counts by role
 *   - a list of users flagged as potential test accounts (heuristics:
 *     username contains 'test', email contains '+test', has no roles)
 *
 * Does not modify anything.
 */

use APP\facades\Repo;
use PKP\cliTool\CommandLineTool;
use PKP\db\DAORegistry;
use PKP\security\Role;

require(dirname(__FILE__) . '/../bootstrap.php');

class ProdInventoryTool extends CommandLineTool
{
    private const CONTEXT_ID = 1;

    public function execute(): void
    {
        $journal = DAORegistry::getDAO('JournalDAO')->getById(self::CONTEXT_ID);
        echo "Journal: {$journal->getPath()} (id={$journal->getId()})\n\n";

        $this->submissions();
        echo "\n";
        $this->users();
    }

    private function submissions(): void
    {
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([self::CONTEXT_ID])
            ->getMany();

        $byStage = [];
        $rows = [];
        foreach ($submissions as $s) {
            $stage = (int) $s->getData('stageId');
            $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
            $pub = $s->getCurrentPublication();
            $title = $pub?->getLocalizedFullTitle(null, 'text') ?? '(no title)';
            // Primary author email
            $authorEmail = '';
            if ($pub) {
                $authors = Repo::author()->getCollector()
                    ->filterByPublicationIds([$pub->getId()])
                    ->getMany();
                foreach ($authors as $a) {
                    $authorEmail = $a->getEmail();
                    break;
                }
            }
            $rows[] = sprintf(
                '  id=%-4d stage=%d  %-50s  <%s>',
                $s->getId(),
                $stage,
                mb_substr($title, 0, 50),
                $authorEmail
            );
        }

        echo '=== SUBMISSIONS: ' . count($rows) . " total ===\n";
        echo 'By stage: ';
        ksort($byStage);
        foreach ($byStage as $stage => $n) {
            $label = match ($stage) {
                1 => 'Submission', 3 => 'Review', 4 => 'CE', 5 => 'Production', default => "stage {$stage}",
            };
            echo "{$label}={$n} ";
        }
        echo "\n\n";
        foreach ($rows as $r) {
            echo $r . "\n";
        }
    }

    private function users(): void
    {
        $all = Repo::user()->getCollector()->getMany();
        $total = 0;
        $suspects = [];
        $byRole = [];

        foreach ($all as $u) {
            $total++;
            $username = (string) $u->getUsername();
            $email = (string) $u->getEmail();
            $groups = Repo::userGroup()->userUserGroups($u->getId(), self::CONTEXT_ID)->all();
            $roleNames = [];
            foreach ($groups as $g) {
                $roleId = (int) $g->roleId;
                $byRole[$roleId] = ($byRole[$roleId] ?? 0) + 1;
                $roleNames[] = self::roleLabel($roleId);
            }
            $roleNames = array_unique($roleNames);

            $isSuspect = false;
            $reasons = [];
            if (stripos($username, 'test') !== false) {
                $isSuspect = true;
                $reasons[] = 'username contains "test"';
            }
            if (stripos($email, '+test') !== false) {
                $isSuspect = true;
                $reasons[] = 'email contains "+test"';
            }
            if (empty($groups)) {
                $isSuspect = true;
                $reasons[] = 'no roles';
            }

            if ($isSuspect) {
                $suspects[] = sprintf(
                    '  id=%-4d %-24s <%s>  roles=[%s]  — %s',
                    $u->getId(),
                    $username,
                    $email,
                    implode(',', $roleNames) ?: '-',
                    implode('; ', $reasons)
                );
            }
        }

        echo "=== USERS: {$total} total ===\n";
        echo 'By role: ';
        ksort($byRole);
        foreach ($byRole as $roleId => $n) {
            echo self::roleLabel($roleId) . "={$n} ";
        }
        echo "\n\n";

        echo 'Potential test users (' . count($suspects) . "):\n";
        foreach ($suspects as $s) {
            echo $s . "\n";
        }
    }

    private static function roleLabel(int $roleId): string
    {
        return match ($roleId) {
            Role::ROLE_ID_MANAGER => 'Manager',
            Role::ROLE_ID_SUB_EDITOR => 'SectionEditor',
            Role::ROLE_ID_AUTHOR => 'Author',
            Role::ROLE_ID_REVIEWER => 'Reviewer',
            Role::ROLE_ID_ASSISTANT => 'Assistant',
            Role::ROLE_ID_READER => 'Reader',
            default => "role#{$roleId}",
        };
    }
}

(new ProdInventoryTool($argv ?? []))->execute();
