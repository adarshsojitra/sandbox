<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('selected_servers', function (Blueprint $table) {
            $table->string('phpmyadmin_url')->nullable()->after('connection_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('selected_servers', function (Blueprint $table) {
            $table->dropColumn('phpmyadmin_url');
        });
    }
};
