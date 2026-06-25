<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Models\UserSubscription;
use App\Protocols\General;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Protocol prefix mapping for server names
     */
    private const PROTOCOL_PREFIXES = [
        'hysteria' => [
            1 => '[Hy]',
            2 => '[Hy2]'
        ],
        'vless' => '[vless]',
        'shadowsocks' => '[ss]',
        'vmess' => '[vmess]',
        'trojan' => '[trojan]',
        'tuic' => '[tuic]',
        'socks' => '[socks]',
        'anytls' => '[anytls]'
    ];


    public function subscribe(Request $request)
    {
        HookManager::call('client.subscribe.before');
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'flag' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $context = $this->resolveSubscribeContext($request);
        if (!$context) {
            return response('', 403, ['Content-Type' => 'text/plain']);
        }

        return $this->doSubscribe(
            $request,
            $context['user'],
            $context['servers'],
            $context['reset_day']
        );
    }

    public function doSubscribe(Request $request, $user, $servers = null, ?int $resetDay = null)
    {
        if ($servers === null) {
            $servers = ServerService::getAvailableServers($user);
            $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
        }

        $clientInfo = $this->getClientInfo($request);

        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));

        $protocolClassName = app('protocols.manager')->matchProtocolClassName($clientInfo['flag'])
            ?? General::class;

        $serversFiltered = $this->filterServers(
            servers: $servers,
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );

        $this->setSubscribeInfoToServers($serversFiltered, $user, count($servers) - count($serversFiltered), $resetDay);
        $serversFiltered = $this->addPrefixToServerName($serversFiltered);

        // Instantiate the protocol class with filtered servers and client info
        $protocolInstance = app()->make($protocolClassName, [
            'user' => $user,
            'servers' => $serversFiltered,
            'clientName' => $clientInfo['name'] ?? null,
            'clientVersion' => $clientInfo['version'] ?? null,
            'userAgent' => $clientInfo['flag'] ?? null
        ]);

        return $protocolInstance->handle();
    }

    public function nodes(Request $request)
    {
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'subscription_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $context = $this->resolveSubscribeContext($request);
        if (!$context) {
            abort(403);
        }

        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));
        $serversFiltered = $this->filterServers(
            servers: $context['servers'],
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );
        $serversFiltered = $this->addPrefixToServerName($serversFiltered);

        $nodes = $this->buildNodeLinks($context['user'], $serversFiltered);
        $decodedNodes = $this->buildDecodedNodeLinks($nodes);

        return response()
            ->view('client.nodes', [
                'app_name' => admin_setting('app_name', 'XBoard'),
                'subscription_name' => $context['subscription']?->plan?->name,
                'subscription_id' => $context['subscription']?->id,
                'nodes' => $nodes,
                'decoded_nodes' => $decodedNodes,
                'node_count' => count($nodes),
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function resolveSubscribeContext(Request $request): ?array
    {
        $user = $request->user();
        $userService = new UserService();

        if ($request->filled('subscription_id')) {
            $subscription = UserSubscription::with('plan')
                ->where('id', $request->integer('subscription_id'))
                ->where('user_id', $user->id)
                ->available()
                ->first();

            if (!$subscription || $user->banned) {
                HookManager::call('client.subscribe.unavailable');
                return null;
            }

            $groupId = $this->resolveSubscriptionGroupId($subscription);
            if (!$groupId) {
                HookManager::call('client.subscribe.unavailable');
                return null;
            }

            $scopedUser = $this->makeSubscriptionScopedUser($user, $subscription);
            $servers = ServerService::getAvailableServersForGroups($scopedUser, [$groupId]);
            $servers = HookManager::filter('client.subscribe.servers', $servers, $scopedUser, $request);

            return [
                'user' => $scopedUser,
                'servers' => $servers,
                'reset_day' => $this->getSubscriptionResetDay($subscription),
                'subscription' => $subscription,
            ];
        }

        if (!$userService->isAvailable($user)) {
            HookManager::call('client.subscribe.unavailable');
            return null;
        }

        $servers = ServerService::getAvailableServers($user);
        $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);

        return [
            'user' => $user,
            'servers' => $servers,
            'reset_day' => null,
            'subscription' => null,
        ];
    }

    private function buildNodeLinks(User $user, array $servers): array
    {
        return collect($servers)
            ->map(fn(array $server) => $this->buildNodeLink($user, $server))
            ->filter()
            ->values()
            ->all();
    }

    private function buildDecodedNodeLinks(array $nodes): array
    {
        return collect($nodes)
            ->map(fn(string $node) => $this->decodeNodeLink($node))
            ->values()
            ->all();
    }

    private function decodeNodeLink(string $node): string
    {
        $node = trim($node);
        if ($node === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($node, PHP_URL_SCHEME));

        return match ($scheme) {
            'vmess' => $this->decodeVmessNode($node),
            'ss' => $this->decodeBase64UserInfoNode($node, 'ss', 'SS Base64'),
            'socks' => $this->decodeBase64UserInfoNode($node, 'socks', 'SOCKS Base64'),
            'http' => $this->decodeBase64UserInfoNode($node, 'http', 'HTTP Base64'),
            default => $this->formatUrlNode($node, '该协议链接本身没有 Base64 包裹，以下为 URL 参数解析。'),
        };
    }

    private function decodeVmessNode(string $node): string
    {
        $payload = preg_replace('/^vmess:\/\//i', '', trim($node));
        $decoded = $this->decodeBase64Payload($payload);

        if ($decoded === null) {
            return "协议: vmess\nBase64解析: 解析失败";
        }

        $json = json_decode($decoded, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $decoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return "协议: vmess\nBase64解析:\n{$decoded}";
    }

    private function decodeBase64UserInfoNode(string $node, string $protocol, string $label): string
    {
        $parts = $this->parseNodeUrl($node);
        $encoded = $parts['userinfo'] ?? '';
        $decoded = $this->decodeBase64Payload($encoded);

        if ($decoded === null && $encoded === '' && !empty($parts['authority'])) {
            $decoded = $this->decodeBase64Payload($parts['authority']);
        }

        $lines = ["协议: {$protocol}"];
        if (!empty($parts['name'])) {
            $lines[] = "名称: {$parts['name']}";
        }

        if ($decoded === null) {
            $lines[] = "{$label}解析: 解析失败";
        } elseif ($protocol === 'ss') {
            $parts = explode(':', $decoded, 2);
            if (count($parts) === 2) {
                $lines[] = "加密方式：{$parts[0]}";
                $lines[] = "密钥：{$parts[1]}";
            } else {
                $lines[] = "{$label}解析: {$decoded}";
            }
        } else {
            $lines[] = "{$label}解析: {$decoded}";
        }

        if (!empty($parts['host'])) {
            $lines[] = "地址: {$parts['host']}";
        }
        if (!empty($parts['port'])) {
            $lines[] = "端口: {$parts['port']}";
        }
        if (!empty($parts['query'])) {
            $lines[] = '参数:';
            foreach ($parts['query'] as $key => $value) {
                $displayKey = $this->displayQueryKey($protocol, (string) $key);
                $lines[] = "  {$displayKey}: " . $this->stringifyQueryValue($value);
            }
        }

        return implode("\n", $lines);
    }

    private function formatUrlNode(string $node, string $note): string
    {
        $parts = $this->parseNodeUrl($node);
        $protocol = $parts['scheme'] ?: 'unknown';
        $userInfoLabel = $protocol === 'vless' ? '密钥' : '用户信息';
        $lines = [
            "协议: {$protocol}",
            "说明: {$note}",
        ];

        if (!empty($parts['name'])) {
            $lines[] = "名称: {$parts['name']}";
        }
        if (!empty($parts['userinfo'])) {
            $lines[] = "{$userInfoLabel}: {$parts['userinfo']}";
        }
        if (!empty($parts['host'])) {
            $lines[] = "地址: {$parts['host']}";
        }
        if (!empty($parts['port'])) {
            $lines[] = "端口: {$parts['port']}";
        }
        if (!empty($parts['query'])) {
            $lines[] = '参数:';
            foreach ($parts['query'] as $key => $value) {
                $displayKey = $this->displayQueryKey($protocol, (string) $key);
                $lines[] = "  {$displayKey}: " . $this->stringifyQueryValue($value);
            }
        }

        return implode("\n", $lines);
    }

    private function parseNodeUrl(string $node): array
    {
        $node = trim($node);
        $scheme = strtolower((string) parse_url($node, PHP_URL_SCHEME));
        $fragment = parse_url($node, PHP_URL_FRAGMENT);
        $queryString = (string) parse_url($node, PHP_URL_QUERY);
        $query = [];

        if ($queryString !== '') {
            parse_str($queryString, $query);
        }

        $withoutScheme = preg_replace('/^[a-z][a-z0-9+.-]*:\/\//i', '', $node) ?? $node;
        $authority = preg_split('/[?#]/', $withoutScheme, 2)[0] ?? '';
        $userinfo = null;
        $hostPort = $authority;

        if (($atPosition = strrpos($authority, '@')) !== false) {
            $userinfo = substr($authority, 0, $atPosition);
            $hostPort = substr($authority, $atPosition + 1);
        }

        $hostPort = rtrim($hostPort, '/');
        [$host, $port] = $this->splitHostPort($hostPort);

        return [
            'scheme' => $scheme,
            'authority' => rawurldecode($authority),
            'userinfo' => $userinfo !== null ? rawurldecode($userinfo) : null,
            'host' => $host,
            'port' => $port,
            'query' => $query,
            'name' => is_string($fragment) ? rawurldecode($fragment) : null,
        ];
    }

    private function splitHostPort(string $hostPort): array
    {
        if ($hostPort === '') {
            return [null, null];
        }

        if (str_starts_with($hostPort, '[') && preg_match('/^\[(?<host>.+)]:(?<port>\d+)$/', $hostPort, $matches)) {
            return [$matches['host'], $matches['port']];
        }

        if (preg_match('/^(?<host>.+):(?<port>\d+)$/', $hostPort, $matches)) {
            return [$matches['host'], $matches['port']];
        }

        return [$hostPort, null];
    }

    private function decodeBase64Payload(?string $payload): ?string
    {
        $payload = trim((string) $payload);
        if ($payload === '') {
            return null;
        }

        $normalized = strtr($payload, '-_', '+/');
        $remainder = strlen($normalized) % 4;
        if ($remainder > 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        return $decoded;
    }

    private function displayQueryKey(string $protocol, string $key): string
    {
        if ($protocol === 'vless' && $key === 'pbk') {
            return 'publicKey';
        }

        return $key;
    }

    private function stringifyQueryValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function buildNodeLink(User $user, array $server): string
    {
        $password = data_get($server, 'password', $user->uuid);

        return match ($server['type'] ?? null) {
            Server::TYPE_VMESS => General::buildVmess($password, $server),
            Server::TYPE_VLESS => General::buildVless($password, $server),
            Server::TYPE_SHADOWSOCKS => General::buildShadowsocks($password, $server),
            Server::TYPE_TROJAN => General::buildTrojan($password, $server),
            Server::TYPE_HYSTERIA => General::buildHysteria($password, $server),
            Server::TYPE_ANYTLS => General::buildAnyTLS($password, $server),
            Server::TYPE_SOCKS => General::buildSocks($password, $server),
            Server::TYPE_TUIC => General::buildTuic($password, $server),
            Server::TYPE_HTTP => General::buildHttp($password, $server),
            default => '',
        };
    }

    /**
     * Parses the input string for requested server types.
     */
    private function parseRequestedTypes(?string $typeInputString): array
    {
        if (blank($typeInputString) || $typeInputString === 'all') {
            return Server::VALID_TYPES;
        }

        $requested = collect(preg_split('/[|,｜]+/', $typeInputString))
            ->map(fn($type) => trim($type))
            ->filter() // Remove empty strings that might result from multiple delimiters
            ->all();

        return array_values(array_intersect($requested, Server::VALID_TYPES));
    }

    /**
     * Parses the input string for filter keywords.
     */
    private function parseFilterKeywords(?string $filterInputString): ?array
    {
        if (blank($filterInputString) || mb_strlen($filterInputString) > 20) {
            return null;
        }

        return collect(preg_split('/[|,｜]+/', $filterInputString))
            ->map(fn($keyword) => trim($keyword))
            ->filter() // Remove empty strings
            ->all();
    }

    /**
     * Filters servers based on allowed types and keywords.
     */
    private function filterServers(array $servers, array $allowedTypes, ?array $filterKeywords): array
    {
        return collect($servers)->filter(function ($server) use ($allowedTypes, $filterKeywords) {
            // Condition 1: Server type must be in the list of allowed types
            if ($allowedTypes && !in_array($server['type'], $allowedTypes)) {
                return false; // Filter out (don't keep)
            }

            // Condition 2: If filterKeywords are provided, at least one keyword must match
            if (!empty($filterKeywords)) { // Check if $filterKeywords is not empty
                $keywordMatch = collect($filterKeywords)->contains(function ($keyword) use ($server) {
                    return stripos($server['name'], $keyword) !== false
                        || in_array($keyword, $server['tags'] ?? []);
                });
                if (!$keywordMatch) {
                    return false; // Filter out if no keywords match
                }
            }
            // Keep the server if its type is allowed AND (no filter keywords OR at least one keyword matched)
            return true;
        })->values()->all();
    }

    private function getClientInfo(Request $request): array
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));

        $clientName = null;
        $clientVersion = null;

        if (preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/', $flag, $matches)) {
            $potentialName = strtolower($matches[1]);
            $clientVersion = preg_replace('/^v/', '', $matches[2]);

            if (in_array($potentialName, app('protocols.flags'))) {
                $clientName = $potentialName;
            }
        }

        if (!$clientName) {
            $flags = collect(app('protocols.flags'))->sortByDesc(fn($f) => strlen($f))->values()->all();
            foreach ($flags as $name) {
                if (stripos($flag, $name) !== false) {
                    $clientName = $name;
                    if (!$clientVersion) {
                        $pattern = '/' . preg_quote($name, '/') . '[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/i';
                        if (preg_match($pattern, $flag, $vMatches)) {
                            $clientVersion = preg_replace('/^v/', '', $vMatches[1]);
                        }
                    }
                    break;
                }
            }
        }

        if (!$clientVersion) {
            if (preg_match('/\/v?(\d+(?:\.\d+){0,2})/', $flag, $matches)) {
                $clientVersion = $matches[1];
            }
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion
        ];
    }

    private function makeSubscriptionScopedUser(User $user, UserSubscription $subscription): User
    {
        $scopedUser = clone $user;
        $scopedUser->forceFill([
            'plan_id' => $subscription->plan_id,
            'group_id' => $this->resolveSubscriptionGroupId($subscription),
            'transfer_enable' => $subscription->transfer_enable,
            'u' => $subscription->u,
            'd' => $subscription->d,
            'expired_at' => $subscription->expired_at,
            'speed_limit' => $subscription->speed_limit,
            'device_limit' => $subscription->device_limit,
            'next_reset_at' => $subscription->next_reset_at,
            'last_reset_at' => $subscription->last_reset_at,
            'reset_count' => $subscription->reset_count,
        ]);
        $scopedUser->setRelation('plan', $subscription->plan);

        return $scopedUser;
    }

    private function getSubscriptionResetDay(UserSubscription $subscription): ?int
    {
        if (!$subscription->next_reset_at) {
            return null;
        }

        $seconds = (int) $subscription->next_reset_at - time();
        if ($seconds <= 0) {
            return 0;
        }

        return (int) ceil($seconds / 86400);
    }

    private function resolveSubscriptionGroupId(UserSubscription $subscription): ?int
    {
        $groupId = $subscription->group_id ?: data_get($subscription, 'plan.group_id');
        return $groupId ? (int) $groupId : null;
    }

    private function setSubscribeInfoToServers(&$servers, $user, $rejectServerCount = 0, ?int $resetDay = null)
    {
        if (!isset($servers[0]))
            return;
        if ($rejectServerCount > 0) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "过滤掉{$rejectServerCount}条线路",
            ]));
        }
        if (!(int) admin_setting('show_info_to_server_enable', 0))
            return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : __('长期有效');
        if ($resetDay === null) {
            $userService = new UserService();
            $resetDay = $userService->getResetDay($user);
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }

    private function addPrefixToServerName(array $servers): array
    {
        if (!admin_setting('show_protocol_to_server_enable', false)) {
            return $servers;
        }
        return collect($servers)
            ->map(function (array $server): array {
                $server['name'] = $this->getPrefixedServerName($server);
                return $server;
            })
            ->all();
    }

    private function getPrefixedServerName(array $server): string
    {
        $type = $server['type'] ?? '';
        if (!isset(self::PROTOCOL_PREFIXES[$type])) {
            return $server['name'] ?? '';
        }
        $prefix = is_array(self::PROTOCOL_PREFIXES[$type])
            ? self::PROTOCOL_PREFIXES[$type][$server['protocol_settings']['version'] ?? 1] ?? ''
            : self::PROTOCOL_PREFIXES[$type];
        return $prefix . ($server['name'] ?? '');
    }
}
