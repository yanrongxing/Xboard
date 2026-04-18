<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoticeController extends Controller
{
    /**
     * 获取公告列表
     * 
     * 获取系统内部创建的系统平台公告通知，支持排序返回。
     */
    public function fetch(Request $request)
    {
        return $this->success(
            Notice::orderBy('sort', 'ASC')
                ->orderBy('id', 'DESC')
                ->get()
        );
    }

    /**
     * 创建/保存编辑公告
     * 
     * 新增站点的活动或业务通知文章。如果有 id 则是修改。
     * 
     * @bodyParam id int 如果是编辑则传这篇公告的 ID
     * @bodyParam title string required 公告标题
     * @bodyParam content string required 公告主体代码
     * @bodyParam show bool 是否显示
     * @bodyParam popup bool 是否需要在用户加载后台时强行弹窗展示
     */
    public function save(NoticeSave $request)
    {
        $data = $request->only([
            'title',
            'content',
            'img_url',
            'tags',
            'show',
            'popup'
        ]);
        if (!$request->input('id')) {
            if (!Notice::create($data)) {
                return $this->fail([500, '保存失败']);
            }
        } else {
            try {
                Notice::find($request->input('id'))->update($data);
            } catch (\Exception $e) {
                return $this->fail([500, '保存失败']);
            }
        }
        return $this->success(true);
    }



    /**
     * 切换单个公告显示状态
     * 
     * 强制翻转某个公告的打开/隐藏表现。
     * 
     * @bodyParam id int required 要切换公告的 ID
     */
    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([500, '公告ID不能为空']);
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            return $this->fail([400202, '公告不存在']);
        }
        $notice->show = $notice->show ? 0 : 1;
        if (!$notice->save()) {
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    /**
     * 彻底抹除公告
     * 
     * @bodyParam id int required 待删除公告的 ID
     */
    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, '公告ID不能为空']);
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            return $this->fail([400202, '公告不存在']);
        }
        if (!$notice->delete()) {
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }

    /**
     * 公告重排序
     * 
     * 拖拽后保存全体公告通知的新排位。
     * 
     * @bodyParam ids array required 公告ID列表的顺序 Example: [2, 1, 3]
     */
    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                $notice = Notice::findOrFail($v);
                $notice->update(['sort' => $k + 1]);
            }
            DB::commit();
            return $this->success(true);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e);
            return $this->fail([500, '排序保存失败']);
        }
    }
}
