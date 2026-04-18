<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * 获取支持的支付插件列表
     * 
     * 读取系统内安装的所有扩展支付插件方法集（微信、支付宝、加密货币等）。
     * 
     * @responseField data array 支付插件驱动标识名数组
     */
    public function getPaymentMethods()
    {
        $methods = [];

        $pluginMethods = PaymentService::getAllPaymentMethodNames();
        $methods = array_merge($methods, $pluginMethods);

        return $this->success(array_unique($methods));
    }

    /**
     * 获取管理端支付网关列表
     * 
     * 获取全部已配置好的支付渠道网关，并附加了接收回调通知所用的回调URL信息。
     */
    public function fetch()
    {
        $payments = Payment::orderBy('sort', 'ASC')->get();
        foreach ($payments as $k => $v) {
            $notifyUrl = url("/api/v1/guest/payment/notify/{$v->payment}/{$v->uuid}");
            if ($v->notify_domain) {
                $parseUrl = parse_url($notifyUrl);
                $notifyUrl = $v->notify_domain . $parseUrl['path'];
            }
            $payments[$k]['notify_url'] = $notifyUrl;
        }
        return $this->success($payments);
    }

    /**
     * 获取支付网关表单配置
     * 
     * 针对选择的某个具体支付插件获取其对应的动态表单结构（便于渲染表单让用户填入如商户ID、密钥等）。
     * 
     * @bodyParam payment string required 支付插件驱动标识名
     * @bodyParam id int 现有的支付记录ID（如果传入则附带已有配置值）
     */
    public function getPaymentForm(Request $request)
    {
        try {
            $paymentService = new PaymentService($request->input('payment'), $request->input('id'));
            return $this->success(collect($paymentService->form()));
        } catch (\Exception $e) {
            return $this->fail([400, '支付方式不存在或未启用']);
        }
    }

    /**
     * 切换支付网关开启状态
     * 
     * 使用对应支付网关 ID 开启或关闭这个网关的前台可用度。
     * 
     * @bodyParam id int required 需要开启/关闭的支付网关ID
     */
    public function show(Request $request)
    {
        $payment = Payment::find($request->input('id'));
        if (!$payment)
            return $this->fail([400202, '支付方式不存在']);
        $payment->enable = !$payment->enable;
        if (!$payment->save())
            return $this->fail([500, '保存失败']);
        return $this->success(true);
    }

    /**
     * 保存/编辑支付配置
     * 
     * 从空白创建一个新的支付通道，或对已有通道参数（支持不同插件的不同表单配置格式）做二次变更。
     * 
     * @bodyParam id int 待修改网关ID (新建时不传)
     * @bodyParam name string required 用户前台显示名称
     * @bodyParam payment string required 驱动类标识名
     * @bodyParam config string required 相关密钥配置的序列化内容
     * @bodyParam handling_fee_fixed int 额外固定手续费
     */
    public function save(Request $request)
    {
        if (!admin_setting('app_url')) {
            return $this->fail([400, '请在站点配置中配置站点地址']);
        }
        $params = $request->validate([
            'name' => 'required',
            'icon' => 'nullable',
            'payment' => 'required',
            'config' => 'required',
            'notify_domain' => 'nullable|url',
            'handling_fee_fixed' => 'nullable|integer',
            'handling_fee_percent' => 'nullable|numeric|between:0,100'
        ], [
            'name.required' => '显示名称不能为空',
            'payment.required' => '网关参数不能为空',
            'config.required' => '配置参数不能为空',
            'notify_domain.url' => '自定义通知域名格式有误',
            'handling_fee_fixed.integer' => '固定手续费格式有误',
            'handling_fee_percent.between' => '百分比手续费范围须在0-100之间'
        ]);
        if ($request->input('id')) {
            $payment = Payment::find($request->input('id'));
            if (!$payment)
                return $this->fail([400202, '支付方式不存在']);
            try {
                $payment->update($params);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
            return $this->success(true);
        }
        $params['uuid'] = Helper::randomChar(8);
        if (!Payment::create($params)) {
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    /**
     * 删除支付通道
     * 
     * 彻底从后台丢弃一个无用的支付网关配置。
     * 
     * @bodyParam id int required 待删除的支付网关ID
     */
    public function drop(Request $request)
    {
        $payment = Payment::find($request->input('id'));
        if (!$payment)
            return $this->fail([400202, '支付方式不存在']);
        return $this->success($payment->delete());
    }


    /**
     * 支付通道排序
     * 
     * @bodyParam ids array required 重新排列的由前至后网关 ID 列表数组
     */
    public function sort(Request $request)
    {
        $request->validate([
            'ids' => 'required|array'
        ], [
            'ids.required' => '参数有误',
            'ids.array' => '参数有误'
        ]);
        try {
            DB::beginTransaction();
            foreach ($request->input('ids') as $k => $v) {
                if (!Payment::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }
}
