<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    /**
     * 获取工单列表/详情
     * 
     * 如果未传 id，则返回该用户所有的工单列表；如果传入 id，则返回该工单的对话流详情。
     * 
     * @queryParam id int 需要查看详情的特定工单的ID (可选) Example: 1
     * @responseField data array|object 如果没有传ID返回工单组，否则返回工单对象包含具体 message
     */
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->where('user_id', $request->user()->id)
                ->first()
                ->load('message');
            if (!$ticket) {
                return $this->fail([400, __('Ticket does not exist')]);
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            $ticket['message']->each(function ($message) use ($ticket) {
                $message['is_me'] = ($message['user_id'] == $ticket->user_id);
            });
            return $this->success(TicketResource::make($ticket)->additional(['message' => true]));
        }
        $ticket = Ticket::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();
        return $this->success(TicketResource::collection($ticket));
    }

    /**
     * 创建工单
     * 
     * 用户发起新的技术支持或财务支持工单。
     * 
     * @bodyParam subject string required 工单主题或简短标题 Example: 支付问题
     * @bodyParam level int required 优先级类型(1:普通 2:重要等) Example: 1
     * @bodyParam message string required 工单正文详细描述 Example: 我支付了没有到账
     */
    public function save(TicketSave $request)
    {
        $ticketService = new TicketService();
        $ticket = $ticketService->createTicket(
            $request->user()->id,
            $request->input('subject'),
            $request->input('level'),
            $request->input('message')
        );
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);

    }

    /**
     * 回复工单
     * 
     * 在已有的工单下追加新的消息回复管理员。若工单已关闭则无法回复。
     * 
     * @bodyParam id int required 需要回复的工单ID Example: 1
     * @bodyParam message string required 回复的具体内容 Example: 请问什么时候能处理好？
     */
    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([400, __('Invalid parameter')]);
        }
        if (empty($request->input('message'))) {
            return $this->fail([400, __('Message cannot be empty')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        if ($ticket->status) {
            return $this->fail([400, __('The ticket is closed and cannot be replied')]);
        }
        if ((int) admin_setting('ticket_must_wait_reply', 0) && $request->user()->id == $this->getLastMessage($ticket->id)->user_id) {
            return $this->fail(codeResponse: [400, __('Please wait for the technical enginneer to reply')]);
        }
        $ticketService = new TicketService();
        if (
            !$ticketService->reply(
                $ticket,
                $request->input('message'),
                $request->user()->id
            )
        ) {
            return $this->fail([400, __('Ticket reply failed')]);
        }
        HookManager::call('ticket.reply.user.after', $ticket);
        return $this->success(true);
    }


    /**
     * 关闭工单
     * 
     * 用户主动关闭自己目前处于开启状态的售后工单。
     * 
     * @bodyParam id int required 要关闭的工单ID Example: 1
     */
    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            return $this->fail([500, __('Close failed')]);
        }
        return $this->success(true);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * 发起提现工单申请
     * 
     * 如果用户的推广佣金余额达到可提现门槛，可以通过此接口直接通过自动创建提现工单向管理员发起申请。
     * 
     * @bodyParam withdraw_method string required 选择的提现方式(alipay, usdt等) Example: alipay
     * @bodyParam withdraw_account string required 收款账号信息 Example: admin@alipay.com
     */
    public function withdraw(TicketWithdraw $request)
    {
        if ((int) admin_setting('withdraw_close_enable', 0)) {
            return $this->fail([400, 'Unsupported withdraw']);
        }
        if (
            !in_array(
                $request->input('withdraw_method'),
                admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT)
            )
        ) {
            return $this->fail([422, __('Unsupported withdrawal method')]);
        }
        $user = User::find($request->user()->id);
        $limit = admin_setting('commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            return $this->fail([422, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit])]);
        }
        try {
            $ticketService = new TicketService();
            $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
            $message = sprintf(
                "%s\r\n%s",
                __('Withdrawal method') . "：" . $request->input('withdraw_method'),
                __('Withdrawal account') . "：" . $request->input('withdraw_account')
            );
            $ticket = $ticketService->createTicket(
                $request->user()->id,
                $subject,
                2,
                $message
            );
        } catch (\Exception $e) {
            throw $e;
        }
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);
    }
}
