<?php

namespace App\Http\Controllers\Api;

use App\AI\Contracts\AiProvider;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAiSuggestion;
use App\Models\SystemSetting;
use App\Reports\Registry;
use App\Reports\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Reports worth an AI narrative — aggregate/scored reports where a
     * sentence of context adds something over the table. The raw-list
     * reports (inventory, retrieval log, audit trail, ...) have nothing
     * for a narrative to say that the rows don't already say directly.
     *
     * @var list<string>
     */
    private const NARRATABLE_REPORTS = [
        'compliance-evidence',
        'office-submission-compliance',
        'document-aging',
    ];

    public function __construct(private readonly Registry $registry) {}

    /**
     * GET /api/reports
     * The reports available in this build (§G / RPT-01…RPT-11).
     */
    public function index()
    {
        return response()->json(
            $this->registry->all()->values()->map(fn (Report $r) => [
                'key' => $r->key(),
                'label' => $r->label(),
                'description' => $r->description(),
                'filters' => $r->acceptedFilters(),
                'columns' => $r->columns(),
            ])
        );
    }

    /**
     * GET /api/reports/{report}[?format=csv]
     * Run a report. JSON by default; `format=csv` streams a download.
     */
    public function show(Request $request, string $report): mixed
    {
        $definition = $this->registry->find($report);
        abort_if($definition === null, 404, "Unknown report '{$report}'.");

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'status' => ['nullable', 'in:pending,approved,rejected,revision'],
            'kind' => ['nullable', 'in:all,document,request'],
            'action' => ['nullable', 'string', 'max:50'],
            'actor_id' => ['nullable', 'exists:users,id'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $filters = array_intersect_key($filters, array_flip($definition->acceptedFilters()));
        $rows = $definition->rows($filters);

        AuditLog::record(
            $request->user()->id,
            'report_generated',
            "Generated the '{$definition->label()}' report".
                (($request->input('format') === 'csv') ? ' (CSV)' : '').'.',
            null,
            null,
            ['report' => $definition->key(), 'row_count' => $rows->count(), 'filters' => $filters],
        );

        if ($request->input('format') === 'csv') {
            return $this->csv($definition, $rows);
        }

        // The on-screen table is capped; the CSV export carries the full
        // set. Keeps a large report from flooding the browser DOM.
        $cap = (int) config('performance.report_row_cap', 500);
        $total = $rows->count();

        return response()->json([
            'key' => $definition->key(),
            'label' => $definition->label(),
            'generated_at' => now()->toDateTimeString(),
            'filters' => $filters,
            'columns' => $definition->columns(),
            'summary' => $definition->summary($filters),
            'rows' => $rows->take($cap)->values(),
            'total_rows' => $total,
            'truncated' => $total > $cap,
            'row_cap' => $cap,
        ]);
    }

    /**
     * POST /api/reports/{report}/narrative
     * A short AI-drafted paragraph over a report's own already-computed
     * figures (§F "report generation assistant"). The figures are never
     * recomputed by the model and nothing here is applied to any
     * record, so there is no accept/dismiss step (BR-03 has nothing to
     * gate) — the draft is shown immediately, clearly as a draft.
     */
    public function narrative(Request $request, string $report, AiProvider $provider): mixed
    {
        if (! in_array($report, self::NARRATABLE_REPORTS, true)) {
            return response()->json([
                'message' => 'This report does not offer an AI narrative.',
            ], 422);
        }

        $definition = $this->registry->find($report);
        abort_if($definition === null, 404, "Unknown report '{$report}'.");

        $settings = SystemSetting::current();

        if (! $settings->aiCapabilityEnabled('report_narrative')) {
            return response()->json(['message' => 'AI report narratives are turned off.'], 422);
        }

        if (! $provider->isConfigured()) {
            return response()->json(['message' => 'The AI layer is not configured.'], 422);
        }

        if (DocumentAiSuggestion::spendThisMonth() >= (float) $settings->ai_monthly_cap_usd) {
            return response()->json(['message' => 'This month\'s AI spend cap has been reached.'], 422);
        }

        $filters = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
        ]);
        $filters = array_intersect_key($filters, array_flip($definition->acceptedFilters()));

        $rows = $definition->rows($filters);

        $suggestion = $provider->narrateReport($definition->label(), [
            'summary' => $definition->summary($filters),
            'columns' => $definition->columns(),
            'sample_rows' => $rows->take(20)->values()->all(),
        ]);

        if ($suggestion === null) {
            return response()->json(['message' => 'Could not draft a narrative right now.'], 422);
        }

        $row = DocumentAiSuggestion::fromReportNarrative($report, $suggestion);
        $row->save();

        AuditLog::record(
            $request->user()->id,
            'ai_report_narrative_generated',
            "Generated an AI narrative for the '{$definition->label()}' report.",
            DocumentAiSuggestion::class,
            $row->id,
            ['report' => $report, 'cost_usd' => $row->cost_usd],
        );

        return response()->json([
            'narrative' => $suggestion->data['narrative'],
            'key_points' => $suggestion->data['key_points'],
            'confidence' => $suggestion->confidence,
            'model' => $suggestion->model,
            'generated_at' => $row->created_at,
        ]);
    }

    /**
     * GET /api/reports/documents
     * Legacy dashboard aggregate kept for the current Reports screen.
     */
    public function documents(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $base = Document::query()->filter($filters + ['include_superseded' => true]);

        return response()->json([
            'total' => (clone $base)->count(),
            'by_status' => (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'by_category' => (clone $base)->join('categories', 'categories.id', '=', 'documents.category_id')
                ->selectRaw('categories.category_name as category, count(*) as total')
                ->groupBy('categories.category_name')->orderByDesc('total')->get(),
            'by_office' => (clone $base)->join('offices', 'offices.id', '=', 'documents.office_id')
                ->selectRaw('offices.office_name as office, count(*) as total')
                ->groupBy('offices.office_name')->orderByDesc('total')->get(),
            'by_month' => (clone $base)
                ->selectRaw("DATE_FORMAT(submitted_at, '%Y-%m') as month, count(*) as total")
                ->groupBy('month')->orderBy('month')->get(),
        ]);
    }

    private function csv(Report $definition, Collection $rows): StreamedResponse
    {
        $columns = $definition->columns();
        $filename = $definition->key().'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($out, array_map(
                    fn (array $c) => $row[$c['key']] ?? '',
                    $columns,
                ));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
