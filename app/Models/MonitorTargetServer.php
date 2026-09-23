<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property int $monitor_target_id
 * @property string $host
 * @property int $port
 * @property string $username
 * @property string $password
 * @property string|null $host_fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MonitorTarget $target
 */
#[Fillable([
    'monitor_target_id',
    'host',
    'port',
    'username',
    'password',
    'host_fingerprint',
])]
#[Hidden(['password'])]
class MonitorTargetServer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<MonitorTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(MonitorTarget::class);
    }

    public function hasPassword(): bool
    {
        $raw = $this->getAttributes()['password'] ?? null;

        return is_string($raw) && $raw !== '';
    }

    public function isReady(): bool
    {
        return $this->host !== ''
            && $this->username !== ''
            && $this->hasPassword();
    }

    public function decryptedPassword(): string
    {
        $raw = $this->getAttributes()['password'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return '';
        }

        try {
            return Crypt::decryptString($raw);
        } catch (DecryptException) {
            return '';
        }
    }
}
