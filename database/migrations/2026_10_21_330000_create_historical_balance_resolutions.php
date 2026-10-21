<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up():void {
        Schema::create('historical_balance_resolutions',function(Blueprint $t){
            $t->id();$t->foreignId('historical_event_id')->constrained()->restrictOnDelete();$t->unsignedInteger('version');
            $t->longText('payload');$t->char('sha256',64);$t->foreignId('created_by')->constrained('users');$t->timestamps();
            $t->unique(['historical_event_id','version'],'history_balance_version_unique');
        });
    }
    public function down():void {
        if(DB::table('historical_balance_resolutions')->exists())throw new RuntimeException('Owner balance evidence must be retained.');
        Schema::dropIfExists('historical_balance_resolutions');
    }
};
