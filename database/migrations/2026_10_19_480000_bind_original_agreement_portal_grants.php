<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up():void {
        Schema::table('portal_grants',function(Blueprint $t){
            $t->foreignId('cutover_financial_binding_id')->nullable()->constrained()->restrictOnDelete();
            $t->char('opening_snapshot_sha256',64)->nullable();
        });
        Schema::create('opening_portal_bindings',function(Blueprint $t){
            $t->foreignId('portal_grant_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignId('cutover_financial_binding_id')->constrained()->restrictOnDelete();
            $t->longText('payload');$t->char('payload_sha256',64);$t->timestamps();
        });
    }
    public function down():void {
        if(DB::table('opening_portal_bindings')->exists()||DB::table('portal_grants')->whereNotNull('cutover_financial_binding_id')->orWhereNotNull('opening_snapshot_sha256')->exists())throw new RuntimeException('Source portal evidence exists; use a forward migration.');
        Schema::dropIfExists('opening_portal_bindings');
        Schema::table('portal_grants',function(Blueprint $t){$t->dropForeign(['cutover_financial_binding_id']);$t->dropColumn(['cutover_financial_binding_id','opening_snapshot_sha256']);});
    }
};
