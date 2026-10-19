<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tip_grants', function(Blueprint $t) {
            $t->id();$t->foreignId('event_id')->constrained()->restrictOnDelete();$t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();$t->char('snapshot_sha256',64);$t->unsignedBigInteger('total_cents');$t->string('locale',2);
            $t->char('token_hash',64)->unique();$t->text('encrypted_token')->nullable();$t->dateTime('expires_at');$t->dateTime('revoked_at')->nullable();
            $t->unsignedInteger('version')->default(1);$t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamps();
        });
        Schema::create('tip_portal_sessions',function(Blueprint $t){$t->id();$t->foreignId('tip_grant_id')->constrained()->restrictOnDelete();$t->char('token_hash',64)->unique();$t->dateTime('expires_at')->index();$t->timestamps();});
        Schema::create('tip_checkout_intents',function(Blueprint $t){
            $t->uuid('id')->primary();$t->foreignId('tip_grant_id')->constrained()->restrictOnDelete();$t->foreignId('event_id')->constrained()->restrictOnDelete();$t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('active_grant_id')->nullable()->unique();$t->unsignedBigInteger('active_event_id')->nullable()->unique();$t->string('environment',10)->default('test');$t->string('option',10);$t->unsignedBigInteger('amount_cents');$t->char('currency',3)->default('USD');
            $t->string('status',40);$t->string('provider_account')->nullable();$t->string('provider_session_id')->nullable()->unique();$t->string('provider_payment_id')->nullable()->unique();
            $t->text('checkout_url')->nullable();$t->dateTime('expires_at');$t->string('last_error',100)->nullable();$t->timestamps();
        });
        Schema::create('tip_receipts',function(Blueprint $t){
            $t->id();$t->uuid('tip_checkout_intent_id')->unique();$t->foreign('tip_checkout_intent_id')->references('id')->on('tip_checkout_intents')->restrictOnDelete();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();$t->foreignId('tip_grant_id')->constrained()->restrictOnDelete();$t->char('external_sha256',64)->unique();
            $t->unsignedBigInteger('gross_cents');$t->char('currency',3);$t->unsignedBigInteger('processing_fee_cents')->nullable();$t->dateTime('received_at');
            $t->string('webhook_receipt_id');$t->foreign('webhook_receipt_id')->references('id')->on('webhook_receipts')->restrictOnDelete();$t->timestamps();
        });
        Schema::create('tip_refunds',function(Blueprint $t){$t->id();$t->foreignId('tip_receipt_id')->constrained()->restrictOnDelete();$t->char('external_sha256',64)->unique();$t->unsignedBigInteger('amount_cents');$t->dateTime('received_at');$t->timestamps();});
        Schema::create('tip_reconciliations',function(Blueprint $t){$t->id();$t->uuid('tip_checkout_intent_id')->nullable();$t->foreign('tip_checkout_intent_id')->references('id')->on('tip_checkout_intents')->restrictOnDelete();$t->string('webhook_receipt_id')->nullable();$t->char('dedupe_sha256',64)->unique();$t->string('reason',100);$t->string('status',20)->default('open');$t->timestamps();});
    }
    public function down(): void {foreach(['tip_reconciliations','tip_refunds','tip_receipts','tip_checkout_intents','tip_portal_sessions','tip_grants'] as $name)Schema::dropIfExists($name);}
};
