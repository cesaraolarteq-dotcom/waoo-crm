<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('report_preview_deliveries', function (Blueprint $t) {
            $t->dateTime('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('cancellation_note', 500)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('report_preview_deliveries', fn (Blueprint $t) => $t->dropConstrainedForeignId('cancelled_by'));
        Schema::table('report_preview_deliveries', fn (Blueprint $t) => $t->dropColumn(['cancelled_at', 'cancellation_note']));
    }
};
