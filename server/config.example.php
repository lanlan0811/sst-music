<?php
// 复制本文件为 config.php 并填写实际值
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'sst_music',
        'user'     => 'sst_music',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],
    // 音源站根地址（末尾不带斜杠）
    // timeout 单次请求上限（秒）：客户端整体超时为 15s，因此不要超过 8
    'source' => [
        'base_url' => 'https://flac.music.hi.cn',
        'timeout'  => 8,
    ],
    // 登录 token 有效期（秒），默认 30 天
    'token_ttl' => 2592000,
    // 定时任务密钥：/api/cron/cleanup?key=xxx
    'cron_key' => 'CHANGE_ME_CRON_KEY',
    // 运行时目录（缓存/日志），需可写
    'runtime_dir' => __DIR__ . '/storage',
];
