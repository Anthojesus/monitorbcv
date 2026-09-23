<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitor_targets')) {
            return;
        }

        DB::table('monitor_targets')
            ->where('interval_seconds', 60)
            ->update(['interval_seconds' => 15]);
    }
};
