<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A section has at most one Honor's Class teacher per department per year —
     * the same rule class_moderator_assignments already enforces. Without this the
     * Science sheet's duplicated "G8 Magis / Ignatius of Loyola" row imports twice.
     */
    public function up(): void
    {
        Schema::table('honors_class_assignments', function (Blueprint $table) {
            $table->unique(['section_id', 'school_year'], 'honors_section_year_unique');
        });
    }

    public function down(): void
    {
        Schema::table('honors_class_assignments', function (Blueprint $table) {
            $table->dropUnique('honors_section_year_unique');
        });
    }
};
