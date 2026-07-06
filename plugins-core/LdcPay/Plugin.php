<?php

namespace Plugin\LdcPay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Http;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const DEFAULT_GATEWAY_URL = 'https://credit.linux.do/epay/pay';

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
                'description' => 'LDC EPay-compatible gateway URL, default: https://credit.linux.do/epay/pay'
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
        $params = [
            'pid' => $this->getConfig('client_id'),
            'type' => 'epay',
            'out_trade_no' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
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
            return false;
        }

        $sign = (string) $params['sign'];
        unset($params['sign'], $params['sign_type']);

        if (!hash_equals(strtolower($sign), $this->sign($params))) {
            return false;
        }

        if (isset($params['trade_status']) && $params['trade_status'] !== 'TRADE_SUCCESS') {
            return false;
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }

    private function submitUrl(): string
    {
        return rtrim($this->getConfig('ldc_url', self::DEFAULT_GATEWAY_URL), '/') . '/submit.php';
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

        return sprintf('%.2f', ($amount / 100) * $rate);
    }

    private function sign(array $params): string
    {
        $params = array_filter($params, static fn ($value) => $value !== '' && $value !== null);
        ksort($params);
        $payload = stripslashes(urldecode(http_build_query($params)));

        return md5($payload . (string) $this->getConfig('client_secret'));
    }
}
