<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Client\AppController;
use Illuminate\Contracts\Routing\Registrar;

class ClientRoute
{
    public function map(Registrar $router)
    {
        // 访客也可以访问的版本控制接口
        $router->group([
            'prefix' => 'client'
        ], function ($router) {
            $router->get('/app/getVersion', [AppController::class, 'getVersion']);
        });

        // 需要 Client Token 校验的接口
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            // App
            $router->get('/app/getConfig', [AppController::class, 'getConfig']);
        });
    }
}
