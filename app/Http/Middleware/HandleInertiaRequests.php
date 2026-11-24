<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'csrfToken' => csrf_token(),
            'auth' => [
                'user' => $request->user()?->only('id', 'username', 'role'),
                'salesHub' => fn () => $request->user() && ($request->user()->role === 'admin' || \Illuminate\Support\Facades\DB::table('saleshub_access')->where('user_id',$request->user()->id)->exists()),
            ],
            'flash' => [
                'readerMessage' => fn () => $request->session()->get('reader_message'),
                'readerError' => fn () => $request->session()->get('reader_error'),
            ],
        ]);
    }
}
