<?php

namespace App\Observers;

use App\Models\Plan;
use App\Models\UserSubscription;
use App\Services\TrafficResetService;
use App\Services\UserSubscriptionService;

class PlanObserver
{
    /**
     * reset user  next_reset_at
     */
    public function updated(Plan $plan): void
    {
        if (!$plan->isDirty('reset_traffic_method')) {
            return;
        }
        $trafficResetService = app(TrafficResetService::class);
        $subscriptionService = app(UserSubscriptionService::class);

        UserSubscription::where('plan_id', $plan->id)
            ->active()
            ->with('user')
            ->lazyById(500)
            ->each(function (UserSubscription $subscription) use ($trafficResetService, $subscriptionService, $plan) {
                $subscription->setRelation('plan', $plan);
                $nextResetTime = $trafficResetService->calculateNextResetTimeForSubscription($subscription);
                $subscription->update([
                    'next_reset_at' => $nextResetTime?->timestamp,
                ]);

                if ($subscription->user) {
                    $subscriptionService->syncUserAggregate($subscription->user);
                }
            });
    }
}

