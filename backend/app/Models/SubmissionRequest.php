<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Maps to the `requests` table from the ERD. Named SubmissionRequest
 * (not Request) to avoid colliding with Illuminate\Http\Request in
 * controllers.
 */
class SubmissionRequest extends Model
{
    protected $table = 'requests';

    protected $fillable = [
        'tracking_no',
        'request_type_id',
        'requested_by',
        'title',
        'description',
        'needed_by',
        'amount',
        'access_level',
        'remarks',
        'status',
        'assigned_to',
        'assigned_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'assigned_at' => 'datetime',
            'needed_by' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Request types that require a monetary amount
     * (docs/request-workflow-spec.md).
     */
    public const AMOUNT_REQUIRED_TYPE_CODES = ['BUD', 'SUP'];

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The OSM admin currently responsible for reviewing this (Phase 4.3). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Same reasoning as Document::review() — resolve to the latest
     * decision, since a request can be reviewed more than once.
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class, 'request_id')->latestOfMany('reviewed_at');
    }
}
