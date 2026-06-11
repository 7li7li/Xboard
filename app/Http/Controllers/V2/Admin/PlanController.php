<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\UserSubscription;
use App\Services\UserSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'subscriptions as users_count',
                'subscriptions as active_users_count' => fn($query) => $query->active(),
            ])
            ->get();

        return $this->success($plans);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }
            
            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    UserSubscription::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit' => $params['speed_limit'],
                        'device_limit' => $params['device_limit'],
                    ]);

                    $subscriptionService = app(UserSubscriptionService::class);
                    UserSubscription::where('plan_id', $plan->id)
                        ->select('user_id')
                        ->distinct()
                        ->with('user')
                        ->get()
                        ->each(function (UserSubscription $subscription) use ($subscriptionService) {
                            if ($subscription->user) {
                                $subscriptionService->syncUserAggregate($subscription->user);
                            }
                        });
                }
                $plan->update($params);
                DB::commit();
                return $this->success(true);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }
        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (UserSubscription::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        
        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }
        
        return $this->success($plan->delete());
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
