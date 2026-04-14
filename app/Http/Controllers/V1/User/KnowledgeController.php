<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeResource;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * 获取知识库文章列表/详情
     * 
     * 用于前端知识库中心的文章展示，支持通过传递ID查看详细正文，支持按语言检索和关键字模糊搜索。
     * 当获取到正文内容时，系统会自动替换文章中包含的动态标签（如 `{{subscribeUrl}}` 自动替换为用户的订阅地址）。
     * 
     * @queryParam id int 可选，如果不传则返回列表，如果传了特定文章ID则返回单篇详情 Example: 1
     * @queryParam language string 按照语种筛选文章（默认en/zh等） Example: zh-CN
     * @queryParam keyword string 关键字搜索标题或内容
     * @responseField data array|object 列表形态下返回按类别分组的文章数组，详情层级下返回单独文章内容
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
        $knowledge = $this->processKnowledgeContent($knowledge, $request->user());

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
            ->map(function ($knowledge) use ($request) {
                $knowledge = $knowledge->toArray();
                $knowledge = $this->processKnowledgeContent($knowledge, $request->user());
                return KnowledgeResource::make($knowledge);
            })
            ->groupBy('category');

        return $this->success($knowledges);
    }

    private function buildKnowledgeQuery(array $select = ['*'])
    {
        return Knowledge::select($select)->where('show', 1);
    }

    private function processKnowledgeContent(array $knowledge, User $user): array
    {
        if (!isset($knowledge['body'])) {
            return $knowledge;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->formatAccessData($knowledge['body']);
        }
        $subscribeUrl = Helper::getSubscribeUrl($user['token']);
        $knowledge['body'] = $this->replacePlaceholders($knowledge['body'], $subscribeUrl);

        return $knowledge;
    }

    private function formatAccessData(&$body): void
    {
        $rules = [
            [
                'type' => 'regex',
                'pattern' => '/<!--access start-->(.*?)<!--access end-->/s',
                'replacement' => '<div class="v2board-no-access">' . __('You must have a valid subscription to view content in this area') . '</div>'
            ]
        ];

        $this->applyReplacementRules($body, $rules);
    }

    private function replacePlaceholders(string $body, string $subscribeUrl): string
    {
        $rules = [
            [
                'type' => 'string',
                'search' => '{{siteName}}',
                'replacement' => admin_setting('app_name', 'XBoard')
            ],
            [
                'type' => 'string',
                'search' => '{{subscribeUrl}}',
                'replacement' => $subscribeUrl
            ],
            [
                'type' => 'string',
                'search' => '{{urlEncodeSubscribeUrl}}',
                'replacement' => urlencode($subscribeUrl)
            ],
            [
                'type' => 'string',
                'search' => '{{safeBase64SubscribeUrl}}',
                'replacement' => str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($subscribeUrl))
            ]
        ];

        $this->applyReplacementRules($body, $rules);
        return $body;
    }

    private function applyReplacementRules(string &$body, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule['type'] === 'regex') {
                $body = preg_replace($rule['pattern'], $rule['replacement'], $body);
            } else {
                $body = str_replace($rule['search'], $rule['replacement'], $body);
            }
        }
    }
}
