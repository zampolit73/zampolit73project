<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PresentationSource extends Model
{
    protected $fillable = [
        'name',
        'url',
        'domain',
        'priority',
        'is_active',
        'last_scanned_at',
        'last_scan_found',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'last_scanned_at' => 'datetime',
            'last_scan_found' => 'integer',
        ];
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(Presentation::class, 'source_id');
    }
}
