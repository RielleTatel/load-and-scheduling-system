<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teacher identity gains an explicit verification state, durable source
     * observations, Admin-approved aliases, and persistent review cases.
     *
     * Existing teachers are backfilled as pending verification by the column
     * default: their `source` label stays as legacy provenance and is never
     * read as evidence of review. Nothing is merged here.
     *
     * The department/name uniqueness rule gains an ordinal so an Admin can
     * record two genuinely different people with one name; ordinary imports
     * always write ordinal 0, so the database still refuses an accidental
     * duplicate.
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('verification_status', 20)->default('pending')->after('source');
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->json('verification_evidence')->nullable()->after('verified_by_user_id');
            $table->timestamp('chair_confirmed_at')->nullable()->after('verification_evidence');
            $table->foreignId('chair_confirmed_by_user_id')->nullable()->after('chair_confirmed_at')->constrained('users')->nullOnDelete();
            $table->text('chair_confirmation_note')->nullable()->after('chair_confirmed_by_user_id');
            $table->foreignId('merged_into_teacher_id')->nullable()->after('chair_confirmation_note')->constrained('teachers');
            $table->timestamp('merged_at')->nullable()->after('merged_into_teacher_id');
            $table->unsignedSmallInteger('name_ordinal')->default(0)->after('normalized_name');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropUnique('teachers_dept_name_unique');
            $table->unique(['department_id', 'normalized_name', 'name_ordinal'], 'teachers_dept_name_ordinal_unique');
            $table->index('normalized_name', 'teachers_normalized_name_index');
            $table->index('verification_status', 'teachers_verification_status_index');
        });

        Schema::create('teacher_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->nullable()->constrained();
            $table->string('source_type', 20);
            $table->string('source_key', 120)->unique();
            $table->string('source_reference')->nullable();
            $table->string('school_year', 9)->nullable();
            $table->string('completeness', 10)->nullable();
            $table->foreignId('coverage_department_id')->nullable()->constrained('departments');
            $table->string('raw_name', 150)->nullable();
            $table->string('observed_name', 150);
            $table->string('normalized_name', 150)->index();
            $table->foreignId('observed_department_id')->nullable()->constrained('departments');
            $table->string('identity_hash', 64);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('teacher_identity_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reason', 30);
            $table->string('status', 12)->default('open')->index();
            $table->string('source_type', 20);
            $table->string('source_key', 120)->nullable()->index();
            $table->string('source_reference')->nullable();
            $table->string('school_year', 9)->nullable();
            $table->string('observed_name', 150)->nullable();
            $table->string('normalized_name', 150)->nullable();
            $table->foreignId('observed_department_id')->nullable()->constrained('departments');
            $table->foreignId('subject_teacher_id')->nullable()->constrained('teachers');
            $table->json('candidate_ids');
            $table->json('chair_context')->nullable();
            $table->string('decision', 30)->nullable();
            $table->foreignId('resolution_teacher_id')->nullable()->constrained('teachers');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });

        Schema::create('teacher_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained();
            $table->string('alias_name', 150);
            $table->string('normalized_name', 150)->index();
            $table->foreignId('identity_case_id')->nullable()->constrained('teacher_identity_cases')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['teacher_id', 'normalized_name'], 'teacher_alias_unique');
        });
    }

    public function down(): void
    {
        // SQLite rebuilds `teachers` to drop columns; other tables reference it.
        Schema::withoutForeignKeyConstraints(fn () => $this->revert());
    }

    private function revert(): void
    {
        Schema::dropIfExists('teacher_aliases');
        Schema::dropIfExists('teacher_identity_cases');
        Schema::dropIfExists('teacher_observations');

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropUnique('teachers_dept_name_ordinal_unique');
            $table->dropIndex('teachers_normalized_name_index');
            $table->dropIndex('teachers_verification_status_index');
            $table->unique(['department_id', 'normalized_name'], 'teachers_dept_name_unique');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropConstrainedForeignId('chair_confirmed_by_user_id');
            $table->dropConstrainedForeignId('merged_into_teacher_id');
            $table->dropColumn([
                'verification_status', 'verified_at', 'verification_evidence',
                'chair_confirmed_at', 'chair_confirmation_note', 'merged_at', 'name_ordinal',
            ]);
        });
    }
};
