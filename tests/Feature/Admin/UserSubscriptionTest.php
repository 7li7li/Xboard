<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\UserSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_excludes_expired_subscriptions_from_aggregate_and_preserves_history(): void
    {
        $user = $this->makeUser();
        $active = $this->makeSubscription($user);
        $expired = $this->makeSubscription($user, ['u' => 100, 'd' => 200]);
        app(UserSubscriptionService::class)->syncUserAggregate($user);
        $expired->update(['expired_at' => 1]);
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));

        $response = $this->postJson($this->fetchUrl(), ['user_id' => $user->id]);

        $response->assertOk()
            ->assertJsonPath('data.transfer_enable', $active->transfer_enable)
            ->assertJsonPath('data.u', 0)
            ->assertJsonPath('data.d', 0)
            ->assertJsonCount(2, 'data.subscriptions');
        $this->assertSame(100, $expired->refresh()->u);
        $this->assertSame(200, $expired->d);
        $this->assertSame(0, $active->refresh()->getTotalUsedTraffic());
    }

    public function test_fetch_clears_aggregate_when_all_subscriptions_have_expired(): void
    {
        $user = $this->makeUser();
        $subscription = $this->makeSubscription($user, ['u' => 100, 'd' => 200]);
        app(UserSubscriptionService::class)->syncUserAggregate($user);
        $subscription->update(['expired_at' => 1]);
        Sanctum::actingAs($this->makeUser(['is_admin' => true]));

        $this->postJson($this->fetchUrl(), ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.plan_id', null)
            ->assertJsonPath('data.transfer_enable', 0)
            ->assertJsonPath('data.u', 0)
            ->assertJsonPath('data.d', 0)
            ->assertJsonCount(1, 'data.subscriptions');
    }

    public function test_non_admin_cannot_fetch_another_users_subscriptions(): void
    {
        $user = $this->makeUser();
        $this->makeSubscription($user);
        Sanctum::actingAs($this->makeUser());

        $this->postJson($this->fetchUrl(), ['user_id' => $user->id])->assertForbidden();
    }

    private function fetchUrl(): string
    {
        return '/' . Route::getRoutes()->getByAction(UserController::class . '@fetchSubscription')->uri();
    }

    private function makeUser(array $overrides = []): User
    {
        return User::withoutEvents(fn() => User::create(array_merge([
            'email' => Str::uuid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'u' => 0,
            'd' => 0,
        ], $overrides)));
    }

    private function makeSubscription(User $user, array $overrides = []): UserSubscription
    {
        $plan = Plan::create([
            'name' => 'Admin subscription test',
            'group_id' => 1,
            'transfer_enable' => 10,
        ]);

        return UserSubscription::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => UserSubscription::STATUS_ACTIVE,
            'started_at' => time() - 3600,
            'expired_at' => time() + 86400,
            'transfer_enable' => 1000,
            'group_id' => $plan->group_id,
            'u' => 0,
            'd' => 0,
        ], $overrides));
    }
}
