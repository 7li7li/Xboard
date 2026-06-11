<?php

use App\Models\Order;
use App\Models\UserSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_user_subscriptions')) {
            Schema::create('v2_user_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->integer('user_id')->index();
                $table->integer('plan_id')->index();
                $table->integer('order_id')->nullable()->index();
                $table->tinyInteger('status')->default(UserSubscription::STATUS_ACTIVE)->index();
                $table->integer('started_at')->nullable();
                $table->bigInteger('expired_at')->nullable()->index();
                $table->bigInteger('transfer_enable')->default(0);
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->integer('group_id')->nullable()->index();
                $table->integer('speed_limit')->nullable();
                $table->integer('device_limit')->nullable();
                $table->integer('next_reset_at')->nullable()->index();
                $table->integer('last_reset_at')->nullable();
                $table->integer('reset_count')->default(0);
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->index(['user_id', 'status', 'expired_at'], 'idx_user_subscriptions_active');
                $table->index(['group_id', 'status', 'expired_at'], 'idx_user_subscriptions_group');
            });
        }

        if (!Schema::hasColumn('v2_order', 'subscription_id')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->unsignedBigInteger('subscription_id')->nullable()->after('plan_id')->index();
            });
        }

        $now = time();

        DB::table('v2_user')
            ->whereNotNull('plan_id')
            ->where('plan_id', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($now) {
                foreach ($users as $user) {
                    $exists = DB::table('v2_user_subscriptions')
                        ->where('user_id', $user->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $order = DB::table('v2_order')
                        ->where('user_id', $user->id)
                        ->where('plan_id', $user->plan_id)
                        ->where('status', Order::STATUS_COMPLETED)
                        ->where('period', '!=', 'reset_traffic')
                        ->orderByDesc('paid_at')
                        ->orderByDesc('created_at')
                        ->first();

                    $subscriptionId = DB::table('v2_user_subscriptions')->insertGetId([
                        'user_id' => $user->id,
                        'plan_id' => $user->plan_id,
                        'order_id' => $order?->id,
                        'status' => UserSubscription::STATUS_ACTIVE,
                        'started_at' => $order?->paid_at ?: ($order?->created_at ?: $user->created_at),
                        'expired_at' => $user->expired_at,
                        'transfer_enable' => $user->transfer_enable ?? 0,
                        'u' => $user->u ?? 0,
                        'd' => $user->d ?? 0,
                        'group_id' => $user->group_id,
                        'speed_limit' => $user->speed_limit,
                        'device_limit' => $user->device_limit ?? null,
                        'next_reset_at' => $user->next_reset_at ?? null,
                        'last_reset_at' => $user->last_reset_at ?? null,
                        'reset_count' => $user->reset_count ?? 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($order) {
                        DB::table('v2_order')
                            ->where('id', $order->id)
                            ->update(['subscription_id' => $subscriptionId]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_order', 'subscription_id')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->dropColumn('subscription_id');
            });
        }

        Schema::dropIfExists('v2_user_subscriptions');
    }
};
