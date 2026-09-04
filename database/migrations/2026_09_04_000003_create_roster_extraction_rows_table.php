<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_extraction_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_import_id')->constrained()->cascadeOnDelete();
            // Fields live as JSON keys, as with plantilla_extraction_rows, so a
            // new field needs no migration. Never authoritative.
            $table->json('row_json');
            $table->string('row_status')->default('extracted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_extraction_rows');
    }
};
