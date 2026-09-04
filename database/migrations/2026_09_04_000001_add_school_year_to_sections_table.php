<?php

use App\Models\SystemConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The registrar re-issues the roster every year: rooms, moderators and
     * teacher-partners all change. Sections were modelled as timeless, so a
     * second import would overwrite the prior year in place and orphan the
     * assignments pointing at those section rows.
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('school_year', 9)->default('2026-2027')->after('id');
        });

        // Existing rows are the 2026-2027 roster.
        DB::table('sections')->update(['school_year' => SystemConstant::get('current_school_year', '2026-2027')]);

        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique(['grade_level', 'name']);
            $table->unique(['school_year', 'grade_level', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique(['school_year', 'grade_level', 'name']);
            $table->unique(['grade_level', 'name']);
            $table->dropColumn('school_year');
        });
    }
};
