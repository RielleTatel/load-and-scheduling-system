<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantilla_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained();
            $table->string('school_year', 9);
            $table->string('status')->default('draft');
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->text('returned_comment')->nullable();
            $table->foreignId('returned_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['department_id', 'school_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_submissions');
    }
};
