<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void
    {
        Schema::create('opening_stripe_evidence', function (Blueprint $t) {
            $t->id(); $t->foreignId('ledger_entry_id')->unique()->constrained()->restrictOnDelete();
            $t->longText('payload'); $t->char('payload_sha256', 64); $t->timestamps();
        });
    }
    public function down(): void
    {
        if (DB::table('opening_stripe_evidence')->exists()) throw new RuntimeException('Verified Stripe evidence exists; use a forward migration.');
        Schema::dropIfExists('opening_stripe_evidence');
    }
};
