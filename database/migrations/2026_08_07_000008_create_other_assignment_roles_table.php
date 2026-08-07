<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_assignment_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('equivalent_hours', 4, 1)->nullable();
            // Explicit flag, not inferred from a null equivalent_hours (SRS FR-7).
            $table->boolean('is_honorarium')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_assignment_roles');
    }
};
