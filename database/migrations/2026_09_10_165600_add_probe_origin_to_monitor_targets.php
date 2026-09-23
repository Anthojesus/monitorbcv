<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_targets', function (Blueprint $table) {
            $table->string('probe_origin', 16)->default('internal')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_targets', function (Blueprint $table) {
            $table->dropColumn('probe_origin');
        });
    }
};
