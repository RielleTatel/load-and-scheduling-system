<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_imports', function (Blueprint $table) {
            $table->id();
            $table->string('school_year', 9);
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('extraction_status')->default('pending');
            $table->timestamp('extracted_at')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_imports');
    }
};
