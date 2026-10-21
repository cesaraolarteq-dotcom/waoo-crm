<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {Schema::table('events',function(Blueprint $t){$t->text('billing_company')->nullable();$t->unsignedInteger('billing_company_version')->default(0);});}
    public function down(): void {
        if(DB::table('events')->whereNotNull('billing_company')->orWhere('billing_company_version','>',0)->exists())throw new RuntimeException('Billing evidence exists; use a forward migration.');
        Schema::table('events',fn(Blueprint $t)=>$t->dropColumn(['billing_company','billing_company_version']));
    }
};
