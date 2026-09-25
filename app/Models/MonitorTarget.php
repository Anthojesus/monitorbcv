<?php

namespace App\Models;

use Database\Factories\MonitorTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $kind
 * @property string $probe_origin
 * @property int|null $proxy_target_id
 * @property string $method
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property array<int, int>|null $expected_status
 * @property string|null $expected_keyword
 * @property bool $verify_ssl
 * @property bool $is_enabled
 * @property bool|null $last_ok
 * @property int|null $last_status_code
 * @property int|null $last_total_ms
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MonitorCheck> $checks
 * @property-read Collection<int, MonitorCommand> $commands
 * @property-read Collection<int, MonitorCommandRun> $commandRuns
 * @property-read MonitorTargetServer|null $server
 * @property-read MonitorTarget|null $proxy
 * @property-read Collection<int, MonitorTarget> $frontedSites
 */
#[Fillable([
    'name',
    'url',
    'kind',
    'probe_origin',
    'proxy_target_id',
    'method',
    'interval_seconds',
    'timeout_seconds',
    'expected_status',
    'expected_keyword',
    'verify_ssl',
    'is_enabled',
    'last_ok',
    'last_status_code',
    'last_total_ms',
    'last_checked_at',
])]
class MonitorTarget extends Model
{
    /** @use HasFactory<MonitorTargetFactory> */
    use HasFactory;

    public const ANY_HTTP_STATUS = 0;

    public const ORIGIN_INTERNAL = 'internal';

    public const ORIGIN_EXTERNAL = 'external';

    public const KIND_HTTP = 'http';

    public const KIND_HEALTH = 'health';

    public const KIND_PROXY = 'proxy';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_status' => 'array',
            'verify_ssl' => 'boolean',
            'is_enabled' => 'boolean',
            'last_ok' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MonitorCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /**
     * @return HasOne<MonitorTargetServer, $this>
     */
    public function server(): HasOne
    {
        return $this->hasOne(MonitorTargetServer::class);
    }

    /**
     * @return BelongsTo<MonitorTarget, $this>
     */
    public function proxy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proxy_target_id');
    }

    /**
     * @return HasMany<MonitorTarget, $this>
     */
    public function frontedSites(): HasMany
    {
        return $this->hasMany(self::class, 'proxy_target_id');
    }

    /**
     * @return BelongsToMany<MonitorCommand, $this>
     */
    public function commands(): BelongsToMany
    {
        return $this->belongsToMany(MonitorCommand::class, 'monitor_target_commands')
            ->orderBy('sort_order');
    }

    /**
     * @return HasMany<MonitorCommandRun, $this>
     */
    public function commandRuns(): HasMany
    {
        return $this->hasMany(MonitorCommandRun::class);
    }

    public function hasSshAccess(): bool
    {
        return $this->server?->isReady() === true;
    }

    /**
     * @return HasMany<MonitorCheck, $this>
     */
    public function recentChecks(): HasMany
    {
        return $this->checks()->latest('checked_at');
    }

    /**
     * @return list<int>
     */
    public static function defaultExpectedStatus(): array
    {
        return [200, 201, 204, 301, 302, 303, 304, 307, 308];
    }

    /**
     * @return array<int, int>
     */
    public function expectedStatusCodes(): array
    {
        $status = $this->expected_status;

        return $status === null || $status === [] ? self::defaultExpectedStatus() : array_values(array_map('intval', $status));
    }

    public function acceptsAnyHttpStatus(): bool
    {
        return in_array(self::ANY_HTTP_STATUS, $this->expectedStatusCodes(), true);
    }

    public function acceptsHttpStatus(?int $status): bool
    {
        if ($status === null || $status < 100 || $status > 599) {
            return false;
        }

        if ($this->acceptsAnyHttpStatus()) {
            return true;
        }

        return in_array($status, $this->expectedStatusCodes(), true);
    }

    public function httpSuccessReason(int $status): string
    {
        $listed = $this->acceptsAnyHttpStatus()
            ? self::defaultExpectedStatus()
            : $this->expectedStatusCodes();

        return in_array($status, $listed, true) ? 'ok' : 'http_reachable';
    }

    public function isHealth(): bool
    {
        return $this->kind === self::KIND_HEALTH;
    }

    public function isProxy(): bool
    {
        return $this->kind === self::KIND_PROXY;
    }

    public function probeOrigin(): string
    {
        return $this->probe_origin === self::ORIGIN_EXTERNAL
            ? self::ORIGIN_EXTERNAL
            : self::ORIGIN_INTERNAL;
    }

    public function isExternalOrigin(): bool
    {
        return $this->probeOrigin() === self::ORIGIN_EXTERNAL;
    }

    public static function guessOrigin(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return self::ORIGIN_INTERNAL;
        }

        if (str_contains($host, '.intra.') || str_contains($host, '.extra.') || str_ends_with($host, '.intra') || str_ends_with($host, '.extra')) {
            return self::ORIGIN_INTERNAL;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

            return filter_var($host, FILTER_VALIDATE_IP, $flags) ? self::ORIGIN_EXTERNAL : self::ORIGIN_INTERNAL;
        }

        return self::ORIGIN_INTERNAL;
    }

    public function isDue(): bool
    {
        if ($this->last_checked_at === null) {
            return true;
        }

        return $this->last_checked_at->lte(
            now()->subSeconds(max(1, $this->interval_seconds)),
        );
    }
}
