<?php
namespace App\Http\Controllers;
use App\Services\LeadRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
class CompanyController {
 public function index(Request $r){
  $v=$r->validate(['q'=>'nullable|string|max:200','page'=>'nullable|integer|min:1','id'=>'nullable|integer']);
  LeadRepository::unicode();$q=DB::table('saleshub_companies as co')->select('co.*')->selectSub(DB::table('saleshub_contacts')->whereColumn('saleshub_contacts.company_id','co.id')->selectRaw('count(*)'),'contacts_count');
  if(!empty($v['q']))$q->whereRaw('instr(hub_lower(co.name),?)>0 OR instr(hub_lower(co.holding),?)>0',[mb_strtolower($v['q']),mb_strtolower($v['q'])]);
  $selected=!empty($v['id'])?DB::table('saleshub_companies')->find($v['id']):null;
  $stats=$selected?DB::table('saleshub_contacts')->where('company_id',$selected->id)->selectRaw("count(*) total,sum(CASE WHEN email<>'' THEN 1 ELSE 0 END) email,sum(CASE WHEN phone<>'' THEN 1 ELSE 0 END) phone,sum(CASE WHEN messenger<>'' THEN 1 ELSE 0 END) messenger")->first():null;
  return Inertia::render('SalesHub/Companies',['companies'=>$q->orderByDesc('contacts_count')->orderBy('co.name')->paginate(50)->withQueryString(),'query'=>$v['q']??'','selected'=>$selected,'stats'=>$stats]);
 }
 public function update(Request $r,int $id){
  $v=$r->validate(['version'=>'required|integer|min:1','holding'=>'nullable|string|max:200','industry'=>'nullable|string|max:200','verification'=>'required|in:Не проверено,На проверке,Подтверждено,Отклонено','target'=>'required|boolean','inn'=>'nullable|string|max:30','website'=>'nullable|string|max:500','owner_raw'=>'nullable|string|max:200','relationship'=>'nullable|string|max:200','need'=>'nullable|string|max:4000','comment'=>'nullable|string|max:10000']);
  $version=$v['version'];unset($v['version']);foreach($v as $k=>$value)if($value===null)$v[$k]='';
  $n=DB::table('saleshub_companies')->where('id',$id)->where('version',$version)->update($v+['version'=>DB::raw('version+1'),'updated_at'=>now()]);
  abort_unless($n===1,409,'Компания изменена коллегой');
  return back()->with('success','Компания обновлена');
 }
}
