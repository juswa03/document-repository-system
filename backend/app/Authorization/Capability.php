<?php

namespace App\Authorization;

/**
 * The named permissions behind the role → permission matrix
 * (decision 0.2, Option B — three roles). The canonical mapping of
 * roles to the capabilities they hold lives in {@see RoleMatrix}; the
 * human-readable table is docs/role-permission-matrix.md and
 * tests/Feature/Conformance/RbacTest.php asserts every cell.
 *
 * Roles are unchanged (`user`, `osm_admin`, `system_admin`). These
 * capabilities are a stable vocabulary the routes, policies and the
 * frontend can share instead of scattering `role === 'osm_admin'`
 * literals. Splitting `osm_admin` into reviewer/approver later means
 * giving those two rows their own column in RoleMatrix — nothing else
 * moves.
 */
enum Capability: string
{
    // Submission (user + osm_admin)
    case DocumentSubmit = 'document.submit';
    case RequestSubmit = 'request.submit';
    case SubmissionTrackOwn = 'submission.track_own';

    // Review & workflow (osm_admin)
    case ReviewQueueView = 'review.queue.view';
    case ReviewAssign = 'review.assign';
    case ReviewDecide = 'review.decide';
    case ReviewApprove = 'review.approve';
    case ReviewSetAccessLevel = 'review.set_access_level';
    case AccessGrantManage = 'access_grant.manage';
    case RetentionManage = 'retention.manage';
    case DisposalApprove = 'disposal.approve';
    case AiSuggestionAct = 'ai_suggestion.act';

    // Shared — osm_admin + system_admin
    case RepositorySearch = 'repository.search';
    case ReportGenerate = 'report.generate';

    // Platform (system_admin)
    case UserManage = 'user.manage';
    case LookupManage = 'lookup.manage';
    case RequiredDocumentsManage = 'required_documents.manage';
    case SettingsManage = 'settings.manage';
    case AiSettingsManage = 'ai_settings.manage';
    case AuditLogRead = 'audit_log.read';
    case GovernanceReviewRecord = 'governance_review.record';

    /** Short label for the frontend matrix. */
    public function label(): string
    {
        return match ($this) {
            self::DocumentSubmit => 'Submit / resubmit a document',
            self::RequestSubmit => 'Submit / resubmit a request',
            self::SubmissionTrackOwn => 'Track own submissions',
            self::ReviewQueueView => 'See the review queue',
            self::ReviewAssign => 'Claim / reassign a queued item',
            self::ReviewDecide => 'Return / reject / mark for revision',
            self::ReviewApprove => 'Approve / publish a submission',
            self::ReviewSetAccessLevel => 'Set access level at approval',
            self::AccessGrantManage => 'Grant / revoke document access',
            self::RetentionManage => 'Archive / restore a document',
            self::DisposalApprove => 'Dispose of an archived document',
            self::AiSuggestionAct => 'Accept / dismiss an AI suggestion',
            self::RepositorySearch => 'Cross-office repository search',
            self::ReportGenerate => 'Run / export reports',
            self::UserManage => 'Manage user accounts',
            self::LookupManage => 'Manage offices / categories / request types',
            self::RequiredDocumentsManage => 'Manage the compliance checklist',
            self::SettingsManage => 'Manage system settings',
            self::AiSettingsManage => 'Manage AI provider settings',
            self::AuditLogRead => 'Read the audit log',
            self::GovernanceReviewRecord => 'Record a governance review',
        };
    }
}
