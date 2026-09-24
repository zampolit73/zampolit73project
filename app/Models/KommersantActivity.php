<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KommersantActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'subject_name',
        'action',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
