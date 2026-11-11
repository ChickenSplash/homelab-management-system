<?php

use App\Http\Controllers\AccountsController;
use Illuminate\Support\Facades\Route;

// The sign-in site, on ACCOUNTS_URL's host only (see bootstrap/app.php).
Route::controller(AccountsController::class)->name('accounts.')->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/login', 'showLogin')->name('login');
    Route::get('/register', 'showRegister')->name('register');
    Route::get('/guest', 'showGuest')->name('guest');
    Route::get('/terms', 'terms')->name('terms');

    Route::middleware('throttle:auth')->group(function () {
        Route::post('/login', 'login')->name('login.store');
        Route::post('/register', 'register')->name('register.store');
    });

    Route::post('/guest', 'guest')->middleware('throttle:guest')->name('guest.store');
    Route::post('/logout', 'logout')->name('logout');
});
