<?php

use App\Http\Controllers\Agents\GabAI;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Route::view('/', 'welcome')->name('home');
Route::view('/sign-in', 'authentication.sign-in')->name('sign-in');
Route::view('/sign-up', 'authentication.sign-up')->name('sign-up');
Route::redirect('/login', '/sign-in');
Route::redirect('/register', '/sign-up');
Route::redirect('/', '/sign-in')->name('home');

Route::get('/dashboard', function () {
    $dashboardRoute = match (request()->user()->role) {
        'admin' => 'admin.dashboard',
        'cashier' => 'cashier.dashboard',
        'employee' => 'employee.dashboard',
        'staff' => 'staff.dashboard',
    };

    return redirect()->route($dashboardRoute);
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post(
        '/gab-ai/start',
        [GabAI::class, 'start']
    )->name('gab-ai.start');

    Route::get(
        '/gab-ai/stream/{streamId}',
        [GabAI::class, 'stream']
    )->name('gab-ai.stream');
});

Route::get('/reports/download/{filename}', function (string $filename) {
    $filename = basename($filename);

    abort_unless(
        preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $filename) === 1,
        404
    );

    $path = Storage::disk('local')->path(
        'reports/'.$filename
    );

    abort_unless(
        file_exists($path),
        404
    );

    return response()
        ->download(
            $path,
            $filename,
            [
                'Content-Type' => 'application/pdf',
            ]
        )
        ->deleteFileAfterSend(true);
})
    ->middleware('auth')
    ->name('reports.download');

Route::middleware(['auth', 'verified', 'role:employee'])->group(function () {
    Route::livewire('/employee/dashboard', 'pages::user.home')->name('employee.dashboard');
    Route::livewire('/employee/reports', 'pages::user.reports')->name('employee.reports');
    Route::livewire('/employee/point-of-sale', 'pages::user.point-of-sale')->name('employee.pos');
    Route::livewire('/employee/transactions', 'pages::user.transaction-history')->name('employee.transactions');
    Route::livewire('/employee/inventory', 'pages::user.inventory')->name('employee.inventory');
    Route::livewire('/employee/user-management', 'pages::user.user-management')->name('employee.user-management');
    Route::livewire('/employee/purchase-order', 'pages::user.purchase-order')->name('employee.purchase-order');
    Route::livewire('/employee/college-requests', 'pages::user.college-requests')->name('employee.college-requests');
});

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::livewire('/admin/dashboard', 'pages::user.home')->name('admin.dashboard');
    Route::livewire('/admin/reports', 'pages::user.reports')->name('admin.reports');
    Route::livewire('/admin/point-of-sale', 'pages::user.point-of-sale')->name('admin.pos');
    Route::livewire('/admin/transactions', 'pages::user.transaction-history')->name('admin.transactions');
    Route::livewire('/admin/inventory', 'pages::user.inventory')->name('admin.inventory');
    Route::livewire('/admin/user-management', 'pages::user.user-management')->name('admin.user-management');
    Route::livewire('/admin/purchase-order', 'pages::user.purchase-order')->name('admin.purchase-order');
    Route::livewire('/admin/college-requests', 'pages::user.college-requests')->name('admin.college-requests');
});

Route::middleware(['auth', 'verified', 'role:cashier'])->group(function () {
    Route::livewire('/cashier/dashboard', 'pages::user.home')->name('cashier.dashboard');
    Route::livewire('/cashier/reports', 'pages::user.reports')->name('cashier.reports');
    Route::livewire('/cashier/point-of-sale', 'pages::user.point-of-sale')->name('cashier.pos');
    Route::livewire('/cashier/transactions', 'pages::user.transaction-history')->name('cashier.transactions');
    Route::livewire('/cashier/inventory', 'pages::user.inventory')->name('cashier.inventory');
    Route::livewire('/cashier/user-management', 'pages::user.user-management')->name('cashier.user-management');
    Route::livewire('/cashier/purchase-order', 'pages::user.purchase-order')->name('cashier.purchase-order');
    Route::livewire('/cashier/college-requests', 'pages::user.college-requests')->name('cashier.college-requests');
});

Route::middleware(['auth', 'verified', 'role:staff'])->group(function () {
    Route::livewire('/staff/dashboard', 'pages::user.home')->name('staff.dashboard');
    Route::livewire('/staff/reports', 'pages::user.reports')->name('staff.reports');
    Route::livewire('/staff/point-of-sale', 'pages::user.point-of-sale')->name('staff.pos');
    Route::livewire('/staff/transactions', 'pages::user.transaction-history')->name('staff.transactions');
    Route::livewire('/staff/inventory', 'pages::user.inventory')->name('staff.inventory');
    Route::livewire('/staff/user-management', 'pages::user.user-management')->name('staff.user-management');
    Route::livewire('/staff/purchase-order', 'pages::user.purchase-order')->name('staff.purchase-order');
    Route::livewire('/staff/college-requests', 'pages::user.college-requests')->name('staff.college-requests');
});

require __DIR__.'/settings.php';
