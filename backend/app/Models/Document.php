<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    /**
     * Filesystem disk that holds uploaded document files. A PRIVATE disk
     * (storage/app/private) — never the web-served `public` disk. Files
     * are reachable only through DocumentController::download, which
     * checks ownership/role.
     */
    public const DISK = 'local';

    /** Decision 0.10 — document types (DR-02). Mirrors config('documents.types'). */
    public const TYPES = ['report', 'memo', 'minutes', 'plan', 'template', 'evidence', 'dataset'];

    /** Decision 0.10 — access levels (DR-07 / FR-06). */
    public const ACCESS_LEVELS = ['public', 'internal', 'restricted', 'confidential'];

    /** Decision 0.4 — retention lifecycle (DR-14), separate from `status`. */
    public const RETENTION_STATUSES = ['active', 'superseded', 'archived', 'disposed'];

    /**
     * Review lifecycle. Distinct from RETENTION_STATUSES: this tracks how
     * far a submission has got through review, retention tracks whether
     * the approved record is still current.
     *
     *   draft    — being encoded by the uploader, not yet submitted. Not
     *              in anyone's queue and not counted in review stats.
     *   pending  — submitted, awaiting a decision. Displayed as
     *              "Submitted" until a reviewer is assigned and
     *              "For review" after — see reviewStage().
     *   revision — returned to the uploader to fix and resubmit.
     *   approved / rejected — terminal.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REVISION = 'revision';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_REVISION,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /**
     * The label dimension the flow calls "Submitted" vs "For Review".
     * Both are `pending` in the database — a submission becomes "for
     * review" the moment a reviewer is on it, so this is derived from
     * assignment rather than stored as a separate status.
     */
    public function reviewStage(): string
    {
        if ($this->status !== self::STATUS_PENDING) {
            return $this->status;
        }

        return $this->assigned_to === null ? 'submitted' : 'for_review';
    }

    protected $fillable = [
        'tracking_no',
        'title',
        'document_type',
        'document_date',
        'reporting_period',
        'access_level',
        'keywords',
        'description',
        'extracted_text',
        'text_extracted_at',
        'remarks',
        'category_id',
        'uploaded_by',
        'office_id',
        'file_path',
        'file_format',
        'file_size',
        'content_hash',
        'status',
        'target_office_id',
        'assigned_to',
        'assigned_at',
        'retention_status',
        'archived_at',
        'disposed_at',
        'disposal_reason',
        'version_number',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'document_date' => 'date',
            'text_extracted_at' => 'datetime',
            'assigned_at' => 'datetime',
            'archived_at' => 'datetime',
            'disposed_at' => 'datetime',
            'file_size' => 'integer',
            'version_number' => 'integer',
        ];
    }

    /**
     * Powers the document repository search (objective 1.3). Every
     * filter is optional. Superseded and archived documents are hidden
     * unless `include_superseded` is truthy (FR-12 / BR-05 — they stay
     * traceable but are never the default "current" result).
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            // A draft is the uploader's private work-in-progress — it has
            // no tracking number and has never been submitted, so it must
            // never surface in the repository, in search, or in a report.
            ->where('status', '!=', self::STATUS_DRAFT)
            ->unless(($filters['include_superseded'] ?? false) || ($filters['retention_status'] ?? false),
                fn (Builder $q) => $q->where('retention_status', 'active'))
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                // Content search (FR-10). FULLTEXT over title / description /
                // keywords / extracted text drives relevance; a per-token
                // LIKE sweep is the correctness backstop (short terms,
                // tracking numbers, and multi-word natural-language input
                // that isn't a contiguous phrase).
                $stop = ['the', 'and', 'for', 'from', 'with', 'that', 'this', 'about', 'any', 'all'];
                $tokens = array_values(array_filter(
                    preg_split('/\s+/', trim($term)),
                    fn ($t) => mb_strlen($t) >= 3 && ! in_array(mb_strtolower($t), $stop, true),
                )) ?: [$term];

                $q->where(function (Builder $qq) use ($term, $tokens) {
                    $qq->whereFullText(['title', 'description', 'keywords', 'extracted_text'], $term);
                    foreach ($tokens as $tok) {
                        $like = '%'.$tok.'%';
                        $qq->orWhere('title', 'like', $like)
                            ->orWhere('description', 'like', $like)
                            ->orWhere('keywords', 'like', $like)
                            ->orWhere('extracted_text', 'like', $like)
                            ->orWhere('tracking_no', 'like', $like);
                    }
                });
            })
            ->when($filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
            ->when($filters['office_id'] ?? null, fn (Builder $q, $v) => $q->where('office_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['retention_status'] ?? null, fn (Builder $q, $v) => $q->where('retention_status', $v))
            ->when($filters['objective_id'] ?? null, fn (Builder $q, $v) => $q->whereHas(
                'objectives', fn (Builder $oq) => $oq->where('strategic_objectives.id', $v),
            ))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->whereDate('submitted_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->whereDate('submitted_at', '<=', $v));
    }

    /**
     * Retention lifecycle (DR-14, decision 0.4). `status` is the review
     * state (pending/approved/…); `retention_status` is separate and
     * moves active → archived → disposed. Only an approved, active
     * document is eligible for archival; only an archived one for
     * disposal. Disposal deletes the file and keeps the record as a
     * tombstone.
     */
    public function retentionPeriodMonths(): int
    {
        $code = $this->category?->category_code;

        return (int) (config("retention.periods_months.{$code}")
            ?? config('retention.periods_months.default'));
    }

    public function retentionDueAt(): ?Carbon
    {
        return $this->document_date?->copy()->addMonths($this->retentionPeriodMonths());
    }

    public function isRetentionDue(): bool
    {
        return $this->status === 'approved'
            && $this->retention_status === 'active'
            && ($due = $this->retentionDueAt()) !== null
            && $due->isPast();
    }

    public function disposalDueAt(): ?Carbon
    {
        return $this->archived_at?->copy()->addMonths((int) config('retention.disposal_grace_months'));
    }

    public function isDisposalDue(): bool
    {
        return $this->retention_status === 'archived'
            && ($due = $this->disposalDueAt()) !== null
            && $due->isPast();
    }

    /** Approved, still-active documents — the pool retention runs against. */
    public function scopeRetainable(Builder $query): Builder
    {
        return $query->where('status', 'approved')->where('retention_status', 'active');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('retention_status', 'archived');
    }

    public function archive(): void
    {
        $this->update(['retention_status' => 'archived', 'archived_at' => now()]);
    }

    public function restoreFromArchive(): void
    {
        $this->update(['retention_status' => 'active', 'archived_at' => null]);
    }

    public function dispose(string $reason): void
    {
        $disk = Storage::disk(self::DISK);
        if ($this->file_path && $disk->exists($this->file_path)) {
            $disk->delete($this->file_path);
        }

        $this->update([
            'retention_status' => 'disposed',
            'disposed_at' => now(),
            'disposal_reason' => $reason,
        ]);
    }

    /**
     * Documents whose stored file is byte-identical to $hash and that
     * this user is entitled to be told about — their own submissions or
     * their office's (PF-06 / AI-03). Superseded/archived rows are
     * ignored: a duplicate warning is about what is currently on file.
     * Cross-office matches are deliberately not surfaced — that would
     * leak the existence of another unit's document.
     */
    public function scopePossibleDuplicateOf(Builder $query, string $hash, User $user): Builder
    {
        return $query
            ->where('content_hash', $hash)
            ->where('retention_status', 'active')
            ->where('status', '!=', self::STATUS_DRAFT)
            ->where(function (Builder $q) use ($user) {
                $q->where('uploaded_by', $user->id)
                    ->when($user->office_id, fn (Builder $qq, $office) => $qq->orWhere('office_id', $office));
            });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The office_admin currently responsible for reviewing this (Phase 4.3). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * A document can be reviewed more than once across its lifetime
     * (revision → resubmit → reviewed again). Always resolve to the
     * most recent decision, not an arbitrary row.
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class, 'document_id')->latestOfMany('reviewed_at');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_number');
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(DocumentStageEvent::class)->orderBy('entered_at');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    /** Strategic objectives this document supports (DR objective linkage, Phase 11). */
    public function objectives(): BelongsToMany
    {
        return $this->belongsToMany(StrategicObjective::class, 'document_strategic_objective')
            ->orderBy('code');
    }

    /**
     * Access-level enforcement (FR-06 / BR-04). public/internal are open
     * to any authenticated user; restricted/confidential need the
     * uploader, an office_admin of the document's target office, or an
     * active access grant for the user or their office. Platform
     * (system_admin) is deliberately not need-to-know for restricted
     * content.
     */
    public function isAccessibleBy(User $user): bool
    {
        if (in_array($this->access_level, ['public', 'internal'], true)) {
            return true;
        }

        if ($this->uploaded_by === $user->id) {
            return true;
        }

        if ($user->role === User::ROLE_OFFICE_ADMIN
            && ($this->target_office_id === null || $this->target_office_id === $user->office_id)) {
            return true;
        }

        return $this->accessGrants()->active()->for($user)->exists();
    }

    /**
     * Restrict a query to documents the user may see. office_admin sees
     * all documents routed to their office (restricted/confidential
     * included — they are the reviewers). system_admin is platform-only
     * and not need-to-know for restricted content. Mirrors
     * isAccessibleBy() so the repository list never leaks a title.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        // A draft belongs to whoever is writing it and to nobody else —
        // not even a reviewer, who has nothing to review until it is
        // submitted.
        $query->where(fn (Builder $q) => $q
            ->where('status', '!=', self::STATUS_DRAFT)
            ->orWhere('uploaded_by', $user->id));

        if ($user->role === User::ROLE_OFFICE_ADMIN) {
            // Office scope: all access levels within the office, plus
            // unscoped (null) legacy rows visible to all offices.
            return $query->where(fn (Builder $q) => $q
                ->where('target_office_id', $user->office_id)
                ->orWhereNull('target_office_id'));
        }

        // system_admin and plain users: only public/internal, own uploads,
        // or documents with an active access grant for them.
        return $query->where(function (Builder $q) use ($user) {
            $q->whereIn('access_level', ['public', 'internal'])
                ->orWhere('uploaded_by', $user->id)
                ->orWhereHas('accessGrants', fn (Builder $g) => $g->active()->for($user));
        });
    }

    public function aiSummary(): HasOne
    {
        return $this->hasOne(AiSummary::class);
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(DocumentAiSuggestion::class);
    }

    /**
     * Freeze the document's current state as a numbered version row
     * before a resubmission overwrites it. The file it points at is
     * left on disk (never deleted on resubmit — Phase 3.1).
     */
    public function snapshotAsVersion(int $supersededBy): DocumentVersion
    {
        return $this->versions()->create([
            'version_number' => $this->version_number,
            'title' => $this->title,
            'document_type' => $this->document_type,
            'document_date' => $this->document_date,
            'reporting_period' => $this->reporting_period,
            'access_level' => $this->access_level,
            'keywords' => $this->keywords,
            'description' => $this->description,
            'remarks' => $this->remarks,
            'category_id' => $this->category_id,
            'file_path' => $this->file_path,
            'file_format' => $this->file_format,
            'file_size' => $this->file_size,
            'status' => $this->status,
            'review_remarks' => $this->review?->remarks,
            'superseded_by' => $supersededBy,
            'superseded_at' => now(),
        ]);
    }
}
