<?php

declare(strict_types=1);

/**
 * 注册 / 登录 / 登出 / 用户信息
 */
class AuthController
{
    public static function register(): void
    {
        $in = input();
        $username = str_param($in, 'username');
        $password = (string)($in['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
            fail(400, '账号需为 3-20 位字母、数字或下划线');
        }
        if (strlen($password) < 6 || strlen($password) > 72) {
            fail(400, '密码长度需为 6-72 位');
        }
        $exists = Db::fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        if ($exists) {
            fail(409, '该账号已被注册', 409);
        }
        Db::execute(
            'INSERT INTO users (username, password_hash, nickname, created_at) VALUES (?, ?, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $username, now_s()]
        );
        $userId = Db::lastId();
        $token = Auth::issueToken($userId);
        json_out([
            'token' => $token,
            'user'  => ['id' => $userId, 'username' => $username, 'nickname' => $username],
        ]);
    }

    public static function login(): void
    {
        $in = input();
        $username = str_param($in, 'username');
        $password = (string)($in['password'] ?? '');
        if ($username === '' || $password === '') {
            fail(400, '请输入账号和密码');
        }
        $ip = Auth::clientIp();
        Auth::assertLoginNotLocked($username, $ip);

        $user = Db::fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Auth::recordLoginFailure($username, $ip);
            fail(401, '账号或密码错误', 401);
        }
        Auth::clearLoginFailures($username);
        Db::execute('DELETE FROM tokens WHERE user_id = ? AND expires_at < ?', [(int)$user['id'], now_s()]);
        $token = Auth::issueToken((int)$user['id']);
        json_out([
            'token' => $token,
            'user'  => [
                'id'       => (int)$user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'] !== '' ? $user['nickname'] : $user['username'],
            ],
        ]);
    }

    public static function logout(): void
    {
        Auth::require();
        Auth::revokeCurrent();
        json_out(null);
    }

    public static function me(): void
    {
        $u = Auth::require();
        json_out([
            'id'       => (int)$u['id'],
            'username' => $u['username'],
            'nickname' => $u['nickname'] !== '' ? $u['nickname'] : $u['username'],
            'created_at' => (int)$u['created_at'],
        ]);
    }

    public static function updateProfile(): void
    {
        $u = Auth::require();
        $in = input();
        $nickname = str_param($in, 'nickname');
        if ($nickname === '' || mb_strlen($nickname, 'UTF-8') > 16) {
            fail(400, '昵称不能为空且不超过 16 个字符');
        }
        Db::execute('UPDATE users SET nickname = ? WHERE id = ?', [$nickname, (int)$u['id']]);
        json_out(['nickname' => $nickname]);
    }
}
