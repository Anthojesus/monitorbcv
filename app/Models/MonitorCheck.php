<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $monitor_target_id
 * @property bool $ok
 * @property int|null $status_code
 * @property int|null $total_ms
 * @property string|null $availability_reason
 * @property array<string, mixed> $payload
 * @property CarbonInterface $checked_at
 * @property-read MonitorTarget $target
 */
class MonitorCheck extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'payload' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MonitorTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(MonitorTarget::class, 'monitor_target_id');
    }

    public function isProbeUnavailable(): bool
    {
        return (bool) data_get($this->payload, 'probe_unavailable')
            || $this->availability_reason === 'probe_unavailable';
    }
}
