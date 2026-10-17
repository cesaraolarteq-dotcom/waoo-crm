<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('financial_revisions',function(Blueprint $t){
            $t->foreignId('quote_revision_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('discount_cents')->default(0); $t->json('discounts_snapshot')->nullable();
        });
        Schema::create('quote_conversions',function(Blueprint $t){
            $t->id(); $t->foreignId('quote_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('quote_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('quote_decision_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('quote_conversions');
        Schema::table('financial_revisions',function(Blueprint $t){$t->dropForeign(['quote_revision_id']);$t->dropColumn(['quote_revision_id','discount_cents','discounts_snapshot']);});
    }
};
