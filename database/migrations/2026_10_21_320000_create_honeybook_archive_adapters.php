<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up():void {
        Schema::create('honeybook_event_facts',function(Blueprint $t){
            $t->id();$t->foreignId('event_id')->constrained()->restrictOnDelete();$t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');$t->longText('payload');$t->char('sha256',64);$t->foreignId('created_by')->constrained('users');$t->timestamps();
            $t->unique(['event_id','version']);
        });
        Schema::create('honeybook_originals',function(Blueprint $t){
            $t->id();$t->char('project_key',64)->index();$t->char('identity_sha256',64)->unique();$t->longText('payload');$t->char('sha256',64);$t->foreignId('created_by')->constrained('users');$t->timestamps();
        });
    }
    public function down():void {
        foreach(['honeybook_event_facts','honeybook_originals'] as $table)if(DB::table($table)->exists())throw new RuntimeException('Archive source evidence must be retained.');
        Schema::dropIfExists('honeybook_originals');Schema::dropIfExists('honeybook_event_facts');
    }
};
