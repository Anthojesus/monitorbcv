<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $seconds
 */
class MonitorIntervalOption extends Model
{
    protected $fillable = [
        'seconds',
    ];

    /**
     * @return list<int>
     */
    public static function seconds(): array
    {
        return static::query()
            ->orderBy('seconds')
            ->pluck('seconds')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }
}
