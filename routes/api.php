<?php

use App\Http\Controllers\AmsController as C;
use App\Http\Controllers\FaceController;
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
    Route::put('/work-slots/{kind}/{id}', [C::class, 'saveSlot']);
    Route::get('/attendance', [C::class, 'attendance']);
    Route::post('/attendance', [C::class, 'punch'])->middleware('throttle:ams-face');
    Route::get('/attendance/{id}/photo/{side}', [C::class, 'photo']);
    Route::get('/teams', [C::class, 'teams']);
    Route::post('/teams', [C::class, 'saveTeam']);
    Route::put('/teams/{id}/members', [C::class, 'members']);
    Route::get('/users', [C::class, 'users']);
    Route::post('/users/import', [C::class, 'importUsers']);
    Route::delete('/users/{id}', [C::class, 'deleteUser']);
    Route::get('/applications/{id}/document', [C::class, 'document']);
    Route::post('/users', [C::class, 'saveUser']);
    Route::put('/users/{id}', [C::class, 'saveUser']);
    Route::post('/face/challenges', [FaceController::class, 'start'])->middleware('throttle:ams-face');
    Route::post('/face/challenges/{id}/step', [FaceController::class, 'step'])->middleware('throttle:ams-face');
    Route::post('/face/challenges/{id}/capture', [FaceController::class, 'capture'])->middleware('throttle:ams-face');
    Route::post('/face/enroll', [FaceController::class, 'enroll'])->middleware('throttle:ams-face');
    Route::delete('/users/{id}/face', [C::class, 'deleteFace']);
    Route::get('/leave-types', [C::class, 'leaveTypes']);
    Route::put('/leave-types/{id}', [C::class, 'saveLeaveType']);
    Route::delete('/leave-types/{id}', [C::class, 'deleteLeaveType']);
    Route::post('/leave-types', [C::class, 'saveLeaveType']);
    Route::get('/calendar', [C::class, 'calendar']);
    Route::post('/calendar', [C::class, 'saveCalendar']);
    Route::delete('/calendar/{id}', [C::class, 'deleteCalendar']);
    Route::get('/audit', [C::class, 'audit']);
});
