<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('joint_capacity_holds',function(Blueprint $t){
            $t->id();$t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('quick_stripe_lease_id')->nullable()->index();
            $t->string('state',30);$t->unsignedInteger('version');$t->dateTime('expires_at');
            $t->json('payload');$t->char('payload_sha256',64);$t->timestamps();
        });
        Schema::create('joint_capacity_hold_events',function(Blueprint $t){
            $t->id();$t->foreignId('joint_capacity_hold_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');$t->json('payload');$t->char('payload_sha256',64);$t->timestamps();
            $t->unique(['joint_capacity_hold_id','version'],'joint_capacity_hold_version_unique');
        });
    }
    public function down(): void
    {
        if(DB::table('joint_capacity_holds')->exists())throw new RuntimeException('Capacity hold evidence exists; use a forward migration.');
        Schema::dropIfExists('joint_capacity_hold_events');Schema::dropIfExists('joint_capacity_holds');
    }
};
