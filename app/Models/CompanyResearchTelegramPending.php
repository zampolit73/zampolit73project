<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyResearchTelegramPending extends Model
{
    protected $table = 'company_research_telegram_pending';

    protected $fillable = [
        'user_id', 'telegram_user_id', 'telegram_chat_id', 'telegram_message_id',
        'report_id', 'company_name', 'had_collision', 'payload', 'token_hash',
        'state', 'expires_at', 'completed_at', 'result_revision', 'result_changed',
    ];

    protected function casts(): array
    {
        return [
            'had_collision' => 'boolean',
            'result_revision' => 'integer',
            'result_changed' => 'boolean',
            'telegram_user_id' => 'integer',
            'telegram_chat_id' => 'integer',
            'telegram_message_id' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
