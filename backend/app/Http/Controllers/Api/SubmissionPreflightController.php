<?php

namespace App\Http\Controllers\Api;

use App\AI\Contracts\AiProvider;
use App\AI\DocumentContext;
use App\AI\Suggestion;
use App\Classification\AccessLevelPolicy;
use App\Dedup\SubmissionPreflight;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Document;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Steps 5 and 6 of the process flow, for the UPLOADER, before anything is
 * saved.
 *
 * The uploader picks a file and fills the form; this endpoint reports
 * whether the file looks like a duplicate or a new version of something
 * already on file, and what the AI would classify it as. The uploader
 * then accepts, edits, or overrides those suggestions and submits — so
 * the confirm-or-override step happens at their desk, not the reviewer's.
 *
 * Nothing here persists a document, a suggestion row, or an AI spend
 * entry: it is a read-only look at an upload-in-progress. The reviewer's
 * own AI pass (AnalyzeDocument → AiSuggestionPanel) still runs after
 * submission and is unchanged.
 */
class SubmissionPreflightController extends Controller
{
    public function __invoke(
        Request $request,
        SubmissionPreflight $preflight,
        AiProvider $provider,
        AccessLevelPolicy $policy,
    ): mixed {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.config('documents.max_upload_kb'),
                'mimes:'.implode(',', config('documents.allowed_mimes')),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'document_type' => ['nullable', Rule::in(Document::TYPES)],
            'document_date' => ['nullable', 'date'],
            'reporting_period' => ['nullable', 'string', 'max:120'],
            'access_level' => ['nullable', Rule::in(Document::ACCESS_LEVELS)],
            'keywords' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        $duplicate = $preflight->inspect($request->file('file'), $user, [
            'title' => $data['title'] ?? null,
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
        ]);

        AuditLog::record(
            $user->id,
            'submission_preflight',
            'Ran a pre-submission duplicate/version check: '.$duplicate['verdict'].'.',
            null,
            null,
            ['verdict' => $duplicate['verdict'], 'match' => $duplicate['match']['ref'] ?? null],
        );

        return response()->json([
            'duplicate_check' => $duplicate,
            'access_policy' => $this->accessPolicy($policy, $data),
            'suggestions' => $this->suggestions($provider, $data),
        ]);
    }

    /**
     * What the category permits, so the form can narrow its access-level
     * dropdown and warn before the submission is refused (step 9).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function accessPolicy(AccessLevelPolicy $policy, array $data): array
    {
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $row = $policy->forCategoryId($categoryId);
        $chosen = $data['access_level'] ?? null;

        return [
            'allowed' => $row['allowed'],
            'default' => $row['default'],
            'chosen_is_allowed' => $chosen === null || in_array($chosen, $row['allowed'], true),
            'message' => ($chosen !== null && ! in_array($chosen, $row['allowed'], true))
                ? $policy->rejectionMessage($categoryId, $chosen)
                : null,
        ];
    }

    /**
     * The same provider calls AnalyzeDocument makes, but against the
     * unsaved form values and returned inline rather than persisted. When
     * the AI layer is off or capped this is simply an empty list and the
     * uploader carries on typing — the flow degrades to manual entry.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function suggestions(AiProvider $provider, array $data): array
    {
        if (! $provider->isConfigured()) {
            return [];
        }

        $settings = SystemSetting::current();

        if (\App\Models\DocumentAiSuggestion::spendThisMonth() >= (float) $settings->ai_monthly_cap_usd) {
            return [];
        }

        $context = new DocumentContext(
            title: (string) ($data['title'] ?? ''),
            documentType: $data['document_type'] ?? null,
            reportingPeriod: $data['reporting_period'] ?? null,
            keywords: $data['keywords'] ?? null,
            description: $data['description'] ?? null,
            currentCategory: isset($data['category_id'])
                ? Category::where('id', $data['category_id'])->value('category_name')
                : null,
            accessLevel: $data['access_level'] ?? null,
            documentDate: $data['document_date'] ?? null,
        );

        $on = fn (string $key) => $settings->aiCapabilityEnabled($key);
        $categories = Category::active()->orderBy('category_name')->pluck('category_name')->all();

        $found = array_filter([
            $on('classification') ? $provider->classify($context, $categories) : null,
            $on('metadata') ? $provider->extractMetadata($context) : null,
            $on('confidentiality') ? $provider->checkConfidentiality($context, Document::ACCESS_LEVELS) : null,
        ]);

        return array_values(array_map(
            fn (Suggestion $s) => [
                'kind' => $s->kind,
                'data' => $s->data,
                'confidence' => $s->confidence,
                'rationale' => $s->rationale,
                'model' => $s->model,
            ],
            $found,
        ));
    }
}
