<?php

use App\Http\Controllers\AmsController as C;
use Illuminate\Support\Facades\Route;

Route::post('/login', [C::class, 'login'])->middleware('throttle:ams-login');
Route::middleware(['token', 'throttle:ams-api'])->group(function () {
    Route::get('/me', [C::class, 'me']);
    Route::post('/logout', [C::class, 'logout']);
    Route::put('/password', [C::class, 'password']);
    Route::get('/dashboard', [C::class, 'dashboard']);
    Route::get('/applications', [C::class, 'applications']);
    Route::post('/applications', [C::class, 'apply']);
    Route::post('/applications/{id}/decision', [C::class, 'decision']);
    Route::post('/applications/{id}/cancel', [C::class, 'cancel']);
    Route::get('/attendance', [C::class, 'attendance']);
    Route::post('/attendance', [C::class, 'punch'])->middleware('throttle:ams-face');
    Route::get('/attendance/{id}/photo/{side}', [C::class, 'photo']);
    Route::get('/teams', [C::class, 'teams']);
    Route::post('/teams', [C::class, 'saveTeam']);
    Route::put('/teams/{id}/members', [C::class, 'members']);
    Route::get('/users', [C::class, 'users']);
    Route::post('/users', [C::class, 'saveUser']);
    Route::put('/users/{id}', [C::class, 'saveUser']);
    Route::post('/users/{id}/face', [C::class, 'enroll'])->middleware('throttle:ams-face');
    Route::delete('/users/{id}/face', [C::class, 'deleteFace']);
    Route::get('/leave-types', [C::class, 'leaveTypes']);
    Route::post('/leave-types', [C::class, 'saveLeaveType']);
    Route::get('/calendar', [C::class, 'calendar']);
    Route::post('/calendar', [C::class, 'saveCalendar']);
    Route::delete('/calendar/{id}', [C::class, 'deleteCalendar']);
    Route::get('/audit',[C::class, 'audit']);
});
