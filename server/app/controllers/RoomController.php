<?php

declare(strict_types=1);

/**
 * 一起听房间：创建 / 加入 / 状态轮询 / 指令 / 退出
 *
 * 同步模型：服务器为唯一权威。room_state.state_at 为服务器毫秒时间戳，
 * 播放中客户端按 position_ms + (server_time - state_at) 估算当前位置。
 * 所有写操作带 version 乐观锁，冲突返回 409 + 最新状态。
 */
class RoomController
{
    private const MAX_QUEUE = 50;

    // ---------- 公共 ----------

    private static function requireRoom(int $roomId, int $uid): array
    {
        $room = Db::fetchOne('SELECT * FROM rooms WHERE id = ?', [$roomId]);
        if (!$room) {
            fail(404, '房间不存在', 404);
        }
        $isOwner = (int)$room['owner_id'] === $uid;
        $isGuest = (int)$room['guest_id'] === $uid;
        if (!$isOwner && !$isGuest) {
            fail(403, '你不在该房间中', 403);
        }
        if ((int)$room['status'] !== 1) {
            fail(409, '房间已关闭', 409);
        }
        return $room;
    }

    private static function stateRow(int $roomId): array
    {
        $row = Db::fetchOne('SELECT * FROM room_state WHERE room_id = ?', [$roomId]);
        if (!$row) {
            Db::execute(
                'INSERT INTO room_state (room_id, queue_index, is_playing, position_ms, state_at, version, updated_by)
                 VALUES (?, 0, 0, 0, ?, 0, 0)',
                [$roomId, now_ms()]
            );
            $row = Db::fetchOne('SELECT * FROM room_state WHERE room_id = ?', [$roomId]);
        }
        return $row;
    }

    private static function queue(int $roomId): array
    {
        $rows = Db::fetchAll('SELECT * FROM room_queue WHERE room_id = ? ORDER BY sort ASC, id ASC', [$roomId]);
        return array_map(function ($r) {
            return [
                'id'        => (int)$r['id'],
                'platform'  => $r['platform'],
                'song_id'   => $r['source_id'],
                'name'      => $r['name'],
                'artist'    => $r['artist'],
                'album'     => $r['album'],
                'cover'     => $r['cover'],
                'duration'  => (int)$r['duration'],
                'qualities' => json_decode((string)$r['qualities'], true) ?: [],
                'added_by'  => (int)$r['added_by'],
            ];
        }, $rows);
    }

