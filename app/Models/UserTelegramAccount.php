<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTelegramAccount extends Model
{
    protected $fillable = [
        'user_id',
        'telegram_user_id',
        'telegram_chat_id',
        'telegram_username',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'telegram_chat_id' => 'integer',
            'linked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
