<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Models\UserSubscription;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ServerHandshakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        Cache::forever('admin_settings', [
            'server_token' => 'server-token',
            'server_ws_enable' => 0,
        ]);
    }

    public function test_v2_handshake_accepts_token_only_without_node(): void
    {
        $response = $this->postJson('/api/v2/server/handshake', [
            'token' => 'server-token',
        ]);

        $response->assertOk()->assertJsonStructure(['websocket' => ['enabled']]);
    }

    public function test_v2_handshake_rejects_invalid_token(): void
    {
        $response = $this->postJson('/api/v2/server/handshake', [
            'token' => 'wrong-token',
        ]);

        $response->assertStatus(422);
    }

    public function test_v2_report_works_without_node_type(): void
    {
        Bus::fake();

        $server = $this->makeServer();

        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
        ]);

        $response->assertOk()->assertJson(['data' => true]);
    }

    public function test_v2_report_ignores_node_type_field(): void
    {
        Bus::fake();

        $server = $this->makeServer();

        // legacy node clients may still send node_type; V2 must accept it as no-op.
        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
            'node_type' => 'this-would-be-rejected-by-v1',
        ]);

        $response->assertOk()->assertJson(['data' => true]);
    }

    public function test_v2_report_rejects_unknown_node(): void
    {
        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => 999999,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Server does not exist']);
    }

    public function test_v2_machine_handshake_with_machine_id_and_no_node(): void
    {
        $machine = ServerMachine::create([
            'name' => 'test-machine',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v2/server/handshake', [
            'machine_id' => $machine->id,
            'token' => 'machine-token',
        ]);

        $response->assertOk();
    }

    public function test_v2_machine_report_requires_node_id(): void
    {
        $machine = ServerMachine::create([
            'name' => 'test-machine',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v2/server/report', [
            'machine_id' => $machine->id,
            'token' => 'machine-token',
        ]);

        $response->assertStatus(422);
    }

    public function test_v2_report_consumes_subscription_traffic(): void
    {
        Queue::fake();

        $server = $this->makeServer();
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $subscription = $this->makeSubscription($user, $plan);

        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
            'traffic' => [
                $user->id => [1024, 2048],
            ],
        ]);

        $response->assertOk()->assertJson(['data' => true]);
        Queue::assertPushed(TrafficFetchJob::class);

        Redis::shouldReceive('sadd')->once();
        Queue::pushed(TrafficFetchJob::class)->first()->handle();

        $subscription->refresh();
        $user->refresh();

        $this->assertSame(1024, $subscription->u);
        $this->assertSame(2048, $subscription->d);
        $this->assertSame(1024, $user->u);
        $this->assertSame(2048, $user->d);
    }

    public function test_v2_report_consumes_traffic_when_legacy_group_ids_are_json_strings(): void
    {
        $server = $this->makeServer(['group_ids' => '["1"]']);
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        $subscription = $this->makeSubscription($user, $plan);
        Redis::shouldReceive('sadd')->once();

        (new TrafficFetchJob($server->toArray(), [
            $user->id => [100, 200],
        ], $server->type, time()))->handle();

        $subscription->refresh();
        $user->refresh();

        $this->assertSame(100, $subscription->u);
        $this->assertSame(200, $subscription->d);
        $this->assertSame(100, $user->u);
        $this->assertSame(200, $user->d);
    }

    public function test_v2_report_backfills_legacy_user_subscription_before_consuming_traffic(): void
    {
        $server = $this->makeServer();
        $plan = $this->makePlan();
        $user = $this->makeUser($plan);
        Redis::shouldReceive('sadd')->once();

        (new TrafficFetchJob($server->toArray(), [
            $user->id => [300, 400],
        ], $server->type, time()))->handle();

        $subscription = UserSubscription::where('user_id', $user->id)->first();
        $user->refresh();

        $this->assertNotNull($subscription);
        $this->assertSame(300, $subscription->u);
        $this->assertSame(400, $subscription->d);
        $this->assertSame(300, $user->u);
        $this->assertSame(400, $user->d);
    }

    private function makeServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'test-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'enabled' => true,
        ], $overrides));
    }

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'test-plan',
            'group_id' => 1,
            'transfer_enable' => 10,
            'show' => true,
            'renew' => true,
            'prices' => [
                Plan::PERIOD_MONTHLY => 100,
            ],
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeUser(Plan $plan, array $overrides = []): User
    {
        return User::withoutEvents(fn() => User::create(array_merge([
            'email' => 'user' . random_int(1000, 9999) . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => $plan->transfer_enable * 1073741824,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides)));
    }

    private function makeSubscription(User $user, Plan $plan, array $overrides = []): UserSubscription
    {
        return UserSubscription::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => UserSubscription::STATUS_ACTIVE,
            'started_at' => time(),
            'expired_at' => time() + 86400,
            'transfer_enable' => $plan->transfer_enable * 1073741824,
            'u' => 0,
            'd' => 0,
            'group_id' => $plan->group_id,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
