<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationReview extends Model
{
    protected $fillable = [
        'investigation_id',
        'reviewer_user_id',
        'status',
        'correct_client_name',
        'confirmation_url',
        'notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(VacancyInvestigation::class, 'investigation_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
