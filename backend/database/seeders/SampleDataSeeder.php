<?php

namespace Database\Seeders;

use App\Classification\AccessLevelPolicy;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentStageEvent;
use App\Models\Office;
use App\Models\RequestType;
use App\Models\Review;
use App\Models\SubmissionRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo-scale sample data: an office admin for every office, twenty plain
 * users spread across those offices, and five submissions each — a mix
 * of documents and requests across a realistic spread of statuses, so
 * every status page (Pending, Rejected, Approved, Needs revision, the
 * office admin's queue and Decided list) has something real to show.
 *
 * Idempotent: re-running this seeder does not duplicate users or
 * submissions. Users are keyed on email. A submission is keyed on
 * (owner, its own deterministic title) — NOT on tracking number, which
 * is only assigned once a row is confirmed new and is a fresh value on
 * every run, so it can never be used to detect a repeat.
 */
class SampleDataSeeder extends Seeder
{
    private const USER_COUNT = 20;

    /** Per user: [decision outcome, kind]. Five per user, a realistic mix. */
    private const PLAN = [
        ['status' => 'approved', 'kind' => 'document'],
        ['status' => 'approved', 'kind' => 'request'],
        ['status' => 'pending', 'kind' => 'document'],
        ['status' => 'revision', 'kind' => 'request'],
        ['status' => 'rejected', 'kind' => 'document'],
    ];

    private AccessLevelPolicy $accessPolicy;

    /** @var array<string, int> per-prefix sequence counter, so tracking numbers never collide within this run. */
    private array $sequences = [];

    public function run(): void
    {
        $this->accessPolicy = app(AccessLevelPolicy::class);

        $offices = Office::orderBy('id')->get();
        $categories = Category::orderBy('id')->get();
        $requestTypes = RequestType::active()->ordered()->get();

        if ($offices->isEmpty() || $categories->isEmpty() || $requestTypes->isEmpty()) {
            $this->command?->warn(
                'SampleDataSeeder: offices, categories, or request types are missing — '
                .'run LookupDataSeeder and OfficeSeeder first. Skipping.'
            );

            return;
        }

        $officeAdmins = $this->seedOfficeAdmins($offices);
        $users = $this->seedUsers($offices);

        foreach ($users as $user) {
            $reviewer = $officeAdmins->get($user->office_id) ?? $officeAdmins->first();
            $this->seedSubmissionsFor($user, $reviewer, $categories, $requestTypes);
        }

        $this->command?->info(sprintf(
            'SampleDataSeeder: %d office admins, %d users, %d submissions in place.',
            $officeAdmins->count(),
            $users->count(),
            $users->count() * count(self::PLAN),
        ));
    }

    /**
     * One active office admin per office, so every office's queue has
     * somebody to review it — the review-config reviewer list and the
     * office-scoped queue both depend on this.
     *
     * @return \Illuminate\Support\Collection<int, User> keyed by office_id
     */
    private function seedOfficeAdmins($offices)
    {
        $admins = collect();

        foreach ($offices as $i => $office) {
            // Some offices already have an active office admin - RoleUserSeeder
            // assigns its own demo account to one office, and an install may
            // have been reconfigured by hand since. Reuse whichever admin is
            // already there instead of adding a second one: the point is
            // that every office has a reviewer, not that every office has
            // exactly one of OUR accounts.
            $existing = User::where('role', User::ROLE_OFFICE_ADMIN)
                ->where('office_id', $office->id)
                ->where('is_active', true)
                ->first();

            if ($existing !== null) {
                $admins->put($office->id, $existing);

                continue;
            }

            $slug = Str::slug($office->office_code ?: "office{$i}");
            $email = "office.admin.{$slug}@example.test";

            $admin = User::updateOrCreate(
                ['email' => $email],
                [
                    'full_name' => "Admin - {$office->office_name}",
                    'role' => User::ROLE_OFFICE_ADMIN,
                    'office_id' => $office->id,
                    'is_active' => true,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            $admins->put($office->id, $admin);
        }

        return $admins;
    }

    /**
     * Twenty plain users spread evenly across the offices, so every
     * office admin has real submissions routed to them and no office is
     * left with an empty queue.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function seedUsers($offices)
    {
        $firstNames = [
            'Maria', 'Juan', 'Ana', 'Jose', 'Rosa', 'Pedro', 'Carmen', 'Luis',
            'Elena', 'Miguel', 'Sofia', 'Carlos', 'Isabel', 'Antonio', 'Teresa',
            'Ramon', 'Cristina', 'Manuel', 'Beatriz', 'Fernando',
        ];
        $lastNames = [
            'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Torres', 'Ramos',
            'Garcia', 'Mendoza', 'Castro', 'Flores', 'Aquino', 'Villanueva',
            'Del Rosario', 'Gonzales', 'Pascual', 'Fernandez', 'Salazar',
            'Marquez', 'Domingo',
        ];

        $users = collect();

        for ($i = 0; $i < self::USER_COUNT; $i++) {
            $office = $offices[$i % $offices->count()];
            $name = "{$firstNames[$i]} {$lastNames[$i]}";
            $email = 'user'.($i + 1).'@example.test';

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'full_name' => $name,
                    'role' => User::ROLE_USER,
                    'office_id' => $office->id,
                    'is_active' => true,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            $users->push($user);
        }

        return $users;
    }

    private function seedSubmissionsFor(User $user, User $reviewer, $categories, $requestTypes): void
    {
        foreach (self::PLAN as $i => $plan) {
            if ($plan['kind'] === 'document') {
                $this->seedDocument($user, $reviewer, $categories, $plan['status'], $i);
            } else {
                $this->seedRequest($user, $reviewer, $requestTypes, $plan['status'], $i);
            }
        }
    }

    private function seedDocument(User $user, User $reviewer, $categories, string $outcome, int $seed): void
    {
        $category = $categories[($user->id + $seed) % $categories->count()];
        $accessLevel = $this->accessPolicy->forCategoryId($category->id)['default'];
        $submittedAt = now()->subDays(30 - ($seed * 5) - ($user->id % 5));

        $title = "{$category->category_name} sample #{$seed} - {$user->full_name}";

        // Stable per (user, seed) key, independent of any tracking number
        // or timestamp — this is what makes re-running the seeder safe.
        // Checking by tracking number instead would never match: the
        // number is only decided a few lines below, and by then it is
        // already a fresh, non-colliding value rather than a repeat of
        // the first run's.
        if (Document::where('uploaded_by', $user->id)->where('title', $title)->exists()) {
            return; // already seeded on a previous run
        }

        $officeCode = $user->office?->office_code ?: 'GEN';
        $prefix = "{$category->category_code}-{$officeCode}-".$submittedAt->format('Ymd').'-';
        $trackingNo = $this->nextTrackingNo($prefix);

        $filePath = $this->writeSampleFile($trackingNo);
        $hash = hash_file('sha256', Storage::disk(Document::DISK)->path($filePath));

        $status = $outcome === 'pending' ? 'pending' : $outcome;

        $document = Document::create([
            'tracking_no' => $trackingNo,
            'title' => $title,
            'document_type' => Document::TYPES[$seed % count(Document::TYPES)],
            'document_date' => $submittedAt->copy()->subDays(3)->toDateString(),
            'reporting_period' => 'AY 2025-2026',
            'access_level' => $accessLevel,
            'keywords' => 'sample, seeded, '.Str::slug($category->category_name, ' '),
            'description' => "Sample {$category->category_name} document seeded for demonstration purposes, submitted by {$user->full_name}.",
            'category_id' => $category->id,
            'uploaded_by' => $user->id,
            'office_id' => $user->office_id,
            'target_office_id' => $user->office_id,
            'file_path' => $filePath,
            'file_format' => 'pdf',
            'file_size' => Storage::disk(Document::DISK)->size($filePath),
            'content_hash' => $hash,
            'status' => $status,
            'assigned_to' => $status === 'pending' ? null : $reviewer->id,
            'assigned_at' => $status === 'pending' ? null : $submittedAt,
            'retention_status' => 'active',
            'version_number' => 1,
            'submitted_at' => $submittedAt,
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ]);

        DocumentStageEvent::record($document, DocumentStageEvent::STAGE_UPLOADED, $user->id);

        AuditLog::record(
            $user->id,
            'document_uploaded',
            "Uploaded document {$document->tracking_no} ({$document->title}).",
            Document::class,
            $document->id,
        );

        if ($status !== 'pending') {
            $this->recordReview($document->id, null, $reviewer, $status, $submittedAt->copy()->addDay());
        }
    }

    private function seedRequest(User $user, User $reviewer, $requestTypes, string $outcome, int $seed): void
    {
        $type = $requestTypes[($user->id + $seed) % $requestTypes->count()];
        $submittedAt = now()->subDays(28 - ($seed * 4) - ($user->id % 4));

        $title = "{$type->type_name} - {$user->full_name} #{$seed}";

        // Same reasoning as seedDocument(): keyed on (user, deterministic
        // title), never on the tracking number, which is only assigned a
        // few lines below and would be a fresh value on every run.
        if (SubmissionRequest::where('requested_by', $user->id)->where('title', $title)->exists()) {
            return; // already seeded on a previous run
        }

        $prefix = "{$type->type_code}-".$submittedAt->format('Ymd').'-';
        $trackingNo = $this->nextTrackingNo($prefix);

        $status = $outcome === 'pending' ? 'pending' : $outcome;

        // Types marked "subject to approval" (urgent, sensitive) demand a
        // stated reason - the same rule the live form enforces.
        $remarks = $type->requires_justification
            ? 'Needed ahead of the office planning cycle; authorised by the department head.'
            : null;

        $request = SubmissionRequest::create([
            'tracking_no' => $trackingNo,
            'request_type_id' => $type->id,
            'title' => $title,
            'description' => "Sample {$type->type_name} seeded for demonstration purposes, requested by {$user->full_name}.",
            'needed_by' => $submittedAt->copy()->addWeeks(2)->toDateString(),
            'access_level' => 'internal',
            'remarks' => $remarks,
            'requested_by' => $user->id,
            'target_office_id' => $user->office_id,
            'status' => $status,
            'assigned_to' => $status === 'pending' ? null : $reviewer->id,
            'assigned_at' => $status === 'pending' ? null : $submittedAt,
            'submitted_at' => $submittedAt,
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ]);

        AuditLog::record(
            $user->id,
            'request_submitted',
            "Submitted request {$request->tracking_no} ({$request->title}).",
            SubmissionRequest::class,
            $request->id,
        );

        if ($status !== 'pending') {
            $this->recordReview(null, $request->id, $reviewer, $status, $submittedAt->copy()->addDay());
        }
    }

    private function recordReview(?int $documentId, ?int $requestId, User $reviewer, string $decision, Carbon $reviewedAt): void
    {
        $remarks = match ($decision) {
            'approved' => null,
            'revision' => 'Please double-check the reporting period and resubmit.',
            'rejected' => 'Does not meet the current submission requirements.',
            default => null,
        };

        Review::create([
            'document_id' => $documentId,
            'request_id' => $requestId,
            'reviewed_by' => $reviewer->id,
            'decision' => $decision,
            'remarks' => $remarks,
            'reviewed_at' => $reviewedAt,
            'created_at' => $reviewedAt,
            'updated_at' => $reviewedAt,
        ]);

        AuditLog::record(
            $reviewer->id,
            "review_{$decision}",
            "Marked as {$decision} (seeded sample data).",
            $documentId ? Document::class : SubmissionRequest::class,
            $documentId ?? $requestId,
        );

        if ($documentId) {
            DocumentStageEvent::record(Document::find($documentId), DocumentStageEvent::STAGE_DECIDED, $reviewer->id, $decision);
        }
    }

    /**
     * A minimal, genuinely valid single-page PDF - small enough to
     * generate inline, but real enough that opening or downloading it
     * never 404s or hands back garbage.
     */
    private function writeSampleFile(string $trackingNo): string
    {
        $pdf = "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>endobj\n"
            ."4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            ."5 0 obj<</Length 66>>stream\n"
            ."BT /F1 18 Tf 50 700 Td (Sample document - {$trackingNo}) Tj ET\n"
            ."endstream endobj\n"
            ."trailer<</Root 1 0 R>>\n";

        $path = 'documents/'.Str::random(40).'.pdf';
        Storage::disk(Document::DISK)->put($path, $pdf);

        return $path;
    }

    /** Stable, collision-free sequence per prefix across this seeder run. */
    private function nextTrackingNo(string $prefix): string
    {
        $start = $this->sequences[$prefix] ?? null;

        if ($start === null) {
            $existing = collect()
                ->concat(Document::where('tracking_no', 'like', $prefix.'%')->pluck('tracking_no'))
                ->concat(SubmissionRequest::where('tracking_no', 'like', $prefix.'%')->pluck('tracking_no'));

            $start = $existing
                ->map(fn (string $t) => (int) substr($t, strlen($prefix)))
                ->max() ?? 0;
        }

        $seq = $start + 1;
        $this->sequences[$prefix] = $seq;

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
