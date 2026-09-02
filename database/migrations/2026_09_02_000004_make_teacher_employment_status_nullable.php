<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No Social Studies row states an employment status, and several other
     * sheets omit it. Rejecting the row over a missing status discarded the
     * teacher's entire load; import it and let the Chair supply the status.
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('employment_status')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('employment_status')->nullable(false)->change();
        });
    }
};
