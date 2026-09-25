<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramInvite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramBindingController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        if ($user->telegramAccount()->exists()) {
            return back()->withErrors([
                'telegram' => 'Этот пользователь уже привязан к Telegram. Сначала отвяжи текущий аккаунт.',
            ]);
        }

        $code = $this->generateUniqueCode();
        $codeHash = hash('sha256', $code);

        DB::transaction(function () use ($request, $user, $codeHash) {
            TelegramInvite::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();

            TelegramInvite::query()->create([
                'user_id' => $user->id,
                'created_by_user_id' => $request->user()->id,
                'code_hash' => $codeHash,
            ]);
        });

        return back()->with('telegram_invite', [
            'user_id' => $user->id,
            'username' => $user->username,
            'code' => $code,
        ]);
    }

    public function destroy(User $user): RedirectResponse
    {
        DB::transaction(function () use ($user) {
            $user->telegramAccount()->delete();

            TelegramInvite::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();
        });

        return back();
    }

    private function generateUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $raw = '';

            for ($i = 0; $i < 8; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $code = substr($raw, 0, 4).'-'.substr($raw, 4);
            $exists = TelegramInvite::query()
                ->where('code_hash', hash('sha256', $code))
                ->exists();
        } while ($exists);

        return $code;
    }
}
