<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Reports\Registry;
use App\Reports\Report;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly Registry $registry)
    {
    }

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

        return response()->json([
            'key' => $definition->key(),
            'label' => $definition->label(),
            'generated_at' => now()->toDateTimeString(),
            'filters' => $filters,
            'columns' => $definition->columns(),
            'summary' => $definition->summary($filters),
            'rows' => $rows,
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

    private function csv(Report $definition, \Illuminate\Support\Collection $rows): StreamedResponse
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
