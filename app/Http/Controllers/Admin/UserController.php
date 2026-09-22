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
    public function index(): Response
    {
        return Inertia::render('AdminUsers', [
            'users' => User::query()
                ->select(['id', 'username', 'role', 'created_at'])
                ->orderByRaw("case when role = 'admin' then 0 else 1 end")
                ->orderBy('username')
                ->get(),
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
