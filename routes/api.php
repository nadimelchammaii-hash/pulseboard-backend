<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\TaskCommentController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\WorkspaceMemberController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

require __DIR__.'/channels.php';

Route::middleware('throttle:api')->group(function () {

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request));

        Route::get('/user', [ProfileController::class, 'show']);
        Route::put('/user/profile', [ProfileController::class, 'update']);
        Route::put('/user/password', [ProfileController::class, 'updatePassword']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        Route::apiResource('workspaces', WorkspaceController::class)->except(['create', 'edit']);

        Route::get('/workspaces/{workspace}/activities', [ActivityController::class, 'index']);

        Route::get('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index']);
        Route::post('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store']);
        Route::patch('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'update']);
        Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy']);

        Route::apiResource('workspaces.projects', ProjectController::class)->except(['create', 'edit']);

        Route::get('/workspaces/{workspace}/projects/{project}/members', [ProjectMemberController::class, 'index']);
        Route::post('/workspaces/{workspace}/projects/{project}/members', [ProjectMemberController::class, 'store']);
        Route::delete('/workspaces/{workspace}/projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy']);

        Route::apiResource('workspaces.projects.tasks', TaskController::class)->except(['create', 'edit']);
        Route::patch('/workspaces/{workspace}/projects/{project}/tasks/{task}/move', [TaskController::class, 'move']);

        Route::get('/workspaces/{workspace}/projects/{project}/tasks/{task}/comments', [TaskCommentController::class, 'index']);
        Route::post('/workspaces/{workspace}/projects/{project}/tasks/{task}/comments', [TaskCommentController::class, 'store']);
        Route::delete('/workspaces/{workspace}/projects/{project}/tasks/{task}/comments/{comment}', [TaskCommentController::class, 'destroy']);
    });

});
