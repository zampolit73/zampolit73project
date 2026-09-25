<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationSource extends Model
{
    protected $fillable = [
        'investigation_id',
        'candidate_id',
        'provider',
        'title',
        'url',
        'snippet',
        'search_query',
        'evidence_score',
    ];

    protected function casts(): array
    {
        return [
            'evidence_score' => 'integer',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(VacancyInvestigation::class, 'investigation_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(InvestigationCandidate::class, 'candidate_id');
    }
}
