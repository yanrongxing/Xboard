<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Http\Requests\Admin\KnowledgeSort;
use App\Models\Knowledge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KnowledgeController extends Controller
{
    /**
     * 获取知识库文章列表/单页
     * 
     * 如果带了 ID 参数则返回具体的那篇文章详情，否则返回所有文章的清单标题和简要信息。
     * 
     * @queryParam id int 过滤查询指定的文章 ID (如果传了只返回这个id详情)
     */
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::find($request->input('id'))->toArray();
            if (!$knowledge)
                return $this->fail([400202, '知识不存在']);
            return $this->success($knowledge);
        }
        $data = Knowledge::select(['title', 'id', 'updated_at', 'category', 'show'])
            ->orderBy('sort', 'ASC')
            ->get();
        return $this->success($data);
    }

    /**
     * 获取已有知识库分类
     * 
     * 获取系统中已经被创建出的所有帮助文档类别（Category），辅助前端提供下拉选择。
     */
    public function getCategory(Request $request)
    {
        return $this->success(array_keys(Knowledge::get()->groupBy('category')->toArray()));
    }

    /**
     * 保存/创建知识库文章
     * 
     * 由管理员新建或者编辑知识库文章主体内容。如果传入了 id 就是更新旧的，否则为新创建。
     * 
     * @bodyParam id int 如果是修改现有文章则必须传这篇的ID
     * @bodyParam category string required 分类名称
     * @bodyParam title string required 文章标题
     * @bodyParam content string required 文章主体（通常为Markdown或者富文本）
     */
    public function save(KnowledgeSave $request)
    {
        $params = $request->validated();

        if (!$request->input('id')) {
            if (!Knowledge::create($params)) {
                return $this->fail([500, '创建失败']);
            }
        } else {
            try {
                Knowledge::find($request->input('id'))->update($params);
            } catch (\Exception $e) {
                \Log::error($e);
                return $this->fail([500, '创建失败']);
            }
        }

        return $this->success(true);
    }

    /**
     * 切换文章是否展示
     * 
     * 切换这篇文章是对用户可见展示（开/关）。
     * 
     * @bodyParam id int required 需要切换开关的知识点ID
     */
    public function show(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '知识库ID不能为空'
        ]);
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            throw new ApiException('知识不存在');
        }
        $knowledge->show = !$knowledge->show;
        if (!$knowledge->save()) {
            throw new ApiException('保存失败');
        }

        return $this->success(true);
    }

    /**
     * 更新知识分类/文章排序
     * 
     * 全量传入文章的排序 ID 数组（重新排版后）。
     * 
     * @bodyParam ids array required 整理好的文章ID排列顺序
     */
    public function sort(Request $request)
    {
        $request->validate([
            'ids' => 'required|array'
        ], [
            'ids.required' => '参数有误',
            'ids.array' => '参数有误'
        ]);
        try {
            DB::beginTransaction();
            foreach ($request->input('ids') as $k => $v) {
                $knowledge = Knowledge::find($v);
                $knowledge->timestamps = false;
                $knowledge->update(['sort' => $k + 1]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ApiException('保存失败');
        }
        return $this->success(true);
    }

    /**
     * 删除单篇知识文章
     * 
     * @bodyParam id int required 要移除的文章 ID
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '知识库ID不能为空'
        ]);
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            return $this->fail([400202, '知识不存在']);
        }
        if (!$knowledge->delete()) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }
}
