<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;

final class SalesHubImport {
 private static function get(array $r,string $k): string{return LeadRules::clean($r[$k]??'');}
 private static function identity(array $r): string {
  return hash('sha256',json_encode(array_map(fn($k)=>LeadRules::key(self::get($r,$k)),['ФИО','Компания','Должность','Почта','Телефон','Мессенджер','Соцсети']),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
 }
 private static function nameKey(string $name,string $company): string{return LeadRules::key($name).'|'.LeadRules::key($company);}
 public static function plan(array $data): array {
  $existing=DB::table('saleshub_contacts')->count();
  $known=[];$names=[];$channels=[];$existingCompanies=[];
  foreach(DB::table('saleshub_companies')->get(['name_key']) as $c)$existingCompanies[$c->name_key]=true;
  if($existing){
   foreach(DB::table('saleshub_contacts')->cursor() as $c){
    $raw=json_decode($c->raw,true)?:[];
    if($raw)$known[self::identity($raw)]=true;
    $known[self::identity(['ФИО'=>$c->full_name,'Компания'=>$c->company_raw,'Должность'=>$c->title,'Почта'=>$c->email,'Телефон'=>$c->phone,'Мессенджер'=>$c->messenger,'Соцсети'=>$c->social])]=true;
    if($c->full_name!=='')$names[self::nameKey($c->full_name,$c->company_raw)]=true;
   }
   foreach(DB::table('saleshub_channels')->cursor() as $c)$channels[$c->kind.':'.$c->value]=true;
  }
  $new=[];$same=0;$review=0;$reachable=0;$dups=[];$seen=[];$newCompanies=[];
  foreach($data['companies'] as $r){$k=LeadRules::key(self::get($r,'Компания (как в базе)'));if(!isset($existingCompanies[$k]))$newCompanies[$k]=$r;}
  foreach($data['contacts'] as $i=>$r){
   $get=fn($k)=>self::get($r,$k);
   $status=$get('Статус обработки')?:'Новый';$priority=$get('Приоритет')?:'Не назначен';$date=$get('Дата следующего касания');
   if(!in_array($status,LeadRules::STATUSES,true)||!in_array($priority,LeadRules::PRIORITIES,true)||($date!==''&&!LeadRules::date($date)))throw new SalesHubImportError('Неверный статус, приоритет или дата в записи '.($i+1));
   if($get('Почта')!==''||$get('Телефон')!==''||$get('Мессенджер')!==''||$get('Соцсети')!=='')$reachable++;
   $ch=LeadRules::channels($get('Почта'),$get('Телефон'),$get('Мессенджер'));$overlap=false;
   foreach($ch as $c){$k=$c['kind'].':'.$c['value'];if(isset($seen[$k])){$dups[$i]=true;$dups[$seen[$k]]=true;}else $seen[$k]=$i;if(isset($channels[$k]))$overlap=true;}
   $id=self::identity($r);$nk=self::nameKey($get('ФИО'),$get('Компания'));
   if($existing&&isset($known[$id])){$same++;continue;}
   if($existing&&($overlap||($get('ФИО')!==''&&isset($names[$nk])))){$review++;continue;}
   $new[]=$r;
   if($existing){$known[$id]=true;$names[$nk]=true;foreach($ch as $c)$channels[$c['kind'].':'.$c['value']]=true;}
   $co=$get('Компания');$k=LeadRules::key($co);if($co!==''&&!isset($existingCompanies[$k])&&!isset($newCompanies[$k]))$newCompanies[$k]=['Компания (как в базе)'=>$co];
  }
  return ['stats'=>['source_contacts'=>count($data['contacts']),'source_companies'=>count($data['companies']),'existing'=>$existing,'to_add'=>count($new),'new_companies'=>count($newCompanies),'unchanged'=>$same,'review'=>$review,'reachable'=>$reachable,'possible_duplicates'=>count($dups)],'contacts'=>$new,'companies'=>$newCompanies];
 }
 public static function commit(array $data,string $digest,array $expected,int $actor): array {
  return DB::transaction(function()use($data,$digest,$expected,$actor){
   DB::statement('UPDATE saleshub_contacts SET version=version WHERE 0');
   if(DB::table('saleshub_imports')->where('digest',$digest)->exists())throw new SalesHubImportError('Этот файл уже импортирован');
   $p=self::plan($data);if($p['stats']!==$expected)throw new SalesHubImportError('База изменилась. Повторите предварительную проверку.');
   $now=now();$cache=[];$owners=[];
   foreach(DB::table('users')->where(fn($q)=>$q->where('role','admin')->orWhereIn('id',DB::table('saleshub_access')->select('user_id')))->get(['id','username']) as $u)$owners[LeadRules::key($u->username)]=$u->id;
   foreach($p['companies'] as $key=>$r){
    $g=fn($k)=>self::get($r,$k);
    DB::table('saleshub_companies')->insert(['name'=>$g('Компания (как в базе)'),'name_key'=>$key,'holding'=>$g('Холдинг'),'industry'=>$g('Отрасль'),'verification'=>$g('Проверка')?:'Не проверено','target'=>$g('Целевая')==='Да','inn'=>$g('ИНН'),'website'=>$g('Сайт'),'owner_raw'=>$g('Ответственный'),'relationship'=>$g('Статус сотрудничества'),'need'=>$g('Потребность'),'comment'=>$g('Комментарий'),'created_at'=>$now,'updated_at'=>$now]);
   }
   foreach(DB::table('saleshub_companies')->get(['id','name_key']) as $c)$cache[$c->name_key]=$c->id;
   foreach($p['contacts'] as $r){
    $g=fn($k)=>self::get($r,$k);$co=$g('Компания');$title=$g('Должность');$email=$g('Почта');$phone=$g('Телефон');$messenger=$g('Мессенджер');$owner=$g('Ответственный');$uid=$owners[LeadRules::key($owner)]??null;
    $id=DB::table('saleshub_contacts')->insertGetId(['company_id'=>$cache[LeadRules::key($co)]??null,'full_name'=>$g('ФИО'),'company_raw'=>$co,'title'=>$title,'email'=>$email,'phone'=>$phone,'messenger'=>$messenger,'social'=>$g('Соцсети'),'status'=>$g('Статус обработки')?:'Новый','priority'=>$g('Приоритет')?:'Не назначен','owner_id'=>$uid,'owner_raw'=>$uid?'':$owner,'next_action'=>$g('Следующее действие'),'next_at'=>LeadRules::date($g('Дата следующего касания')),'last_at'=>$g('Последнее касание'),'comment'=>$g('Комментарий'),'groups'=>json_encode(LeadRules::groups($title),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'raw'=>json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'company_check'=>$g('Проверка компании')==='Проверить'||$g('Разночтения в компаниях')!=='','created_at'=>$now,'updated_at'=>$now]);
    LeadRepository::replaceChannels($id,$email,$phone,$messenger);
   }
   DB::table('saleshub_imports')->insert(['digest'=>$digest,'contacts'=>count($p['contacts']),'companies'=>count($p['companies']),'actor_id'=>$actor,'created_at'=>$now,'updated_at'=>$now]);
   return $p['stats']+['total_after'=>DB::table('saleshub_contacts')->count()];
  });
 }
}
