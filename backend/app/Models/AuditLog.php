<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'action', 'subject_type', 'subject_id',
        'ip_address', 'user_agent', 'description', 'properties', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'properties' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Central place every audited action goes through.
     *
     * Always writes — the process-flow document (PF-18 / BR-06) requires
     * every upload, review, approval, download, revision and archive
     * action to be recorded, so there is deliberately no on/off switch
     * here. Request IP and user-agent are captured automatically.
     */
    public static function record(
        ?int $actorId,
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $properties = null
    ): void {
        static::create([
            'actor_id' => $actorId,
            'action' => $action,
            'description' => Str::limit($description, 255, ''),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 1000, ''),
            'properties' => $properties,
            'created_at' => now(),
        ]);
    }
}
