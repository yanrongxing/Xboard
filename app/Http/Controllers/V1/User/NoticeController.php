<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    /**
     * 获取系统公告
     * 
     * 用于前端展板拉取所有已经发布的展示性公告。
     * 
     * @queryParam current int 当前分页页数 Example: 1
     * @responseField data array 公告列表
     */
    public function fetch(Request $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = 5;
        $model = Notice::orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->where('show', true);
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }
}
