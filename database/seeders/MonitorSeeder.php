<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class MonitorSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('monitor:seed-catalog', ['--force' => true]);
    }
}
