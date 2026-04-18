<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\UpdateService;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    protected $updateService;

    public function __construct(UpdateService $updateService)
    {
        $this->updateService = $updateService;
    }

    /**
     * 检查系统版本更新
     * 
     * 联网探针校验目前平台源代码相对远端 Git 发布页是否已经是最新正式版。
     */
    public function checkUpdate()
    {
        return $this->success($this->updateService->checkForUpdates());
    }

    /**
     * 立即执行在线热更新升级程序
     * 
     * 自动化去拉取差异补丁以及执行内置数据表变更迁移的操作入口。
     */
    public function executeUpdate()
    {
        $result = $this->updateService->executeUpdate();
        return $result['success'] ? $this->success($result) : $this->fail([500, $result['message']]);
    }
}