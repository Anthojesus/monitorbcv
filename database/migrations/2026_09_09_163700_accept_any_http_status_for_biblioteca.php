<?php

use App\Models\MonitorTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('monitor_targets')
            ->where('url', 'like', '%biblioteca.extra.bcv.org.ve%')
            ->update([
                'expected_status' => json_encode([MonitorTarget::ANY_HTTP_STATUS]),
            ]);
    }

    public function down(): void
    {
        DB::table('monitor_targets')
            ->where('url', 'like', '%biblioteca.extra.bcv.org.ve%')
            ->update([
                'expected_status' => json_encode(MonitorTarget::defaultExpectedStatus()),
            ]);
    }
};
