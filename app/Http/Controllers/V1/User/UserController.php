<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    protected $loginService;

    public function __construct(
        LoginService $loginService
    ) {
        $this->loginService = $loginService;
    }

    /**
     * 获取活跃会话列表
     * 
     * 获取当前用户所有的登录会话(Token)列表。
     * 
     * @responseField data array 会话列表
     */
    public function getActiveSession(Request $request)
    {
        $user = $request->user();
        $tokens = $user->tokens()->orderBy('created_at', 'asc')->get();
        $currentTokenId = $user->currentAccessToken()?->id;
        $deviceLimit = max(1, (int)$user->device_limit);
        
        $appRank = 1;
        $result = [];

        foreach ($tokens as $token) {
            $isApp = (bool)$token->is_app;
            $isActive = true;
            
            if ($isApp) {
                if ($appRank > $deviceLimit) {
                    $isActive = false;
                }
                $appRank++;
            }

            $result[] = [
                'id' => $token->id,
                'is_app' => $isApp,
                'device_id' => $token->device_id,
                'device_name' => $token->name, // Sanctum stores it in name
                'device_type' => $token->device_type,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
                'is_current' => $token->id == $currentTokenId,
                'is_active' => $isActive, // true means allowed, false means over limit
            ];
        }

        return $this->success($result);
    }

    /**
     * 移除指定的登录会话
     * 
     * 踢掉指定的登录Session，使其下线。
     * 
     * @bodyParam session_id string required 需要移除的会话ID Example: 1
     * @responseField data bool 成功为 true
     */
    public function removeActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->removeSession($request->input('session_id')));
    }

    /**
     * 检查当前登录状态
     * 
     * 获取当前Token是否有效以及当前登录的角色(是否是管理员)。
     * 
     * @responseField data.is_login bool 是否已登录
     * @responseField data.is_admin bool 是否为管理员
     */
    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user()?->id ? true : false
        ];
        if ($request->user()?->is_admin) {
            $data['is_admin'] = true;
        }
        return $this->success($data);
    }

    /**
     * 修改用户密码
     * 
     * 验证原有密码后，修改用户登录密码，并在修改成功后移除其他设备Token。
     * 
     * @bodyParam old_password string required 原密码 Example: oldpassword123
     * @bodyParam new_password string required 新密码 Example: newpassword123
     */
    public function changePassword(UserChangePassword $request)
    {
        $user = $request->user();
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $request->input('old_password'),
                $user->password
            )
        ) {
            return $this->fail([400, __('The old password is wrong')]);
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            return $this->fail([400, __('Save failed')]);
        }
        
        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        } else {
            $user->tokens()->delete();
        }
        
        return $this->success(true);
    }

    /**
     * 获取用户信息
     * 
     * 拉取当前登录用户的基础账户资料（包含钱包余额、注册时间、是否封禁等）。
     * 
     * @responseField data.email string 电子邮箱
     * @responseField data.balance numeric 账户余额
     * @responseField data.commission_balance numeric 推广佣金余额
     */
    public function info(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'email',
                'transfer_enable',
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        
        // Add can_connect_vpn flag
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $user['can_connect_vpn'] = $currentTokenId ? \App\Services\AuthService::canConnectVpn($request->user(), $currentTokenId) : true;

        return $this->success($user);
    }

    /**
     * 获取个人统计指标
     * 
     * 统计当前用户的未支付订单、待处理工单数量，以及邀请下级人数。
     * 
     * @responseField data array 统计数据数组 [未支付订单数, 待处理工单数, 邀请人数]
     */
    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user()->id)
                ->count(),
            User::where('invite_user_id', $request->user()->id)
                ->count()
        ];
        return $this->success($stat);
    }

    /**
     * 获取订阅信息与流量详情
     * 
     * 核心接口之一。这会返回用户当前拥有的套餐配置、订阅链接、已用上下行流量、剩余到期天数等数据。
     * 
     * @responseField data.transfer_enable numeric 套餐总允许流量(Bytes)
     * @responseField data.u numeric 已用上行流量(Bytes)
     * @responseField data.d numeric 已用下行流量(Bytes)
     * @responseField data.subscribe_url string 专属节点订阅地址
     */
    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid',
                'device_limit',
                'speed_limit',
                'next_reset_at'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
        }
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        
        // Add can_connect_vpn flag
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $user['can_connect_vpn'] = $currentTokenId ? \App\Services\AuthService::canConnectVpn($request->user(), $currentTokenId) : true;

        if (!$user['can_connect_vpn']) {
            $user['subscribe_url'] = '';
        }

        $user = HookManager::filter('user.subscribe.response', $user);
        return $this->success($user);
    }

    /**
     * 重置订阅链接与安全凭证
     * 
     * 为当前用户刷新生成全新的订阅链接Token。原有的所有订阅链接与连接密码将立即作废。
     * 
     * @responseField data string 新的专属订阅地址
     */
    public function resetSecurity(Request $request)
    {
        $user = $request->user();
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            return $this->fail([400, __('Reset failed')]);
        }
        return $this->success(Helper::getSubscribeUrl($user->token));
    }

    /**
     * 修改通知偏好设置
     * 
     * 修改用户关于流量耗尽或套餐过期时的邮件提醒开关。
     * 
     * @bodyParam remind_expire bool 开启订阅到期邮件提醒 Example: true
     * @bodyParam remind_traffic bool 开启流量告警邮件提醒 Example: false
     */
    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic'
        ]);

        $user = $request->user();
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            return $this->fail([400, __('Save failed')]);
        }

        return $this->success(true);
    }

    /**
     * 佣金划转余额
     * 
     * 将账号内现存的推广佣金余额转移划拨到账户余额中，用于后续购买包月套餐。
     * 
     * @bodyParam transfer_amount numeric required 划转金额 Example: 100
     */
    public function transfer(UserTransfer $request)
    {
        $amount = $request->input('transfer_amount');
        try {
            DB::transaction(function () use ($request, $amount) {
                $user = User::lockForUpdate()->find($request->user()->id);
                if (!$user) {
                    throw new \Exception(__('The user does not exist'));
                }
                if ($amount > $user->commission_balance) {
                    throw new \Exception(__('Insufficient commission balance'));
                }
                $user->commission_balance -= $amount;
                $user->balance += $amount;
                if (!$user->save()) {
                    throw new \Exception(__('Transfer failed'));
                }
            });
        } catch (\Exception $e) {
            return $this->fail([400, $e->getMessage()]);
        }
        return $this->success(true);
    }

    /**
     * 获取免密快速登录URL (快速登录)
     * 
     * 在客户端或特定场景下，通过已有认证Token，换取一个可以直接在浏览器授权登录系统的临时安全URL请求地址。
     * 
     * @queryParam redirect string 期望登录成功后跳转的前端相对路径 Example: /dashboard
     * @responseField data string 直接免密跳转登录后台的完整 URL
     */
    public function getQuickLoginUrl(Request $request)
    {
        $user = $request->user();

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }
}
