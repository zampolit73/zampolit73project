<?php
namespace App\Http\Controllers;
use App\Services\SalesHubWorkbook;
use App\Services\SalesHubImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use RuntimeException;

class SalesHubImportController {
 public function index(){return Inertia::render('SalesHub/Import',['total'=>DB::table('saleshub_contacts')->count(),'imports'=>DB::table('saleshub_imports')->orderByDesc('id')->limit(15)->get(['id','contacts','companies','created_at'])]);}
 private function file(Request $r): array {
  $r->validate(['workbook'=>'required|file|max:15000']);
  $f=$r->file('workbook');
  abort_unless(strtolower($f->getClientOriginalExtension())==='xlsx',422,'Выберите файл XLSX');
  return [SalesHubWorkbook::read($f->getRealPath()),hash_file('sha256',$f->getRealPath())];
 }
 public function preview(Request $r){
  try{[$data,$digest]=$this->file($r);$p=SalesHubImport::plan($data);$r->session()->put('saleshub_preview',['digest'=>$digest,'stats'=>$p['stats'],'expires'=>time()+900,'user'=>$r->user()->id]);return response()->json($p['stats']);}
  catch(RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}
 }
 public function commit(Request $r){
  try{[$data,$digest]=$this->file($r);$p=$r->session()->get('saleshub_preview');abort_unless($p&&$p['user']===$r->user()->id&&$p['expires']>=time()&&hash_equals($p['digest'],$digest),409,'Сначала проверьте этот файл');
   $stats=SalesHubImport::commit($data,$digest,$p['stats'],$r->user()->id);$r->session()->forget('saleshub_preview');return response()->json($stats);}
  catch(RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}
 }
}
