<?php

namespace Plugin\LdcPay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const DEFAULT_GATEWAY_URL = 'https://credit.linux.do';

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['LdcPay'] = [
                    'name' => $this->getConfig('display_name', 'LDC Pay'),
                    'icon' => $this->getConfig('icon', 'LDC'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'ldc_url' => [
                'label' => 'Gateway URL',
                'type' => 'string',
                'required' => true,
                'default' => self::DEFAULT_GATEWAY_URL,
                'description' => 'LDC gateway base URL. Supports https://credit.linux.do, /epay, or /epay/pay.'
            ],
            'client_id' => [
                'label' => 'Client ID',
                'type' => 'string',
                'required' => true,
                'description' => 'Client ID from LDC payment application'
            ],
            'client_secret' => [
                'label' => 'Client Secret',
                'type' => 'string',
                'required' => true,
                'description' => 'Client Secret from LDC payment application'
            ],
            'product_name' => [
                'label' => 'Product Name',
                'type' => 'string',
                'description' => 'Optional payment product name. Defaults to the order number.'
            ],
            'ldc_rate' => [
                'label' => 'LDC Payment Rate',
                'type' => 'string',
                'default' => '1',
                'description' => 'Multiplier applied to the order amount. Example: 100 means a 1.00 order pays 100.00 LDC.'
            ],
        ];
    }

    public function pay($order): array
    {
        $merchantTradeNo = $this->merchantTradeNo((string) $order['trade_no']);
        $params = [
            'pid' => $this->getConfig('client_id'),
            'type' => 'epay',
            'out_trade_no' => $merchantTradeNo,
            'notify_url' => $order['notify_url'],
            'return_url' => $this->returnUrl($order, $merchantTradeNo),
            'name' => $this->getConfig('product_name') ?: $order['trade_no'],
            'money' => $this->convertedMoney((int) $order['total_amount']),
        ];

        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';

        try {
            $response = Http::asForm()
                ->withHeaders(['User-Agent' => 'XBoard LDC Pay'])
                ->withoutRedirecting()
                ->timeout(15)
                ->post($this->submitUrl(), $params);
        } catch (\Throwable $e) {
            throw new ApiException('LDC payment gateway request failed: ' . $e->getMessage());
        }

        $paymentUrl = $response->header('Location');
        if ($paymentUrl) {
            return [
                'type' => 1,
                'data' => $this->normalizePaymentUrl($paymentUrl)
            ];
        }

        $body = trim($response->body());
        if ($body !== '') {
            throw new ApiException('LDC payment gateway error: ' . $this->gatewayErrorMessage($body));
        }

        throw new ApiException('LDC payment gateway did not return a payment URL');
    }

    public function notify($params): array|bool
    {
        if (empty($params['sign']) || empty($params['out_trade_no']) || empty($params['trade_no'])) {
            $this->logNotifyFailure('missing required fields', $params);
            return false;
        }

        $sign = (string) $params['sign'];
        unset($params['sign'], $params['sign_type']);

        if (!hash_equals(strtolower($sign), $this->sign($params))) {
            $this->logNotifyFailure('invalid signature', $params);
            return false;
        }

        if (empty($params['trade_status']) || !$this->isPaidStatus((string) $params['trade_status'])) {
            $this->logNotifyFailure('unpaid trade status', $params);
            return false;
        }

        if (!$this->verifyMoney($params)) {
            $this->logNotifyFailure('money mismatch', $params);
            return false;
        }

        return [
            'trade_no' => $this->localTradeNo((string) $params['out_trade_no']),
            'callback_no' => $params['trade_no']
        ];
    }

    private function submitUrl(): string
    {
        return $this->gatewayUrls()['submit'];
    }

    private function gatewayUrls(): array
    {
        $base = rtrim(trim((string) $this->getConfig('ldc_url', self::DEFAULT_GATEWAY_URL)), '/');
        if ($base === '') {
            $base = self::DEFAULT_GATEWAY_URL;
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
            'submit' => $pay . '/submit.php',
            'mapi' => $pay . '/mapi.php',
            'api' => $api . '/api.php',
        ];
    }

    private function callbackUrl(string $sourceUrl, string $path): string
    {
        $base = parse_url($sourceUrl);
        $appUrl = (string) admin_setting('app_url');
        $scheme = $base['scheme'] ?? parse_url($appUrl, PHP_URL_SCHEME);
        $scheme = $scheme ?: 'https';
        $host = $base['host'] ?? parse_url($appUrl, PHP_URL_HOST);
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        if (!$host) {
            return url($path);
        }

        return $scheme . '://' . $host . $port . $path;
    }

    private function returnUrl(array $order, string $merchantTradeNo): string
    {
        return $this->callbackUrl($order['return_url'], '/pay/ldcreturn/')
            . '?out_trade_no=' . rawurlencode($merchantTradeNo);
    }

    private function normalizePaymentUrl(string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $submitUrl = $this->submitUrl();
        $base = parse_url($submitUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? 'credit.linux.do';
        $port = isset($base['port']) ? ':' . $base['port'] : '';

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $port . $url;
        }

        return rtrim(dirname($submitUrl), '/') . '/' . ltrim($url, '/');
    }

    private function gatewayErrorMessage(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return (string) ($json['error_msg'] ?? $json['msg'] ?? $json['message'] ?? substr($body, 0, 256));
        }

        return substr($body, 0, 256);
    }

    private function convertedMoney(int $amount): string
    {
        return sprintf('%.2f', ($amount / 100) * $this->rate());
    }

    private function rate(): float
    {
        $configuredRate = $this->getConfig('ldc_rate', 1);
        if ($configuredRate === '' || $configuredRate === null) {
            $configuredRate = 1;
        }

        if (!is_numeric($configuredRate)) {
            throw new ApiException('LDC payment rate must be numeric');
        }

        $rate = (float) $configuredRate;
        if ($rate <= 0) {
            throw new ApiException('LDC payment rate must be greater than 0');
        }

        return $rate;
    }

    private function isPaidStatus(string $status): bool
    {
        return in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);
    }

    private function verifyMoney(array $params): bool
    {
        if (!isset($params['money'])) {
            return false;
        }

        $order = Order::where('trade_no', $this->localTradeNo((string) $params['out_trade_no']))->first();
        if (!$order) {
            return false;
        }

        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);

        return round((float) $params['money'], 2) === round((float) $this->convertedMoney($expectedAmount), 2);
    }

    private function logNotifyFailure(string $reason, array $params): void
    {
        Log::warning('LDC payment notify verification failed', [
            'reason' => $reason,
            'out_trade_no' => $params['out_trade_no'] ?? null,
            'trade_no' => $params['trade_no'] ?? null,
            'trade_status' => $params['trade_status'] ?? null,
            'money' => $params['money'] ?? null,
        ]);
    }

    private function merchantTradeNo(string $tradeNo): string
    {
        return $tradeNo . '|' . random_int(100000, 999999);
    }

    private function localTradeNo(string $merchantTradeNo): string
    {
        return explode('|', $merchantTradeNo, 2)[0];
    }

    private function sign(array $params): string
    {
        ksort($params);
        $payload = '';
        foreach ($params as $key => $value) {
            if (in_array($key, ['sign', 'sign_type'], true) || is_array($value) || $value === '' || $value === null) {
                continue;
            }
            $payload .= $key . '=' . $value . '&';
        }
        $payload = rtrim($payload, '&');

        return md5($payload . (string) $this->getConfig('client_secret'));
    }
}
