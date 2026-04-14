<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    /**
     * 获取绑定的机器人信息
     * 
     * 用于前端展示当前系统对接的Telegram机器人的名字和链接，引导用户去绑定。
     * 
     * @responseField data.username string 机器人用户名
     */
    public function getBotInfo()
    {
        $telegramService = new TelegramService();
        $response = $telegramService->getMe();
        $data = [
            'username' => $response->result->username
        ];
        return $this->success($data);
    }

    /**
     * 解绑Telegram账号
     * 
     * 接触当前账户与Telegram账号的关联绑定。
     * 
     * @responseField data bool 成功为 true
     */
    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user()->id)->first();
    }
}
