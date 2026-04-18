<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GroupController extends Controller
{
    /**
     * 获取所有服节点分组名称类别
     * 
     * 提取配置出给系统节点标记的分组情况数组（比如按地区、按使用等级划分组别名集合）。
     */
    public function fetch(Request $request): JsonResponse
    {
        $serverGroups = ServerGroup::query()
            ->orderByDesc('id')
            ->withCount('users')
            ->get();

        // 只在需要时手动加载server_count
        $serverGroups->each(function ($group) {
            $group->setAttribute('server_count', $group->server_count);
        });

        return $this->success($serverGroups);
    }

    /**
     * 建立保存配置新建分组
     * 
     * 记录写下一个全新的服分组类别记录。
     * 
     * @bodyParam id int 如果带传入则是修改旧有的分组名称而并非新建
     * @bodyParam name string required 被分配的识别名称（例: [VIP3] 东南亚原生服）
     */
    public function save(Request $request)
    {
        if (empty($request->input('name'))) {
            return $this->fail([422, '组名不能为空']);
        }

        if ($request->input('id')) {
            $serverGroup = ServerGroup::find($request->input('id'));
        } else {
            $serverGroup = new ServerGroup();
        }

        $serverGroup->name = $request->input('name');
        return $this->success($serverGroup->save());
    }

    /**
     * 从系统移除删除废弃服务器分组
     * 
     * 检查验证如果当前这个需要删除的组中还正在关联任意物理机节点、用户，则不允许操作。
     * 
     * @bodyParam id int required 需要物理抹除的机器类别分组记录 ID 号
     */
    public function drop(Request $request)
    {
        $groupId = $request->input('id');

        $serverGroup = ServerGroup::find($groupId);
        if (!$serverGroup) {
            return $this->fail([400202, '组不存在']);
        }
        if (Server::whereJsonContains('group_ids', $groupId)->exists()) {
            return $this->fail([400, '该组已被节点所使用，无法删除']);
        }

        if (Plan::where('group_id', $groupId)->exists()) {
            return $this->fail([400, '该组已被订阅所使用，无法删除']);
        }
        if (User::where('group_id', $groupId)->exists()) {
            return $this->fail([400, '该组已被用户所使用，无法删除']);
        }
        return $this->success($serverGroup->delete());
    }
}
