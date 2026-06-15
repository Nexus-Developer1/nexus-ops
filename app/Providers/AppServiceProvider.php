<?php

namespace App\Providers;

use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use App\Services\Erp\SqlServerErpDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolve o driver do ERP conforme a configuração (config/erp.php).
        $this->app->bind(ErpSyncDriver::class, function () {
            return match (config('erp.driver')) {
                'sqlsrv' => new SqlServerErpDriver(),
                default => new FakeErpDriver(),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
