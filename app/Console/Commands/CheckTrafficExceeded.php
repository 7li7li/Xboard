<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserSubscription;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '检查流量超标用户并通知节点';

    public function handle()
    {
        $count = Redis::scard('traffic:pending_check');
        if ($count <= 0) {
            return;
        }

        $pendingUserIds = array_map('intval', Redis::spop('traffic:pending_check', $count));

        $exceededUserIds = UserSubscription::whereIn('user_id', $pendingUserIds)
            ->active()
            ->whereRaw('u + d >= transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($exceededUserIds->isEmpty()) {
            return;
        }

        $notifiedCount = 0;
        $users = User::whereIn('id', $exceededUserIds)->get();

        foreach ($users as $user) {
            NodeSyncService::notifyUserChanged($user);
            $notifiedCount++;
        }

        $this->info("Checked " . count($pendingUserIds) . " users, refreshed {$notifiedCount} exceeded users.");
    }
}
