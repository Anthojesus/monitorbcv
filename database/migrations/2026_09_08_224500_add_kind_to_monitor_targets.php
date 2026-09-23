<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_targets', function (Blueprint $table) {
            $table->string('kind', 20)->default('http')->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_targets', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
