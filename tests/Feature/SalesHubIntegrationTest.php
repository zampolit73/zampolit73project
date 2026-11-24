<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\SalesHubWorkbook;
use App\Services\SalesHubImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class SalesHubIntegrationTest extends TestCase {
 use RefreshDatabase;
 private function user(string $name,string $role='user'): User {
  return User::create(['username'=>$name,'role'=>$role,'password'=>Hash::make('test-password')]);
 }
 private function workbook(): string {
  $path=tempnam(sys_get_temp_dir(),'hub-xlsx-');$zip=new ZipArchive();$zip->open($path,ZipArchive::OVERWRITE);
  $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
  $rel='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  $zip->addFromString('xl/workbook.xml','<workbook xmlns="'.$ns.'" xmlns:r="'.$rel.'"><sheets><sheet name="Контакты" sheetId="1" r:id="rId1"/><sheet name="Справочник холдингов" sheetId="2" r:id="rId2"/></sheets></workbook>');
  $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
  $ch=['ФИО','Компания','Должность','Почта','Телефон','Мессенджер','Соцсети','Статус обработки','Приоритет','Ответственный','Следующее действие','Дата следующего касания','Последнее касание','Комментарий','Доступность','Каналов связи','Канал для старта','Проверка компании','Таблица-источник','Вкладка-источник','Ссылка на источник','Другие ссылки','Разночтения в компаниях'];
  $co=['Компания (как в базе)','Холдинг','Отрасль','Проверка','Целевая','ИНН','Сайт','Ответственный','Статус сотрудничества','Потребность','Комментарий'];
  $row=function(int $n,array $values){$xml='<row r="'.$n.'">';foreach($values as $i=>$v){$col=chr(65+$i);$xml.='<c r="'.$col.$n.'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1).'</t></is></c>';}return $xml.'</row>';};
  $data=['Synthetic A','Example Co','CIO/CTO','demo@example.org','+79990000000','@demo','','Новый','Не назначен','','','24.11.2026','','note','','','','','test-source','','https://example.org','',''];
  $data2=$data;$data2[0]='Synthetic B';
  $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="'.$ns.'"><sheetData>'.$row(1,$ch).$row(2,$data).$row(3,$data2).'</sheetData></worksheet>');
  $zip->addFromString('xl/worksheets/sheet2.xml','<worksheet xmlns="'.$ns.'"><sheetData>'.$row(3,$co).$row(4,['Example Co','Example Group','IT','Подтверждено','Да']).'</sheetData></worksheet>');
  $zip->close();return $path;
 }
 public function test_module_is_closed_by_default_and_existing_projects_still_work(): void {
  $this->get('/projects/sales-hub')->assertRedirect('/login');
  $u=$this->user('regular');
  $this->actingAs($u)->get('/projects/sales-hub')->assertForbidden();
  $this->get('/projects')->assertOk();
  DB::table('saleshub_access')->insert(['user_id'=>$u->id,'created_at'=>now(),'updated_at'=>now()]);
  $this->get('/projects/sales-hub')->assertOk();
  $this->get('/projects/sales-hub/import')->assertForbidden();
  $this->get('/projects/sales-hub/export')->assertForbidden();
  $this->get('/projects/sales-hub/companies')->assertOk();
  $this->get('/projects/sales-hub/analytics')->assertOk();
 }
 public function test_xlsx_preview_import_and_repeated_import_preserve_contacts(): void {
  $admin=$this->user('hub-admin','admin');$file=$this->workbook();
  try {
   $data=SalesHubWorkbook::read($file);
   $this->assertCount(2,$data['contacts']);$this->assertCount(1,$data['companies']);
   $this->assertSame('Synthetic A',$data['contacts'][0]['ФИО']);
   $stats=SalesHubImport::plan($data)['stats'];
   $this->assertSame(2,$stats['to_add']);$this->assertSame(2,$stats['possible_duplicates']);
   $this->assertDatabaseCount('saleshub_contacts',0);
   $result=SalesHubImport::commit($data,hash_file('sha256',$file),$stats,$admin->id);
   $this->assertSame(2,$result['total_after']);
   $this->assertDatabaseHas('saleshub_contacts',['full_name'=>'Synthetic A','next_at'=>'2026-11-24']);
   $this->assertDatabaseCount('saleshub_channels',6);
   $repeat=SalesHubImport::plan($data)['stats'];
   $this->assertSame(0,$repeat['to_add']);$this->assertSame(2,$repeat['unchanged']);
   $this->actingAs($admin)->get('/projects/sales-hub?preset=duplicates')->assertOk();
   $this->get('/projects/sales-hub/analytics')->assertOk();
  }finally{unlink($file);}
 }
 public function test_manager_cannot_steal_contact_and_user_deletion_keeps_records(): void {
  $a=$this->user('a-manager');$b=$this->user('b-manager');
  foreach([$a,$b] as $u)DB::table('saleshub_access')->insert(['user_id'=>$u->id,'created_at'=>now(),'updated_at'=>now()]);
  $id=DB::table('saleshub_contacts')->insertGetId(['full_name'=>'Synthetic','groups'=>'[]','raw'=>'{}','created_at'=>now(),'updated_at'=>now()]);
  $body=['version'=>1,'status'=>'В работе','priority'=>'Высокий','take'=>true];
  $this->actingAs($a)->patchJson('/projects/sales-hub/leads/'.$id,$body)->assertOk();
  $this->actingAs($b)->patchJson('/projects/sales-hub/leads/'.$id,array_replace($body,['version'=>2]))->assertForbidden();
  $a->delete();
  $this->assertDatabaseHas('saleshub_contacts',['id'=>$id,'owner_id'=>null]);
  $this->assertDatabaseHas('saleshub_history',['contact_id'=>$id,'actor_id'=>null]);
 }
}
