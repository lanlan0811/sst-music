<?php

declare(strict_types=1);

/**
 * 认证：Bearer token 校验、下发、登录限流
 */
class Auth
{
    /** @var array|null 当前请求用户 */
    private static ?array $user = null;

    /**
     * 解析 Authorization: Bearer <token>，成功返回用户行，失败返回 null
     */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $header = $v;
                    break;
                }
            }
        }
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($header), $m)) {
            return null;
        }
        $hash = hash('sha256', strtolower($m[1]));
        $row = Db::fetchOne(
            'SELECT u.id, u.username, u.nickname, u.created_at
               FROM tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.expires_at > ?',
            [$hash, now_s()]
        );
        self::$user = $row;
        return $row;
    }

    /**
     * 要求登录，未登录则 401 退出
     */
    public static function require(): array
    {
        $u = self::user();
        if ($u === null) {
            fail(401, '请先登录', 401);
        }
        return $u;
    }

    /**
     * 为用户下发新 token（原始 token 只在此处出现一次）
     */
    public static function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Db::execute(
            'INSERT INTO tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
            [$userId, hash('sha256', $token), now_s() + (int)config('token_ttl', 2592000), now_s()]
        );
        return $token;
    }

    public static function revokeCurrent(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($header), $m)) {
            Db::execute('DELETE FROM tokens WHERE token_hash = ?', [hash('sha256', strtolower($m[1]))]);
        }
    }

    /**
     * 登录限流检查：15 分钟内失败 >= 5 次则拒绝
     */
    public static function assertLoginNotLocked(string $username, string $ip): void
    {
        $since = now_s() - 900;
        $row = Db::fetchOne(
            'SELECT COUNT(*) AS c FROM login_attempts WHERE username = ? AND created_at > ?',
            [$username, $since]
        );
        if ((int)($row['c'] ?? 0) >= 5) {
            fail(429, '失败次数过多，请 15 分钟后再试', 429);
        }
    }

    public static function recordLoginFailure(string $username, string $ip): void
    {
        Db::execute(
            'INSERT INTO login_attempts (username, ip, created_at) VALUES (?, ?, ?)',
            [$username, $ip, now_s()]
        );
    }

    public static function clearLoginFailures(string $username): void
    {
        Db::execute('DELETE FROM login_attempts WHERE username = ?', [$username]);
    }

    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
