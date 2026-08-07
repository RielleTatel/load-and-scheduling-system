<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantilla_extraction_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_upload_id')->constrained()->cascadeOnDelete();
            // Raw extracted fields, edited by the Chair during review. Never authoritative.
            $table->json('row_json');
            $table->string('row_status')->default('extracted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_extraction_rows');
    }
};
