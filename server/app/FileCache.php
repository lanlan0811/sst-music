<?php

declare(strict_types=1);

/**
 * 文件缓存（用于直链/搜索结果短缓存），避免依赖 Redis
 */
class FileCache
{
    private static function dir(): string
    {
        $dir = runtime_dir() . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . hash('sha256', $key) . '.json';
    }

    public static function get(string $key)
    {
        $file = self::path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['expires_at'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $data['value'];
    }

    public static function set(string $key, $value, int $ttl): void
    {
        $file = self::path($key);
        @file_put_contents(
            $file,
            json_encode(['expires_at' => time() + $ttl, 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    public static function remember(string $key, int $ttl, callable $fn)
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $fn();
        if ($value !== null) {
            self::set($key, $value, $ttl);
        }
        return $value;
    }

    public static function gc(): int
    {
        $n = 0;
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $data = json_decode((string)$raw, true);
            if (!is_array($data) || ($data['expires_at'] ?? 0) < time()) {
                @unlink($file);
                $n++;
            }
        }
        return $n;
    }
}
