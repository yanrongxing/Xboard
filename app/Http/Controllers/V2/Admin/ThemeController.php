<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ThemeController extends Controller
{
    private $themeService;

    public function __construct(ThemeService $themeService)
    {
        $this->themeService = $themeService;
    }

    /**
     * 上传扩展新主题皮肤包
     * 
     * 以 .zip 的标准主题规格文件上传到服务器中供前端挑选配置。
     * 
     * @throws ApiException
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:zip',
                'max:10240', // 最大10MB
            ]
        ], [
            'file.required' => '请选择主题包文件',
            'file.file' => '无效的文件类型',
            'file.mimes' => '主题包必须是zip格式',
            'file.max' => '主题包大小不能超过10MB'
        ]);

        try {
            // 检查上传目录权限
            $uploadPath = storage_path('tmp');
            if (!File::exists($uploadPath)) {
                File::makeDirectory($uploadPath, 0755, true);
            }

            if (!is_writable($uploadPath)) {
                throw new ApiException('上传目录无写入权限');
            }

            // 检查主题目录权限
            $themePath = base_path('theme');
            if (!is_writable($themePath)) {
                throw new ApiException('主题目录无写入权限');
            }

            $file = $request->file('file');

            // 检查文件MIME类型
            $mimeType = $file->getMimeType();
            if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed'])) {
                throw new ApiException('无效的文件类型，仅支持ZIP格式');
            }

            // 检查文件名安全性
            $originalName = $file->getClientOriginalName();
            if (!preg_match('/^[a-zA-Z0-9\-\_\.]+\.zip$/', $originalName)) {
                throw new ApiException('主题包文件名只能包含字母、数字、下划线、中划线和点');
            }

            $this->themeService->upload($file);
            return $this->success(true);

        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Theme upload failed', [
                'error' => $e->getMessage(),
                'file' => $request->file('file')?->getClientOriginalName()
            ]);
            throw new ApiException('主题上传失败：' . $e->getMessage());
        }
    }

    /**
     * 删除物理皮肤结构
     * 
     * @bodyParam name string required 想要除去的主题唯一识别代称名
     */
    public function delete(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required'
        ]);
        $this->themeService->delete($payload['name']);
        return $this->success(true);
    }

    /**
     * 获取已安装所有前端主题列表及启用情况
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getThemes()
    {
        $data = [
            'themes' => $this->themeService->getList(),
            'active' => admin_setting('frontend_theme', 'Xboard')
        ];
        return $this->success($data);
    }

    /**
     * 切换平台默认主题为
     * 
     * @bodyParam name string required 被选定的主题特征名字
     */
    public function switchTheme(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required'
        ]);
        $this->themeService->switch($payload['name']);
        return $this->success(true);
    }

    /**
     * 获取主题下辖自定义配置表单项集
     * 
     * 大多高端主题带有自己专属的幻灯片、页脚修改、色卡调节设置可供调整。
     * 
     * @queryParam name string required 查询对应主题拥有的配置集
     */
    public function getThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required'
        ]);
        $data = $this->themeService->getConfig($payload['name']);
        return $this->success($data);
    }

    /**
     * 固化更新当前这套特供主题选项
     * 
     * @bodyParam name string required 需要更新的是这套专属主题代称
     * @bodyParam config object required 对象格式的该皮肤专属键值对应修改项
     */
    public function saveThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required',
            'config' => 'required'
        ]);
        $this->themeService->updateConfig($payload['name'], $payload['config']);
        $config = $this->themeService->getConfig($payload['name']);
        return $this->success($config);
    }
}
