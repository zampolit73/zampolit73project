<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
class SalesHubAccessController {
 public function index(){return Inertia::render('SalesHub/Access',['users'=>DB::table('users as u')->leftJoin('saleshub_access as a','a.user_id','=','u.id')->select('u.id','u.username','u.role')->selectRaw('CASE WHEN a.user_id IS NULL THEN 0 ELSE 1 END AS allowed')->orderBy('u.username')->get()]);}
 public function update(Request $r){$v=$r->validate(['user_id'=>'required|integer|exists:users,id','allowed'=>'required|boolean']);if($v['allowed'])DB::table('saleshub_access')->updateOrInsert(['user_id'=>$v['user_id']],['created_at'=>now(),'updated_at'=>now()]);else DB::table('saleshub_access')->where('user_id',$v['user_id'])->delete();return back();}
}
