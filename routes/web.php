<?php

use App\Http\Controllers\AccountAvatarController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ContractAmendmentController;
use App\Http\Controllers\ContractAttachmentController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractExtractController;
use App\Http\Controllers\ContractFinanceController;
use App\Http\Controllers\ContractProductController;
use App\Http\Controllers\ContractReviewController;
use App\Http\Controllers\ContractServiceOrderController;
use App\Http\Controllers\ContractTypeRuleController;
use App\Http\Controllers\DocumentSettingsController;
use App\Http\Controllers\InternalApiController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavedReportController;
use App\Http\Controllers\SelectedFundController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::get('/ready', function () {
    try {
        DB::select('SELECT 1');
        DB::table('jobs')->limit(1)->get(['id']);

        return response()->json(['status' => 'ready']);
    } catch (Throwable) {
        return response()->json(['status' => 'unavailable'], 503);
    }
})->name('readiness');
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});
Route::middleware(['auth', EnsureActiveUser::class])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::post('/session/fund', [SelectedFundController::class, 'update'])->name('session.fund');
    Route::get('/internal-api/v1/contracts', [InternalApiController::class, 'index'])->middleware('throttle:60,1')->name('internal-api.contracts.index');
    Route::get('/internal-api/v1/contracts/{contract}', [InternalApiController::class, 'show'])->middleware('throttle:60,1')->name('internal-api.contracts.show');
    Route::post('/saved-reports', [SavedReportController::class, 'store'])->name('saved-reports.store');
    Route::get('/saved-reports/{savedReport}', [SavedReportController::class, 'show'])->name('saved-reports.show');
    Route::delete('/saved-reports/{savedReport}', [SavedReportController::class, 'destroy'])->name('saved-reports.destroy');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::put('/calendar/preferences', [CalendarController::class, 'preferences'])->name('calendar.preferences');
    Route::get('/catalogs', [CatalogController::class, 'index'])->name('catalogs.index');
    Route::put('/document-settings', [DocumentSettingsController::class, 'update'])->name('document-settings.update');
    Route::get('/catalogs/{kind}/{catalog}', [CatalogController::class, 'show'])->whereIn('kind', ['fund', 'department'])->whereNumber('catalog')->name('catalogs.show');
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('/suppliers/lookup', [SupplierController::class, 'lookup'])->middleware('throttle:60,1')->name('suppliers.lookup');
    Route::post('/suppliers/registration-lookup', [SupplierController::class, 'registrationLookup'])->middleware('throttle:60,1')->name('suppliers.registration-lookup');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
    Route::get('/contract-types', [ContractTypeRuleController::class, 'index'])->name('contract-types.index');
    Route::post('/contract-types', [ContractTypeRuleController::class, 'store'])->name('contract-types.store');
    Route::post('/catalogs', [CatalogController::class, 'store'])->name('catalogs.store');
    Route::get('/funds/{fund}/contract-defaults', [ContractController::class, 'fundDefaults'])->whereNumber('fund')->name('contracts.fund-defaults');
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account', [AccountController::class, 'update'])->name('account.update');
    Route::get('/account/avatar', [AccountAvatarController::class, 'show'])->name('account.avatar.show');
    Route::post('/account/avatar', [AccountAvatarController::class, 'store'])->middleware('throttle:10,1')->name('account.avatar.store');
    Route::delete('/account/avatar', [AccountAvatarController::class, 'destroy'])->name('account.avatar.destroy');
    Route::get('/administration', [AdministrationController::class, 'index'])->name('administration.index');
    Route::get('/administration/records/{kind}/{record}', [AdministrationController::class, 'record'])
        ->whereIn('kind', ['contract-audit', 'user-audit', 'event', 'import', 'issue'])->whereNumber('record')->name('administration.record');
    Route::get('/administration/export/{format}', [AdministrationController::class, 'export'])->middleware('throttle:10,1')->name('administration.export');
    Route::post('/administration/history/{history}/reconcile', [AdministrationController::class, 'reconcile'])->name('administration.reconcile');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/exports', [ReportController::class, 'exports'])->name('reports.exports');
    Route::get('/reports/download/{export}', [ReportController::class, 'download'])->name('reports.download');
    Route::get('/reports/status/{export}', [ReportController::class, 'status'])->middleware('throttle:300,1')->name('reports.status');
    Route::get('/reports/export/{format}', [ReportController::class, 'export'])->middleware('throttle:10,1')->name('reports.export');
    Route::get('/contracts/{contract}/pdf', [ReportController::class, 'detail'])->name('contracts.pdf');
    Route::get('/contracts/{contract}/extract', [ContractExtractController::class, 'show'])->middleware('throttle:20,1')->name('contracts.extract');
    Route::get('/contracts/{contract}/publication', [ContractExtractController::class, 'publication'])->middleware('throttle:20,1')->name('contracts.publication');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::resource('contracts.finance', ContractFinanceController::class)->parameters(['finance' => 'finance']);
    Route::get('/contracts/{contract}/review', [ContractReviewController::class, 'edit'])->name('contracts.review.edit');
    Route::put('/contracts/{contract}/review', [ContractReviewController::class, 'update'])->name('contracts.review.update');
    Route::post('/contracts/{contract}/finance/{finance}/restore', [ContractFinanceController::class, 'restore'])->name('contracts.finance.restore');
    Route::resource('contracts.amendments', ContractAmendmentController::class)->parameters(['amendments' => 'amendment']);
    Route::get('/contracts/{contract}/service-orders', [ContractServiceOrderController::class, 'index'])->name('contracts.service-orders.index');
    Route::post('/contracts/{contract}/service-orders', [ContractServiceOrderController::class, 'store'])->middleware('throttle:30,1')->name('contracts.service-orders.store');
    Route::get('/contracts/{contract}/products', [ContractProductController::class, 'index'])->name('contracts.products.index');
    Route::get('/contracts/{contract}/products/budget-lookup', [ContractProductController::class, 'budgetLookup'])->middleware('throttle:60,1')->name('contracts.products.budget-lookup');
    Route::get('/contracts/{contract}/products/item-lookup', [ContractProductController::class, 'itemLookup'])->middleware('throttle:60,1')->name('contracts.products.item-lookup');
    Route::post('/contracts/{contract}/products', [ContractProductController::class, 'store'])->middleware('throttle:60,1')->name('contracts.products.store');
    Route::put('/contracts/{contract}/products/{item}', [ContractProductController::class, 'update'])->whereNumber('item')->middleware('throttle:60,1')->name('contracts.products.update');
    Route::delete('/contracts/{contract}/products/{item}', [ContractProductController::class, 'destroy'])->whereNumber('item')->middleware('throttle:60,1')->name('contracts.products.destroy');
    Route::post('/contracts/{contract}/amendments/{amendment}/restore', [ContractAmendmentController::class, 'restore'])->name('contracts.amendments.restore');
    Route::post('/contracts/{contract}/attachments', [ContractAttachmentController::class, 'store'])->middleware('throttle:20,1')->name('contracts.attachments.store');
    Route::get('/contracts/{contract}/attachments/{attachment}', [ContractAttachmentController::class, 'download'])->name('contracts.attachments.download');
    Route::delete('/contracts/{contract}/attachments/{attachment}', [ContractAttachmentController::class, 'destroy'])->name('contracts.attachments.destroy');
    Route::post('/contracts/{contract}/attachments/{attachment}/restore', [ContractAttachmentController::class, 'restore'])->name('contracts.attachments.restore');
    Route::get('/contracts/lookup', [ContractController::class, 'lookup'])->middleware('throttle:60,1')->name('contracts.lookup');
    Route::get('/contracts/inspector-defaults', [ContractController::class, 'inspectorDefaults'])->middleware('throttle:60,1')->name('contracts.inspector-defaults');
    Route::resource('contracts', ContractController::class)->only(['index', 'show', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::post('/contracts/{contract}/restore', [ContractController::class, 'restore'])->withTrashed()->name('contracts.restore');
    Route::middleware('can:viewAny,App\Models\User')->group(function () {
        Route::resource('users', UserController::class)->except('show');
        Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
        Route::post('/users/{user}/restore', [UserController::class, 'restore'])->withTrashed()->name('users.restore');
    });
});
