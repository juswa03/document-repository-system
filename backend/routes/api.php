<?php

use App\Authorization\RoleMatrix;
use App\Http\Controllers\Api\AccessGrantController;
use App\Http\Controllers\Api\Admin\AiSettingController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\GovernanceReviewController;
use App\Http\Controllers\Api\Admin\OfficeController;
use App\Http\Controllers\Api\Admin\RequestTypeController;
use App\Http\Controllers\Api\Admin\RequiredDocumentController;
use App\Http\Controllers\Api\Admin\SystemSettingController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AiSuggestionController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentRepositoryController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RetentionController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\Category;
use App\Models\Office;
use App\Models\RequestType;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function () {
    Route::post('/login', [LoginController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/me', [LoginController::class, 'me']);

    // Lookup data for dropdowns — any authenticated role can read these.
    Route::get('/request-types', fn () => response()->json(RequestType::orderBy('type_name')->get()));
    Route::get('/categories', fn () => response()->json(Category::orderBy('category_name')->get()));
    Route::get('/offices', fn () => response()->json(Office::orderBy('office_name')->get()));

    // Notifications — any authenticated user reads/marks their own.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}', [NotificationController::class, 'markRead']);

    // Document download — ownership/role check happens inside the
    // controller since it depends on who uploaded the specific file,
    // not a fixed role.
    Route::get('/documents/{id}/file', [DocumentController::class, 'download']);
    Route::get('/documents/{id}/versions', [DocumentController::class, 'versions']);

    // System admin — user management.
    Route::middleware('role:system_admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::get('/settings', [SystemSettingController::class, 'show']);
        Route::patch('/settings', [SystemSettingController::class, 'update']);
        Route::get('/audit-log', [AuditLogController::class, 'index']);

        // Role → permission matrix (decision 0.2, Option B) — read-only,
        // drives the Manage Roles screen. Sourced from App\Authorization\RoleMatrix.
        Route::get('/role-matrix', fn () => response()->json(RoleMatrix::forDisplay()));

        // AI agent layer (§F) — provider / model / cap / threshold.
        Route::get('/ai-settings', [AiSettingController::class, 'show']);
        Route::patch('/ai-settings', [AiSettingController::class, 'update']);
        Route::post('/ai-settings/test', [AiSettingController::class, 'test']);

        // Lookup data management — previously only editable by hand-
        // editing seeder files and re-running them.
        Route::post('/offices', [OfficeController::class, 'store']);
        Route::patch('/offices/{office}', [OfficeController::class, 'update']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::patch('/categories/{category}', [CategoryController::class, 'update']);
        Route::post('/request-types', [RequestTypeController::class, 'store']);
        Route::patch('/request-types/{requestType}', [RequestTypeController::class, 'update']);

        // Compliance checklist (Phase 6.2) — drives RPT-06 / RPT-07.
        Route::get('/required-documents', [RequiredDocumentController::class, 'index']);
        Route::post('/required-documents', [RequiredDocumentController::class, 'store']);
        Route::patch('/required-documents/{requiredDocument}', [RequiredDocumentController::class, 'update']);
        Route::delete('/required-documents/{requiredDocument}', [RequiredDocumentController::class, 'destroy']);

        // Governance review cadence (BR-07, Phase 7.2).
        Route::get('/governance-reviews', [GovernanceReviewController::class, 'index']);
        Route::post('/governance-reviews', [GovernanceReviewController::class, 'store']);
    });

    // Document repository search (objective 1.3) — cross-office, so
    // both admin roles can browse it, unlike the user's own submissions.
    Route::middleware('role:osm_admin,system_admin')->prefix('repository')->group(function () {
        Route::get('/documents', [DocumentRepositoryController::class, 'index']);
        Route::post('/search', [DocumentRepositoryController::class, 'search']);
    });

    // Reports (objective 1.4 / §G) — same audience as the repository search.
    Route::middleware('role:osm_admin,system_admin')->prefix('reports')->group(function () {
        Route::get('/', [ReportController::class, 'index']);
        Route::get('/documents', [ReportController::class, 'documents']);   // legacy dashboard aggregate
        Route::get('/{report}', [ReportController::class, 'show']);
    });

    // OSM admin — review queue + decisions + access grants.
    Route::middleware('role:osm_admin')->prefix('osm-admin')->group(function () {
        Route::get('/queue', [SubmissionController::class, 'queue']);
        Route::get('/stats', [SubmissionController::class, 'stats']);
        Route::get('/review-config', [SubmissionController::class, 'reviewConfig']);
        Route::post('/reviews', [ReviewController::class, 'store']);

        // Review routing & assignment (PF-08) — claim / reassign / release.
        Route::post('/documents/{document}/assign', [SubmissionController::class, 'assignDocument']);
        Route::post('/requests/{submission}/assign', [SubmissionController::class, 'assignRequest']);
        Route::get('/documents/{document}/access-grants', [AccessGrantController::class, 'index']);
        Route::post('/documents/{document}/access-grants', [AccessGrantController::class, 'store']);
        Route::delete('/access-grants/{accessGrant}', [AccessGrantController::class, 'destroy']);

        // AI suggestions (§F) — review, then accept (applies) or dismiss.
        Route::get('/documents/{document}/ai-suggestions', [AiSuggestionController::class, 'index']);
        Route::post('/ai-suggestions/{aiSuggestion}/accept', [AiSuggestionController::class, 'accept']);
        Route::post('/ai-suggestions/{aiSuggestion}/dismiss', [AiSuggestionController::class, 'dismiss']);

        // Retention lifecycle (DR-14) — archive / restore / dispose.
        Route::get('/retention', [RetentionController::class, 'overview']);
        Route::post('/documents/{document}/archive', [RetentionController::class, 'archive']);
        Route::post('/documents/{document}/restore', [RetentionController::class, 'restore']);
        Route::post('/documents/{document}/dispose', [RetentionController::class, 'dispose']);
    });

    // User / office — submit and track. OSM admins can also submit
    // (objective 2.1 requires both roles to be able to upload).
    Route::middleware('role:user,osm_admin')->prefix('dashboard')->group(function () {
        Route::get('/submissions', [SubmissionController::class, 'mine']);
        Route::post('/requests', [SubmissionController::class, 'storeRequest']);
        Route::post('/documents', [SubmissionController::class, 'storeDocument']);
        Route::post('/requests/{id}/resubmit', [SubmissionController::class, 'resubmitRequest']);
        Route::post('/documents/{id}/resubmit', [SubmissionController::class, 'resubmitDocument']);
    });
});
