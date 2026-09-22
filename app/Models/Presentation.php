<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Presentation extends Model
{
    protected $fillable = [
        'source_id',
        'title',
        'speaker_name',
        'job_title',
        'company',
        'event_name',
        'event_year',
        'file_type',
        'file_url',
        'source_page_url',
        'review_status',
        'link_status',
        'has_email',
        'has_phone',
        'is_good_lead',
        'assigned_to_user_id',
        'assigned_at',
        'discovered_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_year' => 'integer',
            'has_email' => 'boolean',
            'has_phone' => 'boolean',
            'is_good_lead' => 'boolean',
            'assigned_at' => 'datetime',
            'discovered_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PresentationSource::class, 'source_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
