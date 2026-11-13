<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\RealtimeController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/chat');

/* Authentication ------------------------------------------------------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat');

    // Private file downloads (membership checked in AttachmentPolicy).
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    /* JSON API used by the chat UI (session + CSRF protected) ----------- */
    Route::prefix('api')->group(function () {
        Route::get('/bootstrap', [ChatController::class, 'bootstrap']);
        Route::post('/realtime/token', [RealtimeController::class, 'token'])->middleware('throttle:chat-token');

        // Friends
        Route::get('/friends', [FriendController::class, 'index']);
        Route::get('/users/search', [FriendController::class, 'search'])->middleware('throttle:chat-search');
        Route::post('/friends/requests', [FriendController::class, 'request'])->middleware('throttle:chat-friend-request');
        Route::post('/friends/requests/{friendship}/accept', [FriendController::class, 'accept']);
        Route::delete('/friends/requests/{friendship}', [FriendController::class, 'cancel']);
        Route::delete('/friends/{user}', [FriendController::class, 'destroy']);

        // Conversations & messages
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations/direct', [ConversationController::class, 'direct']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])->middleware('throttle:chat-send');
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read']);
        Route::delete('/messages/{message}', [MessageController::class, 'destroy']);

        // Groups
        Route::post('/groups', [GroupController::class, 'store'])->middleware('throttle:chat-group');
        Route::patch('/groups/{conversation}', [GroupController::class, 'update']);
        Route::delete('/groups/{conversation}', [GroupController::class, 'destroy']);
        Route::post('/groups/{conversation}/members', [GroupController::class, 'addMembers']);
        Route::delete('/groups/{conversation}/members/{user}', [GroupController::class, 'removeMember']);
        Route::patch('/groups/{conversation}/members/{user}', [GroupController::class, 'setRole']);
        Route::post('/groups/{conversation}/transfer', [GroupController::class, 'transfer']);
        Route::post('/groups/{conversation}/leave', [GroupController::class, 'leave']);
    });
});
