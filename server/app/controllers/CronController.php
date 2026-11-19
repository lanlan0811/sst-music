<?php

declare(strict_types=1);

/**
 * 定时任务：清理过期数据（宝塔计划任务每日调用）
 */
class CronController
{
    public static function cleanup(): void
    {
        $key = str_param($_GET, 'key');
        $expected = (string)config('cron_key', '');
        if ($expected === '' || !hash_equals($expected, $key)) {
            fail(403, '无权访问', 403);
        }
        $now = now_s();
        $tokens = Db::execute('DELETE FROM tokens WHERE expires_at < ?', [$now]);
        $attempts = Db::execute('DELETE FROM login_attempts WHERE created_at < ?', [$now - 86400]);
        $cache = Db::execute('DELETE FROM url_cache WHERE expires_at < ?', [$now]);
        $files = FileCache::gc();
        // 24 小时无活动的房间自动关闭
        $rooms = Db::execute('UPDATE rooms SET status = 2 WHERE status = 1 AND updated_at < ?', [$now - 86400]);
        // 清理已关闭房间的队列
        Db::execute(
            'DELETE q FROM room_queue q JOIN rooms r ON r.id = q.room_id WHERE r.status = 2'
        );
        json_out([
            'tokens'  => $tokens,
            'attempts' => $attempts,
            'url_cache' => $cache,
            'file_cache' => $files,
            'rooms'   => $rooms,
        ]);
    }
}
