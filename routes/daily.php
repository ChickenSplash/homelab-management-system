<?php

use App\Http\Controllers\DailyController;
use Illuminate\Support\Facades\Route;

Route::controller(DailyController::class)->group(function () {
    Route::get('/bootstrap', 'bootstrap');
    Route::patch('/settings', 'settings');
    Route::get('/calendar', 'calendar');
    Route::get('/days/{date}', 'day');
    Route::get('/export', 'export');
    Route::post('/journals', 'createJournal');
    Route::patch('/journals/{id}', 'updateJournal');
    Route::delete('/journals/{id}', 'deleteJournal');
    Route::put('/journals/{id}/entries/{date}', 'saveEntry');
    Route::post('/activities', 'createActivity');
    Route::patch('/activities/{id}', 'updateActivity');
    Route::put('/activities/{id}/schedule', 'schedule');
    Route::put('/days/{date}/activities/{id}', 'saveActivityDay');
});
