<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class AppController extends Controller
{
    /**
     * 获取客户端配置(Clash)
     * 
     * 该接口主要用于定制化客户端获取Clash规格的YAML格式配置。
     * 根据当前登录用户是否具备有效订阅，返回包含相应节点代理信息的配置文件。
     * 
     * @responseField proxies array 代理节点列表
     * @responseField proxy-groups array 选路组配置
     * @response 200 plain/text
     */
    public function getConfig(Request $request)
    {
        $servers = [];
        $user = $request->user();
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
        }
        $defaultConfig = base_path() . '/resources/rules/app.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.app.clash.yaml';
        if (File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }
        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            $protocol_settings = $item['protocol_settings'];
            if ($item['type'] === 'shadowsocks'
                && in_array(data_get($protocol_settings, 'cipher'), [
                    'aes-128-gcm',
                    'aes-192-gcm',
                    'aes-256-gcm',
                    'chacha20-ietf-poly1305'
                ])
            ) {
                array_push($proxy, \App\Protocols\Clash::buildShadowsocks($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'vmess') {
                array_push($proxy, \App\Protocols\Clash::buildVmess($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'trojan') {
                array_push($proxy, \App\Protocols\Clash::buildTrojan($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        return(Yaml::dump($config));
    }

    /**
     * 获取客户端最新版本
     * 
     * 检测各个平台 (Windows, macOS, Android) 配置中的最新可用版本及下载链接，用于引导客户下载升级。
     * 
     * @responseField data.windows_version string Windows客户端版本号
     * @responseField data.windows_download_url string Windows客户端下载直链
     * @responseField data.macos_version string macOS客户端版本号
     * @responseField data.macos_download_url string macOS客户端下载直链
     * @responseField data.android_version string Android客户端版本号
     * @responseField data.android_download_url string Android客户端下载直链
     */
    public function getVersion(Request $request)
    {
        if (strpos($request->header('user-agent'), 'tidalab/4.0.0') !== false
            || strpos($request->header('user-agent'), 'tunnelab/4.0.0') !== false
        ) {
            if (strpos($request->header('user-agent'), 'Win64') !== false) {
                $data = [
                        'version' => admin_setting('windows_version'),
                        'download_url' => admin_setting('windows_download_url')
                ];
            } else {
                $data = [
                        'version' => admin_setting('macos_version'),
                        'download_url' => admin_setting('macos_download_url')
                ];
            }
        }else{
            $data = [
                'windows_version' => admin_setting('windows_version'),
                'windows_download_url' => admin_setting('windows_download_url'),
                'macos_version' => admin_setting('macos_version'),
                'macos_download_url' => admin_setting('macos_download_url'),
                'android_version' => admin_setting('android_version'),
                'android_download_url' => admin_setting('android_download_url')
            ];
        }
        return $this->success($data);
    }
}
