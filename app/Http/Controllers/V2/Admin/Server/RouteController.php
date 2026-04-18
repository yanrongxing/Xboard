<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ServerRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RouteController extends Controller
{
    /**
     * 拉出目前现役的全部路由策略/过滤分流策略
     * 
     * 此控制器专供于节点侧应用类似于V2ray/Xray的黑洞IP阻断策略与白名单DNS清洗列表管理查阅。
     */
    public function fetch(Request $request)
    {
        $routes = ServerRoute::get();
        return [
            'data' => $routes
        ];
    }

    /**
     * 增加/更新定制拦截路由记录
     * 
     * @bodyParam id int 带入意味着覆盖这条记录的内容
     * @bodyParam remarks string required 备忘名称
     * @bodyParam match array required 需要拦截或放行的数组如 geosite:cn 或者 IP集合
     * @bodyParam action string required 处理模式 in:block(阻断),direct(直行),dns(转包验证解析),proxy(强制兜底代理)
     */
    public function save(Request $request)
    {
        $params = $request->validate([
            'remarks' => 'required',
            'match' => 'required|array',
            'action' => 'required|in:block,direct,dns,proxy',
            'action_value' => 'nullable'
        ], [
            'remarks.required' => '备注不能为空',
            'match.required' => '匹配值不能为空',
            'action.required' => '动作类型不能为空',
            'action.in' => '动作类型参数有误'
        ]);
        $params['match'] = array_filter($params['match']);
        // TODO: remove on 1.8.0
        if ($request->input('id')) {
            try {
                $route = ServerRoute::find($request->input('id'));
                $route->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500,'保存失败']);
            }
        }
        try{
            ServerRoute::create($params);
            return $this->success(true);
        }catch(\Exception $e){
            Log::error($e);
            return $this->fail([500,'创建失败']);
        }
    }

    /**
     * 删除不再使用或废弃掉的分流屏蔽策略指令
     * 
     * @bodyParam id int required 要拔除掉屏蔽策略表项的单条关联引索号
     */
    public function drop(Request $request)
    {
        $route = ServerRoute::find($request->input('id'));
        if (!$route) throw new ApiException('路由不存在');
        if (!$route->delete()) throw new ApiException('删除失败');
        return [
            'data' => true
        ];
    }
}
