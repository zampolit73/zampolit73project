<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::create('saleshub_access',function(Blueprint $t){$t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();$t->timestamps();});
  Schema::create('saleshub_imports',function(Blueprint $t){$t->id();$t->string('digest')->unique();$t->unsignedInteger('contacts');$t->unsignedInteger('companies');$t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();});
  Schema::create('saleshub_companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('name_key')->unique();$t->string('holding')->default('');$t->string('industry')->default('');$t->string('verification')->default('Не проверено');$t->boolean('target')->default(false);$t->string('inn')->default('');$t->string('website')->default('');$t->string('owner_raw')->default('');$t->string('relationship')->default('');$t->text('need')->default('');$t->text('comment')->default('');$t->unsignedInteger('version')->default(1);$t->timestamps();$t->index(['holding','verification']);$t->index('industry');});
  Schema::create('saleshub_contacts',function(Blueprint $t){$t->id();$t->foreignId('company_id')->nullable()->constrained('saleshub_companies')->nullOnDelete();$t->string('full_name');$t->string('company_raw')->default('');$t->string('title')->default('');$t->text('email')->default('');$t->text('phone')->default('');$t->text('messenger')->default('');$t->text('social')->default('');$t->string('status')->default('Новый');$t->string('priority')->default('Не назначен');$t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();$t->string('owner_raw')->default('');$t->text('next_action')->default('');$t->date('next_at')->nullable();$t->text('last_at')->default('');$t->text('comment')->default('');$t->json('groups');$t->json('raw');$t->boolean('company_check')->default(false);$t->unsignedInteger('version')->default(1);$t->timestamps();$t->index(['owner_id','status']);$t->index(['status','next_at']);$t->index('company_id');});
  Schema::create('saleshub_channels',function(Blueprint $t){$t->foreignId('contact_id')->constrained('saleshub_contacts')->cascadeOnDelete();$t->string('kind');$t->string('value');$t->primary(['contact_id','kind','value']);$t->index(['kind','value','contact_id']);});
  Schema::create('saleshub_history',function(Blueprint $t){$t->id();$t->foreignId('contact_id')->constrained('saleshub_contacts')->cascadeOnDelete();$t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();$t->json('changes');$t->timestamp('created_at');$t->index(['contact_id','created_at']);});
 }
 public function down(): void { foreach(['saleshub_imports','saleshub_access','saleshub_history','saleshub_channels','saleshub_contacts','saleshub_companies'] as $table)Schema::dropIfExists($table); }
};
