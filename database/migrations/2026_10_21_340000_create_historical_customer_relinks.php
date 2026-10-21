<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {
        Schema::create('historical_customer_relinks', function(Blueprint $t) {
            $t->id(); $t->foreignId('historical_event_id')->constrained()->restrictOnDelete();
            $t->foreignId('revision_id')->constrained('historical_event_revisions')->restrictOnDelete();
            $t->char('contact_key',64)->nullable()->index(); $t->unsignedInteger('version');
            $t->longText('payload'); $t->char('sha256',64); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['historical_event_id','version'],'history_relink_version_unique');
        });
    }
    public function down(): void {
        if(DB::table('historical_customer_relinks')->exists()) throw new RuntimeException('Customer correction evidence exists; use a forward migration.');
        Schema::dropIfExists('historical_customer_relinks');
    }
};
