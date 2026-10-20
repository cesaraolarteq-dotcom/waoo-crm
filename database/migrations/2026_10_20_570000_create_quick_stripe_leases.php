<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        Schema::create('quick_stripe_leases',function(Blueprint $t){
            $t->id();$t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();$t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');$t->string('state',30);$t->dateTime('expires_at')->index();
            $t->longText('payload');$t->char('payload_sha256',64);$t->timestamps();
        });
        Schema::create('quick_stripe_lease_events',function(Blueprint $t){
            $t->id();$t->foreignId('quick_stripe_lease_id')->constrained()->restrictOnDelete();$t->unsignedInteger('version');
            $t->longText('payload');$t->char('payload_sha256',64);$t->timestamps();$t->unique(['quick_stripe_lease_id','version'],'quick_stripe_lease_version_unique');
        });
    }
    public function down():void{Schema::dropIfExists('quick_stripe_lease_events');Schema::dropIfExists('quick_stripe_leases');}
};
