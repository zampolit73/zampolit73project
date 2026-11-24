<?php
namespace App\Http\Controllers;
use App\Services\LeadRepository as Leads;
use App\Services\LeadRules as Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HubController {
 private function filters(Request $r): array {
  return $r->validate(['q'=>'nullable|string|max:200','company'=>'nullable|string|max:200','company_mode'=>'nullable|in:contains,exact','titles'=>'nullable|array|max:3','titles.*'=>'nullable|string|max:200','title_mode'=>'nullable|in:contains,exact','groups'=>'nullable|array|max:12','groups.*'=>Rule::in(array_keys(Rules::GROUPS)),'holding'=>'nullable|string|max:200','industry'=>'nullable|string|max:200','status'=>['nullable',Rule::in(Rules::STATUSES)],'priority'=>['nullable',Rule::in(Rules::PRIORITIES)],'owner'=>'nullable|string|max:20','min_score'=>'nullable|integer|min:0|max:100','email'=>'nullable|boolean','phone'=>'nullable|boolean','messenger'=>'nullable|boolean','social'=>'nullable|boolean','check'=>'nullable|boolean','preset'=>'nullable|in:manual,hot,it,messenger,procurement,free,due,duplicates,quality','company_id'=>'nullable|integer','page'=>'nullable|integer|min:1|max:100000']);
 }
 public function index(Request $r){
  $f=$this->filters($r);$q=Leads::filters($f,$r->user()->id);
  $leads=Leads::rows($q)->orderByDesc('score')->orderBy('c.id')->paginate(50)->withQueryString()->through(fn($row)=>Leads::serialize($row));
  $stats=DB::table('saleshub_contacts as c')->leftJoin('saleshub_companies as co','c.company_id','=','co.id')->selectRaw("count(*) total, sum(CASE WHEN c.email<>'' OR c.phone<>'' OR c.messenger<>'' OR c.social<>'' THEN 1 ELSE 0 END) reachable, sum(CASE WHEN ".Leads::FREE." THEN 1 ELSE 0 END) free, sum(CASE WHEN ".Leads::SCORE.">=60 AND c.status NOT IN ('Закрыт','Неактуален') AND (c.email<>'' OR c.phone<>'' OR c.messenger<>'' OR c.social<>'') THEN 1 ELSE 0 END) hot")->first();
  return Inertia::render('SalesHub/Leads',['leads'=>$leads,'filters'=>$f,'stats'=>$stats,'options'=>['groups'=>array_keys(Rules::GROUPS),'statuses'=>Rules::STATUSES,'priorities'=>Rules::PRIORITIES,'owners'=>DB::table('users')->where(fn($q)=>$q->where('role','admin')->orWhereIn('id',DB::table('saleshub_access')->select('user_id')))->orderBy('username')->get(['id','username as name']),'holdings'=>DB::table('saleshub_companies')->where('verification','Подтверждено')->where('holding','<>','')->distinct()->orderBy('holding')->pluck('holding'),'industries'=>DB::table('saleshub_companies')->where('industry','<>','')->distinct()->orderBy('industry')->pluck('industry')]]);
 }
 public function show(Request $r,int $id){
  $row=Leads::rows(Leads::base()->where('c.id',$id))->first();abort_unless($row,404);
  $history=DB::table('saleshub_history as h')->leftJoin('users as u','u.id','=','h.actor_id')->where('h.contact_id',$id)->orderByDesc('h.id')->limit(30)->get(['h.created_at','h.changes','u.username as name']);
  $raw=json_decode(DB::table('saleshub_contacts')->where('id',$id)->value('raw'),true)?:[];
  $source=array_intersect_key($raw,array_flip(['Таблица-источник','Вкладка-источник','Ссылка на источник','Другие ссылки','Разночтения в компаниях']));
  return response()->json(Leads::serialize($row)+['history'=>$history,'source'=>$source]);
 }
 public function update(Request $r,int $id){
  $v=$r->validate(['version'=>'required|integer|min:1','status'=>['required',Rule::in(Rules::STATUSES)],'priority'=>['required',Rule::in(Rules::PRIORITIES)],'next_action'=>'nullable|string|max:2000','next_at'=>'nullable|date_format:Y-m-d','comment'=>'nullable|string|max:10000','take'=>'nullable|boolean','owner_id'=>'nullable|integer|exists:users,id']);
  DB::transaction(function()use($r,$id,$v){
   // Obtain SQLite writer lock before reading ownership/version; competing claims serialize.
   DB::statement('UPDATE saleshub_contacts SET version=version WHERE id=?',[$id]);
   $old=DB::table('saleshub_contacts')->find($id);abort_unless($old,404);
   $admin=$r->user()->role==='admin';$uid=$r->user()->id;if(!empty($v['owner_id']))abort_unless(DB::table('users')->where('id',$v['owner_id'])->where(fn($q)=>$q->where('role','admin')->orWhereIn('id',DB::table('saleshub_access')->select('user_id')))->exists(),422,'Нет доступа к Sales Hub');$free=$old->owner_id===null&&in_array($old->owner_raw,['','Не назначен'],true);
   abort_if(!$admin&&(!$free&&(int)$old->owner_id!==$uid),403,'Контакт закреплён за коллегой');
   abort_if(!$admin&&array_key_exists('owner_id',$v),403);
   $owner=$admin&&array_key_exists('owner_id',$v)?$v['owner_id']:$old->owner_id;
   if(!empty($v['take'])&&$free)$owner=$uid;
   abort_if(!$admin&&(int)$owner!==$uid,403,'Сначала возьмите контакт в работу');
   $change=['status'=>$v['status'],'priority'=>$v['priority'],'next_action'=>$v['next_action']??'','next_at'=>$v['next_at']??null,'comment'=>$v['comment']??'','owner_id'=>$owner,'owner_raw'=>($owner!==null||($admin&&array_key_exists('owner_id',$v)))?'':$old->owner_raw];
   $changed=DB::table('saleshub_contacts')->where('id',$id)->where('version',$v['version'])->update($change+['version'=>DB::raw('version+1'),'updated_at'=>now()]);
   abort_unless($changed===1,409,'Контакт изменён коллегой. Откройте его повторно.');
   DB::table('saleshub_history')->insert(['contact_id'=>$id,'actor_id'=>$uid,'changes'=>json_encode($change,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>now()]);
  });
  return response()->json(['ok'=>true]);
 }
 public function assign(Request $r,int $id){
  $v=$r->validate(['version'=>'required|integer|min:1','owner_id'=>'present|nullable|integer|exists:users,id']);
  $uid=$r->user()->id;$admin=$r->user()->role==='admin';
  return DB::transaction(function()use($uid,$admin,$id,$v){
   DB::statement('UPDATE saleshub_contacts SET version=version WHERE id=?',[$id]);
   $old=DB::table('saleshub_contacts')->find($id);abort_unless($old,404);
   abort_unless((int)$old->version===(int)$v['version'],409,'Контакт уже изменён. Обновите список.');
   $free=$old->owner_id===null&&in_array($old->owner_raw,['','Не назначен'],true);
   $owner=$v['owner_id']===null?null:(int)$v['owner_id'];
   abort_unless($admin||($free&&$owner!==null),403,'Переназначить или освободить контакт может только администратор');
   if($owner!==null)abort_unless(DB::table('users')->where('id',$owner)->where(fn($q)=>$q->where('role','admin')->orWhereIn('id',DB::table('saleshub_access')->select('user_id')))->exists(),422,'У сотрудника нет доступа к Sales Hub');
   if($old->owner_id==$owner&&$old->owner_raw==='')return response()->json(['ok'=>true,'version'=>$old->version]);
   $change=['owner_id'=>$owner,'owner_raw'=>''];
   if($owner!==null&&$old->status==='Новый')$change['status']='В работе';
   $updated=DB::table('saleshub_contacts')->where('id',$id)->where('version',$v['version'])->update($change+['version'=>DB::raw('version+1'),'updated_at'=>now()]);
   abort_unless($updated===1,409,'Контакт уже изменён. Обновите список.');
   DB::table('saleshub_history')->insert(['contact_id'=>$id,'actor_id'=>$uid,'changes'=>json_encode(['assignment'=>['from'=>$old->owner_id,'from_raw'=>$old->owner_raw,'to'=>$owner]]+$change,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>now()]);
   return response()->json(['ok'=>true,'version'=>$old->version+1]);
  });
 }
 public function store(Request $r){
  $v=$r->validate(['full_name'=>'required|string|max:255','company_raw'=>'nullable|string|max:255','title'=>'nullable|string|max:500','email'=>'nullable|string|max:5000','phone'=>'nullable|string|max:5000','messenger'=>'nullable|string|max:5000','social'=>'nullable|string|max:5000']);
  $id=DB::transaction(function()use($v){
   $co=Rules::clean($v['company_raw']??'');$cid=null;
   if($co!==''){DB::table('saleshub_companies')->insertOrIgnore(['name'=>$co,'name_key'=>Rules::key($co),'created_at'=>now(),'updated_at'=>now()]);$cid=DB::table('saleshub_companies')->where('name_key',Rules::key($co))->value('id');}
   $data=[];foreach(['full_name','company_raw','title','email','phone','messenger','social'] as $k)$data[$k]=Rules::clean($v[$k]??'');
   abort_if($data['full_name']==='',422,'Укажите имя');
   $id=DB::table('saleshub_contacts')->insertGetId($data+['company_id'=>$cid,'groups'=>json_encode(Rules::groups($data['title']),JSON_UNESCAPED_UNICODE),'raw'=>'{}','created_at'=>now(),'updated_at'=>now()]);
   Leads::replaceChannels($id,$data['email'],$data['phone'],$data['messenger']);return $id;
  });
  return response()->json(['id'=>$id],201);
 }
 public function identity(Request $r,int $id){
  $v=$r->validate(['version'=>'required|integer|min:1','full_name'=>'required|string|max:255','company_raw'=>'nullable|string|max:255','title'=>'nullable|string|max:500','email'=>'nullable|string|max:5000','phone'=>'nullable|string|max:5000','messenger'=>'nullable|string|max:5000','social'=>'nullable|string|max:5000']);
  DB::transaction(function()use($r,$id,$v){
   DB::statement('UPDATE saleshub_contacts SET version=version WHERE id=?',[$id]);
   $old=DB::table('saleshub_contacts')->find($id);abort_unless($old,404);
   abort_unless($r->user()->role==='admin'||(int)$old->owner_id===$r->user()->id,403,'Сначала возьмите контакт в работу');
   abort_unless((int)$old->version===(int)$v['version'],409,'Контакт изменён коллегой');
   $data=[];foreach(['full_name','company_raw','title','email','phone','messenger','social'] as $k)$data[$k]=Rules::clean($v[$k]??'');
   abort_if($data['full_name']==='',422,'Укажите имя');
   $cid=null;$co=$data['company_raw'];
   if($co!==''){DB::table('saleshub_companies')->insertOrIgnore(['name'=>$co,'name_key'=>Rules::key($co),'created_at'=>now(),'updated_at'=>now()]);$cid=DB::table('saleshub_companies')->where('name_key',Rules::key($co))->value('id');}
   $data['company_id']=$cid;$data['groups']=json_encode(Rules::groups($data['title']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
   DB::table('saleshub_contacts')->where('id',$id)->update($data+['version'=>DB::raw('version+1'),'updated_at'=>now()]);
   Leads::replaceChannels($id,$data['email'],$data['phone'],$data['messenger']);
   DB::table('saleshub_history')->insert(['contact_id'=>$id,'actor_id'=>$r->user()->id,'changes'=>json_encode(['identity_fields'=>array_keys($data)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_at'=>now()]);
  });
  return response()->json(['ok'=>true]);
 }
 public function export(Request $r){
  abort_unless($r->user()?->role==='admin',403);
  $q=Leads::rows(Leads::filters($this->filters($r),$r->user()->id))->orderBy('c.id');
  return response()->streamDownload(function()use($q){
   $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
   fputcsv($out,['ФИО','Компания','Должность','Почта','Телефон','Мессенджер','Соцсети','Статус обработки','Приоритет','Ответственный','Следующее действие','Дата следующего касания','Последнее касание','Комментарий','Балл','Возможный дубль'],',','"','');
   foreach($q->cursor() as $row){
    $values=[$row->full_name,$row->company_raw,$row->title,$row->email,$row->phone,$row->messenger,$row->social,$row->status,$row->priority,$row->owner_name??$row->owner_raw,$row->next_action,$row->next_at,$row->last_at,$row->comment,$row->score,$row->possible_duplicate?'Да':'Нет'];
    // Guard against spreadsheet formula injection when CSV is opened in Excel.
    $values=array_map(fn($v)=>is_string($v)&&preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$v)?"'".$v:$v,$values);
    fputcsv($out,$values,',','"','');
   }fclose($out);
  },'saleshub-'.now()->format('Y-m-d').'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store']);
 }
 public function analytics(){
  return Inertia::render('SalesHub/Analytics',['statuses'=>DB::table('saleshub_contacts')->selectRaw('status,count(*) total')->groupBy('status')->get(),'owners'=>DB::table('saleshub_contacts as c')->leftJoin('users as u','u.id','=','c.owner_id')->selectRaw("coalesce(u.username,nullif(c.owner_raw,''),'Не назначен') owner,count(*) total")->groupBy('owner')->get(),'groups'=>DB::table('saleshub_contacts as c')->crossJoin(DB::raw('json_each(c.groups) as g'))->selectRaw('g.value name,count(*) total')->groupBy('g.value')->get(),'classes'=>DB::query()->fromSub(Leads::base()->selectRaw(Leads::SCORE.' score'),'s')->selectRaw("CASE WHEN score>=80 THEN 'A' WHEN score>=60 THEN 'B' WHEN score>=40 THEN 'C' ELSE 'D' END class,count(*) total")->groupBy('class')->get(),'quality'=>Leads::base()->whereRaw(Leads::QUALITY)->count(),'duplicates'=>Leads::base()->whereRaw(Leads::DUP)->count()]);
 }
}