    private static function members(array $room): array
    {
        $ids = array_filter([(int)$room['owner_id'], (int)$room['guest_id']]);
        $rows = $ids ? Db::fetchAll(
            'SELECT id, username, nickname FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_values($ids)
        ) : [];
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = [
                'id'       => (int)$r['id'],
                'username' => $r['username'],
                'nickname' => $r['nickname'] !== '' ? $r['nickname'] : $r['username'],
            ];
        }
        return [
            'owner' => $map[(int)$room['owner_id']] ?? null,
            'guest' => (int)$room['guest_id'] > 0 ? ($map[(int)$room['guest_id']] ?? null) : null,
        ];
    }

    private static function snapshot(int $roomId): array
    {
        $room = Db::fetchOne('SELECT * FROM rooms WHERE id = ?', [$roomId]);
        $state = self::stateRow($roomId);
        return [
            'version'       => (int)$state['version'],
            'server_time'   => now_ms(),
            'state'         => [
                'queue_index' => (int)$state['queue_index'],
                'is_playing'  => (int)$state['is_playing'] === 1,
                'position_ms' => (int)$state['position_ms'],
                'state_at'    => (int)$state['state_at'],
                'updated_by'  => (int)$state['updated_by'],
            ],
            'queue'         => self::queue($roomId),
            'members'       => self::members($room),
        ];
    }

    /**
     * 写入新状态并自增 version
     */
    private static function writeState(int $roomId, array $patch, int $uid): int
    {
        $cur = self::stateRow($roomId);
        $data = array_merge([
            'queue_index' => (int)$cur['queue_index'],
            'is_playing'  => (int)$cur['is_playing'],
            'position_ms' => (int)$cur['position_ms'],
        ], $patch);
        Db::execute(
            'UPDATE room_state SET queue_index = ?, is_playing = ?, position_ms = ?, state_at = ?, version = version + 1, updated_by = ?
              WHERE room_id = ?',
            [
                (int)$data['queue_index'],
                (int)$data['is_playing'],
                (int)$data['position_ms'],
                now_ms(),
                $uid,
                $roomId,
            ]
        );
        Db::execute('UPDATE rooms SET updated_at = ? WHERE id = ?', [now_s(), $roomId]);
        return (int)self::stateRow($roomId)['version'];
    }

    /**
     * 快照（含房间码），供 state/command 返回
     */
    private static function fullSnapshot(int $roomId): array
    {
        $snap = self::snapshot($roomId);
        $room = Db::fetchOne('SELECT code FROM rooms WHERE id = ?', [$roomId]);
        $snap['changed'] = true;
        $snap['code'] = $room['code'] ?? '';
        return $snap;
    }

    // ---------- 接口 ----------

    public static function create(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $in = input();
        $friendId = int_param($in, 'friend_id');
        $friend = Db::fetchOne('SELECT id FROM users WHERE id = ?', [$friendId]);
        if (!$friend) {
            fail(404, '好友不存在', 404);
        }
        if (!FriendController::areFriends($uid, $friendId)) {
            fail(403, '只能邀请好友一起听', 403);
        }
        // 我已在其他房间 → 返回该房间而非新建
        $existing = Db::fetchOne(
            'SELECT * FROM rooms WHERE status = 1 AND (owner_id = ? OR guest_id = ?) ORDER BY id DESC LIMIT 1',
            [$uid, $uid]
        );
        if ($existing) {
            json_out(['room_id' => (int)$existing['id'], 'code' => $existing['code'], 'reused' => true]);
        }
        // 好友在别的有效房间里（别人开的或未加入我）→ 阻止重复开房
        $friendRoom = Db::fetchOne(
            'SELECT id FROM rooms WHERE status = 1 AND (owner_id = ? OR guest_id = ?)',
            [$friendId, $friendId]
        );
        if ($friendRoom) {
            fail(409, '对方正在其他一起听房间中', 409);
        }
        for ($try = 0; $try < 5; $try++) {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
            if (Db::fetchOne('SELECT id FROM rooms WHERE code = ?', [$code])) {
                continue;
            }
            Db::tx(function () use ($code, $uid, $friendId) {
                Db::execute(
                    'INSERT INTO rooms (code, owner_id, guest_id, status, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
                    [$code, $uid, $friendId, now_s(), now_s()]
                );
                $roomId = Db::lastId();
                Db::execute(
                    'INSERT INTO room_state (room_id, queue_index, is_playing, position_ms, state_at, version, updated_by)
                     VALUES (?, 0, 0, 0, ?, 0, ?)',
                    [$roomId, now_ms(), $uid]
                );
                return $roomId;
            });
            $room = Db::fetchOne('SELECT id FROM rooms WHERE code = ?', [$code]);
            json_out(['room_id' => (int)$room['id'], 'code' => $code, 'reused' => false]);
        }
        fail(500, '创建房间失败，请重试', 500);
    }

    public static function join(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $in = input();
        $code = strtoupper(str_param($in, 'code'));
        if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
            fail(400, '请输入 6 位邀请码');
        }
        $room = Db::fetchOne('SELECT * FROM rooms WHERE code = ?', [$code]);
        if (!$room) {
            fail(404, '邀请码无效', 404);
        }
        if ((int)$room['status'] !== 1) {
            fail(409, '该房间已关闭', 409);
        }
        $ownerId = (int)$room['owner_id'];
        if ($ownerId === $uid) {
            json_out(['room_id' => (int)$room['id']]);
        }
        if (!FriendController::areFriends($uid, $ownerId)) {
            fail(403, '只有房主的好友才能加入', 403);
        }
        if ((int)$room['guest_id'] !== 0 && (int)$room['guest_id'] !== $uid) {
            fail(409, '房间已满', 409);
        }
        // 我仍在其他房间 → 拒绝
        $mine = Db::fetchOne(
            'SELECT id FROM rooms WHERE status = 1 AND id <> ? AND (owner_id = ? OR guest_id = ?)',
            [(int)$room['id'], $uid, $uid]
        );
        if ($mine) {
            fail(409, '请先退出当前房间', 409);
        }
        if ((int)$room['guest_id'] === 0) {
            Db::execute('UPDATE rooms SET guest_id = ?, updated_at = ? WHERE id = ?', [$uid, now_s(), (int)$room['id']]);
        }
        json_out(['room_id' => (int)$room['id']]);
    }

    public static function state(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $roomId = int_param($_GET, 'room_id');
        $knownVersion = int_param($_GET, 'v', -1);
        self::requireRoom($roomId, $uid);
        $state = self::stateRow($roomId);
        if ($knownVersion === (int)$state['version']) {
            json_out(['changed' => false, 'version' => (int)$state['version'], 'server_time' => now_ms()]);
        }
        json_out(self::fullSnapshot($roomId));
    }

    public static function command(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $in = input();
        $roomId = int_param($in, 'room_id');
        $version = int_param($in, 'version', -1);
        $action = str_param($in, 'action');
        $payload = is_array($in['payload'] ?? null) ? $in['payload'] : [];
        self::requireRoom($roomId, $uid);

        $state = self::stateRow($roomId);
        if ($version >= 0 && $version !== (int)$state['version']) {
            fail(409, '状态已更新，请重试', 409, self::fullSnapshot($roomId));
        }

        $queue = self::queue($roomId);
        $queueIndex = (int)$state['queue_index'];
        $isPlaying = (int)$state['is_playing'];
        $position = (int)$state['position_ms'];

        switch ($action) {
            case 'add_song':
                if (count($queue) >= self::MAX_QUEUE) {
                    fail(409, '共享歌单已满（最多 ' . self::MAX_QUEUE . ' 首）', 409);
                }
                $platform = str_param($payload, 'platform', 'kuwo');
                $songId = str_param($payload, 'song_id');
                $name = str_param($payload, 'name');
                if ($songId === '' || $name === '') {
                    fail(400, '缺少歌曲信息');
                }
                $maxSort = Db::fetchOne('SELECT MAX(sort) AS m FROM room_queue WHERE room_id = ?', [$roomId]);
                $sort = (int)($maxSort['m'] ?? 0) + 1;
                Db::execute(
                    'INSERT INTO room_queue (room_id, sort, platform, source_id, name, artist, album, cover, duration, qualities, added_by, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $roomId,
                        $sort,
                        strtolower($platform) === 'wyy' ? 'wyy' : 'kuwo',
                        $songId,
                        mb_substr($name, 0, 200, 'UTF-8'),
                        mb_substr(str_param($payload, 'artist'), 0, 200, 'UTF-8'),
                        mb_substr(str_param($payload, 'album'), 0, 200, 'UTF-8'),
                        substr(str_param($payload, 'cover'), 0, 500),
                        int_param($payload, 'duration'),
                        json_encode($payload['qualities'] ?? [], JSON_UNESCAPED_UNICODE),
                        $uid,
                        now_s(),
                    ]
                );
                self::writeState($roomId, [], $uid);
                break;

            case 'remove_song':
                $itemId = int_param($payload, 'item_id', int_param($payload, 'index', -1));
                $removed = null;
                if (isset($payload['item_id'])) {
                    $removed = Db::fetchOne('SELECT * FROM room_queue WHERE id = ? AND room_id = ?', [$itemId, $roomId]);
                } elseif ($itemId >= 0 && isset($queue[$itemId])) {
                    $removed = Db::fetchOne('SELECT * FROM room_queue WHERE id = ?', [(int)$queue[$itemId]['id']]);
                }
                if (!$removed) {
                    fail(404, '歌曲不在队列中', 404);
                }
                Db::execute('DELETE FROM room_queue WHERE id = ?', [(int)$removed['id']]);
                $newQueue = self::queue($roomId);
                $newIndex = $queueIndex;
                if ($queueIndex >= count($newQueue)) {
                    $newIndex = max(0, count($newQueue) - 1);
                }
                self::writeState($roomId, ['queue_index' => $newIndex], $uid);
                break;

            case 'play':
                if (!$queue) {
                    fail(409, '共享歌单为空', 409);
                }
                $index = int_param($payload, 'index', $queueIndex);
                $position = isset($payload['position_ms']) ? max(0, int_param($payload, 'position_ms')) : $position;
                self::writeState($roomId, [
                    'is_playing'  => 1,
                    'queue_index' => min($index, count($queue) - 1),
                    'position_ms' => $position,
                ], $uid);
                break;

            case 'pause':
                $position = isset($payload['position_ms']) ? max(0, int_param($payload, 'position_ms')) : $position;
                self::writeState($roomId, ['is_playing' => 0, 'position_ms' => $position], $uid);
                break;

            case 'seek':
                $target = max(0, int_param($payload, 'position_ms'));
                self::writeState($roomId, ['position_ms' => $target], $uid);
                break;

            case 'next':
            case 'prev':
                if (!$queue) {
                    fail(409, '共享歌单为空', 409);
                }
                $step = $action === 'next' ? 1 : -1;
                $idx = max(0, min(count($queue) - 1, $queueIndex + $step));
                self::writeState($roomId, [
                    'queue_index' => $idx,
                    'position_ms' => 0,
                    'is_playing'  => $isPlaying,
                ], $uid);
                break;

            case 'jump':
                if (!$queue) {
                    fail(409, '共享歌单为空', 409);
                }
                $idx = int_param($payload, 'index', 0);
                if ($idx < 0 || $idx >= count($queue)) {
                    fail(400, '队列索引无效');
                }
                self::writeState($roomId, [
                    'queue_index' => $idx,
                    'position_ms' => 0,
                    'is_playing'  => 1,
                ], $uid);
                break;

            default:
                fail(400, '未知指令');
        }

        json_out(self::fullSnapshot($roomId));
    }

    public static function leave(): void
    {
        $u = Auth::require();
        $uid = (int)$u['id'];
        $in = input();
        $roomId = int_param($in, 'room_id');
        $room = Db::fetchOne('SELECT * FROM rooms WHERE id = ?', [$roomId]);
        if (!$room) {
            fail(404, '房间不存在', 404);
        }
        $isOwner = (int)$room['owner_id'] === $uid;
        $isGuest = (int)$room['guest_id'] === $uid;
        if (!$isOwner && !$isGuest) {
            fail(403, '你不在该房间中', 403);
        }
        if ($isOwner) {
            // 房主退出 → 关闭房间
            Db::execute('UPDATE rooms SET status = 2, updated_at = ? WHERE id = ?', [now_s(), $roomId]);
        } else {
            Db::execute('UPDATE rooms SET guest_id = NULL, updated_at = ? WHERE id = ?', [now_s(), $roomId]);
        }
        json_out(null);
    }
}
