<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\UserSubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        $userIds = array_keys($this->data);
        $users = User::whereIn('id', $userIds)
            ->withCount('subscriptions')
            ->get()
            ->keyBy('id');
        $subscriptionService = app(UserSubscriptionService::class);

        foreach ($this->data as $uid => $v) {
            $user = $users->get((int) $uid);
            if (!$user) {
                continue;
            }

            if ((int) ($user->subscriptions_count ?? 0) === 0 && $user->plan_id) {
                $subscriptionService->ensureSubscriptionFromLegacyUser($user);
                $user->refresh();
            }

            $subscriptionService->consumeTraffic(
                $user,
                $this->server['group_ids'] ?? [],
                (float) ($this->server['rate'] ?? 1),
                (int) $v[0],
                (int) $v[1]
            );

            User::withoutEvents(function () use ($user) {
                $user->forceFill(['t' => time()])->save();
            });
        }

        if (!empty($userIds)) {
            try {
                Redis::sadd('traffic:pending_check', ...$userIds);
            } catch (\Throwable $e) {
                Log::warning('Unable to mark users for traffic exceeded check: ' . $e->getMessage(), [
                    'user_ids' => $userIds,
                ]);
            }
        }
    }
}
