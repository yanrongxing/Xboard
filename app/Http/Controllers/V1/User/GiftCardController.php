<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\GiftCardCheckRequest;
use App\Http\Requests\User\GiftCardRedeemRequest;
use App\Models\GiftCardUsage;
use App\Services\GiftCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GiftCardController extends Controller
{
    /**
     * 查询兑换码信息
     */
    /**
     * 查询兑换码信息
     * 
     * 用于检查用户提交的礼品卡/兑换码卡密的有效性，并返回面额及绑定奖励的预览信息。
     * 
     * @bodyParam code string required 礼品兑换码 Example: ABCD-1234
     */
    public function check(GiftCardCheckRequest $request)
    {
        try {
            $giftCardService = new GiftCardService($request->input('code'));
            $giftCardService->setUser($request->user());

            // 1. 验证礼品卡本身是否有效 (如不存在、已过期、已禁用)
            $giftCardService->validateIsActive();

            // 2. 检查用户是否满足使用条件，但不在此处抛出异常
            $eligibility = $giftCardService->checkUserEligibility();

            // 3. 获取卡片信息和奖励预览
            $codeInfo = $giftCardService->getCodeInfo();
            $rewardPreview = $giftCardService->previewRewards();

            return $this->success([
                'code_info' => $codeInfo, // 这里面已经包含 plan_info
                'reward_preview' => $rewardPreview,
                'can_redeem' => $eligibility['can_redeem'],
                'reason' => $eligibility['reason'],
            ]);

        } catch (ApiException $e) {
            // 这里只捕获 validateIsActive 抛出的异常
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('礼品卡查询失败', [
                'code' => $request->input('code'),
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '查询失败，请稍后重试']);
        }
    }

    /**
     * 使用兑换码
     */
    /**
     * 使用兑换码
     * 
     * 正式消耗一张礼品卡，将其包含的余额或时长充值进入当前用户的账户内。
     * 
     * @bodyParam code string required 礼品兑换码 Example: ABCD-1234
     */
    public function redeem(GiftCardRedeemRequest $request)
    {
        try {
            $giftCardService = new GiftCardService($request->input('code'));
            $giftCardService->setUser($request->user());
            $giftCardService->validate();

            // 使用礼品卡
            $result = $giftCardService->redeem([
                // 'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            Log::info('礼品卡使用成功', [
                'code' => $request->input('code'),
                'user_id' => $request->user()->id,
                'rewards' => $result['rewards'],
            ]);

            return $this->success([
                'message' => '兑换成功！',
                'rewards' => $result['rewards'],
                'invite_rewards' => $result['invite_rewards'],
                'template_name' => $result['template_name'],
            ]);

        } catch (ApiException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('礼品卡使用失败', [
                'code' => $request->input('code'),
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail([500, '兑换失败，请稍后重试']);
        }
    }

    /**
     * 获取用户兑换记录
     */
    /**
     * 获取用户兑换记录
     * 
     * 查询当前用户历史上兑换过的所有礼品卡记录，支持分页。
     * 
     * @queryParam page int 当前页码 Example: 1
     * @queryParam per_page int 每页条数 Example: 15
     */
    public function history(Request $request)
    {
        $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ]);

        $perPage = $request->input('per_page', 15);

        $usages = GiftCardUsage::with(['template', 'code'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = $usages->getCollection()->map(function (GiftCardUsage $usage) {
            return [
                'id' => $usage->id,
                'code' => ($usage->code instanceof \App\Models\GiftCardCode && $usage->code->code)
                    ? (substr($usage->code->code, 0, 8) . '****')
                    : '',
                'template_name' => $usage->template->name ?? '',
                'template_type' => $usage->template->type ?? '',
                'template_type_name' => $usage->template->type_name ?? '',
                'rewards_given' => $usage->rewards_given,
                'invite_rewards' => $usage->invite_rewards,
                'multiplier_applied' => $usage->multiplier_applied,
                'created_at' => $usage->created_at,
            ];
        })->values();
        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $usages->currentPage(),
                'last_page' => $usages->lastPage(),
                'per_page' => $usages->perPage(),
                'total' => $usages->total(),
            ],
        ]);
    }

    /**
     * 获取兑换记录详情
     */
    /**
     * 获取兑换记录详情
     * 
     * 查看某次礼品卡兑换过程的详细记录和相关额外奖励触发信息。
     * 
     * @queryParam id int required 需要查看的兑换记录(Usage)的ID Example: 1
     */
    public function detail(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_gift_card_usage,id',
        ]);

        $usage = GiftCardUsage::with(['template', 'code', 'inviteUser'])
            ->where('user_id', $request->user()->id)
            ->where('id', $request->input('id'))
            ->first();

        if (!$usage) {
            return $this->fail([404, '记录不存在']);
        }

        return $this->success([
            'id' => $usage->id,
            'code' => $usage->code->code ?? '',
            'template' => [
                'name' => $usage->template->name ?? '',
                'description' => $usage->template->description ?? '',
                'type' => $usage->template->type ?? '',
                'type_name' => $usage->template->type_name ?? '',
                'icon' => $usage->template->icon ?? '',
                'theme_color' => $usage->template->theme_color ?? '',
            ],
            'rewards_given' => $usage->rewards_given,
            'invite_rewards' => $usage->invite_rewards,
            'invite_user' => $usage->inviteUser ? [
                'id' => $usage->inviteUser->id ?? '',
                'email' => isset($usage->inviteUser->email) ? (substr($usage->inviteUser->email, 0, 3) . '***@***') : '',
            ] : null,
            'user_level_at_use' => $usage->user_level_at_use,
            'plan_id_at_use' => $usage->plan_id_at_use,
            'multiplier_applied' => $usage->multiplier_applied,
            // 'ip_address' => $usage->ip_address,
            'notes' => $usage->notes,
            'created_at' => $usage->created_at,
        ]);
    }

    /**
     * 获取可用的礼品卡类型
     */
    /**
     * 获取可用的礼品卡类型
     * 
     * 枚举系统中支持的所有礼品卡类别（如通用充值卡、特定套餐时长卡等）。
     */
    public function types(Request $request)
    {
        return $this->success([
            'types' => \App\Models\GiftCardTemplate::getTypeMap(),
        ]);
    }
}
