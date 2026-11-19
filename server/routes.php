<?php

declare(strict_types=1);

// 路由表：所有业务接口
$router = new Router();

// 健康检查
$router->get('/api/health', function () {
    json_out(['ok' => true, 'time' => now_ms()]);
});

// 认证
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/user/me', [AuthController::class, 'me']);
$router->put('/api/user/profile', [AuthController::class, 'updateProfile']);

// 搜索 / 播放 / 歌词
$router->get('/api/search', [SongController::class, 'search']);
$router->get('/api/song/url', [SongController::class, 'url']);
$router->get('/api/song/lyric', [SongController::class, 'lyric']);

// 好友
$router->get('/api/friends', [FriendController::class, 'list']);
$router->get('/api/friends/requests', [FriendController::class, 'requests']);
$router->post('/api/friends/request', [FriendController::class, 'sendRequest']);
$router->post('/api/friends/handle', [FriendController::class, 'handle']);
$router->delete('/api/friends/{id}', [FriendController::class, 'remove']);

// 一起听
$router->post('/api/room/create', [RoomController::class, 'create']);
$router->post('/api/room/join', [RoomController::class, 'join']);
$router->get('/api/room/state', [RoomController::class, 'state']);
$router->post('/api/room/command', [RoomController::class, 'command']);
$router->post('/api/room/leave', [RoomController::class, 'leave']);

// 定时任务
$router->get('/api/cron/cleanup', [CronController::class, 'cleanup']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
