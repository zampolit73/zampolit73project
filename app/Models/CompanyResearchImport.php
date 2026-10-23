<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyResearchImport extends Model
{
    protected $fillable = [
        'identifier', 'company_inn', 'company_name', 'payload', 'payload_sha256',
        'revision', 'imported_by',
    ];

    protected $casts = ['revision' => 'integer'];

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CompanyResearchImportRevision::class, 'research_import_id');
    }
}
