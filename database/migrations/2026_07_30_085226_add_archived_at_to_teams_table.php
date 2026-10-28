<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Abschluss-Zeitpunkt eines Jahrgangs (Sprint 5.7).
 *
 * Bewusst **nicht** über das vorhandene `deleted_at`: der Soft-Delete-Scope
 * versteckt Zeilen global, eine archivierte Gruppe soll aber sichtbar bleiben —
 * nur ausgegraut und aus den kohortenweiten Kennzahlen heraus. `deleted_at`
 * bleibt dem echten Löschen vorbehalten (S5.7.5).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('inactivity_threshold_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
