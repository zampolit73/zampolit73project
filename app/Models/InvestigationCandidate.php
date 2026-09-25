<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestigationCandidate extends Model
{
    protected $fillable = [
        'investigation_id',
        'company_name',
        'candidate_type',
        'confidence',
        'is_end_client',
        'rank',
        'explanation',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'is_end_client' => 'boolean',
            'rank' => 'integer',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(VacancyInvestigation::class, 'investigation_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(InvestigationSource::class, 'candidate_id');
    }
}
