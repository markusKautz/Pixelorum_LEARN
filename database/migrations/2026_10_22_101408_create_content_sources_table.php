<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Die transportierbaren Metadaten der Lernquellen (DEV-3). Lektionen und
     * Fragen verweisen weiter über `source_id` in ihrer JSON-Spalte, ohne
     * Fremdschlüssel: Das Format der Quellenangabe bleibt unverändert.
     */
    public function up(): void
    {
        Schema::create('content_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 100)->unique();
            $table->string('kind');
            $table->string('title');
            $table->string('publisher')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('version', 100)->nullable();
            $table->date('published_at')->nullable();
            $table->date('accessed_at')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_sources');
    }
};
