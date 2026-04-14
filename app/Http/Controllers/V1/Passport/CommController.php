<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\CaptchaService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommController extends Controller
{

    /**
     * 发送邮箱验证码
     * 
     * 用于在用户注册或找回密码时，向指定邮箱发送含有6位验证码的邮件。
     * 
     * @bodyParam email string required 需要接收验证码的电子邮箱 Example: demo@test.com
     * @bodyParam turnstile string Turnstile/recaptcha等极验证码的Token（若系统开启人机验证则必填）
     */
    public function sendEmailVerify(CommSendEmailVerify $request)
    {
                // 验证人机验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return $this->fail($captchaError);
        }

        $email = $request->input('email');

        // 检查白名单后缀限制
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            $isRegisteredEmail = User::byEmail($email)->exists();
            if (!$isRegisteredEmail) {
                $allowedSuffixes = Helper::getEmailSuffix();
                $emailSuffix = substr(strrchr($email, '@'), 1);

                if (!in_array($emailSuffix, $allowedSuffixes)) {
                    return $this->fail([400, __('Email suffix is not in whitelist')]);
                }
            }
        }

        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            return $this->fail([400, __('Email verification code has been sent, please request again later')]);
        }
        $code = rand(100000, 999999);
        $subject = admin_setting('app_name', 'XBoard') . __('Email verification code');

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'code' => $code,
                'url' => admin_setting('app_url')
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email), time(), 60);
        return $this->success(true);
    }

    /**
     * 记录邀请链接访问次数 (PV)
     * 
     * 用户访问他人的邀请链接时，后台静默记录1次PV浏览量。
     * 
     * @bodyParam invite_code string required 推广邀请码 Example: abc12345
     */
    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return $this->success(true);
    }

}
