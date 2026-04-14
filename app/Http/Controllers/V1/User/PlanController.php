<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    protected PlanService $planService;

    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }
    /**
     * 获取订阅计划列表/详情
     * 
     * 用于用户侧拉取当前站点的所有可售订阅套餐，或者查看某个具体套餐的详情。
     * 用户权限不足时无法查看到隐藏套餐。
     * 
     * @queryParam id int 可选，传入具体订阅计划ID获取详细信息 Example: 1
     * @responseField data.id int 订阅计划ID
     * @responseField data.name string 套餐名称
     * @responseField data.content string 套餐详情介绍
     * @responseField data.month_price numeric 月付价格
     */
    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        if ($request->input('id')) {
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            if (!$this->planService->isPlanAvailableForUser($plan, $user)) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
            return $this->success(PlanResource::make($plan));
        }

        $plans = $this->planService->getAvailablePlans();
        return $this->success(PlanResource::collection($plans));
    }
}
