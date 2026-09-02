<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teacher identity had no stable key: Teacher::updateOrCreate() matched the
     * full name exactly, so re-importing a corrected plantilla forked the teacher
     * and migrated their load to the new row.
     *
     * `department_id` becomes nullable because the registrar's roster names people
     * — moderators and teacher-partners — before their department's plantilla
     * exists. They are adopted when that sheet is imported.
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('full_name');
            $table->string('source', 20)->default('plantilla')->after('department_id');
            $table->foreignId('department_id')->nullable()->change();
        });

        foreach (DB::table('teachers')->get() as $teacher) {
            DB::table('teachers')->where('id', $teacher->id)
                ->update(['normalized_name' => \App\Models\Teacher::normalize($teacher->full_name)]);
        }

        $this->mergeDuplicates();

        Schema::table('teachers', function (Blueprint $table) {
            $table->unique(['department_id', 'normalized_name'], 'teachers_dept_name_unique');
        });
    }

    /**
     * Fold any rows that already collided onto the earliest one, moving their
     * assignments across rather than deleting a teacher's load.
     */
    private function mergeDuplicates(): void
    {
        $groups = DB::table('teachers')
            ->select('department_id', 'normalized_name', DB::raw('COUNT(*) as total'))
            ->groupBy('department_id', 'normalized_name')
            ->having('total', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $ids = DB::table('teachers')
                ->where('normalized_name', $group->normalized_name)
                ->where(fn ($q) => $group->department_id === null
                    ? $q->whereNull('department_id')
                    : $q->where('department_id', $group->department_id))
                ->orderBy('id')->pluck('id')->all();

            $keep = array_shift($ids);

            foreach (['teacher_section_assignments', 'class_moderator_assignments', 'honors_class_assignments', 'service_loads', 'teacher_other_assignments'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('teacher_id', $ids)->update(['teacher_id' => $keep]);
                }
            }

            DB::table('teachers')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropUnique('teachers_dept_name_unique');
            $table->dropColumn(['normalized_name', 'source']);
        });
    }
};
