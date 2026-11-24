<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class EnsureSalesHubAccess {
 public function handle(Request $request,Closure $next){
  abort_unless($request->user() && ($request->user()->role==='admin'||DB::table('saleshub_access')->where('user_id',$request->user()->id)->exists()),403);
  return $next($request);
 }
}
