<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bestehende E-Mail-Adressen werden auf Kleinschreibung normalisiert;
     * neue Schreibvorgänge normalisieren die Models selbst (Mutator auf
     * User und TeamInvitation).
     */
    public function up(): void
    {
        DB::table('users')->update(['email' => DB::raw('LOWER(email)')]);
        DB::table('team_invitations')->update(['email' => DB::raw('LOWER(email)')]);
    }
};
