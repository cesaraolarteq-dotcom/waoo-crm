<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        Schema::create('event_message_policies',function(Blueprint $t){$t->id();$t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();$t->boolean('paused')->default(false);$t->unsignedInteger('version')->default(0);$t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamps();});
        Schema::create('outbound_messages',function(Blueprint $t){$t->id();$t->foreignId('event_id')->constrained()->restrictOnDelete();$t->foreignId('customer_id')->constrained()->restrictOnDelete();$t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();$t->foreignId('portal_grant_id')->nullable()->constrained()->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->unsignedInteger('event_version');$t->unsignedInteger('customer_version');$t->string('template_key',60);$t->string('template_version',100);$t->string('locale',2);$t->string('channel',10)->default('email');$t->string('environment',10)->default('sandbox');$t->char('template_sha256',64);$t->char('recipient_sha256',64)->index();$t->char('intent_sha256',64)->unique();$t->char('source_sha256',64);$t->dateTime('due_at')->index();$t->longText('payload');$t->timestamps();});
        Schema::create('outbound_message_states',function(Blueprint $t){$t->id();$t->foreignId('outbound_message_id')->unique()->constrained()->restrictOnDelete();$t->unsignedInteger('version')->default(1);$t->string('status',30)->default('scheduled')->index();$t->unsignedInteger('attempt_count')->default(0);$t->string('error_code',60)->nullable();$t->string('provider',60)->nullable();$t->string('provider_message_id',255)->nullable();$t->char('provider_message_sha256',64)->nullable()->unique();$t->dateTime('sent_at')->nullable();$t->dateTime('delivered_at')->nullable();$t->timestamps();});
        Schema::create('outbound_message_events',function(Blueprint $t){$t->id();$t->foreignId('outbound_message_id')->constrained()->restrictOnDelete();$t->char('event_key',64)->unique();$t->string('kind',40);$t->json('metadata');$t->dateTime('occurred_at');$t->timestamps();});
    }
    public function down():void {Schema::dropIfExists('outbound_message_events');Schema::dropIfExists('outbound_message_states');Schema::dropIfExists('outbound_messages');Schema::dropIfExists('event_message_policies');}
};
