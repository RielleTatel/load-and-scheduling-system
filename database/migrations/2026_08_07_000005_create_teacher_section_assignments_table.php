<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_section_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('section_id')->constrained();
            $table->foreignId('department_id')->constrained();
            $table->string('school_year', 9);
            $table->decimal('hours', 4, 1);
            $table->timestamps();

            // One subject teacher per section per department per year.
            // Explicit short name — the auto-generated one exceeds MySQL's 64-char limit.
            $table->unique(['section_id', 'department_id', 'school_year'], 'tsa_section_dept_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_section_assignments');
    }
};
