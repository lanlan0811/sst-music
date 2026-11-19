<?php

declare(strict_types=1);

/**
 * 好友：列表 / 请求 / 同意拒绝 / 删除
 */
class FriendController
{
    public static function list(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $rows = Db::fetchAll(
            'SELECT u.id, u.username, u.nickname
               FROM friends f JOIN users u ON u.id = f.friend_id
              WHERE f.user_id = ?
              ORDER BY f.id DESC',
            [$uid]
        );
        // 好友当前所在房间（用于展示"正在一起听"）
        $rooms = Db::fetchAll(
            'SELECT id, code, owner_id, guest_id FROM rooms
              WHERE status = 1 AND (owner_id = ? OR guest_id = ?)',
            [$uid, $uid]
        );
        $friendRoom = [];
        foreach ($rooms as $r) {
            $other = (int)$r['owner_id'] === $uid ? (int)$r['guest_id'] : (int)$r['owner_id'];
            if ($other > 0) {
                $friendRoom[$other] = ['id' => (int)$r['id'], 'code' => $r['code']];
            }
        }
        $list = array_map(function ($r) use ($friendRoom) {
            $room = $friendRoom[(int)$r['id']] ?? null;
            return [
                'id'        => (int)$r['id'],
                'username'  => $r['username'],
                'nickname'  => $r['nickname'] !== '' ? $r['nickname'] : $r['username'],
                'room_id'   => $room['id'] ?? null,
                'room_code' => $room['code'] ?? null,
            ];
        }, $rows);
        json_out(['list' => $list]);
    }

    public static function requests(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $incoming = Db::fetchAll(
            "SELECT fr.id, fr.from_id, fr.status, fr.created_at, u.username, u.nickname
               FROM friend_requests fr JOIN users u ON u.id = fr.from_id
              WHERE fr.to_id = ? AND fr.status = 0
              ORDER BY fr.id DESC",
            [$uid]
        );
        $outgoing = Db::fetchAll(
            "SELECT fr.id, fr.to_id, fr.status, fr.created_at, u.username, u.nickname
               FROM friend_requests fr JOIN users u ON u.id = fr.to_id
              WHERE fr.from_id = ? AND fr.status = 0
              ORDER BY fr.id DESC",
            [$uid]
        );
        $fmt = fn($r, $idKey) => [
            'id'        => (int)$r['id'],
            'user_id'   => (int)$r[$idKey],
            'username'  => $r['username'],
            'nickname'  => $r['nickname'] !== '' ? $r['nickname'] : $r['username'],
            'created_at' => (int)$r['created_at'],
        ];
        json_out([
            'incoming' => array_map(fn($r) => $fmt($r, 'from_id'), $incoming),
            'outgoing' => array_map(fn($r) => $fmt($r, 'to_id'), $outgoing),
        ]);
    }

    public static function sendRequest(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $in = input();
        $username = str_param($in, 'username');
        if ($username === '') {
            fail(400, '请输入对方账号');
        }
        $target = Db::fetchOne('SELECT id, username, nickname FROM users WHERE username = ?', [$username]);
        if (!$target) {
            fail(404, '该账号不存在', 404);
        }
        $tid = (int)$target['id'];
        if ($tid === $uid) {
            fail(400, '不能添加自己为好友');
        }
        $already = Db::fetchOne('SELECT id FROM friends WHERE user_id = ? AND friend_id = ?', [$uid, $tid]);
        if ($already) {
            fail(409, '你们已经是好友了', 409);
        }
        // 对方已向我发过请求 → 直接互相成为好友
        $reverse = Db::fetchOne(
            'SELECT * FROM friend_requests WHERE from_id = ? AND to_id = ? AND status = 0',
            [$tid, $uid]
        );
        if ($reverse) {
            self::makeFriends($uid, $tid);
            Db::execute('UPDATE friend_requests SET status = 1 WHERE id = ?', [(int)$reverse['id']]);
            json_out(['status' => 'accepted']);
        }
        $pending = Db::fetchOne(
            'SELECT id FROM friend_requests WHERE from_id = ? AND to_id = ? AND status = 0',
            [$uid, $tid]
        );
        if ($pending) {
            fail(409, '请求已发送，请等待对方处理', 409);
        }
        // 曾拒绝过则重置为待处理
        Db::execute(
            'INSERT INTO friend_requests (from_id, to_id, status, created_at) VALUES (?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE status = 0, created_at = VALUES(created_at)',
            [$uid, $tid, now_s()]
        );
        json_out(['status' => 'pending']);
    }

    public static function handle(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $in = input();
        $requestId = int_param($in, 'request_id');
        $accept = (bool)($in['accept'] ?? false);
        $req = Db::fetchOne('SELECT * FROM friend_requests WHERE id = ? AND to_id = ? AND status = 0', [$requestId, $uid]);
        if (!$req) {
            fail(404, '请求不存在或已处理', 404);
        }
        $fromId = (int)$req['from_id'];
        if ($accept) {
            self::makeFriends($uid, $fromId);
            Db::execute('UPDATE friend_requests SET status = 1 WHERE id = ?', [$requestId]);
        } else {
            Db::execute('UPDATE friend_requests SET status = 2 WHERE id = ?', [$requestId]);
        }
        json_out(['accepted' => $accept]);
    }

    public static function remove(array $params): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $friendId = (int)($params['id'] ?? 0);
        Db::execute('DELETE FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)', [
            $uid, $friendId, $friendId, $uid,
        ]);
        Db::execute(
            'DELETE FROM friend_requests WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)',
            [$uid, $friendId, $friendId, $uid]
        );
        json_out(null);
    }

    /** 双向写入好友关系 */
    public static function makeFriends(int $a, int $b): void
    {
        Db::execute(
            'INSERT IGNORE INTO friends (user_id, friend_id, created_at) VALUES (?, ?, ?), (?, ?, ?)',
            [$a, $b, now_s(), $b, $a, now_s()]
        );
    }

    public static function areFriends(int $a, int $b): bool
    {
        return Db::fetchOne('SELECT id FROM friends WHERE user_id = ? AND friend_id = ?', [$a, $b]) !== null;
    }
}
