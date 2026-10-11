<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_versions', function (Blueprint $table) {
            $table->json('generation_checkpoint')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('timetable_versions', function (Blueprint $table) {
            $table->dropColumn('generation_checkpoint');
        });
    }
};
