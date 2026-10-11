<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_versions', function (Blueprint $table) {
            $table->id();
            $table->string('school_year', 9);
            $table->unsignedTinyInteger('quarter');
            $table->unsignedSmallInteger('version');
            $table->string('status', 16)->default('generating');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('generation_seed');
            $table->unsignedInteger('edit_sequence')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['school_year', 'quarter', 'version'], 'timetable_year_qtr_version_unique');
            $table->index(['school_year', 'quarter', 'status'], 'timetable_year_qtr_status_index');
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained();
            $table->foreignId('department_id')->constrained();
            $table->foreignId('teacher_id')->constrained();
            $table->unsignedTinyInteger('day');
            $table->unsignedTinyInteger('period');
            $table->timestamps();

            $table->unique(['timetable_version_id', 'section_id', 'day', 'period'], 'timetable_entry_section_cell_unique');
            $table->index(['timetable_version_id', 'teacher_id', 'day', 'period'], 'timetable_entry_teacher_slot_index');
        });

        Schema::create('teacher_unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained();
            $table->string('school_year', 9);
            $table->unsignedTinyInteger('quarter');
            $table->unsignedTinyInteger('day');
            $table->unsignedTinyInteger('period');
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['teacher_id', 'school_year', 'quarter', 'day', 'period'], 'teacher_unavailable_slot_unique');
            $table->index(['school_year', 'quarter', 'day', 'period'], 'teacher_unavailable_cycle_index');
        });

        Schema::create('timetable_validation_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_version_id')->constrained()->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('severity', 12)->default('hard');
            $table->string('category', 32)->default('section_rule');
            $table->text('message');
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('day')->nullable();
            $table->unsignedTinyInteger('period')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['timetable_version_id', 'severity'], 'timetable_issue_version_severity_index');
        });

        Schema::create('timetable_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('action', 24);
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamps();

            $table->unique(['timetable_version_id', 'sequence'], 'timetable_change_version_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_changes');
        Schema::dropIfExists('timetable_validation_issues');
        Schema::dropIfExists('teacher_unavailabilities');
        Schema::dropIfExists('timetable_entries');
        Schema::dropIfExists('timetable_versions');
    }
};
