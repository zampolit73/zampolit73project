<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $telegramBotConfigured = filled(config('services.telegram.token'))
            && filled(config('services.telegram.webhook_secret'));

        return Inertia::render('AdminUsers', [
            'users' => User::query()
                ->select(['id', 'username', 'role', 'created_at'])
                ->with('telegramAccount')
                ->orderByRaw("case when role = 'admin' then 0 else 1 end")
                ->orderBy('username')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'role' => $user->role,
                    'created_at' => $user->created_at,
                    'telegram' => $user->telegramAccount ? [
                        'telegram_user_id' => $user->telegramAccount->telegram_user_id,
                        'telegram_username' => $user->telegramAccount->telegram_username,
                        'linked_at' => $user->telegramAccount->linked_at?->toIso8601String(),
                    ] : null,
                ])
                ->values(),
            'telegramInvite' => $request->session()->get('telegram_invite'),
            'telegramBot' => [
                'configured' => $telegramBotConfigured,
                'username' => filled(config('services.telegram.username'))
                    ? ltrim((string) config('services.telegram.username'), '@')
                    : null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:255', 'unique:users,username'],
            'password' => ['required', 'confirmed', Password::min(8), 'max:255'],
        ], [
            'username.unique' => 'Пользователь с таким логином уже существует.',
            'password.confirmed' => 'Подтверждение пароля не совпадает.',
        ]);

        User::query()->create([
            'username' => trim($data['username']),
            'password' => Hash::make($data['password']),
            'role' => 'user',
        ]);

        return back();
    }
}
