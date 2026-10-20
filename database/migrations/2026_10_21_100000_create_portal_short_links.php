<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-10-20: a short link for the client (/r/<12 chars>) instead of /portal#token=<43 chars>. The code is a bearer
// secret like the token: stored as a hash for lookup and encrypted to show it again. One per grant; revocation/expiry follow it.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('portal_short_links', function (Blueprint $t) {
            $t->id(); $t->foreignId('portal_grant_id')->unique()->constrained()->restrictOnDelete();
            $t->char('code_hash', 64)->unique(); $t->text('encrypted_code'); $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_short_links');
    }
};
