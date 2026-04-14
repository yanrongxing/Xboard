<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * 获取订单列表
     * 
     * 获取当前用户的订单列表，可以按照订单状态筛选。
     * 
     * @queryParam status int 订单状态筛选(0:待支付 1:已支付 2:已取消 3:已完成) Example: 1
     * @responseField data array 订单数组
     */
    public function fetch(Request $request)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2,3',
        ]);
        $orders = Order::with('plan')
            ->where('user_id', $request->user()->id)
            ->when($request->input('status') !== null, function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->success(OrderResource::collection($orders));
    }

    /**
     * 获取订单详情
     * 
     * 根据订单号（trade_no）获取特定订单的详细信息，包括支付方式和订阅计划。
     * 
     * @queryParam trade_no string required 订单流水号 Example: 2023010112345678
     * @responseField data.trade_no string 订单号
     * @responseField data.total_amount numeric 订单总金额
     * @responseField data.status int 订单状态
     */
    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        $order = Order::with(['payment', 'plan'])
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        $order['try_out_plan_id'] = (int) admin_setting('try_out_plan_id');
        if (!$order->plan) {
            return $this->fail([400, __('Subscription plan does not exist')]);
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return $this->success(OrderResource::make($order));
    }

    /**
     * 创建订单
     * 
     * 创建一个新的购买或续费订阅计划的订单。
     * 
     * @bodyParam plan_id int required 订阅计划的ID Example: 1
     * @bodyParam period string required 购买周期（如 month, quarter, half_year, year） Example: month
     * @bodyParam coupon_code string 优惠券码（可选） 
     * @responseField data string 创建成功的订单号(trade_no)
     */
    public function save(OrderSave $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:App\Models\Plan,id',
            'period' => 'required|string'
        ]);

        $user = User::findOrFail($request->user()->id);
        $userService = app(UserService::class);

        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $plan = Plan::findOrFail($request->input('plan_id'));
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $request->input('period'));

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            $request->input('period'),
            $request->input('coupon_code')
        );

        return $this->success($order->trade_no);
    }

    protected function applyCoupon(Order $order, string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $order->coupon_id = $couponService->getId();
    }

    protected function handleUserBalance(Order $order, User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $order->total_amount;

        if ($remainingBalance > 0) {
            if (!$userService->addBalance($order->user_id, -$order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $order->total_amount;
            $order->total_amount = 0;
        } else {
            if (!$userService->addBalance($order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $user->balance;
            $order->total_amount = $order->total_amount - $user->balance;
        }
    }

    /**
     * 订单支付(Checkout)
     * 
     * 将创建好的订单发起支付，选择支付网关提交。
     * 
     * @bodyParam trade_no string required 需要支付的订单号 Example: 2023010112345678
     * @bodyParam method int required 支付方式ID（通过获取支付方式接口获取） Example: 1
     * @bodyParam token string Stripe支付Token等相关参数（可选）
     * @responseField type int 返回动作类型(-1:无需跳转 0:URL跳转 1:HTML代码渲染)
     * @responseField data string 支付网关返回的内容(如URL或HTML)
     */
    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->where('status', 0)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no))
                return $this->fail([400, '支付失败']);
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || !$payment->enable) {
            return $this->fail([400, __('Payment method is not available')]);
        }
        $paymentService = new PaymentService($payment->payment, $payment->id);
        $order->handling_amount = NULL;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = (int) round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $method;
        if (!$order->save())
            return $this->fail([400, __('Request failed, please try again later')]);
        $result = $paymentService->pay([
            'trade_no' => $tradeNo,
            'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token')
        ]);
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    /**
     * 检查订单状态
     * 
     * 轮询查询某个订单当前的支付或处理状态。
     * 
     * @queryParam trade_no string required 订单流水号 Example: 2023010112345678
     * @responseField data int 订单当前状态(0:待支付 1:开通中 2:已取消 3:已完成)
     */
    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        return $this->success($order->status);
    }

    /**
     * 获取可用支付方式
     * 
     * 获取系统当前开启并允许用户使用的所有支付网关及手续费等信息。
     * 
     * @responseField data.id int 支付方式ID
     * @responseField data.name string 支付方式名称
     * @responseField data.icon string 支付方式图标
     */
    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        return $this->success($methods);
    }

    /**
     * 取消订单
     * 
     * 放弃并取消一个未支付的订单。
     * 
     * @bodyParam trade_no string required 需要取消的订单号 Example: 2023010112345678
     * @responseField data bool 成功为 true
     */
    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        if ($order->status !== 0) {
            return $this->fail([400, __('You can only cancel pending orders')]);
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, __('Cancel failed')]);
        }
        return $this->success(true);
    }
}
