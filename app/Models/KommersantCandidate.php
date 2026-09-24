<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KommersantCandidate extends Model
{
    protected $fillable = [
        'full_name',
        'company',
        'job_title',
        'linkedin_url',
        'confidence_status',
        'result_reason',
        'source_check',
        'assigned_to_user_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
