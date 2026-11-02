<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** C3 B4: one immutable reversal per transfer. The transfer, bindings and opening rows stay as history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutover_reversals', function (Blueprint $t) {
            $t->id(); $t->foreignId('cutover_transfer_id')->unique()->constrained()->restrictOnDelete();
            $t->string('reason', 500); $t->longText('payload'); $t->char('payload_sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('cutover_reversals')->exists()) throw new RuntimeException('Cutover reversal history exists; use a forward migration.');
        Schema::dropIfExists('cutover_reversals');
    }
};
