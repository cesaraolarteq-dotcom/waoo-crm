<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_intents',function(Blueprint $table){
            $table->string('card_fee_policy',40)->nullable();
            $table->char('card_fee_terms_sha256',64)->nullable();
        });
    }
    public function down(): void
    {
        if(\Illuminate\Support\Facades\DB::table('payment_intents')->whereNotNull('card_fee_policy')->exists())throw new \RuntimeException('Card fee evidence exists; use a forward migration.');
        Schema::table('payment_intents',fn(Blueprint $table)=>$table->dropColumn(['card_fee_policy','card_fee_terms_sha256']));
    }
};
