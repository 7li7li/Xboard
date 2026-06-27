<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerRoute;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class ServerService
{

    /**
     * 获取所有服务器列表
     * @return Collection
     */
    public static function getAllServers(): Collection
    {
        $query = Server::orderBy('sort', 'ASC');

        return $query->get()->append([
            'last_check_at',
            'last_push_at',
            'online',
            'is_online',
            'available_status',
            'cache_key',
            'load_status',
            'metrics',
            'online_conn'
        ]);
    }

    /**
     * 获取机器下所有已启用节点
     */
    public static function getMachineNodes(ServerMachine $machine): Collection
    {
        return Server::where('machine_id', $machine->id)
            ->where('enabled', true)
            ->orderBy('sort', 'ASC')
            ->get();
    }

    /**
     * 获取指定用户可用的服务器列表
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        $groupIds = app(UserSubscriptionService::class)->getAvailableGroupIds($user);
        return self::getAvailableServersForGroups($user, $groupIds);
    }

    /**
     * Get enabled nodes available to the given user in specific permission groups.
     */
    public static function getAvailableServersForGroups(User $user, array $groupIds): array
    {
        $groupIds = collect($groupIds)
            ->filter(fn($groupId) => $groupId !== null && $groupId !== '')
            ->map(fn($groupId) => (int) $groupId)
            ->unique()
            ->values()
            ->all();

        if (empty($groupIds)) {
            return [];
        }

        $servers = Server::where(function ($query) use ($groupIds) {
                foreach ($groupIds as $groupId) {
                    $query->orWhereJsonContains('group_ids', (string) $groupId);
                    $query->orWhereJsonContains('group_ids', (int) $groupId);
                }
            })
            ->where('show', true)
            ->where(function ($query) {
                $query->whereNull('transfer_enable')
                    ->orWhere('transfer_enable', 0)
                    ->orWhereRaw('u + d < transfer_enable');
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key', 'server_key']);

        $servers = collect($servers)->map(function ($server) use ($user) {
            // 判断动态端口
            if (str_contains($server->port, '-')) {
                $port = $server->port;
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            $server->rate = $server->getCurrentRate();
            return $server;
        })->toArray();

        return $servers;
    }

    /**
     * 根据权限组获取可用的用户列表
     * @param array $groupIds
     * @return Collection
     */
    public static function getAvailableUsers(Server $node)
    {
        $users = app(UserSubscriptionService::class)->getAvailableNodeUsers($node);
        return HookManager::filter('server.users.get', $users, $node);
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])->whereIn('id', $routeIds)->get();
        return $routes;
    }

    /**
     * 处理节点流量数据汇报
     */
    public static function processTraffic(Server $node, array $traffic): void
    {
        $data = self::normalizeTrafficPayload($traffic);

        if (empty($data)) {
            return;
        }

        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        Cache::put(CacheKey::get("SERVER_{$nodeType}_ONLINE_USER", $nodeId), count($data), 3600);
        Cache::put(CacheKey::get("SERVER_{$nodeType}_LAST_PUSH_AT", $nodeId), time(), 3600);

        (new UserService())->trafficFetch($node, $node->type, $data);
    }

    private static function normalizeTrafficPayload(array $traffic): array
    {
        $data = [];

        foreach ($traffic as $key => $item) {
            $record = self::normalizeTrafficRecord($key, $item);
            if (!$record) {
                continue;
            }

            [$uid, $upload, $download] = $record;
            if (!isset($data[$uid])) {
                $data[$uid] = [0, 0];
            }

            $data[$uid][0] += $upload;
            $data[$uid][1] += $download;
        }

        return array_filter($data, fn(array $item): bool => ($item[0] + $item[1]) > 0);
    }

    private static function normalizeTrafficRecord(int|string $key, mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $uid = self::numericTrafficValue($item, ['user_id', 'uid', 'userId', 'id']);
        $upload = self::numericTrafficValue($item, ['u', 'up', 'upload', 'uploaded', 'upload_bytes', 'uplink', 'uploadTraffic']);
        $download = self::numericTrafficValue($item, ['d', 'down', 'download', 'downloaded', 'download_bytes', 'downlink', 'downloadTraffic']);

        if ($uid === null && is_numeric($key)) {
            $uid = (int) $key;
        }

        if ($uid !== null && $upload !== null && $download !== null) {
            return self::validTrafficRecord($uid, $upload, $download);
        }

        $values = array_values($item);
        if (count($values) >= 3 && is_numeric($values[0]) && is_numeric($values[1]) && is_numeric($values[2])) {
            return self::validTrafficRecord((int) $values[0], (int) $values[1], (int) $values[2]);
        }

        if ($uid !== null && count($values) >= 2 && is_numeric($values[0]) && is_numeric($values[1])) {
            return self::validTrafficRecord($uid, (int) $values[0], (int) $values[1]);
        }

        return null;
    }

    private static function numericTrafficValue(array $item, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && is_numeric($item[$key])) {
                return (int) $item[$key];
            }
        }

        return null;
    }

    private static function validTrafficRecord(int $uid, int $upload, int $download): ?array
    {
        if ($uid <= 0 || $upload < 0 || $download < 0 || ($upload + $download) <= 0) {
            return null;
        }

        return [$uid, $upload, $download];
    }

    /**
     * 处理节点在线设备汇报
     */
    public static function processAlive(int $nodeId, array $alive): void
    {
        $service = app(DeviceStateService::class);
        foreach ($alive as $uid => $ips) {
            $service->setDevices((int) $uid, $nodeId, (array) $ips);
        }
    }

    /**
     * 处理节点连接数汇报
     */
    public static function processOnline(Server $node, array $online): void
    {
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        $nodeType = $node->type;
        $nodeId = $node->id;

        foreach ($online as $uid => $conn) {
            $cacheKey = CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}", $uid);
            Cache::put($cacheKey, (int) $conn, $cacheTime);
        }
    }

    /**
     * 处理节点负载状态汇报
     */
    public static function processStatus(Server $node, array $status): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        $statusData = [
            'cpu' => (float) ($status['cpu'] ?? 0),
            'mem' => [
                'total' => (int) ($status['mem']['total'] ?? 0),
                'used' => (int) ($status['mem']['used'] ?? 0),
            ],
            'swap' => [
                'total' => (int) ($status['swap']['total'] ?? 0),
                'used' => (int) ($status['swap']['used'] ?? 0),
            ],
            'disk' => [
                'total' => (int) ($status['disk']['total'] ?? 0),
                'used' => (int) ($status['disk']['used'] ?? 0),
            ],
            'updated_at' => now()->timestamp,
            'kernel_status' => $status['kernel_status'] ?? null,
        ];

        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        cache([
            CacheKey::get("SERVER_{$nodeType}_LOAD_STATUS", $nodeId) => $statusData,
            CacheKey::get("SERVER_{$nodeType}_LAST_LOAD_AT", $nodeId) => now()->timestamp,
        ], $cacheTime);
    }

    /**
     * 标记节点心跳
     */
    public static function touchNode(Server $node): void
    {
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id),
            time(),
            3600
        );
    }

    /**
     * Update node metrics and load status
     */
    public static function updateMetrics(Server $node, array $metrics): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);

        $metricsData = [
            'uptime' => (int) ($metrics['uptime'] ?? 0),
            'goroutines' => (int) ($metrics['goroutines'] ?? 0),
            'active_connections' => (int) ($metrics['active_connections'] ?? 0),
            'total_connections' => (int) ($metrics['total_connections'] ?? 0),
            'total_users' => (int) ($metrics['total_users'] ?? 0),
            'active_users' => (int) ($metrics['active_users'] ?? 0),
            'inbound_speed' => (int) ($metrics['inbound_speed'] ?? 0),
            'outbound_speed' => (int) ($metrics['outbound_speed'] ?? 0),
            'cpu_per_core' => $metrics['cpu_per_core'] ?? [],
            'load' => $metrics['load'] ?? [],
            'speed_limiter' => $metrics['speed_limiter'] ?? [],
            'gc' => $metrics['gc'] ?? [],
            'api' => $metrics['api'] ?? [],
            'ws' => $metrics['ws'] ?? [],
            'limits' => $metrics['limits'] ?? [],
            'updated_at' => now()->timestamp,
            'kernel_status' => (bool) ($metrics['kernel_status'] ?? false),
        ];

        Cache::put(
            CacheKey::get('SERVER_' . $nodeType . '_METRICS', $nodeId),
            $metricsData,
            $cacheTime
        );
    }

    public static function buildNodeConfig(Server $node): array
    {
        $nodeType = $node->type;
        $protocolSettings = $node->protocol_settings;
        $serverPort = $node->server_port;
        $host = $node->host;

        $baseConfig = [
            'protocol' => $nodeType,
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $serverPort,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
        ];

        $response = match ($nodeType) {
            'shadowsocks' => [
                ...$baseConfig,
                'cipher' => $protocolSettings['cipher'],
                'plugin' => $protocolSettings['plugin'],
                'plugin_opts' => $protocolSettings['plugin_opts'],
                'server_key' => match ($protocolSettings['cipher']) {
                        '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                        '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                        default => null,
                    },
            ],
            'vmess' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'trojan' => [
                ...$baseConfig,
                'host' => $host,
                'server_name' => data_get($protocolSettings, 'tls_settings.server_name'),
                'multiplex' => data_get($protocolSettings, 'multiplex'),
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
            ],
            'vless' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'flow' => $protocolSettings['flow'],
                'decryption' => match (data_get($protocolSettings, 'encryption.enabled')) {
                    true => data_get($protocolSettings, 'encryption.decryption'),
                    default => null,
                },
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'hysteria' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'version' => (int) $protocolSettings['version'],
                'host' => $host,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'up_mbps' => (int) $protocolSettings['bandwidth']['up'],
                'down_mbps' => (int) $protocolSettings['bandwidth']['down'],
                ...match ((int) $protocolSettings['version']) {
                        1 => ['obfs' => $protocolSettings['obfs']['password'] ?? null],
                        2 => [
                            'obfs' => $protocolSettings['obfs']['open'] ? $protocolSettings['obfs']['type'] : null,
                            'obfs-password' => $protocolSettings['obfs']['password'] ?? null,
                        ],
                        default => [],
                    },
            ],
            'tuic' => [
                ...$baseConfig,
                'version' => (int) $protocolSettings['version'],
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'congestion_control' => $protocolSettings['congestion_control'],
                'tls_settings' => $protocolSettings['tls'],
                'auth_timeout' => '3s',
                'zero_rtt_handshake' => false,
                'heartbeat' => '3s',
            ],
            'anytls' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'padding_scheme' => $protocolSettings['padding_scheme'],
            ],
            'socks' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings'),
            ],
            'naive' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'http' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'mieru' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'transport' => data_get($protocolSettings, 'transport', 'TCP'),
                'traffic_pattern' => $protocolSettings['traffic_pattern'],
            ],
            default => [],
        };

        if (!empty($node['route_ids'])) {
            $response['routes'] = self::getRoutes($node['route_ids']);
        }

        if (!empty($node['custom_outbounds'])) {
            $response['custom_outbounds'] = $node['custom_outbounds'];
        }

        if (!empty($node['custom_routes'])) {
            $response['custom_routes'] = $node['custom_routes'];
        }

        if (!empty($node['cert_config'])) {
            $certConfig = $node['cert_config'];
            // Normalize: accept both "mode" and "cert_mode" from the database
            if (isset($certConfig['mode']) && !isset($certConfig['cert_mode'])) {
                $certConfig['cert_mode'] = $certConfig['mode'];
                unset($certConfig['mode']);
            }
            if (data_get($certConfig, 'cert_mode') !== 'none') {
                $response['cert_config'] = $certConfig;
            }
        }

        return $response;
    }

    /**
     * 根据协议类型和标识获取服务器
     * @param int $serverId
     * @param string $serverType
     * @return Server|null
     */
    public static function getServer($serverId, ?string $serverType = null): Server | null
    {
        return Server::query()
            ->when($serverType, function ($query) use ($serverType) {
                $query->where('type', Server::normalizeType($serverType));
            })
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();
    }
}
