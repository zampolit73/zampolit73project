<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VacancyInvestigation extends Model
{
    protected $fillable = [
        'user_id',
        'input_source',
        'input_text',
        'normalized_text',
        'fingerprint',
        'status',
        'progress_stage',
        'progress_text',
        'result_summary',
        'queued_at',
        'started_at',
        'finished_at',
        'cancelled_at',
        'timed_out_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'timed_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(InvestigationCandidate::class, 'investigation_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(InvestigationReview::class, 'investigation_id');
    }
}
