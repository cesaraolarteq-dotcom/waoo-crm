<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** S1: the whole-team state the owner accepted when preparing the unpaid hold; the client's card checkout must not start on a worse one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_stripe_leases', fn (Blueprint $t) => $t->string('admission_state', 32)->nullable()->after('state'));
    }

    public function down(): void
    {
        Schema::table('quick_stripe_leases', fn (Blueprint $t) => $t->dropColumn('admission_state'));
    }
};
