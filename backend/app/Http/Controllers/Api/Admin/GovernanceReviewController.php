<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GovernanceReview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BR-07 / Phase 7.2 — record and review the periodic OSM governance
 * reviews of categories, access levels and retention status.
 * system_admin (governance_review.record capability).
 */
class GovernanceReviewController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => GovernanceReview::status(),
            'history' => GovernanceReview::with('reviewer:id,full_name')
                ->latest('performed_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(config('governance.scopes'))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $performedAt = now();
        $review = GovernanceReview::create([
            'reviewed_by' => $request->user()->id,
            'scope' => $data['scope'],
            'performed_at' => $performedAt,
            'notes' => $data['notes'] ?? null,
            'next_due_at' => $performedAt->copy()->addMonths(GovernanceReview::cadenceMonths($data['scope']))->toDateString(),
        ]);

        AuditLog::record(
            $request->user()->id,
            'governance_review_recorded',
            "Recorded a governance review of {$data['scope']}.",
            GovernanceReview::class,
            $review->id,
        );

        return response()->json($review->load('reviewer:id,full_name'), 201);
    }
}
