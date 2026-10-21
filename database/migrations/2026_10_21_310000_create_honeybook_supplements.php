<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void {
        Schema::create('honeybook_supplements', function (Blueprint $t) {
            $t->id(); $t->char('fingerprint', 64)->unique(); $t->longText('payload');
            $t->foreignId('created_by')->constrained('users'); $t->timestamp('created_at');
        });
    }
    public function down(): void {
        if (DB::table('honeybook_supplements')->exists()) throw new RuntimeException('Supplement evidence must be retained.');
        Schema::dropIfExists('honeybook_supplements');
    }
};
