<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('travel_fee_quotes', function (Blueprint $t) {
            $t->id(); $t->char('input_hash', 64); $t->char('rules_hash', 64); $t->json('inputs'); $t->json('rules');
            $t->string('status', 20); $t->unsignedBigInteger('fee_cents')->nullable(); $t->json('breakdown'); $t->dateTime('expires_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['input_hash','rules_hash','expires_at'], 'travel_quote_cache_index');
        });
        Schema::create('travel_route_daily_usage', function (Blueprint $t) { $t->date('day')->primary(); $t->unsignedInteger('calls')->default(0); });
        Schema::table('events', fn (Blueprint $t) => $t->json('travel_fee_evidence')->nullable());
    }
    public function down(): void
    {
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('travel_fee_evidence'));
        Schema::dropIfExists('travel_route_daily_usage'); Schema::dropIfExists('travel_fee_quotes');
    }
};
