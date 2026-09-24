<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KommersantManager extends Model
{
    protected $fillable = [
        'category_id',
        'row_number',
        'industry',
        'place',
        'full_name',
        'linkedin_url',
        'job_title',
        'company',
        'pdf_page',
        'assigned_to_user_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'place' => 'integer',
            'pdf_page' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KommersantCategory::class, 'category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
