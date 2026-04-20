<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeResource;
use App\Models\Knowledge;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    /**
     * 游客获取知识库文章列表/详情（无需登录）
     *
     * 与用户版本类似，但不会替换订阅链接等用户相关占位符，
     * 且受限内容区域（<!--access start-->...<!--access end-->）会被隐藏提示替换。
     */
    public function fetch(Request $request)
    {
        $request->validate([
            'id' => 'nullable|sometimes|integer|min:1',
            'language' => 'nullable|sometimes|string|max:10',
            'keyword' => 'nullable|sometimes|string|max:255',
        ]);

        return $request->input('id')
            ? $this->fetchSingle($request)
            : $this->fetchList($request);
    }

    private function fetchSingle(Request $request)
    {
        $knowledge = $this->buildKnowledgeQuery()
            ->where('id', $request->input('id'))
            ->first();

        if (!$knowledge) {
            return $this->fail([500, __('Article does not exist')]);
        }

        $knowledge = $knowledge->toArray();
        $knowledge = $this->processGuestContent($knowledge);

        return $this->success(KnowledgeResource::make($knowledge));
    }

    private function fetchList(Request $request)
    {
        $builder = $this->buildKnowledgeQuery(['id', 'category', 'title', 'updated_at', 'body'])
            ->where('language', $request->input('language'))
            ->orderBy('sort', 'ASC');

        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()
            ->map(function ($knowledge) {
                $knowledge = $knowledge->toArray();
                $knowledge = $this->processGuestContent($knowledge);
                return KnowledgeResource::make($knowledge);
            })
            ->groupBy('category');

        return $this->success($knowledges);
    }

    private function buildKnowledgeQuery(array $select = ['*'])
    {
        return Knowledge::select($select)->where('show', 1);
    }

    /**
     * 游客版本的内容处理：
     * - 隐藏受限内容区域
     * - 将订阅相关占位符替换为提示文字
     */
    private function processGuestContent(array $knowledge): array
    {
        if (!isset($knowledge['body'])) {
            return $knowledge;
        }

        // 隐藏受限内容
        $knowledge['body'] = preg_replace(
            '/<!--access start-->(.*?)<!--access end-->/s',
            '<div class="v2board-no-access">' . __('You must have a valid subscription to view content in this area') . '</div>',
            $knowledge['body']
        );

        // 将用户专属占位符替换为通用提示
        $siteName = admin_setting('app_name', 'XBoard');
        $loginHint = __('Please login to view your subscription URL');

        $knowledge['body'] = str_replace(
            ['{{siteName}}', '{{subscribeUrl}}', '{{urlEncodeSubscribeUrl}}', '{{safeBase64SubscribeUrl}}'],
            [$siteName, $loginHint, $loginHint, $loginHint],
            $knowledge['body']
        );

        return $knowledge;
    }
}
