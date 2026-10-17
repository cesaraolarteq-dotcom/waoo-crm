<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up():void
    {
        Schema::create('imported_project_links',function(Blueprint $t){$t->id();$t->char('project_key',64)->unique();$t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();$t->foreignId('customer_id')->constrained()->restrictOnDelete();$t->unsignedInteger('version')->default(1);$t->unsignedBigInteger('current_revision_id')->nullable();$t->char('domain_sha256',64);$t->timestamps();});
        Schema::create('imported_project_revisions',function(Blueprint $t){$t->id();$t->foreignId('imported_project_link_id')->constrained()->restrictOnDelete();$t->unsignedInteger('revision');$t->foreignId('import_batch_id')->constrained()->restrictOnDelete();$t->foreignId('import_row_id')->constrained()->restrictOnDelete();$t->unsignedInteger('event_version');$t->json('source_snapshot');$t->json('normalized');$t->json('mapping');$t->char('source_sha256',64);$t->char('file_sha256',64);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->unique(['imported_project_link_id','revision'],'imported_revision_number_unique');});
        Schema::create('imported_ledger_lines',function(Blueprint $t){$t->id();$t->foreignId('imported_project_revision_id')->constrained()->restrictOnDelete();$t->unsignedInteger('source_position');$t->bigInteger('amount_cents');$t->date('received_date');$t->string('method',120);$t->string('source',20)->default('honeybook');$t->timestamps();$t->unique(['imported_project_revision_id','source_position'],'imported_source_position_unique');});
        Schema::create('import_service_mappings',function(Blueprint $t){$t->id();$t->char('source_key',64)->unique();$t->unsignedInteger('version')->default(1);$t->json('selection');$t->foreignId('updated_by')->constrained('users')->restrictOnDelete();$t->timestamps();});
        Schema::table('booking_evidence',function(Blueprint $t){$t->dateTime('received_at')->nullable()->change();$t->foreignId('imported_revision_id')->nullable()->constrained('imported_project_revisions')->restrictOnDelete();$t->date('received_date')->nullable();});
    }
    public function down():void
    {
        if(DB::table('booking_evidence')->whereNull('received_at')->exists())throw new RuntimeException('Day-only imported booking evidence requires a forward migration; no timestamp will be invented.');
        Schema::table('booking_evidence',function(Blueprint $t){$t->dropForeign(['imported_revision_id']);$t->dropColumn(['imported_revision_id','received_date']);$t->dateTime('received_at')->nullable(false)->change();});
        Schema::dropIfExists('import_service_mappings');Schema::dropIfExists('imported_ledger_lines');Schema::dropIfExists('imported_project_revisions');Schema::dropIfExists('imported_project_links');
    }
};
