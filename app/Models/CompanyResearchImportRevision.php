<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyResearchImportRevision extends Model
{
    protected $fillable = [
        'research_import_id', 'revision', 'payload', 'payload_sha256', 'imported_by',
    ];

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
