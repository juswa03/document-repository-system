<?php

namespace App\Authorization;

use App\Models\User;

/**
 * Canonical role → capability map (decision 0.2, Option B).
 *
 * Single source of truth for the backend. docs/role-permission-matrix.md
 * is its human-readable twin; keep the two in step. RbacTest asserts
 * that every route's gate agrees with this table.
 */
final class RoleMatrix
{
    /**
     * Short name + one-line description per role — the copy the Manage
     * roles screen shows. Served from here so the screen never drifts
     * from the enforced model.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public static function roleMeta(): array
    {
        return [
            User::ROLE_USER => [
                'name' => 'User / office',
                'description' => 'Submits documents or requests and tracks the status of their own submissions.',
            ],
            User::ROLE_OSM_ADMIN => [
                'name' => 'OSM admin',
                'description' => 'The whole OSM review-and-publish function — completeness check, classification, '
                    .'return / reject / approve, access grants, retention and disposal, plus repository search and reports.',
            ],
            User::ROLE_SYSTEM_ADMIN => [
                'name' => 'System admin',
                'description' => 'Platform only — user accounts, lookups, required documents, settings, AI settings, '
                    .'the audit log and the governance cadence. No document decisions and no document submission.',
            ],
        ];
    }

    /**
     * Capabilities grouped into the four functional areas the matrix is
     * displayed under. `map()` is built from these buckets, so the groups
     * and the enforced sets can never disagree.
     *
     * @return array<string, list<Capability>>
     */
    public static function groupedCapabilities(): array
    {
        return [
            'Submit & track' => [
                Capability::DocumentSubmit,
                Capability::RequestSubmit,
                Capability::SubmissionTrackOwn,
            ],
            'Review & publish' => [
                Capability::ReviewQueueView,
                Capability::ReviewAssign,
                Capability::ReviewDecide,
                Capability::ReviewApprove,
                Capability::ReviewSetAccessLevel,
                Capability::AccessGrantManage,
                Capability::RetentionManage,
                Capability::DisposalApprove,
                Capability::AiSuggestionAct,
            ],
            'Records & reporting' => [
                Capability::RepositorySearch,
                Capability::ReportGenerate,
            ],
            'Platform administration' => [
                Capability::UserManage,
                Capability::LookupManage,
                Capability::RequiredDocumentsManage,
                Capability::SettingsManage,
                Capability::AiSettingsManage,
                Capability::AuditLogRead,
                Capability::GovernanceReviewRecord,
            ],
        ];
    }

    /**
     * @return array<string, list<Capability>>
     */
    public static function map(): array
    {
        $g = self::groupedCapabilities();
        $submit = $g['Submit & track'];
        $review = $g['Review & publish'];
        $shared = $g['Records & reporting'];
        $platform = $g['Platform administration'];

        return [
            User::ROLE_USER => $submit,
            User::ROLE_OSM_ADMIN => [...$submit, ...$review, ...$shared],
            User::ROLE_SYSTEM_ADMIN => [...$shared, ...$platform],
        ];
    }

    public static function roleHas(string $role, Capability $capability): bool
    {
        return in_array($capability, self::map()[$role] ?? [], true);
    }

    /**
     * The full matrix as plain data for the API / frontend: one row per
     * capability (with its group) and an allow flag per role, plus the
     * per-role display copy.
     *
     * @return array{
     *     roles: list<string>,
     *     meta: array<string, array{name: string, description: string}>,
     *     rows: list<array{capability: string, label: string, group: string, allowed: array<string, bool>}>
     * }
     */
    public static function forDisplay(): array
    {
        $roles = array_keys(self::map());

        $groupOf = [];
        foreach (self::groupedCapabilities() as $group => $capabilities) {
            foreach ($capabilities as $capability) {
                $groupOf[$capability->value] = $group;
            }
        }

        $rows = array_map(fn (Capability $c) => [
            'capability' => $c->value,
            'label' => $c->label(),
            'group' => $groupOf[$c->value] ?? 'Other',
            'allowed' => array_combine(
                $roles,
                array_map(fn (string $role) => self::roleHas($role, $c), $roles),
            ),
        ], Capability::cases());

        return [
            'roles' => $roles,
            'meta' => self::roleMeta(),
            'rows' => $rows,
        ];
    }
}
