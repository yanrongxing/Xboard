<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\OrderUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{

    /**
     * 获取订单详情
     * 
     * 获取单一订单的所有相关连同上下文数据（如用户信息、购买的套餐快照、甚至如果分发了佣金包含的佣金账单记录）。
     * 
     * @queryParam id int required 需要查询详细信息的记录 ID
     */
    public function detail(Request $request)
    {
        $order = Order::with(['user', 'plan', 'commission_log', 'invite_user'])->find($request->input('id'));
        if (!$order)
            return $this->fail([400202, '订单不存在']);
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        $order['period'] = PlanService::getLegacyPeriod((string) $order->period);
        return $this->success($order);
    }

    /**
     * 获取订单列表
     * 
     * 查询所有由用户主动或系统开出的账单流水。可专门筛选出包含了渠道返利的明细。
     * 
     * @queryParam is_commission bool 可选，如果为true则仅查询有佣金入账的邀请订单
     * @queryParam current int 当前页码
     * @queryParam pageSize int 每页容量
     */
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);
        $orderModel = Order::with('plan:id,name');

        if ($request->boolean('is_commission')) {
            $orderModel->whereNotNull('invite_user_id')
                ->whereNotIn('status', [0, 2])
                ->where('commission_balance', '>', 0);
        }

        $this->applyFiltersAndSorts($request, $orderModel);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginatedResults */
        $paginatedResults = $orderModel
            ->latest('created_at')
            ->paginate(
                perPage: $pageSize,
                page: $current
            );

        $paginatedResults->getCollection()->transform(function ($order) {
            $orderArray = $order->toArray();
            $orderArray['period'] = PlanService::getLegacyPeriod((string) $order->period);
            return $orderArray;
        });

        return $this->paginate($paginatedResults);
    }

    private function applyFiltersAndSorts(Request $request, Builder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    private function applyFilters(Request $request, Builder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];

            $builder->where(function ($query) use ($field, $value) {
                $this->buildFilterQuery($query, $field, $value);
            });
        });
    }

    private function buildFilterQuery(Builder $query, string $field, mixed $value): void
    {
        // Handle array values for 'in' operations
        if (is_array($value)) {
            $query->whereIn($field, $value);
            return;
        }

        // Handle operator-based filtering
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // Convert numeric strings to appropriate type
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // Apply operator
        $query->where($field, match (strtolower($operator)) {
            'eq' => '=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like',
            'notlike' => 'not like',
            'null' => static fn($q) => $q->whereNull($field),
            'notnull' => static fn($q) => $q->whereNotNull($field),
            default => 'like'
        }, match (strtolower($operator)) {
            'like', 'notlike' => "%{$filterValue}%",
            'null', 'notnull' => null,
            default => $filterValue
        });
    }

    private function applySorting(Request $request, Builder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    /**
     * 手动标记订单已支付
     * 
     * 管理员在后台针对处于[挂起/未支付]状态的订单强制设定为支付成功状态（模拟收到现金操作）。
     * 
     * @bodyParam trade_no string required 订单系统交易号
     */
    public function paid(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->paid('manual_operation')) {
            return $this->fail([500, '更新失败']);
        }
        return $this->success(true);
    }

    /**
     * 手动取消未支付订单
     * 
     * 管理员在后台直接关闭放弃/驳回某个长时间未入账的僵尸订单记录。
     * 
     * @bodyParam trade_no string required 要撤销掉的订单支付流水单号
     */
    public function cancel(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, '更新失败']);
        }
        return $this->success(true);
    }

    /**
     * 更新订单抽成状态等简要设置
     * 
     * 例如可被用于更改此笔订单的最终返利到账标记等操作。
     * 
     * @bodyParam trade_no string required 必选更新的订单交易流水号
     * @bodyParam commission_status int 佣金下发审核是否成功已入账
     */
    public function update(OrderUpdate $request)
    {
        $params = $request->only([
            'commission_status'
        ]);

        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }

        try {
            $order->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '更新失败']);
        }

        return $this->success(true);
    }

    /**
     * 后台强制分发创建订单
     * 
     * 管理员代替用户手工在后台挂上一笔未支付的新订阅账单。支持分配特定的购买套餐、时效等等。
     * 
     * @bodyParam email string required 针对分配购买的主人账号邮箱
     * @bodyParam plan_id int required 强行开通并分配指向的商品套餐 ID
     * @bodyParam period string required 这次分配想让它续或买多久 (month/quarter 等)
     * @bodyParam total_amount int required 为该笔订单设置的需收款面值(分)
     */
    public function assign(OrderAssign $request)
    {
        $plan = Plan::find($request->input('plan_id'));
        $user = User::byEmail($request->input('email'))->first();

        if (!$user) {
            return $this->fail([400202, '该用户不存在']);
        }

        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return $this->fail([400, '该用户还有待支付的订单，无法分配']);
        }

        try {
            DB::beginTransaction();
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $period = $request->input('period');
            $order->period = PlanService::getPeriodKey((string) $period);
            $order->trade_no = Helper::guid();
            $order->total_amount = $request->input('total_amount');

            if (PlanService::getPeriodKey((string) $order->period) === Plan::PERIOD_RESET_TRAFFIC) {
                $order->type = Order::TYPE_RESET_TRAFFIC;
            } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id) {
                $order->type = Order::TYPE_UPGRADE;
            } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
                $order->type = Order::TYPE_RENEWAL;
            } else {
                $order->type = Order::TYPE_NEW_PURCHASE;
            }

            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollBack();
                return $this->fail([500, '订单创建失败']);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->success($order->trade_no);
    }
}
