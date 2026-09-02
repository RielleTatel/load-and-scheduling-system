<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The registrar's roster is the authority for who moderates each section.
     * Four of the seven plantilla sheets leave the Class Moderator column blank
     * or record only a count, so it cannot be recovered from them.
     *
     * Stored as names rather than foreign keys: the roster is known up front,
     * but the teachers only exist once their department's plantilla is imported
     * (and twelve of the moderators/partners have no plantilla row at all).
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('moderator_name')->nullable()->after('is_magis');
            $table->string('teacher_partner_name')->nullable()->after('moderator_name');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn(['moderator_name', 'teacher_partner_name']);
        });
    }
};
