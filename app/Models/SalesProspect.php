<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesProspect extends Model
{
    protected $fillable = [
        'name', 'normalized_name', 'inn', 'website', 'status', 'priority',
        'owner_id', 'contact_name', 'contact_role', 'contact_url',
        'evidence_url', 'notes', 'next_step', 'source_candidate_id',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function sourceCandidate(): BelongsTo
    {
        return $this->belongsTo(InvestigationCandidate::class, 'source_candidate_id');
    }
}
