<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public const INTENT_PURCHASE = 'purchase';
    public const INTENT_RENEW = 'renew';
    public const INTENT_UPGRADE = 'upgrade';
    public const INTENT_RESET = 'reset';

    public const STR_TO_TIME = [
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36,
    ];

    public Order $order;
    public ?User $user = null;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public static function createFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode = null,
        ?int $subscriptionId = null,
        string $intent = self::INTENT_PURCHASE,
    ): Order {
        $userService = app(UserService::class);
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $period, $subscriptionId, $intent);
        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode, $subscriptionId, $intent]);

        return DB::transaction(function () use ($user, $plan, $period, $couponCode, $userService, $subscriptionId, $intent) {
            $periodKey = PlanService::getPeriodKey($period);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'subscription_id' => $subscriptionId,
                'period' => $periodKey,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) (($plan->prices[$periodKey] ?? 0) * 100),
            ]);

            $orderService = new self($order);

            if ($couponCode) {
                $orderService->applyCoupon($couponCode);
            }

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user, $intent);
            $orderService->setInvite($user);

            if ($user->balance && $order->total_amount > 0) {
                $orderService->handleUserBalance($user, $userService);
            }

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            HookManager::call('order.create.after', $order);
            HookManager::call('order.after_create', $order);

            return $order;
        });
    }

    public static function createBatchRenewalFromRequest(
        User $user,
        array $requestedItems,
        ?string $couponCode = null,
    ): Order {
        if ($couponCode) {
            throw new ApiException('Coupons are not supported for batch renewal');
        }

        $items = self::normalizeBatchRenewalItems($user, $requestedItems);
        if (empty($items)) {
            throw new ApiException('Please select the subscription to renew');
        }

        $firstItem = $items[0];
        $firstPlan = Plan::find($firstItem['plan_id']);
        if (!$firstPlan) {
            throw new ApiException(__('Subscription plan does not exist'));
        }

        HookManager::call('order.create.before', [$user, $firstPlan, $firstItem['period'], $couponCode, null, self::INTENT_RENEW]);

        return DB::transaction(function () use ($user, $items, $firstPlan) {
            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $firstPlan->id,
                'subscription_id' => null,
                'period' => $items[0]['period'],
                'trade_no' => Helper::generateOrderNo(),
                'type' => Order::TYPE_RENEWAL,
                'items' => $items,
                'total_amount' => array_sum(array_column($items, 'amount')),
            ]);

            $orderService = new self($order);
            $userService = app(UserService::class);

            $orderService->setVipDiscount($user);
            $orderService->setInvite($user);

            if ($user->balance && $order->total_amount > 0) {
                $orderService->handleUserBalance($user, $userService);
            }

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            HookManager::call('order.create.after', $order);
            HookManager::call('order.after_create', $order);

            return $order;
        });
    }

    public static function createBatchRenewalForAllSubscriptions(
        User $user,
        string $preferredPeriod,
        ?string $couponCode = null,
    ): Order {
        $period = PlanService::getPeriodKey($preferredPeriod);
        if ($period === Plan::PERIOD_RESET_TRAFFIC) {
            throw new ApiException(__('Wrong plan period'));
        }

        $subscriptions = UserSubscription::with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', self::renewableSubscriptionStatuses())
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($subscriptions as $subscription) {
            /** @var UserSubscription $subscription */
            $plan = $subscription->plan;
            if (!$plan || !$plan->renew) {
                continue;
            }

            $renewalPeriod = self::resolveRenewalPeriod($plan, $period);
            if (!$renewalPeriod) {
                continue;
            }

            $items[] = [
                'subscription_id' => $subscription->id,
                'period' => $renewalPeriod,
            ];
        }

        if (empty($items)) {
            throw new ApiException('Please select the subscription to renew');
        }

        return self::createBatchRenewalFromRequest($user, $items, $couponCode);
    }

    public function open(): void
    {
        $order = $this->order;
        $plan = Plan::find($order->plan_id);
        if (!$plan) {
            throw new \RuntimeException('Subscription plan does not exist');
        }

        HookManager::call('order.open.before', $order);

        DB::transaction(function () use ($order, $plan) {
            $this->user = User::lockForUpdate()->find($order->user_id);
            if (!$this->user) {
                throw new \RuntimeException('User does not exist');
            }

            if ($order->surplus_credit) {
                $this->user->balance += $order->surplus_credit;
            }

            if ($order->surplus_order_ids) {
                Order::whereIn('id', $order->surplus_order_ids)
                    ->update(['status' => Order::STATUS_DISCOUNTED]);
            }

            if ((string) $order->period === Plan::PERIOD_RESET_TRAFFIC) {
                $this->resetSubscriptionTraffic($order);
            } else {
                $this->openSubscriptionOrder($order, $plan);
            }

            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException('Order save failed');
            }
        });

        $eventId = match ((int) $order->type) {
            Order::TYPE_NEW_PURCHASE => admin_setting('new_order_event_id', 0),
            Order::TYPE_RENEWAL => admin_setting('renew_order_event_id', 0),
            Order::TYPE_UPGRADE => admin_setting('change_order_event_id', 0),
            default => 0,
        };

        if ($eventId) {
            $this->openEvent($eventId);
        }

        HookManager::call('order.open.after', $order);
    }

    public function setOrderType(User $user, string $intent = self::INTENT_PURCHASE): void
    {
        $order = $this->order;
        $period = PlanService::getPeriodKey((string) $order->period);
        $subscriptionService = app(UserSubscriptionService::class);

        if ($period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
            $target = $subscriptionService->resolveOrderSubscription($user, $order);
            if ($target) {
                $order->subscription_id = $target->id;
            }
            return;
        }

        $target = $subscriptionService->resolveOrderSubscription($user, $order);

        if ($intent === self::INTENT_PURCHASE && !$order->subscription_id) {
            $target = $subscriptionService->resolveSamePlanRenewalTarget($user, (int) $order->plan_id);
            if ($target) {
                $order->type = Order::TYPE_RENEWAL;
                $order->subscription_id = $target->id;
                return;
            }

            $order->type = Order::TYPE_NEW_PURCHASE;
            return;
        }

        if ($target && ($intent === self::INTENT_UPGRADE || $target->plan_id !== $order->plan_id)) {
            if (!(int) admin_setting('plan_change_enable', 1)) {
                throw new ApiException('Currently plan changes are not allowed');
            }
            if ($target->plan_id === $order->plan_id) {
                throw new ApiException('Please choose a different subscription plan to upgrade');
            }
            $order->type = Order::TYPE_UPGRADE;
            $order->subscription_id = $target->id;
            $this->getSurplusValue($target, $order);
            return;
        }

        if ($target && ($intent === self::INTENT_RENEW || $target->plan_id === $order->plan_id)) {
            $order->type = Order::TYPE_RENEWAL;
            $order->subscription_id = $target->id;
            return;
        }

        $order->type = Order::TYPE_NEW_PURCHASE;
    }

    public function setVipDiscount(User $user): void
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) {
            return;
        }

        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) {
            return;
        }

        $commissionType = (int) $inviter->commission_type;
        if ($commissionType === User::COMMISSION_TYPE_SYSTEM) {
            $commissionType = (bool) admin_setting('commission_first_time_enable', true)
                ? User::COMMISSION_TYPE_ONETIME
                : User::COMMISSION_TYPE_PERIOD;
        }

        $isCommission = match ($commissionType) {
            User::COMMISSION_TYPE_PERIOD => true,
            User::COMMISSION_TYPE_ONETIME => !$this->haveValidOrder($user),
            default => false,
        };

        if (!$isCommission) {
            return;
        }

        $rate = $inviter->commission_rate ?: admin_setting('invite_commission', 10);
        $order->commission_balance = $order->total_amount * ($rate / 100);
    }

    public function paid(string $callbackNo): bool
    {
        $order = $this->order;
        if ($order->status !== Order::STATUS_PENDING) {
            return true;
        }

        $order->status = Order::STATUS_PROCESSING;
        $order->paid_at = time();
        $order->callback_no = $callbackNo;
        if (!$order->save()) {
            return false;
        }

        try {
            OrderHandleJob::dispatchSync($order->trade_no);
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }

        return true;
    }

    public function cancel(): bool
    {
        $order = $this->order;
        HookManager::call('order.cancel.before', $order);

        try {
            DB::beginTransaction();
            $order->status = Order::STATUS_CANCELLED;
            if (!$order->save()) {
                throw new \Exception('Failed to save order status.');
            }

            if ($order->balance_amount) {
                $userService = new UserService();
                if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                    throw new \Exception('Failed to add balance.');
                }
            }

            DB::commit();
            HookManager::call('order.cancel.after', $order);
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return false;
        }
    }

    protected function applyCoupon(string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($this->order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $this->order->coupon_id = $couponService->getId();
    }

    protected function handleUserBalance(User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $this->order->total_amount;

        if ($remainingBalance >= 0) {
            if (!$userService->addBalance($this->order->user_id, -$this->order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $this->order->total_amount;
            $this->order->total_amount = 0;
            return;
        }

        if (!$userService->addBalance($this->order->user_id, -$user->balance)) {
            throw new ApiException(__('Insufficient balance'));
        }
        $this->order->balance_amount = $user->balance;
        $this->order->total_amount = $this->order->total_amount - $user->balance;
    }

    private function haveValidOrder(User $user): ?Order
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->first();
    }

    private function openSubscriptionOrder(Order $order, Plan $plan): void
    {
        $subscriptionService = app(UserSubscriptionService::class);

        if ((int) $order->type === Order::TYPE_RENEWAL && !empty($order->items)) {
            $this->openBatchRenewalOrder($order, $subscriptionService);
            return;
        }

        $target = $subscriptionService->resolveOrderSubscription($this->user, $order);

        match ((int) $order->type) {
            Order::TYPE_RENEWAL => $target
                ? $subscriptionService->renewSubscription($target, $plan, $order->period, $order)
                : throw new \RuntimeException('Target subscription does not exist'),
            Order::TYPE_UPGRADE => $target
                ? $subscriptionService->upgradeSubscription($target, $plan, $order->period, $order)
                : throw new \RuntimeException('Target subscription does not exist'),
            default => $subscriptionService->createSubscription($this->user, $plan, $order->period, $order),
        };

        if ((int) $order->type === Order::TYPE_RENEWAL && (string) $order->period === Plan::PERIOD_ONETIME && $order->subscription_id) {
            $subscription = UserSubscription::find($order->subscription_id);
            if ($subscription) {
                app(TrafficResetService::class)->performResetSubscription($subscription, TrafficResetLog::SOURCE_ORDER);
            }
        }
    }

    private static function normalizeBatchRenewalItems(User $user, array $requestedItems): array
    {
        $normalized = [];
        foreach ($requestedItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $subscriptionId = (int) ($item['subscription_id'] ?? 0);
            $period = (string) ($item['period'] ?? '');
            if ($subscriptionId <= 0 || $period === '') {
                continue;
            }

            $normalized[$subscriptionId] = [
                'subscription_id' => $subscriptionId,
                'period' => $period,
            ];
        }

        if (empty($normalized)) {
            return [];
        }

        $subscriptions = UserSubscription::with('plan')
            ->where('user_id', $user->id)
            ->whereIn('id', array_keys($normalized))
            ->whereIn('status', self::renewableSubscriptionStatuses())
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($normalized as $subscriptionId => $item) {
            /** @var UserSubscription|null $subscription */
            $subscription = $subscriptions->get($subscriptionId);
            if (!$subscription || !$subscription->plan) {
                throw new ApiException(__('Subscription plan does not exist'));
            }

            $period = PlanService::getPeriodKey($item['period']);
            if ($period === Plan::PERIOD_RESET_TRAFFIC) {
                throw new ApiException(__('Wrong plan period'));
            }

            $plan = $subscription->plan;
            if (!$plan->renew) {
                throw new ApiException(__('This subscription cannot be renewed, please change to another subscription'));
            }

            $price = $plan->prices[$period] ?? null;
            if ($price === null || $price <= 0) {
                throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
            }

            $items[] = [
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'period' => $period,
                'amount' => (int) ($price * 100),
            ];
        }

        return $items;
    }

    private static function renewableSubscriptionStatuses(): array
    {
        return [
            UserSubscription::STATUS_ACTIVE,
            UserSubscription::STATUS_EXPIRED,
        ];
    }

    private static function resolveRenewalPeriod(Plan $plan, string $preferredPeriod): ?string
    {
        $prices = $plan->prices ?? [];
        if (($prices[$preferredPeriod] ?? 0) > 0) {
            return $preferredPeriod;
        }

        foreach (array_values(Plan::LEGACY_PERIOD_MAPPING) as $period) {
            if ($period !== Plan::PERIOD_RESET_TRAFFIC && ($prices[$period] ?? 0) > 0) {
                return $period;
            }
        }

        return null;
    }

    private function openBatchRenewalOrder(Order $order, UserSubscriptionService $subscriptionService): void
    {
        $items = is_array($order->items) ? $order->items : [];
        $itemsAmount = array_sum(array_map(
            fn($item) => is_array($item) ? (int) ($item['amount'] ?? 0) : 0,
            $items
        ));
        $refundableAmount = (int) (($order->total_amount ?? 0) + ($order->balance_amount ?? 0));
        $skippedAmount = 0;
        $renewedCount = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemAmount = (int) ($item['amount'] ?? 0);
            $itemRefundAmount = $itemsAmount > 0
                ? (int) round($refundableAmount * ($itemAmount / $itemsAmount))
                : 0;

            $subscription = UserSubscription::where('id', (int) ($item['subscription_id'] ?? 0))
                ->where('user_id', $order->user_id)
                ->whereIn('status', self::renewableSubscriptionStatuses())
                ->lockForUpdate()
                ->first();
            if (!$subscription) {
                $skippedAmount += $itemRefundAmount;
                Log::warning('Skipped missing subscription in batch renewal order', [
                    'trade_no' => $order->trade_no,
                    'subscription_id' => $item['subscription_id'] ?? null,
                ]);
                continue;
            }

            $plan = Plan::find((int) ($item['plan_id'] ?? 0));
            if (!$plan) {
                $skippedAmount += $itemRefundAmount;
                Log::warning('Skipped missing plan in batch renewal order', [
                    'trade_no' => $order->trade_no,
                    'plan_id' => $item['plan_id'] ?? null,
                    'subscription_id' => $subscription->id,
                ]);
                continue;
            }

            $period = PlanService::getPeriodKey((string) ($item['period'] ?? ''));
            $subscriptionService->renewSubscription($subscription, $plan, $period, $order);
            $renewedCount++;

            if ($period === Plan::PERIOD_ONETIME) {
                app(TrafficResetService::class)->performResetSubscription($subscription->refresh(), TrafficResetLog::SOURCE_ORDER);
            }
        }

        if ($renewedCount === 0 && $skippedAmount <= 0) {
            throw new \RuntimeException('No valid subscriptions to renew');
        }

        if ($skippedAmount > 0) {
            if (!app(UserService::class)->addBalance($order->user_id, $skippedAmount)) {
                throw new \RuntimeException('Failed to refund skipped subscription amount');
            }
            $order->surplus_credit = (int) ($order->surplus_credit ?? 0) + $skippedAmount;
        }

        $order->subscription_id = null;
        $order->save();
    }

    private function resetSubscriptionTraffic(Order $order): void
    {
        $subscription = app(UserSubscriptionService::class)->resolveOrderSubscription($this->user, $order);
        if ($subscription) {
            app(TrafficResetService::class)->performResetSubscription($subscription, TrafficResetLog::SOURCE_ORDER);
            $order->subscription_id = $subscription->id;
            return;
        }

        app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
    }

    private function getSurplusValue(UserSubscription $subscription, Order $order): void
    {
        if (!(int) admin_setting('surplus_enable', 1)) {
            return;
        }

        $orders = Order::where('subscription_id', $subscription->id)
            ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC])
            ->where('status', Order::STATUS_COMPLETED)
            ->get();

        if ($orders->isEmpty()) {
            $order->surplus_amount = 0;
            $order->surplus_order_ids = [];
            return;
        }

        $paidAmount = $orders->sum(fn(Order $item) => ($item->total_amount ?? 0) + ($item->balance_amount ?? 0) + ($item->surplus_amount ?? 0) - ($item->surplus_credit ?? 0));
        $trafficRatio = $subscription->transfer_enable > 0
            ? $subscription->getRemainingTraffic() / $subscription->transfer_enable
            : 0;

        if ($subscription->expired_at === null) {
            $ratio = $trafficRatio;
        } else {
            $startedAt = $subscription->started_at ?: $subscription->created_at;
            $totalSeconds = max(1, $subscription->expired_at - $startedAt);
            $remainSeconds = max(0, $subscription->expired_at - time());
            $cycleRatio = $remainSeconds / $totalSeconds;
            $ratio = admin_setting('change_order_event_id', 0) == 1
                ? min($cycleRatio, $trafficRatio)
                : $cycleRatio;
        }

        $order->surplus_amount = (int) max(0, $paidAmount * $ratio);
        $order->surplus_order_ids = $orders->pluck('id')->all();

        if ($order->surplus_amount >= $order->total_amount) {
            $order->surplus_credit = (int) ($order->surplus_amount - $order->total_amount);
            $order->total_amount = 0;
        } else {
            $order->total_amount = (int) ($order->total_amount - $order->surplus_amount);
        }
    }

    private function openEvent($eventId): void
    {
        if ((int) $eventId === 1 && $this->user) {
            app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        }
    }
}
