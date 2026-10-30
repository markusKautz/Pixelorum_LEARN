<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ab wie vielen Tagen ohne Aktivität ein_e Teilnehmer_in in der Auswertung als
     * „inaktiv" markiert wird (Sprint-5-Entscheidung 5). Pro Gruppe, weil eine
     * Gruppe im Blockunterricht andere Rhythmen hat als eine ohne — der
     * Default 14 ist die Vorgabe des Auftraggebers.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedSmallInteger('inactivity_threshold_days')->default(14);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('inactivity_threshold_days');
        });
    }
};
