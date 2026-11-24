<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;

final class CsvImport {
 public static function rows(string $path,string $required): \Generator {
  $f=fopen($path,'rb');if(!$f)throw new \RuntimeException('Не удалось открыть CSV');
  try{
   $header=fgetcsv($f,0,',','"','');if(!$header)throw new \RuntimeException('Пустой CSV');
   $header=array_map(fn($v)=>trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$v)),$header);
   // Google Sheets registry may include two introductory rows.
   for($i=0;!in_array($required,$header,true)&&$i<2;$i++){$next=fgetcsv($f,0,',','"','');if(!$next)break;$header=array_map(fn($v)=>trim((string)$v),$next);}
   if(!in_array($required,$header,true))throw new \RuntimeException('Не найдена колонка '.$required);
   while(($row=fgetcsv($f,0,',','"',''))!==false){
    $row=array_pad(array_slice($row,0,count($header)),count($header),'');$r=[];foreach($header as $i=>$h)if($h!=='')$r[$h]=(string)$row[$i];if(LeadRules::clean($r[$required]??'')!=='')yield $r;
   }
  }finally{fclose($f);}
 }
 public static function run(string $saleshub_contacts,?string $saleshub_companies=null): array {
  return DB::transaction(function()use($saleshub_contacts,$saleshub_companies){
   // First write obtains SQLite writer lock; rollback on any failed row.
   DB::statement('UPDATE saleshub_contacts SET version=version WHERE 0');
   if(DB::table('saleshub_contacts')->exists())throw new \RuntimeException('База не пуста; повторный первоначальный импорт запрещён');
   $cache=[];$now=now();$count=0;
   $get=function($r,$k){return LeadRules::clean($r[$k]??'');};
   if($saleshub_companies)foreach(self::rows($saleshub_companies,'Компания (как в базе)') as $r){
    $name=$get($r,'Компания (как в базе)');$key=LeadRules::key($name);
    DB::table('saleshub_companies')->updateOrInsert(['name_key'=>$key],['name'=>$name,'holding'=>$get($r,'Холдинг'),'industry'=>$get($r,'Отрасль'),'verification'=>$get($r,'Проверка')?:'Не проверено','target'=>$get($r,'Целевая')==='Да','inn'=>$get($r,'ИНН'),'website'=>$get($r,'Сайт'),'owner_raw'=>$get($r,'Ответственный'),'relationship'=>$get($r,'Статус сотрудничества'),'need'=>$get($r,'Потребность'),'comment'=>$get($r,'Комментарий'),'created_at'=>$now,'updated_at'=>$now]);
   }
   foreach(DB::table('saleshub_companies')->get(['id','name_key']) as $co)$cache[$co->name_key]=$co->id;
   foreach(self::rows($saleshub_contacts,'ФИО') as $r){
    if(!array_key_exists('Компания',$r)||!array_key_exists('Должность',$r))throw new \RuntimeException('CSV не соответствует листу Контакты');
    $name=$get($r,'ФИО');$co=$get($r,'Компания');$key=LeadRules::key($co);$cid=null;
    if($co!==''){if(!isset($cache[$key]))$cache[$key]=DB::table('saleshub_companies')->insertGetId(['name'=>$co,'name_key'=>$key,'created_at'=>$now,'updated_at'=>$now]);$cid=$cache[$key];}
    $title=$get($r,'Должность');$email=$get($r,'Почта');$phone=$get($r,'Телефон');$messenger=$get($r,'Мессенджер');$status=$get($r,'Статус обработки')?:'Новый';$priority=$get($r,'Приоритет')?:'Не назначен';
    if(!in_array($status,LeadRules::STATUSES,true)||!in_array($priority,LeadRules::PRIORITIES,true))throw new \RuntimeException('Неизвестный статус или приоритет; запись '.($count+1));
    $date=$get($r,'Дата следующего касания');$parsed=LeadRules::date($date);if($date!==''&&!$parsed)throw new \RuntimeException('Нераспознанная дата; запись '.($count+1));
    $id=DB::table('saleshub_contacts')->insertGetId(['company_id'=>$cid,'full_name'=>$name,'company_raw'=>$co,'title'=>$title,'email'=>$email,'phone'=>$phone,'messenger'=>$messenger,'social'=>$get($r,'Соцсети'),'status'=>$status,'priority'=>$priority,'owner_raw'=>$get($r,'Ответственный'),'next_action'=>$get($r,'Следующее действие'),'next_at'=>$parsed,'last_at'=>$get($r,'Последнее касание'),'comment'=>$get($r,'Комментарий'),'groups'=>json_encode(LeadRules::groups($title),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'raw'=>json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'company_check'=>$get($r,'Проверка компании')==='Проверить'||$get($r,'Разночтения в компаниях')!=='','created_at'=>$now,'updated_at'=>$now]);
    LeadRepository::replaceChannels($id,$email,$phone,$messenger);$count++;
   }
   if(!$count)throw new \RuntimeException('Нет контактов');
   return ['saleshub_contacts'=>$count,'saleshub_companies'=>DB::table('saleshub_companies')->count(),'channels'=>DB::table('saleshub_channels')->count()];
  });
 }
}
