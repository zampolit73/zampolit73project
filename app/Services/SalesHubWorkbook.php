<?php
namespace App\Services;

use ZipArchive;
use SimpleXMLElement;

final class SalesHubWorkbook {
 private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
 private const REL='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
 private const CONTACT_HEADERS=['ФИО','Компания','Должность','Почта','Телефон','Мессенджер','Соцсети','Статус обработки','Приоритет','Ответственный','Следующее действие','Дата следующего касания','Последнее касание','Комментарий','Доступность','Каналов связи','Канал для старта','Проверка компании','Таблица-источник','Вкладка-источник','Ссылка на источник','Другие ссылки','Разночтения в компаниях'];
 private const COMPANY_HEADERS=['Компания (как в базе)','Холдинг','Отрасль','Проверка','Целевая','ИНН','Сайт','Ответственный','Статус сотрудничества','Потребность','Комментарий'];
 private static function xml(ZipArchive $z,string $path,int $max=24000000): SimpleXMLElement {
  $stat=$z->statName($path);if(!$stat||$stat['size']>$max)throw new SalesHubImportError('Недопустимая структура XLSX');
  $raw=$z->getFromName($path);if($raw===false||preg_match('/<!DOCTYPE|<!ENTITY/i',$raw))throw new SalesHubImportError('Недопустимый XML');
  $xml=@simplexml_load_string($raw,SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT);if(!$xml)throw new SalesHubImportError('Некорректный XML XLSX');
  return $xml;
 }
 private static function text(SimpleXMLElement $x): string {
  $parts=$x->xpath('.//*[local-name()="t" and not(ancestor::*[local-name()="rPh"])]')?:[];
  return implode('',array_map(fn($v)=>(string)$v,$parts));
 }
 private static function sheet(ZipArchive $z,string $path,array $strings,int $headerAt,array $required,bool $date1904): array {
  $xml=self::xml($z,$path);
  $rows=$xml->children(self::NS)->sheetData->row;
  if(count($rows)>22000)throw new SalesHubImportError('Слишком много строк в листе');
  $headers=[];$result=[];
  foreach($rows as $row){
   $n=(int)$row['r'];if($n<$headerAt)continue;
   $cells=[];
   foreach($row->c as $c){
    $addr=(string)$c['r'];if(!preg_match('/^([A-Z]{1,3})[0-9]+$/',$addr,$m))continue;
    $col=$m[1];if(strlen($col)>2)continue;
    $t=(string)$c['t'];$v=(string)$c->v;
    if($t==='s'){if(!ctype_digit($v)||!array_key_exists((int)$v,$strings))throw new SalesHubImportError('Некорректная строка XLSX');$v=$strings[(int)$v];}
    elseif($t==='inlineStr')$v=self::text($c);
    elseif($t==='e'){$v='';if(isset($headers[$col])&&in_array($headers[$col],array_slice(self::CONTACT_HEADERS,0,14),true))throw new SalesHubImportError('Ошибка формулы в исходном поле, строка '.$n);}
    if(strlen($v)>100000)throw new SalesHubImportError('Слишком длинное значение, строка '.$n);
    if($n===$headerAt){$headers[$col]=trim($v);continue;}
    if(!isset($headers[$col])||$headers[$col]==='')continue;
    if(in_array($headers[$col],['Дата следующего касания','Последнее касание'],true)&&($t===''||$t==='n')&&is_numeric($v)&&$v>=1&&$v<100000){
      $epoch=new \DateTimeImmutable($date1904?'1904-01-01':'1899-12-30');$v=$epoch->modify('+'.(int)$v.' days')->format('Y-m-d');
    }
    $cells[$headers[$col]]=$v;
   }
   if($n===$headerAt){foreach($required as $h)if(!in_array($h,$headers,true))throw new SalesHubImportError('Не найдена колонка: '.$h);continue;}
   if(array_filter($cells,fn($v)=>trim($v)!==''))$result[]=$cells;
  }
  if(!$headers)throw new SalesHubImportError('Не найдены заголовки листа');
  return $result;
 }
 public static function read(string $path): array {
  $z=new ZipArchive();if($z->open($path,ZipArchive::RDONLY)!==true)throw new SalesHubImportError('Файл не является XLSX');
  try{
   if($z->numFiles>1500)throw new SalesHubImportError('Слишком много частей XLSX');
   $total=0;for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$total+=$s['size'];if($total>150000000)throw new SalesHubImportError('Слишком большой распакованный XLSX');}
   $book=self::xml($z,'xl/workbook.xml',1000000);
   $rels=self::xml($z,'xl/_rels/workbook.xml.rels',1000000);
   $targets=[];foreach($rels->children('http://schemas.openxmlformats.org/package/2006/relationships') as $r){
    $target=(string)$r['Target'];if((string)$r['TargetMode']==='External')continue;
    $target=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;
    if(preg_match('~^xl/worksheets/[A-Za-z0-9_.-]+\.xml$~D',$target))$targets[(string)$r['Id']]=$target;
   }
   $strings=[];if($z->locateName('xl/sharedStrings.xml')!==false){
    $ss=self::xml($z,'xl/sharedStrings.xml');foreach($ss->children(self::NS)->si as $si){$strings[]=self::text($si);if(count($strings)>400000)throw new SalesHubImportError('Слишком много строк XLSX');}unset($ss);
   }
   $sheets=[];$b=$book->children(self::NS);$date1904=in_array((string)$b->workbookPr['date1904'],['1','true'],true);
   foreach($b->sheets->sheet as $s){$name=(string)$s['name'];$rid=(string)$s->attributes(self::REL)['id'];if(isset($targets[$rid]))$sheets[$name]=$targets[$rid];}
   foreach(['Контакты','Справочник холдингов'] as $name)if(!isset($sheets[$name]))throw new SalesHubImportError('Нет листа «'.$name.'»');
   $contacts=self::sheet($z,$sheets['Контакты'],$strings,1,self::CONTACT_HEADERS,$date1904);
   $companies=self::sheet($z,$sheets['Справочник холдингов'],$strings,3,self::COMPANY_HEADERS,$date1904);
   // The source includes calculated helper cells. Keep every actual contact row, not formula-only tail rows.
   $contacts=array_values(array_filter($contacts,fn($r)=>array_filter(array_map(fn($h)=>LeadRules::clean($r[$h]??''),['ФИО','Компания','Должность','Почта','Телефон','Мессенджер','Соцсети']))));
   $companies=array_values(array_filter($companies,fn($r)=>LeadRules::clean($r['Компания (как в базе)']??'')!==''));
   if(!$contacts||count($contacts)>20000)throw new SalesHubImportError('Некорректное количество контактов');
   return compact('contacts','companies');
  }finally{$z->close();}
 }
}
