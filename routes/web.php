<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('/test', 'test')->name('home');
Route::view('/sign-in', 'authentication.sign-in')->name('sign-in');
Route::view('/sign-up', 'authentication.sign-up')->name('sign-up');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/dashboard', 'pages::user.home')->name('dashboard');
    Route::livewire('/reports', 'pages::user.reports')->name('reports');
    Route::livewire('/point-of-sale', 'pages::user.point-of-sale')->name('pos');
    Route::livewire('/inventory', 'pages::user.inventory')->name('inventory');
    Route::livewire('/user-management', 'pages::user.user-management')->name('user-management');
});

require __DIR__.'/settings.php';
