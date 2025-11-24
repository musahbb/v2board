<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            // append fake nodes into subscribe output if enabled and user is unpaid/expired
            $this->appendFakeNodes($servers, $user);
            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    /**
     * Append fake nodes to the servers list when enabled in config.
     * This only modifies the output used for subscription; it does not alter DB.
     *
     * Config options:
     *  - v2board.fake_nodes_enable (0/1)
     *  - v2board.fake_nodes_count (int)
     */
    private function appendFakeNodes(&$servers, $user)
    {
        if (!config('v2board.fake_nodes_enable', 0)) return;
        // only show fake nodes to users without a plan or with expired plan
        $isUnpaidOrExpired = ($user->plan_id === NULL) || ($user->expired_at !== NULL && $user->expired_at < time());
        if (!$isUnpaidOrExpired) return;
        $count = (int)config('v2board.fake_nodes_count', 3);
        if ($count <= 0) return;
        if (!isset($servers[0])) return;

        $template = $servers[0];
        for ($i = 0; $i < $count; $i++) {
            $fake = $template;
            // mark as fake and avoid clashing ids
            $fake['id'] = 0;
            // give it a distinguishable name
            // set fake node display name as requested
            $fake['name'] = "网址 V4pn.com";
            // random plausible host and port
            $fake['host'] = rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254) . '.' . rand(1, 254);
            $fake['port'] = rand(1025, 64000);
            // mark offline
            $fake['last_check_at'] = 0;
            $fake['is_online'] = 0;
            // remove sensitive tls private stuff if present
            if (isset($fake['tls_settings']) && isset($fake['tls_settings']['private_key'])) {
                $fake['tls_settings'] = array_diff_key($fake['tls_settings'], array('private_key' => ''));
            }
            // ensure fields used by buildUri exist
            if (!isset($fake['network'])) $fake['network'] = $template['network'] ?? 'tcp';
            if (!isset($fake['tls'])) $fake['tls'] = $template['tls'] ?? 0;

            $servers[] = $fake;
        }
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
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
}
