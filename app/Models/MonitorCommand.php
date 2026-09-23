<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $slug
 * @property string $label
 * @property string $command
 * @property string $kind
 * @property int $timeout_seconds
 * @property int $sort_order
 * @property bool $is_enabled
 * @property bool $is_custom
 * @property int|null $owner_target_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MonitorTarget> $targets
 */
#[Fillable([
    'slug',
    'label',
    'command',
    'kind',
    'timeout_seconds',
    'sort_order',
    'is_enabled',
    'is_custom',
    'owner_target_id',
])]
class MonitorCommand extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_custom' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<MonitorTarget, $this>
     */
    public function targets(): BelongsToMany
    {
        return $this->belongsToMany(MonitorTarget::class, 'monitor_target_commands');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true)->orderBy('sort_order');
    }

    public function isChange(): bool
    {
        return $this->kind === 'change';
    }

    public function isQuery(): bool
    {
        return $this->kind === 'query';
    }

    public function isCustom(): bool
    {
        return $this->is_custom;
    }
}
