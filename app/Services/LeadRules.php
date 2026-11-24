<?php
namespace App\Services;

final class LeadRules {
 public const STATUSES=['Новый','В работе','Первый контакт','Ответил','Встреча','Переговоры','Отложен','Неактуален','Закрыт'];
 public const PRIORITIES=['Не назначен','Высокий','Средний','Низкий'];
 public const GROUPS=[
  'CIO'=>'(?<![a-z0-9])cio(?![a-z0-9])|chief information officer|директор.{0,18}(информационн|ит|it)[ -]|(?:ит|it)[ -]директор|директор по (?:ит|it)(?![а-яa-z])',
  'CTO'=>'(?<![a-z0-9])cto(?![a-z0-9])|chief technology officer|технический директор|директор по технологиям',
  'CDO'=>'(?<![a-z0-9])cdo(?![a-z0-9])|chief data officer|директор по данным',
  'CPO'=>'(?<![a-z0-9])cpo(?![a-z0-9])|chief product officer|директор по продукт',
  'CISO'=>'(?<![a-z0-9])ciso(?![a-z0-9])|chief information security officer|директор по информационной безопасности',
  'Руководители разработки'=>'head of (?:software )?(?:development|engineering)|engineering director|руководитель.{0,35}разработки|директор.{0,20}разработки',
  'Руководители QA'=>'head of (?:qa|testing|quality assurance)|руководитель.{0,35}тестирован|директор.{0,25}тестирован',
  'Закупки'=>'procurement|(?:руководитель|директор|начальник).{0,30}закуп',
  'HR'=>'(?<![a-z0-9])hrd(?![a-z0-9])|hr director|head of (?:hr|recruitment|talent acquisition)|директор по персоналу|руководитель.{0,30}(?:подбора|рекрут)',
  'CEO'=>'(?<![a-z0-9])(?:ceo|сeo)(?![a-z0-9])|chief executive officer|генеральный директор',
 ];
 public static function clean(mixed $v): string {
  $s=trim((string)$v);return in_array(mb_strtolower($s),['данных нет','не назначено'],true)?'':$s;
 }
 public static function key(mixed $v): string {return mb_strtolower(preg_replace('/\s+/u',' ',self::clean($v))??'');}
 public static function groups(string $title): array {
  return array_keys(array_filter(self::GROUPS,fn($p)=>preg_match('~'.$p.'~iu',$title)===1));
 }
 public static function channels(string $email,string $phone,string $messenger): array {
  $result=[];
  $add=function($kind,$value)use(&$result){$result[$kind.':'.$value]=['kind'=>$kind,'value'=>$value];};
  foreach(explode(';',$email) as $e){$e=mb_strtolower(trim($e));if(preg_match('/^[^\s@;,]+@[^\s@;,]+\.[^\s@;,]+$/u',$e))$add('email',$e);}
  foreach(explode(';',$phone) as $p){$p=preg_replace('/\D/','',$p);if(strlen($p)===11&&str_starts_with($p,'8'))$p='7'.substr($p,1);elseif(strlen($p)===10)$p='7'.$p;if(strlen($p)>=9&&strlen($p)<=15)$add('phone',$p);}
  foreach(explode(';',$messenger) as $m){$m=rtrim(preg_replace('/^telegram\s*/i','',mb_strtolower(trim($m))),'/');if(preg_match('~^(?:https?://(?:www\.)?(?:t\.me|telegram\.me)/|@)~',$m))$m='tg:'.preg_replace('~^(?:https?://(?:www\.)?(?:t\.me|telegram\.me)/|@)~','',$m);if(strlen($m)>3&&preg_match('~^(tg:|https?://)~',$m))$add('messenger',$m);}
  return array_values($result);
 }
 public static function date(mixed $v): ?string {
  $s=self::clean($v);foreach(['!Y-m-d','!d.m.Y','!d/m/Y','!Y-m-d H:i:s'] as $fmt){$d=\DateTimeImmutable::createFromFormat($fmt,$s);$e=\DateTimeImmutable::getLastErrors();if($d&&($e===false||(!$e['warning_count']&&!$e['error_count'])))return $d->format('Y-m-d');}return null;
 }
}
