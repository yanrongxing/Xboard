<?php

namespace App\Docs\Scribe;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

class AssignGroup extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $uri = $endpointData->uri;
        
        $groupName = 'Other API';
        $subgroupName = '';

        // Admin API
        if (strpos($uri, 'api/v2/57a7189f') !== false) {
            $groupName = '管理后台 API (Admin)';
            $parts = explode('/', $uri);
            // $parts will be ['api', 'v2', '57a7189f', 'something']
            if (isset($parts[4])) {
                $subgroupName = ucfirst($parts[4]) . ' 模块';
            }
        } 
        // User/Client/Guest API
        elseif (strpos($uri, 'api/v1/user') !== false || strpos($uri, 'api/v2/user') !== false ||
                strpos($uri, 'api/v1/client') !== false || strpos($uri, 'api/v2/client') !== false ||
                strpos($uri, 'api/v1/guest') !== false || strpos($uri, 'api/v1/passport') !== false || strpos($uri, 'api/v2/passport') !== false) {
            $groupName = '用户端 API (User & Guest)';
            $parts = explode('/', $uri);
            if (count($parts) === 4) {
                // e.g. api/v1/user/info -> User 模块
                $subgroupName = ucfirst($parts[2]) . ' 模块';
            } elseif (count($parts) >= 5) {
                // e.g. api/v1/user/order/fetch -> Order 模块
                $subgroupName = ucfirst($parts[3]) . ' 模块';
            }
        }
        // Server API
        elseif (strpos($uri, 'api/v1/server') !== false || strpos($uri, 'api/v2/server') !== false) {
            $groupName = '服务端端点 API (Server Nodes)';
            $parts = explode('/', $uri);
            if (isset($parts[3])) {
                $subgroupName = ucfirst($parts[3]) . ' 模块';
            }
        }

        if ($subgroupName === '') {
            $subgroupName = 'Common';
        }

        return [
            'groupName' => $groupName,
            'groupDescription' => '',
            'subgroup' => $subgroupName,
            'subgroupDescription' => '',
        ];
    }
}
