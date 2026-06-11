<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends Model
{
    protected $table = 'v2_user_subscriptions';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'started_at' => 'timestamp',
        'expired_at' => 'timestamp',
        'next_reset_at' => 'timestamp',
        'last_reset_at' => 'timestamp',
        'status' => 'integer',
        'transfer_enable' => 'integer',
        'u' => 'integer',
        'd' => 'integer',
        'group_id' => 'integer',
        'speed_limit' => 'integer',
        'device_limit' => 'integer',
        'reset_count' => 'integer',
    ];

    public const STATUS_ACTIVE = 1;
    public const STATUS_CANCELLED = 2;
    public const STATUS_EXPIRED = 3;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $query) {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', time());
            });
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->active()->whereRaw('u + d < transfer_enable');
    }

    public function getTotalUsedTraffic(): int
    {
        return ($this->u ?? 0) + ($this->d ?? 0);
    }

    public function getRemainingTraffic(): int
    {
        return max(0, (int) ($this->transfer_enable ?? 0) - $this->getTotalUsedTraffic());
    }

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE
            && ($this->expired_at === null || $this->expired_at > time());
    }

    public function isAvailable(): bool
    {
        return $this->isActive() && $this->getRemainingTraffic() > 0;
    }
}
