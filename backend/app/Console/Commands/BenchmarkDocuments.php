<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use App\Reports\Registry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Times the hot read paths against a synthetic data volume so NFR-06
 * ("immediate response") and NFR-08 ("under load") can be verified on
 * the target hardware rather than assumed. Fails (exit 1) if any p95
 * exceeds its target in config/performance.php.
 *
 *   php artisan documents:benchmark --seed --rows=5000
 *   php artisan documents:benchmark --cleanup
 */
class BenchmarkDocuments extends Command
{
    protected $signature = 'documents:benchmark
        {--seed : Insert synthetic PERF-* documents first}
        {--rows= : How many to seed (default from config)}
        {--iterations= : Timed runs per operation (default from config)}
        {--cleanup : Delete the synthetic PERF-* documents and exit}
        {--json : Emit the results as JSON}';

    protected $description = 'Benchmark the hot document read paths (NFR-06 / NFR-08)';

    public function handle(Registry $reports): int
    {
        if ($this->option('cleanup')) {
            $n = Document::where('tracking_no', 'like', 'PERF-%')->delete();
            $this->info("Removed {$n} synthetic document(s).");

            return self::SUCCESS;
        }

        $rows = (int) ($this->option('rows') ?: config('performance.sample_rows'));
        $iterations = (int) ($this->option('iterations') ?: config('performance.iterations'));

        if ($this->option('seed')) {
            $this->seed($rows);
        }

        $admin = User::where('role', User::ROLE_OSM_ADMIN)->first();
        if ($admin === null) {
            $this->error('No osm_admin user found — run the database seeders first.');

            return self::FAILURE;
        }
        $category = Category::query()->value('id');
        $sampleRef = Document::query()->value('tracking_no');

        $operations = [
            'repository_metadata_search' => fn () => Document::query()->accessibleBy($admin)
                ->filter(['category_id' => $category, 'status' => 'approved'])->paginate(15),
            'repository_content_search' => fn () => Document::query()->accessibleBy($admin)
                ->filter(['q' => 'infrastructure procurement schedule'])->paginate(15),
            'review_queue' => fn () => Document::with(['category', 'uploader', 'assignee'])
                ->where('status', 'pending')->get(),
            'dashboard_stats' => fn () => Document::selectRaw('status, count(*) c')->groupBy('status')->get(),
            'report_document_inventory' => fn () => $reports->find('document-inventory')?->rows([]),
            'document_lookup_by_tracking_no' => fn () => Document::where('tracking_no', $sampleRef)->first(),
        ];

        $targets = config('performance.targets_ms');
        $results = [];
        $failed = false;

        foreach ($operations as $name => $op) {
            $samples = [];
            for ($i = 0; $i < $iterations; $i++) {
                $start = hrtime(true);
                $op();
                $samples[] = (hrtime(true) - $start) / 1e6;   // ms
            }
            sort($samples);
            $p95 = $samples[(int) floor(0.95 * (count($samples) - 1))];
            $target = $targets[$name] ?? null;
            $pass = $target === null || $p95 <= $target;
            $failed = $failed || ! $pass;

            $results[$name] = [
                'p50' => round($samples[(int) floor(0.5 * (count($samples) - 1))], 1),
                'p95' => round($p95, 1),
                'max' => round(end($samples), 1),
                'target' => $target,
                'pass' => $pass,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['rows' => Document::count(), 'iterations' => $iterations, 'results' => $results], JSON_PRETTY_PRINT));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Documents in table: '.Document::count()."  ·  iterations/op: {$iterations}");
        $this->table(
            ['Operation', 'p50 ms', 'p95 ms', 'max ms', 'target', ''],
            collect($results)->map(fn ($r, $k) => [
                $k, $r['p50'], $r['p95'], $r['max'], $r['target'] ?? '—', $r['pass'] ? 'PASS' : 'SLOW',
            ])->values(),
        );

        if ($failed) {
            $this->error('One or more operations exceeded their p95 target.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function seed(int $rows): void
    {
        $category = Category::query()->value('id') ?? Category::create(['category_name' => 'Perf', 'category_code' => 'PERF'])->id;
        $office = Office::query()->value('id') ?? Office::create(['office_name' => 'Perf', 'office_code' => 'PERF'])->id;
        $user = User::query()->value('id');

        $filler = 'The Office for Strategy Management notes the infrastructure procurement schedule '
            .'covering the science building the library refurbishment and the campus photovoltaic array '
            .'subject to the usual controls and quarterly reporting. ';

        $this->info("Seeding {$rows} synthetic documents…");
        $bar = $this->output->createProgressBar($rows);

        collect(range(1, $rows))->chunk(500)->each(function ($chunk) use ($category, $office, $user, $filler, $bar) {
            $now = now();
            $batch = $chunk->map(function ($i) use ($category, $office, $user, $filler, $now) {
                $days = $i % 900;

                return [
                    'tracking_no' => 'PERF-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT).'-'.Str::random(4),
                    'title' => "Synthetic document {$i}",
                    'document_type' => ['report', 'memo', 'minutes', 'plan'][$i % 4],
                    'document_date' => $now->copy()->subDays($days)->toDateString(),
                    'reporting_period' => 'FY '.(2024 + ($i % 3)),
                    'access_level' => 'internal',
                    'keywords' => 'synthetic, benchmark, strategy',
                    'description' => "Synthetic benchmark document number {$i}.",
                    'extracted_text' => $i % 8 === 0 ? str_repeat($filler, 3) : null,
                    'category_id' => $category,
                    'uploaded_by' => $user,
                    'office_id' => $office,
                    'file_path' => "documents/perf-{$i}.pdf",
                    'file_format' => 'pdf',
                    'file_size' => 12000,
                    'status' => ['pending', 'approved', 'approved', 'revision'][$i % 4],
                    'retention_status' => 'active',
                    'version_number' => 1,
                    'submitted_at' => $now->copy()->subDays($days),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

            Document::insert($batch);
            $bar->advance(count($batch));
        });

        $bar->finish();
        $this->newLine(2);
    }
}
