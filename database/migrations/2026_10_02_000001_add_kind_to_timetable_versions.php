<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_versions', function (Blueprint $table) {
            $table->string('kind', 16)->default('normal');
            $table->index(['school_year', 'quarter', 'kind', 'status'], 'timetable_kind_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('timetable_versions', function (Blueprint $table) {
            $table->dropIndex('timetable_kind_status_index');
            $table->dropColumn('kind');
        });
    }
};
