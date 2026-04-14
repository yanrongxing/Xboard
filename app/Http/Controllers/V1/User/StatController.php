<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrafficLogResource;
use App\Models\StatUser;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    /**
     * 获取历史流量消耗日志
     * 
     * 返回用户本月内每天（或每次记录点）上行和下行的流量消耗统计数据日志。
     * 常用于绘制前端流量走势图。
     * 
     * @responseField data array 包含各时间点流量使用量的数组
     */
    public function getTrafficLog(Request $request)
    {
        $startDate = now()->startOfMonth()->timestamp;
        $records = StatUser::query()
            ->where('user_id', $request->user()->id)
            ->where('record_at', '>=', $startDate)
            ->orderBy('record_at', 'DESC')
            ->get();

        $data = TrafficLogResource::collection(collect($records));
        return $this->success($data);
    }
}
