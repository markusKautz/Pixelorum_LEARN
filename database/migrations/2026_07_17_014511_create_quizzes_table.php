<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('kind');
            // Ein path_final-Quiz ohne Pfad wäre invariantenwidrig und stirbt
            // deshalb mit dem Pfad; custom-Quizze haben hier ohnehin null.
            $table->foreignId('learning_path_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('pass_threshold')->default(80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
