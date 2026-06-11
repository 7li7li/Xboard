<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Collection;

class PlanService
{
    public Plan $plan;

    public function __construct(Plan $plan)
    {
        $this->plan = $plan;
    }

    public function getAvailablePlans(): Collection
    {
        return Plan::where('show', true)
            ->where('sell', true)
            ->orderBy('sort')
            ->get()
            ->filter(fn(Plan $plan) => $this->hasCapacity($plan));
    }

    public function getAvailablePlan(int $planId): ?Plan
    {
        return Plan::where('id', $planId)
            ->where('sell', true)
            ->where('renew', true)
            ->first();
    }

    public function isPlanAvailableForUser(Plan $plan, User $user): bool
    {
        $hasActivePlan = UserSubscription::where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->active()
            ->exists();

        if ((bool) $plan->show && (bool) $plan->sell && $this->hasCapacity($plan)) {
            return true;
        }

        return $hasActivePlan && (bool) $plan->renew;
    }

    public function validatePurchase(User $user, string $period, ?int $subscriptionId = null, string $intent = OrderService::INTENT_PURCHASE): void
    {
        if (!$this->plan) {
            throw new ApiException(__('Subscription plan does not exist'));
        }

        $periodKey = self::getPeriodKey($period);
        $price = $this->plan->prices[$periodKey] ?? null;

        if ($price === null) {
            throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
        }

        if ($periodKey === Plan::PERIOD_RESET_TRAFFIC) {
            $this->validateResetTrafficPurchase($user, $subscriptionId);
            return;
        }

        $targetSubscription = null;
        if ($subscriptionId) {
            $targetSubscription = UserSubscription::where('id', $subscriptionId)
                ->where('user_id', $user->id)
                ->active()
                ->first();

            if (!$targetSubscription) {
                throw new ApiException(__('Subscription plan does not exist'));
            }
        } elseif ($intent === OrderService::INTENT_PURCHASE) {
            $targetSubscription = app(UserSubscriptionService::class)
                ->resolveSamePlanRenewalTarget($user, $this->plan);
        } elseif ($intent !== OrderService::INTENT_PURCHASE) {
            $targetSubscription = $this->resolveImplicitTargetSubscription($user, $intent);
        }

        $isRenewal = $targetSubscription && $targetSubscription->plan_id === $this->plan->id;
        if (!$isRenewal && !$this->hasCapacity($this->plan)) {
            throw new ApiException(__('Current product is sold out'));
        }

        $this->validatePlanAvailability($targetSubscription);
    }

    protected function resolveImplicitTargetSubscription(User $user, string $intent): ?UserSubscription
    {
        $query = UserSubscription::where('user_id', $user->id)
            ->active();

        if ($intent === OrderService::INTENT_RENEW) {
            $query->where('plan_id', $this->plan->id);
        }

        $subscriptions = $query
            ->orderByDesc('id')
            ->get();

        if ($subscriptions->isEmpty()) {
            throw new ApiException(__('Subscription plan does not exist'));
        }

        if ($subscriptions->count() > 1) {
            throw new ApiException('Please select the subscription to renew or upgrade');
        }

        return $subscriptions->first();
    }

    public static function getPeriodKey(string $period): string
    {
        if (in_array($period, self::getNewPeriods(), true)) {
            return $period;
        }

        return Plan::LEGACY_PERIOD_MAPPING[$period] ?? $period;
    }

    public static function convertToLegacyPeriod(string $period): string
    {
        $flippedMapping = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        return $flippedMapping[$period] ?? $period;
    }

    public static function getNewPeriods(): array
    {
        return array_values(Plan::LEGACY_PERIOD_MAPPING);
    }

    public static function getLegacyPeriod(string $period): string
    {
        $flipped = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        return $flipped[$period] ?? $period;
    }

    protected function validateResetTrafficPurchase(User $user, ?int $subscriptionId = null): void
    {
        $query = UserSubscription::where('user_id', $user->id)
            ->where('plan_id', $this->plan->id)
            ->available();

        if ($subscriptionId) {
            $query->where('id', $subscriptionId);
        }

        $matches = $query->get();
        if ($matches->isEmpty()) {
            throw new ApiException(__('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
        }

        if (!$subscriptionId && $matches->count() > 1) {
            throw new ApiException('Please select the subscription to reset');
        }
    }

    protected function validatePlanAvailability(?UserSubscription $targetSubscription = null): void
    {
        if ($targetSubscription && $targetSubscription->plan_id === $this->plan->id) {
            if (!$this->plan->renew) {
                throw new ApiException(__('This subscription cannot be renewed, please change to another subscription'));
            }
            return;
        }

        if (!$this->plan->show || !$this->plan->sell) {
            throw new ApiException(__('This subscription has been sold out, please choose another subscription'));
        }
    }

    public function hasCapacity(Plan $plan): bool
    {
        if ($plan->capacity_limit === null) {
            return true;
        }

        $activeUserCount = UserSubscription::where('plan_id', $plan->id)
            ->active()
            ->count();

        return ($plan->capacity_limit - $activeUserCount) > 0;
    }

    public function getAvailablePeriods(Plan $plan): array
    {
        return array_filter(
            $plan->getActivePeriods(),
            fn($period) => isset($plan->prices[$period]) && $plan->prices[$period] > 0
        );
    }

    public function canResetTraffic(Plan $plan): bool
    {
        return $plan->reset_traffic_method !== Plan::RESET_TRAFFIC_NEVER
            && $plan->getResetTrafficPrice() > 0;
    }
}
