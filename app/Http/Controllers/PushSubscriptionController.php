<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data=$request->validate([
            'endpoint'=>['required','string','max:2048'],
            'keys.p256dh'=>['required','string','max:1024'],
            'keys.auth'=>['required','string','max:1024'],
        ]);
        $subscription=PushSubscription::updateOrCreate(
            ['endpoint'=>$data['endpoint']],
            ['user_id'=>$request->user()->id,'public_key'=>$data['keys']['p256dh'],'auth_token'=>$data['keys']['auth'],'content_encoding'=>'aes128gcm'],
        );
        return response()->json(['subscribed'=>true,'id'=>$subscription->id]);
    }

    public function config(): JsonResponse
    {
        return response()->json([
            'publicKey'=>config('webpush.public_key'),
            'enabled'=>filled(config('webpush.public_key'))&&filled(config('webpush.private_key')),
        ]);
    }

    public function test(Request $request, WebPushService $webPush): JsonResponse
    {
        $sent=0;
        foreach(PushSubscription::where('user_id',$request->user()->id)->get() as $subscription){
            if($webPush->sendToSubscription($subscription,['title'=>config('app.name'),'body'=>'Тестовое push-уведомление','url'=>'/'])) $sent++;
        }
        return response()->json(['sent'=>$sent]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['endpoint'=>['required','url','max:2048']]);
        PushSubscription::query()->where('user_id',$request->user()->id)->where('endpoint',$request->string('endpoint'))->delete();
        return response()->json(['subscribed'=>false]);
    }
}
