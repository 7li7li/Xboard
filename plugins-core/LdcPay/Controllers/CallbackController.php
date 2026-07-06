<?php

namespace Plugin\LdcPay\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CallbackController extends Controller
{
    public function notify(Request $request)
    {
        return response($this->handleSignedCallback($request) ? 'success' : 'fail');
    }

    public function returnPage(Request $request): RedirectResponse
    {
        $merchantTradeNo = $this->inputOrderNo($request);
        if (!$merchantTradeNo) {
            return redirect($this->appUrl());
        }

        if ($request->filled('sign')) {
            $this->handleSignedCallback($request);
        } else {
            $this->handleUnsignedReturn($merchantTradeNo);
        }

        return redirect($this->appUrl('/#/order/' . $this->localTradeNo($merchantTradeNo)));
    }

    private function handleSignedCallback(Request $request): bool
    {
        $merchantTradeNo = $this->inputOrderNo($request);
        if (!$merchantTradeNo) {
            return false;
        }

        try {
            [$order, $payment] = $this->resolveOrderPayment($merchantTradeNo);
            HookManager::call('payment.notify.before', ['LdcPay', $payment->uuid, $request]);

            $paymentService = new PaymentService('LdcPay', $payment->id);
            $verify = $paymentService->notify($request->input());
            if (!$verify || $verify['trade_no'] !== $order->trade_no) {
                HookManager::call('payment.notify.failed', ['LdcPay', $payment->uuid, $request]);
                return false;
            }

            HookManager::call('payment.notify.verified', $verify);

            return $this->markPaid($order, $verify['callback_no']);
        } catch (\Throwable $e) {
            Log::error($e);
            return false;
        }
    }

    private function handleUnsignedReturn(string $merchantTradeNo): void
    {
        try {
            [$order, $payment] = $this->resolveOrderPayment($merchantTradeNo);
            if ($order->status !== Order::STATUS_PENDING) {
                return;
            }

            $result = $this->queryOrder($payment, $order, $merchantTradeNo);
            if (
                isset($result['code'], $result['status'], $result['money'])
                && (int) $result['code'] === 1
                && (int) $result['status'] === 1
                && round((float) $result['money'], 2) === round((float) $this->convertedMoney($payment, $order), 2)
            ) {
                $this->markPaid($order, (string) ($result['trade_no'] ?? $order->trade_no));
            }
        } catch (\Throwable $e) {
            Log::error($e);
        }
    }

    private function resolveOrderPayment(string $merchantTradeNo): array
    {
        $order = Order::with('payment')->where('trade_no', $this->localTradeNo($merchantTradeNo))->first();
        if (!$order || !$order->payment || $order->payment->payment !== 'LdcPay' || !$order->payment->enable) {
            throw new \RuntimeException('LDC order payment is not available');
        }

        return [$order, $order->payment];
    }

    private function markPaid(Order $order, string $callbackNo): bool
    {
        if ($order->status !== Order::STATUS_PENDING) {
            return true;
        }

        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);

        return true;
    }

    private function queryOrder(Payment $payment, Order $order, string $merchantTradeNo): ?array
    {
        $config = is_array($payment->config) ? $payment->config : json_decode((string) $payment->config, true);
        $response = Http::timeout(10)->get($this->gatewayUrls($config)['api'], [
            'act' => 'order',
            'pid' => trim((string) ($config['client_id'] ?? '')),
            'key' => trim((string) ($config['client_secret'] ?? '')),
            'out_trade_no' => $merchantTradeNo,
        ]);

        $result = $response->json();

        return is_array($result) ? $result : null;
    }

    private function convertedMoney(Payment $payment, Order $order): string
    {
        $config = is_array($payment->config) ? $payment->config : json_decode((string) $payment->config, true);
        $rate = $config['ldc_rate'] ?? 1;
        if ($rate === '' || $rate === null) {
            $rate = 1;
        }
        if (!is_numeric($rate) || (float) $rate <= 0) {
            throw new \RuntimeException('Invalid LDC payment rate');
        }

        $amount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);

        return sprintf('%.2f', ($amount / 100) * (float) $rate);
    }

    private function gatewayUrls(array $config): array
    {
        $base = rtrim(trim((string) ($config['ldc_url'] ?? 'https://credit.linux.do')), '/');
        if ($base === '') {
            $base = 'https://credit.linux.do';
        }

        if (preg_match('#/epay/pay$#i', $base)) {
            $pay = $base;
            $api = preg_replace('#/pay$#i', '', $base);
        } elseif (preg_match('#/epay$#i', $base)) {
            $api = $base;
            $pay = $base . '/pay';
        } else {
            $api = $base . '/epay';
            $pay = $api . '/pay';
        }

        return [
            'api' => $api . '/api.php',
            'submit' => $pay . '/submit.php',
        ];
    }

    private function inputOrderNo(Request $request): ?string
    {
        $orderNo = trim((string) $request->input('out_trade_no', ''));
        if ($orderNo === '' || !preg_match('/^[a-zA-Z0-9._\-|]+$/', $orderNo)) {
            return null;
        }

        return $orderNo;
    }

    private function localTradeNo(string $merchantTradeNo): string
    {
        return explode('|', $merchantTradeNo, 2)[0];
    }

    private function appUrl(string $path = ''): string
    {
        return rtrim((string) (admin_setting('app_url') ?: url('/')), '/') . $path;
    }
}
