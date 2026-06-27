<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\NodeSyncService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserSubscriptionService
{
    public function getActiveSubscriptions(User|int $user): EloquentCollection
    {
        $userId = $user instanceof User ? $user->id : $user;

        return UserSubscription::with('plan')
            ->where('user_id', $userId)
            ->active()
            ->orderByRaw('expired_at IS NULL ASC')
            ->orderBy('expired_at')
            ->orderByDesc('id')
            ->get();
    }

    public function getAvailableSubscriptions(User|int $user): EloquentCollection
    {
        $userId = $user instanceof User ? $user->id : $user;

        return UserSubscription::with('plan')
            ->where('user_id', $userId)
            ->available()
            ->orderByRaw('expired_at IS NULL ASC')
            ->orderBy('expired_at')
            ->orderByDesc('id')
            ->get();
    }

    public function hasActiveSubscriptions(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return UserSubscription::where('user_id', $userId)->active()->exists();
    }

    public function hasAvailableSubscriptions(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return UserSubscription::where('user_id', $userId)->available()->exists();
    }

    public function getActiveGroupIds(User|int $user): array
    {
        return $this->getActiveSubscriptions($user)
            ->map(fn(UserSubscription $subscription) => $this->resolveGroupId($subscription))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getAvailableGroupIds(User|int $user): array
    {
        return $this->getAvailableSubscriptions($user)
            ->map(fn(UserSubscription $subscription) => $this->resolveGroupId($subscription))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getAggregate(User|int $user): array
    {
        $subscriptions = $this->getActiveSubscriptions($user);

        if ($subscriptions->isEmpty()) {
            return [
                'plan_id' => null,
                'group_id' => null,
                'transfer_enable' => 0,
                'u' => 0,
                'd' => 0,
                'expired_at' => 0,
                'speed_limit' => null,
                'device_limit' => null,
                'next_reset_at' => null,
                'last_reset_at' => null,
                'reset_count' => 0,
            ];
        }

        $primary = $this->choosePrimarySubscription($subscriptions);

        return [
            'plan_id' => $primary?->plan_id,
            'group_id' => $primary ? $this->resolveGroupId($primary) : null,
            'transfer_enable' => (int) $subscriptions->sum('transfer_enable'),
            'u' => (int) $subscriptions->sum('u'),
            'd' => (int) $subscriptions->sum('d'),
            'expired_at' => $this->aggregateExpiredAt($subscriptions),
            'speed_limit' => $this->aggregateSpeedLimit($subscriptions),
            'device_limit' => $this->aggregateDeviceLimit($subscriptions),
            'next_reset_at' => $subscriptions->pluck('next_reset_at')->filter()->min(),
            'last_reset_at' => $subscriptions->pluck('last_reset_at')->filter()->max(),
            'reset_count' => (int) $subscriptions->sum('reset_count'),
        ];
    }

    public function syncUserAggregate(User $user): User
    {
        $aggregate = $this->getAggregate($user);

        User::withoutEvents(function () use ($user, $aggregate) {
            $user->forceFill($aggregate);
            $user->save();
        });

        return $user->refresh();
    }

    public function createSubscription(
        User $user,
        Plan $plan,
        string $period,
        ?Order $order = null,
        ?int $startedAt = null,
        ?int $baseExpiredAt = null
    ): UserSubscription {
        $period = PlanService::getPeriodKey($period);
        $now = time();

        $subscription = UserSubscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'order_id' => $order?->id,
            'status' => UserSubscription::STATUS_ACTIVE,
            'started_at' => $startedAt ?? $order?->paid_at ?? $now,
            'expired_at' => $period === Plan::PERIOD_ONETIME ? null : $this->calculateExpiredAt($period, $baseExpiredAt),
            'transfer_enable' => $this->planTrafficBytes($plan),
            'u' => 0,
            'd' => 0,
            'group_id' => $plan->group_id,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
        ]);

        $subscription->next_reset_at = app(TrafficResetService::class)
            ->calculateNextResetTimeForSubscription($subscription)?->timestamp;
        $subscription->save();

        if ($order && !$order->subscription_id) {
            $order->subscription_id = $subscription->id;
            $order->save();
        }

        $this->syncUserAggregate($user);
        NodeSyncService::notifyUserChanged($user->refresh());

        return $subscription->refresh();
    }

    public function renewSubscription(UserSubscription $subscription, Plan $plan, string $period, ?Order $order = null): UserSubscription
    {
        $period = PlanService::getPeriodKey($period);

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'order_id' => $order?->id ?? $subscription->order_id,
            'status' => UserSubscription::STATUS_ACTIVE,
            'expired_at' => $period === Plan::PERIOD_ONETIME
                ? null
                : $this->calculateExpiredAt($period, $subscription->expired_at),
            'transfer_enable' => $this->planTrafficBytes($plan),
            'group_id' => $plan->group_id,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
        ]);

        $subscription->next_reset_at = app(TrafficResetService::class)
            ->calculateNextResetTimeForSubscription($subscription)?->timestamp;
        $subscription->save();

        if ($order && !$order->subscription_id) {
            $order->subscription_id = $subscription->id;
            $order->save();
        }

        $this->syncUserAggregate($subscription->user);
        NodeSyncService::notifyUserChanged($subscription->user->refresh());

        return $subscription->refresh();
    }

    public function createSubscriptionForDays(
        User $user,
        Plan $plan,
        int $validityDays,
        ?Order $order = null,
        ?int $startedAt = null
    ): UserSubscription {
        $now = time();
        $subscription = UserSubscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'order_id' => $order?->id,
            'status' => UserSubscription::STATUS_ACTIVE,
            'started_at' => $startedAt ?? $order?->paid_at ?? $now,
            'expired_at' => $validityDays > 0 ? $now + ($validityDays * 86400) : null,
            'transfer_enable' => $this->planTrafficBytes($plan),
            'u' => 0,
            'd' => 0,
            'group_id' => $plan->group_id,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
        ]);

        $subscription->next_reset_at = app(TrafficResetService::class)
            ->calculateNextResetTimeForSubscription($subscription)?->timestamp;
        $subscription->save();

        $this->syncUserAggregate($user);
        NodeSyncService::notifyUserChanged($user->refresh());

        return $subscription->refresh();
    }

    public function upgradeSubscription(UserSubscription $subscription, Plan $plan, string $period, ?Order $order = null): UserSubscription
    {
        $user = $subscription->user;
        $oldGroupId = $subscription->group_id;

        $subscription->forceFill([
            'status' => UserSubscription::STATUS_EXPIRED,
            'expired_at' => time(),
        ])->save();

        $newSubscription = $this->createSubscription($user, $plan, $period, $order);

        if ($oldGroupId) {
            NodeSyncService::notifyUserRemovedFromGroup($user->id, (int) $oldGroupId);
            NodeSyncService::notifyUserChanged($user->refresh());
        }

        return $newSubscription;
    }

    public function ensureSubscriptionFromLegacyUser(User $user): ?UserSubscription
    {
        if (!$user->id || !$user->plan_id) {
            return null;
        }

        if (UserSubscription::where('user_id', $user->id)->exists()) {
            $this->syncUserAggregate($user);
            return null;
        }

        $plan = Plan::find($user->plan_id);
        if (!$plan) {
            return null;
        }

        $subscription = UserSubscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'order_id' => null,
            'status' => UserSubscription::STATUS_ACTIVE,
            'started_at' => $user->created_at ?: time(),
            'expired_at' => $user->expired_at,
            'transfer_enable' => $user->transfer_enable ?? $this->planTrafficBytes($plan),
            'u' => $user->u ?? 0,
            'd' => $user->d ?? 0,
            'group_id' => $user->group_id ?: $plan->group_id,
            'speed_limit' => $user->speed_limit ?? $plan->speed_limit,
            'device_limit' => $user->device_limit ?? $plan->device_limit,
            'next_reset_at' => $user->next_reset_at,
            'last_reset_at' => $user->last_reset_at,
            'reset_count' => $user->reset_count ?? 0,
        ]);

        if (!$subscription->next_reset_at) {
            $subscription->next_reset_at = app(TrafficResetService::class)
                ->calculateNextResetTimeForSubscription($subscription)?->timestamp;
            $subscription->save();
        }

        $this->syncUserAggregate($user);

        return $subscription;
    }

    public function resolveOrderSubscription(User $user, Order $order, bool $requireActive = true): ?UserSubscription
    {
        if ($order->subscription_id) {
            $query = UserSubscription::where('id', $order->subscription_id)
                ->where('user_id', $user->id);

            if ($requireActive) {
                $query->active();
            }

            return $query->first();
        }

        $query = UserSubscription::where('user_id', $user->id)
            ->where('plan_id', $order->plan_id);

        if ($requireActive) {
            $query->active();
        }

        $matches = $query
            ->orderByRaw('expired_at IS NULL ASC')
            ->orderByDesc('expired_at')
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        return null;
    }

    public function resolveSamePlanRenewalTarget(User|int $user, Plan|int $plan): ?UserSubscription
    {
        $userId = $user instanceof User ? $user->id : $user;
        $planId = $plan instanceof Plan ? $plan->id : $plan;

        return UserSubscription::where('user_id', $userId)
            ->where('plan_id', $planId)
            ->active()
            ->orderByRaw('expired_at IS NULL DESC')
            ->orderByDesc('expired_at')
            ->orderByDesc('id')
            ->first();
    }

    public function addBonusTraffic(User $user, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $subscription = $this->getActiveSubscriptions($user)->first();
        if (!$subscription) {
            $user->transfer_enable = ($user->transfer_enable ?? 0) + $bytes;
            $user->save();
            return;
        }

        $subscription->transfer_enable = ($subscription->transfer_enable ?? 0) + $bytes;
        $subscription->save();
        $this->syncUserAggregate($user);
        NodeSyncService::notifyUserChanged($user->refresh());
    }

    public function addBonusDeviceLimit(User $user, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $subscription = $this->getActiveSubscriptions($user)->first();
        if (!$subscription) {
            $user->device_limit = ($user->device_limit ?? 0) + $amount;
            $user->save();
            return;
        }

        if ($subscription->device_limit) {
            $subscription->device_limit += $amount;
            $subscription->save();
            $user = $this->syncUserAggregate($user);
            NodeSyncService::notifyUserChanged($user);
        }
    }

    public function extendPrimarySubscription(User $user, int $days): User
    {
        if ($days <= 0) {
            return $user;
        }

        $subscription = $this->getActiveSubscriptions($user)->first();
        if (!$subscription) {
            $currentExpired = $user->expired_at ?? time();
            $user->expired_at = max($currentExpired, time()) + ($days * 86400);
            return $user;
        }

        $currentExpired = $subscription->expired_at ?? time();
        $subscription->expired_at = max($currentExpired, time()) + ($days * 86400);
        $subscription->next_reset_at = app(TrafficResetService::class)
            ->calculateNextResetTimeForSubscription($subscription)?->timestamp;
        $subscription->save();

        $user = $this->syncUserAggregate($user);
        NodeSyncService::notifyUserChanged($user);

        return $user;
    }

    public function consumeNodeTraffic(User $user, Server $server, int $upload, int $download): void
    {
        $this->consumeTraffic($user, $server->group_ids ?? [], (float) $server->getCurrentRate(), $upload, $download);
    }

    public function consumeTraffic(User $user, mixed $groupIds, float $rate, int $upload, int $download): void
    {
        $upload = (int) floor($upload * $rate);
        $download = (int) floor($download * $rate);
        $total = $upload + $download;

        if ($total <= 0) {
            return;
        }

        $groupIds = $this->normalizeGroupIds($groupIds);
        if (empty($groupIds)) {
            return;
        }

        DB::transaction(function () use ($user, $groupIds, $upload, $download, $total) {
            $subscriptions = UserSubscription::with('plan')
                ->where('user_id', $user->id)
                ->where(function ($query) use ($groupIds) {
                    $query->whereIn('group_id', $groupIds)
                        ->orWhereHas('plan', fn($planQuery) => $planQuery->whereIn('group_id', $groupIds));
                })
                ->available()
                ->lockForUpdate()
                ->orderByRaw('expired_at IS NULL ASC')
                ->orderBy('expired_at')
                ->orderBy('id')
                ->get();

            if ($subscriptions->isEmpty()) {
                $availableSubscriptions = UserSubscription::with('plan')
                    ->where('user_id', $user->id)
                    ->available()
                    ->lockForUpdate()
                    ->orderByRaw('expired_at IS NULL ASC')
                    ->orderBy('expired_at')
                    ->orderBy('id')
                    ->get();

                if ($availableSubscriptions->count() === 1) {
                    $subscriptions = $availableSubscriptions;
                }
            }

            $remainingTotal = $total;
            $remainingUpload = $upload;
            $remainingDownload = $download;

            foreach ($subscriptions as $subscription) {
                if ($remainingTotal <= 0) {
                    break;
                }

                $capacity = $subscription->getRemainingTraffic();
                if ($capacity <= 0) {
                    continue;
                }

                $consumeTotal = min($capacity, $remainingTotal);
                $consumeUpload = $remainingTotal > 0
                    ? (int) floor($remainingUpload * ($consumeTotal / $remainingTotal))
                    : 0;
                $consumeDownload = $consumeTotal - $consumeUpload;

                if ($consumeUpload > $remainingUpload) {
                    $consumeUpload = $remainingUpload;
                    $consumeDownload = $consumeTotal - $consumeUpload;
                }
                if ($consumeDownload > $remainingDownload) {
                    $consumeDownload = $remainingDownload;
                    $consumeUpload = $consumeTotal - $consumeDownload;
                }

                $subscription->u = ($subscription->u ?? 0) + $consumeUpload;
                $subscription->d = ($subscription->d ?? 0) + $consumeDownload;
                $subscription->save();

                $remainingUpload -= $consumeUpload;
                $remainingDownload -= $consumeDownload;
                $remainingTotal -= $consumeTotal;
            }

            $this->syncUserAggregate($user);
        });
    }

    public function syncPrimarySubscriptionFromUser(User $user): ?UserSubscription
    {
        if (!$user->plan_id) {
            $user->subscriptions()
                ->active()
                ->update([
                    'status' => UserSubscription::STATUS_CANCELLED,
                    'updated_at' => time(),
                ]);

            $this->syncUserAggregate($user);
            return null;
        }

        $activeSubscriptions = $user->subscriptions()->active()->get();
        if ($activeSubscriptions->count() > 1) {
            $this->syncUserAggregate($user);
            return null;
        }

        $plan = Plan::find($user->plan_id);
        if (!$plan) {
            return null;
        }

        $subscription = $activeSubscriptions->first();

        $payload = [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => UserSubscription::STATUS_ACTIVE,
            'started_at' => $user->created_at ?: time(),
            'expired_at' => $user->expired_at,
            'transfer_enable' => $user->transfer_enable ?? $this->planTrafficBytes($plan),
            'u' => $user->u ?? 0,
            'd' => $user->d ?? 0,
            'group_id' => $user->group_id ?: $plan->group_id,
            'speed_limit' => $user->speed_limit ?? $plan->speed_limit,
            'device_limit' => $user->device_limit ?? $plan->device_limit,
            'next_reset_at' => $user->next_reset_at,
            'last_reset_at' => $user->last_reset_at,
            'reset_count' => $user->reset_count ?? 0,
        ];

        if ($subscription) {
            $subscription->forceFill($payload);
            $subscription->save();
        } else {
            $subscription = UserSubscription::create($payload);
        }

        $subscription->next_reset_at = app(TrafficResetService::class)
            ->calculateNextResetTimeForSubscription($subscription)?->timestamp;
        $subscription->save();

        $this->syncUserAggregate($user);

        return $subscription->refresh();
    }

    public function getNodeSubscriptions(User|int $user, Server $server, bool $availableOnly = true): EloquentCollection
    {
        $userId = $user instanceof User ? $user->id : $user;
        $groupIds = $this->normalizeGroupIds($server->group_ids ?? []);

        if (empty($groupIds)) {
            return new EloquentCollection();
        }

        $query = UserSubscription::with('plan')
            ->where('user_id', $userId)
            ->where(function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds)
                    ->orWhereHas('plan', fn($planQuery) => $planQuery->whereIn('group_id', $groupIds));
            });

        $query = $availableOnly ? $query->available() : $query->active();

        return $query->orderByRaw('expired_at IS NULL ASC')
            ->orderBy('expired_at')
            ->get();
    }

    public function getNodeAccessProfile(User $user, Server $server): ?array
    {
        if ($user->banned) {
            return null;
        }

        $subscriptions = $this->getNodeSubscriptions($user, $server);
        if ($subscriptions->isEmpty()) {
            return null;
        }

        return [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'speed_limit' => $this->aggregateSpeedLimit($subscriptions),
            'device_limit' => $this->aggregateDeviceLimit($subscriptions),
        ];
    }

    public function getAvailableNodeUsers(Server $server): Collection
    {
        $groupIds = $this->normalizeGroupIds($server->group_ids ?? []);
        if (empty($groupIds)) {
            return collect();
        }

        $subscriptions = UserSubscription::with(['user:id,uuid,banned', 'plan'])
            ->where(function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds)
                    ->orWhereHas('plan', fn($planQuery) => $planQuery->whereIn('group_id', $groupIds));
            })
            ->available()
            ->get()
            ->groupBy('user_id');

        return $subscriptions
            ->map(function (Collection $items) {
                $user = $items->first()?->user;
                if (!$user || $user->banned) {
                    return null;
                }

                return (object) [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'speed_limit' => $this->aggregateSpeedLimit($items),
                    'device_limit' => $this->aggregateDeviceLimit($items),
                ];
            })
            ->filter()
            ->values();
    }

    public function calculateExpiredAt(string $period, ?int $timestamp = null): ?int
    {
        $period = PlanService::getPeriodKey($period);

        if ($period === Plan::PERIOD_ONETIME) {
            return null;
        }

        $timestamp = $timestamp && $timestamp > time() ? $timestamp : time();

        if (isset(OrderService::STR_TO_TIME[$period])) {
            return Carbon::createFromTimestamp($timestamp)
                ->addMonths(OrderService::STR_TO_TIME[$period])
                ->timestamp;
        }

        return null;
    }

    public function planTrafficBytes(Plan $plan): int
    {
        return (int) $plan->transfer_enable * 1073741824;
    }

    private function choosePrimarySubscription(EloquentCollection|Collection $subscriptions): ?UserSubscription
    {
        return $subscriptions
            ->sortByDesc(fn(UserSubscription $subscription) => $subscription->expired_at === null ? PHP_INT_MAX : $subscription->expired_at)
            ->first();
    }

    private function aggregateExpiredAt(EloquentCollection|Collection $subscriptions): ?int
    {
        if ($subscriptions->contains(fn(UserSubscription $subscription) => $subscription->expired_at === null)) {
            return null;
        }

        return $subscriptions->pluck('expired_at')->filter()->max() ?: 0;
    }

    private function aggregateSpeedLimit(EloquentCollection|Collection $subscriptions): ?int
    {
        $values = $subscriptions->pluck('speed_limit');

        if ($values->contains(fn($value) => empty($value))) {
            return null;
        }

        return $values->filter()->max();
    }

    private function aggregateDeviceLimit(EloquentCollection|Collection $subscriptions): ?int
    {
        $values = $subscriptions->pluck('device_limit');

        if ($values->contains(fn($value) => empty($value))) {
            return null;
        }

        $sum = $values->filter()->sum();
        return $sum > 0 ? (int) $sum : null;
    }

    private function normalizeGroupIds(mixed $groupIds): array
    {
        $normalized = [];
        $this->collectGroupIds($groupIds, $normalized);

        return array_values(array_unique(array_filter(
            array_map('intval', $normalized),
            fn(int $groupId): bool => $groupId > 0
        )));
    }

    private function collectGroupIds(mixed $value, array &$groupIds): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->collectGroupIds($decoded, $groupIds);
                return;
            }

            foreach (preg_split('/[,\s]+/', $value) ?: [] as $item) {
                $this->collectGroupIds($item, $groupIds);
            }
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectGroupIds($item, $groupIds);
            }
            return;
        }

        if (is_numeric($value)) {
            $groupIds[] = $value;
        }
    }

    private function resolveGroupId(UserSubscription $subscription): ?int
    {
        $groupId = $subscription->group_id ?: $subscription->plan?->group_id;
        return $groupId ? (int) $groupId : null;
    }
}
