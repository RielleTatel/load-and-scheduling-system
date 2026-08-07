<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_moderator_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('section_id')->constrained();
            $table->string('school_year', 9);
            $table->decimal('hours', 4, 1);
            $table->timestamps();

            // Exactly one moderator per section per year (drawn from any department).
            $table->unique(['section_id', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_moderator_assignments');
    }
};
