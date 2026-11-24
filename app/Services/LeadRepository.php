<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;

final class LeadRepository {
 public const SCORE="(CASE WHEN json_array_length(c.groups)>0 THEN 40 ELSE 0 END + CASE WHEN co.target=1 THEN 25 ELSE 0 END + CASE WHEN c.messenger<>'' THEN 15 ELSE 0 END + CASE WHEN c.email<>'' THEN 10 ELSE 0 END + CASE WHEN c.phone<>'' THEN 10 ELSE 0 END)";
 public const DUP="EXISTS(SELECT 1 FROM saleshub_channels a JOIN saleshub_channels b ON a.kind=b.kind AND a.value=b.value AND a.contact_id<>b.contact_id WHERE a.contact_id=c.id)";
 public const FREE="c.owner_id IS NULL AND c.owner_raw IN ('','Не назначен')";
 public const QUALITY="(c.company_raw='' OR c.title='' OR (c.email='' AND c.phone='' AND c.messenger='' AND c.social='') OR c.company_check=1 OR (c.email<>'' AND NOT EXISTS (SELECT 1 FROM saleshub_channels x WHERE x.contact_id=c.id AND x.kind='email')) OR (c.phone<>'' AND NOT EXISTS (SELECT 1 FROM saleshub_channels x WHERE x.contact_id=c.id AND x.kind='phone')))";
 public static function base(): Builder {
  return DB::table('saleshub_contacts as c')->leftJoin('saleshub_companies as co','co.id','=','c.company_id')->leftJoin('users as u','u.id','=','c.owner_id');
 }
 public static function rows(Builder $q): Builder {
  return $q->select(['c.id','c.company_id','c.full_name','c.company_raw','c.title','c.email','c.phone','c.messenger','c.social','c.status','c.priority','c.owner_id','c.owner_raw','u.username as owner_name','c.next_action','c.next_at','c.last_at','c.comment','c.groups','c.version','co.holding','co.industry','co.verification','co.target'])
   ->selectRaw(self::SCORE.' as score, '.self::DUP.' as possible_duplicate, '.self::QUALITY.' as quality_check')
   ->selectRaw("EXISTS(SELECT 1 FROM saleshub_contacts peer WHERE peer.company_id=c.company_id AND peer.id<>c.id AND ((peer.owner_id IS NOT NULL AND (c.owner_id IS NULL OR peer.owner_id<>c.owner_id)) OR (peer.owner_raw NOT IN ('','Не назначен') AND peer.owner_raw<>c.owner_raw))) as company_claimed");
 }
 // SQLite lower() is ASCII only; use a per-connection Unicode-aware function.
 public static function unicode(): void {
  DB::connection()->getPdo()->sqliteCreateFunction('hub_lower',fn($s)=>mb_strtolower((string)$s),1);
  DB::connection()->getPdo()->sqliteCreateFunction('hub_role_contains',function($title,$word){$word=(string)$word;return preg_match('/^(CEO|CIO|CTO|CFO|COO|CISO|CPO|CMO|CHRO|CDO|CSO|CRO|CBDO|CCO|HR|CLO|CAO|VP|PM|PO|QA|IT)$/i',$word)?(int)(preg_match('~(?<![a-z0-9])'.preg_quote($word,'~').'(?![a-z0-9])~iu',(string)$title)===1):(int)str_contains(mb_strtolower((string)$title),mb_strtolower($word));},2);
 }
 public static function filters(array $f,int $uid): Builder {
  self::unicode();$q=self::base();
  $active=fn($b)=>$b->whereNotIn('c.status',['Закрыт','Неактуален']);
  $preset=$f['preset']??'manual';
  if($preset!=='manual'){
   switch($preset){
    case 'hot':$active($q)->whereRaw(self::SCORE.'>=60')->whereRaw("(c.email<>'' OR c.phone<>'' OR c.messenger<>'' OR c.social<>'')");break;
    case 'it':$active($q)->whereRaw("EXISTS(SELECT 1 FROM json_each(c.groups) WHERE value IN ('CIO','CTO','CDO','Руководители разработки'))");break;
    case 'messenger':$q->where('c.status','Новый')->where('c.messenger','<>','');break;
    case 'procurement':$active($q)->whereJsonContains('c.groups','Закупки');break;
    case 'free':$active($q)->whereRaw(self::FREE);break;
    case 'due':$active($q)->whereDate('c.next_at','<=',now()->toDateString());break;
    case 'duplicates':$q->whereRaw(self::DUP);break;
    case 'quality':$q->whereRaw(self::QUALITY);break;
   }
  }else{
   if(!empty($f['q'])){$v=mb_strtolower(trim($f['q']));$q->whereRaw('(instr(hub_lower(c.full_name),?)>0 OR instr(hub_lower(c.company_raw),?)>0 OR instr(hub_lower(c.title),?)>0)',[$v,$v,$v]);}
   if(!empty($f['company'])){$v=mb_strtolower(trim($f['company']));$q->whereRaw(($f['company_mode']??'contains')==='exact'?'hub_lower(trim(c.company_raw))=?':'instr(hub_lower(c.company_raw),?)>0',[$v]);}
   $titles=array_filter(array_map(fn($v)=>trim((string)$v),$f['titles']??[]));if($titles)$q->where(function($b)use($titles,$f){foreach($titles as $v)$b->orWhereRaw(($f['title_mode']??'contains')==='exact'?'hub_lower(trim(c.title))=?':'hub_role_contains(c.title,?)=1',[mb_strtolower($v)]);});
   if(!empty($f['groups']))$q->where(function($b)use($f){foreach($f['groups'] as $g)$b->orWhereJsonContains('c.groups',$g);});
   if(!empty($f['holding']))$q->where('co.verification','Подтверждено')->whereRaw('hub_lower(co.holding)=?',[mb_strtolower(trim($f['holding']))]);
   if(!empty($f['industry']))$q->whereRaw('hub_lower(co.industry)=?',[mb_strtolower(trim($f['industry']))]);
   foreach(['status','priority'] as $k)if(!empty($f[$k]))$q->where('c.'.$k,$f[$k]);
   foreach(['email','phone','messenger','social'] as $k)if(!empty($f[$k]))$q->where('c.'.$k,'<>','');
   if(!empty($f['min_score']))$q->whereRaw(self::SCORE.'>=?',[$f['min_score']]);
   if(!empty($f['check']))$q->where('c.company_check',1);
  }
  if(($f['owner']??'')==='mine')$q->where('c.owner_id',$uid);
  elseif(($f['owner']??'')==='free')$q->whereRaw(self::FREE);
  elseif(!empty($f['owner']))$q->where('c.owner_id',(int)$f['owner']);
  if(!empty($f['company_id']))$q->where('c.company_id',$f['company_id']);
  return $q;
 }
 public static function serialize($row): array {
  $a=(array)$row;$a['groups']=json_decode($a['groups'],true)?:[];return $a;
 }
 public static function replaceChannels(int $id,string $email,string $phone,string $messenger): void {
  DB::table('saleshub_channels')->where('contact_id',$id)->delete();
  $channels=array_map(fn($a)=>['contact_id'=>$id]+$a,LeadRules::channels($email,$phone,$messenger));
  if($channels)DB::table('saleshub_channels')->insert($channels);
 }
}
