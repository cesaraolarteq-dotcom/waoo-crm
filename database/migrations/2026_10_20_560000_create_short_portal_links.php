<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('short_portal_links', function(Blueprint $t) {
            $t->id();$t->foreignId('portal_grant_id')->unique()->constrained('portal_grants')->restrictOnDelete();
            $t->char('code_hash',64)->unique();$t->text('encrypted_code');$t->timestamps();
        });
    }
    public function down(): void {Schema::dropIfExists('short_portal_links');}
};
