<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KommersantCategory extends Model
{
    protected $fillable = [
        'slug',
        'label',
        'title',
        'source_note',
        'pdf_pages',
        'newspaper_pages',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function managers(): HasMany
    {
        return $this->hasMany(KommersantManager::class, 'category_id');
    }
}
