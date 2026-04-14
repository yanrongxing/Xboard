<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Services\CouponService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
     * 校验/获取优惠券信息
     * 
     * 用于在购物车或订单结算时，查询输入兑换码对应的折扣详情。
     * 
     * @bodyParam code string required 优惠券代码 Example: 88888888
     * @bodyParam plan_id int 拟购买的订阅套餐ID
     * @bodyParam period string 拟购买的时长类型（如 month, year等）
     * @responseField data object 优惠券具体金额/比例信息
     */
    public function check(Request $request)
    {
        if (empty($request->input('code'))) {
            return $this->fail([422, __('Coupon cannot be empty')]);
        }
        $couponService = new CouponService($request->input('code'));
        $couponService->setPlanId($request->input('plan_id'));
        $couponService->setUserId($request->user()->id);
        $couponService->setPeriod($request->input('period'));
        $couponService->check();
        return $this->success(CouponResource::make($couponService->getCoupon()));
    }
}
