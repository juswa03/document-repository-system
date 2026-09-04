<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Http\Request;

class AccessGrantController extends Controller
{
    /**
     * GET /api/osm-admin/documents/{document}/access-grants
     */
    public function index(Document $document)
    {
        return response()->json(
            $document->accessGrants()
                ->with(['grantee:id,full_name,email', 'granteeOffice:id,office_name', 'grantedBy:id,full_name'])
                ->latest()
                ->get()
        );
    }

    /**
     * POST /api/osm-admin/documents/{document}/access-grants
     * Grant a user or an office access to a Restricted / Confidential
     * document (BR-04). Confidential grants are time-boxed by default.
     */
    public function store(Request $request, Document $document)
    {
        if (! in_array($document->access_level, ['restricted', 'confidential'], true)) {
            return response()->json([
                'message' => "Access grants only apply to restricted or confidential documents (this one is {$document->access_level}).",
            ], 422);
        }

        $data = $request->validate([
            'grantee_user_id' => ['required_without:grantee_office_id', 'nullable', 'exists:users,id'],
            'grantee_office_id' => ['required_without:grantee_user_id', 'nullable', 'exists:offices,id'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($document->access_level === 'confidential' && empty($data['expires_at'])) {
            $data['expires_at'] = now()->addDays(90);
        }

        $grant = $document->accessGrants()->create([
            ...$data,
            'granted_by' => $request->user()->id,
        ]);

        AuditLog::record(
            $request->user()->id,
            'access_granted',
            "Granted access to {$document->tracking_no} ("
                .($grant->grantee_user_id ? "user #{$grant->grantee_user_id}" : "office #{$grant->grantee_office_id}").').',
            Document::class,
            $document->id,
            $grant->only(['grantee_user_id', 'grantee_office_id', 'expires_at', 'reason']),
        );

        return response()->json($grant, 201);
    }

    /**
     * DELETE /api/osm-admin/access-grants/{accessGrant}
     */
    public function destroy(Request $request, AccessGrant $accessGrant)
    {
        if ($accessGrant->revoked_at) {
            return response()->json(['message' => 'That grant is already revoked.'], 422);
        }

        $accessGrant->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);

        AuditLog::record(
            $request->user()->id,
            'access_revoked',
            "Revoked an access grant on document #{$accessGrant->document_id}.",
            Document::class,
            $accessGrant->document_id,
        );

        return response()->json(['message' => 'Access revoked.']);
    }
}
