<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    /**
     * GET /api/admin/audit-log
     * Paginated audit trail with filters (action, actor, date range).
     * `?format=csv` streams the filtered set as a download.
     */
    public function index(Request $request): mixed
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $query = AuditLog::with('actor')
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('created_at');

        if (($filters['format'] ?? null) === 'csv') {
            return $this->csv($query);
        }

        $logs = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $logs->through(fn (AuditLog $log) => $this->row($log))->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
            'available_actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    private function row(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'actor' => $log->actor?->full_name ?? 'System',
            'action' => $log->action,
            'description' => $log->description,
            'ip_address' => $log->ip_address,
            'subject' => $log->subject_type ? class_basename($log->subject_type).($log->subject_id ? " #{$log->subject_id}" : '') : null,
            'properties' => $log->properties,
            'created_at' => $log->created_at,
        ];
    }

    private function csv($query): StreamedResponse
    {
        $filename = 'audit-log-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Actor', 'Action', 'Details', 'IP', 'Changes']);
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $log) {
                    fputcsv($out, [
                        optional($log->created_at)->toDateTimeString(),
                        $log->actor?->full_name ?? 'System',
                        $log->action,
                        $log->description,
                        $log->ip_address,
                        $log->properties ? json_encode($log->properties) : '',
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
