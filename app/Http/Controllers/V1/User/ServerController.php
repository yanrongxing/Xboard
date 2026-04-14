<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\NodeResource;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    /**
     * 获取可用服务器节点
     * 
     * 返回当前用户在活跃订阅状态下有权限使用的全部服务端点列表（自动携带 ETag 控制缓存）。
     * 
     * @responseField data array 服务器节点列表
     */
    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
        }
        $eTag = sha1(json_encode(array_column($servers, 'cache_key')));
        if (strpos($request->header('If-None-Match', ''), $eTag) !== false ) {
            return response(null,304);
        }
        $data = NodeResource::collection($servers);
        return response([
            'data' => $data
        ])->header('ETag', "\"{$eTag}\"");
    }
}
