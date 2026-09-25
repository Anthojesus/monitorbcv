<?php

namespace Database\Factories;

use App\Models\MonitorTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitorTarget>
 */
class MonitorTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'url' => 'https://example.com',
            'kind' => 'http',
            'probe_origin' => MonitorTarget::ORIGIN_INTERNAL,
            'method' => 'GET',
            'interval_seconds' => 5,
            'timeout_seconds' => 10,
            'expected_status' => [200, 301, 302],
            'expected_keyword' => null,
            'verify_ssl' => true,
            'is_enabled' => true,
        ];
    }

    public function proxy(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => MonitorTarget::KIND_PROXY,
            'expected_status' => [MonitorTarget::ANY_HTTP_STATUS],
        ]);
    }
}
