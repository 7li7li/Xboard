<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $period = PlanService::getLegacyPeriod((string) $this->period);
        $items = $this->normalizedItems();
        $plans = $this->plansForItems($items);

        return [
            ...parent::toArray($request),
            'period' => $period,
            'refund_amount' => (int) ($this->surplus_credit ?? 0),
            'plan' => $this->resolvePlanResource($period, $request, $items, $plans),
            'order_items' => $this->resolveOrderItems($items, $plans),
            'payment' => $this->whenLoaded('payment', fn() => $this->payment ? [
                'id' => $this->payment->id,
                'name' => $this->payment->name,
                'payment' => $this->payment->payment,
                'icon' => $this->payment->icon,
            ] : null),
        ];
    }

    private function resolvePlanResource(string $legacyPeriod, Request $request, array $items, Collection $plans): mixed
    {
        if (empty($items)) {
            return $this->whenLoaded('plan', fn() => PlanResource::make($this->plan));
        }

        $periodAmount = array_sum(array_map(
            fn(array $item): int => (int) ($item['amount'] ?? 0),
            $items
        ));

        $firstPlan = $plans->get((int) ($items[0]['plan_id'] ?? 0)) ?: $this->plan;
        $plan = $firstPlan ? (new PlanResource($firstPlan))->toArray($request) : [];

        $plan['name'] = __('Batch renewal') . ' x ' . count($items);
        $plan['transfer_enable'] = collect($items)
            ->sum(fn(array $item): int => (int) ($plans->get((int) ($item['plan_id'] ?? 0))?->transfer_enable ?? 0));

        foreach (array_keys(Plan::LEGACY_PERIOD_MAPPING) as $periodKey) {
            $plan[$periodKey] = null;
        }

        if ($legacyPeriod) {
            $plan[$legacyPeriod] = $periodAmount;
        }

        return $plan;
    }

    private function resolveOrderItems(array $items, Collection $plans): array
    {
        if (empty($items)) {
            return [];
        }

        return array_map(function (array $item) use ($plans): array {
            $period = PlanService::getLegacyPeriod((string) ($item['period'] ?? ''));
            $plan = $plans->get((int) ($item['plan_id'] ?? 0));

            return [
                'subscription_id' => $item['subscription_id'] ?? null,
                'plan_id' => $item['plan_id'] ?? null,
                'plan_name' => $plan?->name,
                'period' => $period,
                'amount' => (int) ($item['amount'] ?? 0),
                'transfer_enable' => (int) ($plan?->transfer_enable ?? 0),
            ];
        }, $items);
    }

    private function plansForItems(array $items): Collection
    {
        if (empty($items)) {
            return collect();
        }

        return Plan::whereIn('id', collect($items)->pluck('plan_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
    }

    private function normalizedItems(): array
    {
        if ((int) $this->type !== Order::TYPE_RENEWAL || empty($this->items) || !is_array($this->items)) {
            return [];
        }

        return array_values(array_filter($this->items, fn($item): bool => is_array($item)));
    }
}
