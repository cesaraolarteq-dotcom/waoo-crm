<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // CAN-SPAM: a client who unsubscribed from promotions never gets another promotional email (ANNIV-01).
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->timestamp('promo_opt_out_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('promo_opt_out_at'));
    }
};
