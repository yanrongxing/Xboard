<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Client\AppController;
use App\Http\Controllers\V1\Client\ClientController;
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
            // Client
            $router->get('/subscribe', [ClientController::class, 'subscribe'])->name('client.subscribe.legacy');
            // App
            $router->get('/app/getConfig', [AppController::class, 'getConfig']);
        });
    }
}
