<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('honors_class_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('section_id')->constrained();
            $table->string('school_year', 9);
            $table->decimal('hours', 4, 1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('honors_class_assignments');
    }
};
