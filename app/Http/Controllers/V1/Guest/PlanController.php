<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\PlanService;
use Auth;
use Illuminate\Http\Request;

class PlanController extends Controller
{

    protected $planService;
    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }
    /**
     * 获取所有的前台展示套餐
     * 
     * 返回所有可供游客/新注册用户购买的订阅计划详情列表。该接口在游客页面下即可访问，无需登录。
     * 
     * @responseField data array 套餐数据列表
     */
    public function fetch(Request $request)
    {
        $plan = $this->planService->getAvailablePlans();
        return $this->success(PlanResource::collection($plan));
    }
}
