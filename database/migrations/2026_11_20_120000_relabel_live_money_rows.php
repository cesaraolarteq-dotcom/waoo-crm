<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Audit 2026-11-09 / deployed 2026-11-20: on the real CRM every money row says 'live'. Rows written there before this
// release still say 'test' (the old default); relabel them so they stay visible. Test environments are untouched.
return new class extends Migration {
    public function up(): void
    {
        if (! \App\Support\WaooRuntime::live()) return;
        foreach (['ledger_entries', 'payment_intents', 'tip_checkout_intents', 'owner_report_snapshots'] as $table) {
            DB::table($table)->where('environment', 'test')->update(['environment' => 'live']);
        }
    }

    public function down(): void {}
};
