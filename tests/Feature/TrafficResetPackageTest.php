<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\OrderService;
use App\Services\UserSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrafficResetPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausted_single_subscription_can_be_reset_and_user_aggregate_is_synced(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $subscription = $this->makeSubscription($user, $plan, [
            'transfer_enable' => 1_000,
            'u' => 600,
            'd' => 400,
        ]);
        app(UserSubscriptionService::class)->syncUserAggregate($user);

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            'reset_price',
            null,
            null,
            OrderService::INTENT_RESET,
        );

        $this->assertSame(Order::TYPE_RESET_TRAFFIC, $order->type);
        $this->assertSame($subscription->id, $order->subscription_id);

        $this->assertTrue((new OrderService($order))->paid('reset-package-callback'));

        $subscription->refresh();
        $user->refresh();
        $order->refresh();

        $this->assertSame(0, $subscription->u);
        $this->assertSame(0, $subscription->d);
        $this->assertSame(0, $user->u);
        $this->assertSame(0, $user->d);
        $this->assertSame(1, $subscription->reset_count);
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertDatabaseHas('v2_traffic_reset_logs', [
            'user_id' => $user->id,
            'reset_type' => TrafficResetLog::TYPE_PURCHASE,
            'trigger_source' => TrafficResetLog::SOURCE_ORDER,
        ]);
    }

    public function test_multiple_subscriptions_only_reset_the_selected_subscription(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $target = $this->makeSubscription($user, $plan, [
            'transfer_enable' => 1_000,
            'u' => 100,
            'd' => 200,
        ]);
        $other = $this->makeSubscription($user, $plan, [
            'transfer_enable' => 2_000,
            'u' => 300,
            'd' => 400,
        ]);
        app(UserSubscriptionService::class)->syncUserAggregate($user);

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            'reset_price',
            null,
            $target->id,
            OrderService::INTENT_RESET,
        );

        $this->assertTrue((new OrderService($order))->paid('multi-reset-package-callback'));

        $target->refresh();
        $other->refresh();
        $user->refresh();
        $order->refresh();

        $this->assertSame(0, $target->u);
        $this->assertSame(0, $target->d);
        $this->assertSame(300, $other->u);
        $this->assertSame(400, $other->d);
        $this->assertSame(300, $user->u);
        $this->assertSame(400, $user->d);
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
    }

    public function test_subscription_list_exposes_reset_package_metadata(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $subscription = $this->makeSubscription($user, $plan);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/user/getSubscribe');

        $response->assertOk();
        $response->assertJsonPath('data.subscriptions.0.id', $subscription->id);
        $response->assertJsonPath('data.subscriptions.0.reset_price', 200);
        $response->assertJsonPath('data.subscriptions.0.can_reset_traffic', true);
    }

    public function test_reset_order_endpoint_preserves_selected_subscription(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $target = $this->makeSubscription($user, $plan);
        $this->makeSubscription($user, $plan);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id,
            'subscription_id' => $target->id,
            'period' => 'reset_price',
            'intent' => OrderService::INTENT_RESET,
        ]);

        $response->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertSame(Order::TYPE_RESET_TRAFFIC, $order->type);
        $this->assertSame($target->id, $order->subscription_id);
    }

    public function test_reset_order_endpoint_accepts_canonical_period(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $subscription = $this->makeSubscription($user, $plan);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id,
            'subscription_id' => $subscription->id,
            'period' => Plan::PERIOD_RESET_TRAFFIC,
            'intent' => OrderService::INTENT_RESET,
        ]);

        $response->assertOk();
        $order = Order::where('trade_no', $response->json('data'))->firstOrFail();
        $this->assertSame(Plan::PERIOD_RESET_TRAFFIC, $order->period);
        $this->assertSame(Order::TYPE_RESET_TRAFFIC, $order->type);
        $this->assertSame($subscription->id, $order->subscription_id);
    }

    public function test_plan_configured_to_never_reset_does_not_offer_a_reset_package(): void
    {
        $plan = $this->makePlan();
        $plan->update(['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        $user = $this->makeUser($plan);
        $subscription = $this->makeSubscription($user, $plan);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/user/getSubscribe');

        $response->assertOk();
        $response->assertJsonPath('data.subscriptions.0.id', $subscription->id);
        $response->assertJsonPath('data.subscriptions.0.can_reset_traffic', false);

        $this->expectException(ApiException::class);
        OrderService::createFromRequest(
            $user,
            $plan->refresh(),
            'reset_price',
            null,
            $subscription->id,
            OrderService::INTENT_RESET,
        );
    }

    public function test_reset_purchase_requires_a_target_when_multiple_same_plan_subscriptions_exist(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $this->makeSubscription($user, $plan);
        $this->makeSubscription($user, $plan);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Please select the subscription to reset');

        OrderService::createFromRequest(
            $user,
            $plan,
            'reset_price',
            null,
            null,
            OrderService::INTENT_RESET,
        );
    }

    public function test_reset_purchase_rejects_a_subscription_from_another_plan(): void
    {
        $sourcePlan = $this->makePlan('Source');
        $targetPlan = $this->makePlan('Target', 2);
        $user = $this->makeUser($sourcePlan);
        $subscription = $this->makeSubscription($user, $sourcePlan);

        $this->expectException(ApiException::class);

        OrderService::createFromRequest(
            $user,
            $targetPlan,
            'reset_price',
            null,
            $subscription->id,
            OrderService::INTENT_RESET,
        );
    }

    public function test_open_does_not_fall_back_to_reset_all_subscriptions_without_a_target(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $first = $this->makeSubscription($user, $plan, ['u' => 100, 'd' => 100]);
        $second = $this->makeSubscription($user, $plan, ['u' => 200, 'd' => 200]);
        app(UserSubscriptionService::class)->syncUserAggregate($user);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'subscription_id' => null,
            'period' => Plan::PERIOD_RESET_TRAFFIC,
            'trade_no' => 'reset-fallback-' . Str::random(20),
            'type' => Order::TYPE_RESET_TRAFFIC,
            'status' => Order::STATUS_PROCESSING,
            'total_amount' => 200,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        try {
            (new OrderService($order))->open();
            $this->fail('An order without a target subscription should fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Target subscription does not exist', $exception->getMessage());
        }

        $first->refresh();
        $second->refresh();
        $this->assertSame(100, $first->u);
        $this->assertSame(100, $first->d);
        $this->assertSame(200, $second->u);
        $this->assertSame(200, $second->d);
    }

    private function makePlan(string $name = 'Reset Plan', int $groupId = 1): Plan
    {
        return Plan::query()->create([
            'group_id' => $groupId,
            'transfer_enable' => 10,
            'name' => $name,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [
                Plan::PERIOD_MONTHLY => 10,
                Plan::PERIOD_RESET_TRAFFIC => 2,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function makeUser(Plan $plan): User
    {
        return User::withoutEvents(fn() => User::query()->create([
            'email' => 'reset-' . Str::lower(Str::random(20)) . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 86_400,
            'created_at' => time(),
            'updated_at' => time(),
        ]));
    }

    private function makeSubscription(User $user, Plan $plan, array $overrides = []): UserSubscription
    {
        return UserSubscription::query()->create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => UserSubscription::STATUS_ACTIVE,
            'started_at' => time() - 3_600,
            'expired_at' => time() + 86_400,
            'transfer_enable' => 1_000,
            'u' => 0,
            'd' => 0,
            'group_id' => $plan->group_id,
            'reset_count' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
