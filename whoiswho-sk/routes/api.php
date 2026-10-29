<?php

use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\GraphController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RiskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [HealthController::class, 'index'])->name('health');

    Route::middleware(['auth.apikey', 'throttle:whoiswho-api'])->group(function (): void {
        Route::get('/companies/{ico}', [CompanyController::class, 'show'])->name('companies.show');
        Route::get('/companies/{ico}/graph', [GraphController::class, 'show'])->name('companies.graph');
        Route::get('/companies/{ico}/risk', [RiskController::class, 'show'])->name('companies.risk');
        Route::post('/reports/due-diligence', [ReportController::class, 'store'])->name('reports.due-diligence');
        Route::get('/reports/{jobId}', [ReportController::class, 'show'])->name('reports.show');
        Route::get('/reports/{jobId}/download', [ReportController::class, 'download'])->name('reports.download');
        Route::post('/reports/{jobId}/checkout', [ReportController::class, 'checkout'])->name('reports.checkout');
    });
});
