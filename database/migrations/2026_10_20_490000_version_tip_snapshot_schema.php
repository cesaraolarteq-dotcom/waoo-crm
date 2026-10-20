<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tip_grants', fn (Blueprint $t) => $t->unsignedSmallInteger('snapshot_schema_version')->default(1));
    }
    public function down(): void
    {
        if (DB::table('tip_grants')->where('snapshot_schema_version', '!=', 1)->exists()) throw new RuntimeException('A newer TIP evidence schema exists; use a forward migration.');
        Schema::table('tip_grants', fn (Blueprint $t) => $t->dropColumn('snapshot_schema_version'));
    }
};
