<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fields from the registrar's "List of Class Mods and Teacher-Partners 2026",
     * which is the authoritative roster of the 36 JHS sections. `name` stays the
     * short form the plantillas use; `full_name` is the registrar's official title.
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('name');
            $table->string('room', 10)->nullable()->after('full_name');
            $table->boolean('is_magis')->default(false)->after('room');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn(['full_name', 'room', 'is_magis']);
        });
    }
};
