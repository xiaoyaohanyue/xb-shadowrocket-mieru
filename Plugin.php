<?php

namespace Plugin\ShadowrocketMieru;

use App\Models\Server;
use App\Protocols\Shadowrocket;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    private const INFO_NODE_PORTS = [
        'remaining_traffic' => 65535,
        'reset_day' => 65534,
        'expired_date' => 65533,
        'filtered_count' => 65532,
    ];

    private const SHADOWROCKET_TYPES = [
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_VMESS,
        Server::TYPE_VLESS,
        Server::TYPE_TROJAN,
        Server::TYPE_HYSTERIA,
        Server::TYPE_TUIC,
        Server::TYPE_ANYTLS,
        Server::TYPE_SOCKS,
        Server::TYPE_MIERU,
    ];

    public function boot(): void
    {
        $this->listen('client.subscribe.before', [$this, 'handleSubscribeBefore'], 20);
    }

    public function handleSubscribeBefore(): void
    {
        if (!$this->getConfig('enabled', true)) {
            return;
        }

        if ($this->getConfig('skip_when_core_supported', true) && method_exists(Shadowrocket::class, 'buildMieru')) {
            return;
        }

        $request = request();
        if (!$this->isShadowrocketRequest($request)) {
            return;
        }

        $user = $request->user();
        if (!$user) {
            return;
        }

        if (!(new UserService())->isAvailable($user)) {
            HookManager::call('client.subscribe.unavailable');
            $this->intercept(response('', 403, ['Content-Type' => 'text/plain']));
        }

        $servers = ServerService::getAvailableServers($user);
        $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
        $availableServerCount = count($servers);
        $servers = $this->filterServers($servers, $request);
        $this->addSubscribeInfoToServers(
            $servers,
            $user,
            $availableServerCount - count($servers)
        );

        $mieruServers = array_values(array_filter(
            $servers,
            static fn(array $server): bool => ($server['type'] ?? null) === Server::TYPE_MIERU
        ));

        if (empty($mieruServers)) {
            return;
        }

        $shadowrocketServers = array_values(array_filter(
            $servers,
            static fn(array $server): bool => ($server['type'] ?? null) !== Server::TYPE_MIERU
        ));

        $baseBody = $this->buildShadowrocketBody($user, $shadowrocketServers, $request);
        foreach ($mieruServers as $server) {
            $baseBody .= $this->buildMieru($server['password'], $server);
        }

        if ($this->getConfig('log_hits', false)) {
            Log::info('Shadowrocket Mieru 插件已追加 Mieru 节点', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mieru_count' => count($mieruServers),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        $this->intercept(response(base64_encode($baseBody), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]));
    }

    private function isShadowrocketRequest(Request $request): bool
    {
        $flag = strtolower((string) ($request->input('flag') ?: $request->header('User-Agent', '')));

        return str_contains($flag, 'shadowrocket');
    }

    /**
     * The plugin intercepts the request before ClientController can add these
     * informational nodes, so mirror the controller behavior here.
     */
    private function addSubscribeInfoToServers(array &$servers, $user, int $rejectedServerCount = 0): void
    {
        $template = collect($servers)->first(
            static fn(array $server): bool => ($server['type'] ?? null) !== Server::TYPE_MIERU
        );
        if (!$template) {
            return;
        }

        if ($rejectedServerCount > 0) {
            array_unshift($servers, $this->buildInfoServer(
                $template,
                "过滤掉{$rejectedServerCount}条线路",
                self::INFO_NODE_PORTS['filtered_count']
            ));
        }

        if (!(int) admin_setting('show_info_to_server_enable', 0)) {
            return;
        }

        $usedTraffic = ($user['u'] ?? 0) + ($user['d'] ?? 0);
        $remainingTraffic = Helper::trafficConvert(($user['transfer_enable'] ?? 0) - $usedTraffic);
        $expiredDate = $user['expired_at']
            ? date('Y-m-d', $user['expired_at'])
            : __('长期有效');
        $resetDay = (new UserService())->getResetDay($user);

        array_unshift($servers, $this->buildInfoServer(
            $template,
            "套餐到期：{$expiredDate}",
            self::INFO_NODE_PORTS['expired_date']
        ));

        if ($resetDay) {
            array_unshift($servers, $this->buildInfoServer(
                $template,
                "距离下次重置剩余：{$resetDay} 天",
                self::INFO_NODE_PORTS['reset_day']
            ));
        }

        array_unshift($servers, $this->buildInfoServer(
            $template,
            "剩余流量：{$remainingTraffic}",
            self::INFO_NODE_PORTS['remaining_traffic']
        ));
    }

    private function buildInfoServer(array $template, string $name, int $port): array
    {
        unset($template['ports']);
        $template['name'] = $name;
        $template['port'] = $port;

        return $template;
    }

    private function buildShadowrocketBody($user, array $servers, Request $request): string
    {
        $protocol = app()->make(Shadowrocket::class, [
            'user' => $user,
            'servers' => $servers,
            'clientName' => 'shadowrocket',
            'clientVersion' => $this->detectVersion($request),
            'userAgent' => strtolower((string) ($request->input('flag') ?: $request->header('User-Agent', ''))),
        ]);

        $response = $protocol->handle();
        $content = method_exists($response, 'getContent') ? $response->getContent() : '';

        return base64_decode((string) $content, true) ?: '';
    }

    private function buildMieru(string $password, array $server): string
    {
        $settings = data_get($server, 'protocol_settings', []);
        $profile = $this->resolveProfileName($server);
        $transport = strtoupper((string) data_get($settings, 'transport', 'TCP'));
        if (!in_array($transport, ['TCP', 'UDP'], true)) {
            $transport = 'TCP';
        }

        $queryParts = [
            'profile=' . rawurlencode($profile !== '' ? $profile : 'default'),
            'port=' . rawurlencode((string) ($server['ports'] ?? $server['port'])),
            'protocol=' . rawurlencode($transport),
        ];

        if ($trafficPattern = data_get($settings, 'traffic_pattern')) {
            $queryParts[] = 'traffic-pattern=' . rawurlencode((string) $trafficPattern);
        }

        if ($multiplexing = $this->normalizeMultiplexing($this->getConfig('multiplexing', ''))) {
            $queryParts[] = 'multiplexing=' . rawurlencode($multiplexing);
        }

        $username = rawurlencode($password);
        $encodedPassword = rawurlencode($password);
        $host = Helper::wrapIPv6($server['host']);
        $name = rawurlencode($server['name']);

        return "mierus://{$username}:{$encodedPassword}@{$host}?" . implode('&', $queryParts) . "#{$name}\r\n";
    }

    private function resolveProfileName(array $server): string
    {
        if ($this->getConfig('use_node_name_as_profile', true)) {
            return (string) ($server['name'] ?? 'default');
        }

        $profile = trim((string) $this->getConfig('profile', ''));

        return $profile !== '' ? $profile : (string) ($server['name'] ?? 'default');
    }

    private function normalizeMultiplexing(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return null;
        }

        $map = [
            'OFF' => 'MULTIPLEXING_OFF',
            'LOW' => 'MULTIPLEXING_LOW',
            'MIDDLE' => 'MULTIPLEXING_MIDDLE',
            'HIGH' => 'MULTIPLEXING_HIGH',
            'MULTIPLEXING_OFF' => 'MULTIPLEXING_OFF',
            'MULTIPLEXING_LOW' => 'MULTIPLEXING_LOW',
            'MULTIPLEXING_MIDDLE' => 'MULTIPLEXING_MIDDLE',
            'MULTIPLEXING_HIGH' => 'MULTIPLEXING_HIGH',
        ];

        return $map[$value] ?? null;
    }

    private function detectVersion(Request $request): ?string
    {
        $flag = strtolower((string) ($request->input('flag') ?: $request->header('User-Agent', '')));
        if (preg_match('/shadowrocket[\/\s]+v?(\d+(?:\.\d+){0,2})/i', $flag, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\/v?(\d+(?:\.\d+){0,2})/', $flag, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function filterServers(array $servers, Request $request): array
    {
        $types = $this->parseRequestedTypes($request->input('types'));
        $keywords = $this->parseFilterKeywords($request->input('filter'));

        return collect($servers)
            ->filter(function (array $server) use ($types, $keywords): bool {
                if (!in_array($server['type'] ?? '', self::SHADOWROCKET_TYPES, true)) {
                    return false;
                }

                if ($types && !in_array($server['type'] ?? '', $types, true)) {
                    return false;
                }

                if ($keywords) {
                    return collect($keywords)->contains(function (string $keyword) use ($server): bool {
                        return stripos((string) ($server['name'] ?? ''), $keyword) !== false
                            || in_array($keyword, $server['tags'] ?? [], true);
                    });
                }

                return true;
            })
            ->values()
            ->all();
    }

    private function parseRequestedTypes(?string $value): array
    {
        if (blank($value) || $value === 'all') {
            return self::SHADOWROCKET_TYPES;
        }

        $types = preg_split('/[|,，\s]+/u', $value) ?: [];

        return collect($types)
            ->map(static fn(string $type): string => trim($type))
            ->filter()
            ->intersect(self::SHADOWROCKET_TYPES)
            ->values()
            ->all();
    }

    private function parseFilterKeywords(?string $value): ?array
    {
        if (blank($value) || mb_strlen($value) > 20) {
            return null;
        }

        return collect(preg_split('/[|,，\s]+/u', $value) ?: [])
            ->map(static fn(string $keyword): string => trim($keyword))
            ->filter()
            ->values()
            ->all();
    }
}
