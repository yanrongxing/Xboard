<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

$securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

Route::any("/{$securePath}/custom-api", function(Request $request) use ($securePath) {
    // 3. 使用 XBoard 真正的后台 Token 校验逻辑
    $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
    
    // 如果没有有效的 Sanctum Token，说明是浏览器直接访问或者未授权 API 访问
    if (!$user || !$user->is_admin) {
        // 如果是 POST 请求，直接拒绝
        if ($request->isMethod('post')) {
            return response()->json(['message' => 'Unauthorized. Invalid admin token.'], 403);
        }
        
        // 浏览器 GET 访问：渲染一个能够自动从本地存储读取 Token 并附加到请求头的前端骨架页面
        return '<!DOCTYPE html>
        <html lang="zh">
        <head><meta charset="UTF-8"><title>定制功能</title></head>
        <body style="font-family: sans-serif; padding: 20px;">
            <h2 id="status" style="color: blue;">正在获取后台授权状态...</h2>
            <div id="app-content"></div>
            <script>
                // 从管理后台的 localStorage 中提取真实的 auth token
                let authData = localStorage.getItem("auth_data");
                let token = "";
                if (authData) {
                    try {
                        let parsed = JSON.parse(authData);
                        token = parsed.token || parsed; 
                    } catch(e) { token = authData; }
                }
                
                if (!token) {
                    document.getElementById("status").innerHTML = "<span style=\'color:red\'>未找到后台登录凭证！请先在同一浏览器中登录 XBoard 管理后台。</span><br><a href=\'/'.$securePath.'\'>点击去登录</a>";
                } else {
                    document.getElementById("status").style.display = "none";
                    
                    // 携带正确的 Admin Token 重新请求当前页面获取真实的 HTML 表单
                    fetch("/'.$securePath.'/custom-api?action=" + new URLSearchParams(window.location.search).get("action"), {
                        method: "GET",
                        headers: { "Authorization": token }
                    }).then(res => {
                        if (res.status === 403) {
                            document.getElementById("status").style.display = "block";
                            document.getElementById("status").innerHTML = "<span style=\'color:red\'>授权已过期或无效，请重新登录！</span><br><a href=\'/'.$securePath.'\'>点击去登录</a>";
                        } else {
                            res.text().then(html => {
                                document.getElementById("app-content").innerHTML = html;
                            });
                        }
                    });
                }

                // 劫持表单提交，让它自动带上 Token
                document.addEventListener("submit", function(e) {
                    e.preventDefault();
                    let form = e.target;
                    let formData = new FormData(form);
                    
                    fetch(form.action, {
                        method: form.method,
                        headers: { "Authorization": token },
                        body: formData
                    }).then(res => res.text()).then(res => {
                        alert("保存结果：" + res);
                        location.reload();
                    });
                });
            </script>
        </body>
        </html>';
    }

    // --- 下面的代码只有在带了合法的后台 Token 后才会执行 ---

    $action = $request->input('action');

    // === 定制功能 1：设置 Privacy & Terms ===
    if ($action === 'set_config') {
        if ($request->isMethod('post')) {
            admin_setting([
                'privacy_url' => $request->input('privacy_url'),
                'terms_url' => $request->input('terms_url'),
            ]);
            return 'Privacy & Terms 设置保存成功！';
        }
        return '<h2>快速设置 Privacy & Terms</h2>
        <form method="POST" action="/'.$securePath.'/custom-api?action=set_config" style="line-height: 2;">
            <label>Privacy URL:</label><br>
            <input type="text" name="privacy_url" value="'.admin_setting('privacy_url').'" style="width:400px; padding: 5px;"><br><br>
            <label>Terms URL:</label><br>
            <input type="text" name="terms_url" value="'.admin_setting('terms_url').'" style="width:400px; padding: 5px;"><br><br>
            <button type="submit" style="padding: 5px 20px; cursor: pointer;">保存</button>
        </form>';
    }

    // === 定制功能 2：APP 高级设置（包含四端） ===
    if ($action === 'app_config') {
        if ($request->isMethod('post')) {
            $platforms = ['ios', 'android', 'windows', 'macos'];
            $updates = [];
            foreach ($platforms as $p) {
                $updates["{$p}_version"] = $request->input("{$p}_version");
                $updates["{$p}_download_url"] = $request->input("{$p}_download_url");
                $updates["{$p}_is_force_update"] = $request->input("{$p}_is_force_update");
                $updates["{$p}_update_content"] = $request->input("{$p}_update_content");
            }
            admin_setting($updates);
            return 'APP 各端高级配置已成功保存到数据库！';
        }

        $html = '<h2>APP 高级配置中心 (全平台支持)</h2><form method="POST" action="/'.$securePath.'/custom-api?action=app_config" style="line-height: 1.8;">';
        
        $platforms = [
            'ios' => 'iOS (苹果端)', 
            'android' => 'Android (安卓端)', 
            'windows' => 'Windows (电脑端)', 
            'macos' => 'macOS (苹果电脑)'
        ];

        foreach ($platforms as $key => $name) {
            $html .= "<fieldset style='margin-bottom: 20px; padding: 15px; border: 1px solid #ccc;'>
                <legend style='font-weight: bold; color: #333;'>{$name} 配置</legend>
                
                <label>版本号:</label><br>
                <input type="text" name="{$key}_version" value="'.admin_setting("{$key}_version").'" style="width:400px; padding: 5px;"><br>
                
                <label>下载链接:</label><br>
                <input type="text" name="{$key}_download_url" value="'.admin_setting("{$key}_download_url").'" style="width:400px; padding: 5px;"><br>
                
                <label>强制更新:</label><br>
                <select name="{$key}_is_force_update" style="width:414px; padding: 5px;">
                    <option value="0" '.(admin_setting("{$key}_is_force_update", 0) == 0 ? 'selected' : '').'>否 (0)</option>
                    <option value="1" '.(admin_setting("{$key}_is_force_update", 0) == 1 ? 'selected' : '').'>是 (1)</option>
                </select><br>

                <label>更新内容/日志 (支持换行):</label><br>
                <textarea name="{$key}_update_content" style="width:400px; height:60px; padding: 5px;">'.admin_setting("{$key}_update_content").'</textarea>
            </fieldset>";
        }

        $html .= '<button type="submit" style="padding: 10px 30px; font-size: 16px; font-weight: bold; cursor: pointer; background: #007bff; color: #fff; border: none; border-radius: 4px;">保存所有端配置</button></form>';
        return $html;
    }

    return '<h2>定制接口总览</h2>
            <ul>
                <li><a href="/'.$securePath.'/custom-api?action=set_config">点击进入: 设置 Privacy & Terms 配置</a></li>
                <li><a href="/'.$securePath.'/custom-api?action=app_config">点击进入: APP 各端高级配置 (强更与日志)</a></li>
            </ul>';
});

Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');