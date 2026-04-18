<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    /**
     * 获取后台订阅套餐列表
     * 
     * 查询系统中建立的所有商品套餐信息，并附带关联查询该套餐下当前正在活跃使用的人数。
     * 
     * @responseField data array 订阅套餐及其购买人数的统计列表
     */
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        return $this->success($plans);
    }

    /**
     * 创建或保存（修改）订阅套餐
     * 
     * 录入全套新建的套餐基本信息、流量配额限制及标价内容。如果携带了 ID 则是修改现存套餐；
     * 修改时可勾选`force_update`来强制同步更新已购买此套餐的用户的流量/设备阈值。
     * 
     * @bodyParam id int 如果是修改必须传原ID
     * @bodyParam force_update bool 是否强制同步变更到存量用户身上
     */
    public function save(PlanSave $request)
    {
        $params = $request->validated();
        
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }
            
            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit' => $params['speed_limit'],
                        'device_limit' => $params['device_limit'],
                    ]);
                }
                $plan->update($params);
                DB::commit();
                return $this->success(true);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }
        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }
        return $this->success(true);
    }

    /**
     * 彻底删除某个订阅套餐
     * 
     * 该操作只有在套餐未被任何用户订阅、且无关联订单购买记录时才能执行，否则应选择下架(隐藏)该套餐。
     * 
     * @bodyParam id int required 需要删除的套餐ID
     */
    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        
        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }
        
        return $this->success($plan->delete());
    }

    /**
     * 快捷更新套餐状态（上下架/可续费）
     * 
     * 仅快速更改套餐是否展示、是否允许购买、是否允许续费等单个开关按钮的状态。
     * 
     * @bodyParam id int required 所需更新的订阅套餐ID
     * @bodyParam show bool 前台是否展示
     * @bodyParam renew bool 是否允许续费
     * @bodyParam sell bool 是否允许新购
     */
    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    /**
     * 更新前台套餐展示排序
     * 
     * 管理员在前端拖拽重新调整套餐卡片出场顺序后，提交通知的全量ID重排序列。
     * 
     * @bodyParam ids array required 套餐ID数组序列 Example: [3, 1, 2]
     */
    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
