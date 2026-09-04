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
     * @return array<string, list<Capability>>
     */
    public static function map(): array
    {
        $osmReview = [
            Capability::ReviewQueueView,
            Capability::ReviewAssign,
            Capability::ReviewDecide,
            Capability::ReviewApprove,
            Capability::ReviewSetAccessLevel,
            Capability::AccessGrantManage,
            Capability::RetentionManage,
            Capability::DisposalApprove,
            Capability::AiSuggestionAct,
        ];

        $submit = [
            Capability::DocumentSubmit,
            Capability::RequestSubmit,
            Capability::SubmissionTrackOwn,
        ];

        $shared = [
            Capability::RepositorySearch,
            Capability::ReportGenerate,
        ];

        $platform = [
            Capability::UserManage,
            Capability::LookupManage,
            Capability::RequiredDocumentsManage,
            Capability::SettingsManage,
            Capability::AiSettingsManage,
            Capability::AuditLogRead,
            Capability::GovernanceReviewRecord,
        ];

        return [
            User::ROLE_USER => $submit,
            User::ROLE_OSM_ADMIN => [...$submit, ...$osmReview, ...$shared],
            User::ROLE_SYSTEM_ADMIN => [...$shared, ...$platform],
        ];
    }

    public static function roleHas(string $role, Capability $capability): bool
    {
        return in_array($capability, self::map()[$role] ?? [], true);
    }

    /**
     * The full matrix as plain data for the API / frontend:
     * one row per capability with an allow flag per role.
     *
     * @return array{roles: list<string>, rows: list<array{capability: string, label: string, allowed: array<string, bool>}>}
     */
    public static function forDisplay(): array
    {
        $roles = array_keys(self::map());

        $rows = array_map(fn (Capability $c) => [
            'capability' => $c->value,
            'label' => $c->label(),
            'allowed' => array_combine(
                $roles,
                array_map(fn (string $role) => self::roleHas($role, $c), $roles),
            ),
        ], Capability::cases());

        return ['roles' => $roles, 'rows' => $rows];
    }
}
