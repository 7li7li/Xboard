<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_renewal_order_resource_returns_itemized_details_and_total_price(): void
    {
        app()->setLocale('en-US');

        $firstPlan = $this->makePlan('Basic', 100, 1);
        $secondPlan = $this->makePlan('Pro', 200, 2);
        $order = Order::query()->create([
            'user_id' => 1,
            'plan_id' => $firstPlan->id,
            'subscription_id' => null,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'batch-renewal-test',
            'type' => Order::TYPE_RENEWAL,
            'status' => Order::STATUS_PENDING,
            'total_amount' => 25000,
            'balance_amount' => 5000,
            'items' => [
                [
                    'subscription_id' => 11,
                    'plan_id' => $firstPlan->id,
                    'period' => Plan::PERIOD_MONTHLY,
                    'amount' => 10000,
                ],
                [
                    'subscription_id' => 22,
                    'plan_id' => $secondPlan->id,
                    'period' => Plan::PERIOD_MONTHLY,
                    'amount' => 20000,
                ],
            ],
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $payload = (new OrderResource($order->load('plan')))->toArray(Request::create('/'));

        $this->assertSame('month_price', $payload['period']);
        $this->assertSame('Batch renewal x 2', $payload['plan']['name']);
        $this->assertSame(300, $payload['plan']['transfer_enable']);
        $this->assertSame(30000, $payload['plan']['month_price']);
        $this->assertCount(2, $payload['order_items']);
        $this->assertSame([
            'subscription_id' => 11,
            'plan_id' => $firstPlan->id,
            'plan_name' => 'Basic',
            'period' => 'month_price',
            'amount' => 10000,
            'transfer_enable' => 100,
        ], $payload['order_items'][0]);
        $this->assertSame([
            'subscription_id' => 22,
            'plan_id' => $secondPlan->id,
            'plan_name' => 'Pro',
            'period' => 'month_price',
            'amount' => 20000,
            'transfer_enable' => 200,
        ], $payload['order_items'][1]);
    }

    private function makePlan(string $name, int $transferEnable, int $groupId): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'group_id' => $groupId,
            'transfer_enable' => $transferEnable,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'prices' => [
                Plan::PERIOD_MONTHLY => $transferEnable,
            ],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
