<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantilla_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_submission_id')->constrained();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('extraction_status')->default('pending');
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_uploads');
    }
};
