# 随身听 API 文档

- Base URL：`https://你的域名/api`
- 响应格式：`{"code":0,"msg":"ok","data":...}`；`code=0` 表示成功
- 认证：需要登录的接口带请求头 `Authorization: Bearer <token>`
- 错误码：`400` 参数错误、`401` 未登录、`403` 无权限、`404` 不存在、`409` 冲突、`429` 限流、`500` 服务器错误、`502` 音源站异常

## 1. 基础

### GET /api/health

```json
{"code":0,"msg":"ok","data":{"ok":true,"time":1795061664536}}
```

## 2. 账号

### POST /api/auth/register

请求：`{"username":"alice","password":"secret123"}`

- `username`：3-20 位字母、数字、下划线
- `password`：6-72 位

返回：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "token": "64位hex",
    "user": { "id": 1, "username": "alice", "nickname": "alice" }
  }
}
```

### POST /api/auth/login

请求：`{"username":"alice","password":"secret123"}`；连续失败 5 次锁 15 分钟（429）。

### POST /api/auth/logout

需要登录，使当前 token 失效。

### GET /api/user/me

需要登录。返回 `{id, username, nickname, created_at}`。

### PUT /api/user/profile

需要登录。请求：`{"nickname":"小明"}`（≤16 字）。

## 3. 音源

### GET /api/search?platform=kuwo&keyword=晴天&page=1

- `platform`：`kuwo` 或 `wyy`
- `keyword`：URL 编码
- `page`：从 1 开始，每页 20 条

返回：

```json
{
  "code": 0,
  "data": {
    "total": 3528,
    "list": [
      {
        "id": "539121879",
        "name": "晴天",
        "artist": "何老希",
        "album": "晴天 (女声治愈版)",
        "cover": "https://img1.kuwo.cn/...",
        "duration": 273,
        "qualities": [
          {"quality":"flac","format":"flac","bitrate":2000,"label":"无损","size":"47.94Mb"},
          {"quality":"320","format":"mp3","bitrate":320,"label":"320K","size":"10.42Mb"},
          {"quality":"128","format":"mp3","bitrate":128,"label":"128K","size":"4.17Mb"}
        ]
      }
    ]
  }
}
```

说明：
- 部分中文关键词在源站可能返回 0 条或乱码，客户端应提示切换平台/换关键词
- `cover` 已统一为 `https://`

### GET /api/song/url?platform=kuwo&id=539121879&quality=flac

- `quality`：`flac` / `320` / `128`，缺省 flac；请求音质不可用时自动降级到最低可用音质

返回：

```json
{
  "code": 0,
  "data": {
    "url": "https://...flac",
    "format": "flac",
    "bitrate": 2000,
    "duration": 273,
    "quality": "flac",
    "expires_in": 80
  }
}
```

> 直链有效期短（Token 防盗链），服务端缓存 80 秒。播放失败时重新请求本接口。

### GET /api/song/lyric?platform=kuwo&id=539121879

返回 LRC 文本：

```json
{"code":0,"data":{"lrc":"[ti:]\n[ar:]\n[00:00.00]晴天...\n"}}
```

没有歌词时 `lrc` 为空字符串。

## 4. 好友（全部需要登录）

### GET /api/friends

```json
{
  "code": 0,
  "data": {
    "list": [
      {"id": 2, "username": "bob", "nickname": "bob", "room_id": 1, "room_code": "ABC123"}
    ]
  }
}
```

`room_id`/`room_code` 非空表示好友正在一起听房间中。

### GET /api/friends/requests

```json
{
  "code": 0,
  "data": {
    "incoming": [{"id": 1, "user_id": 2, "username": "bob", "nickname": "bob", "created_at": 1795062000}],
    "outgoing": []
  }
}
```

### POST /api/friends/request

请求：`{"username":"bob"}`

- 对方已向我发过请求时直接成为好友，返回 `{"status":"accepted"}`
- 正常发送返回 `{"status":"pending"}`

### POST /api/friends/handle

请求：`{"request_id":1,"accept":true}`

### DELETE /api/friends/{id}

删除好友（双向）+ 清理历史请求。

## 5. 一起听（全部需要登录）

### POST /api/room/create

请求：`{"friend_id":2}`

- 必须是好友
- 我已在一个房间时直接返回该房间（`reused: true`）
- 好友正在其他房间时返回 409

返回：`{"room_id":1,"code":"ABC123","reused":false}`

### POST /api/room/join

请求：`{"code":"ABC123"}`

- 只有房主的好友可加入
- 房间最多 2 人
- 已在其他房间需先退出（409）

返回：`{"room_id":1}`

### GET /api/room/state?room_id=1&v=3

- `v`：客户端已知的 version；相同时返回 `{"changed":false,"version":3,"server_time":...}` 便于轮询省流

变化时返回完整快照：

```json
{
  "code": 0,
  "data": {
    "changed": true,
    "version": 4,
    "server_time": 1795062458510,
    "code": "ABC123",
    "state": {
      "queue_index": 0,
      "is_playing": true,
      "position_ms": 0,
      "state_at": 1795062458463,
      "updated_by": 1
    },
    "queue": [
      {
        "id": 1, "platform": "kuwo", "song_id": "539121879", "name": "晴天",
        "artist": "何老希", "album": "晴天 (女声治愈版)", "cover": "https://...",
        "duration": 273, "qualities": [...], "added_by": 1
      }
    ],
    "members": {
      "owner": {"id":1,"username":"alice","nickname":"alice"},
      "guest": {"id":2,"username":"bob","nickname":"bob"}
    }
  }
}
```

**同步算法**：播放中当前进度 = `position_ms + (本机时间 + 时钟偏移 - state_at)`；建议每 1.5s 轮询，漂移 >1.5s 时 seek 校准。

### POST /api/room/command

请求：

```json
{"room_id":1,"version":4,"action":"play","payload":{}}
```

| action | payload | 说明 |
|---|---|---|
| `add_song` | `platform, song_id, name, artist, album, cover, duration, qualities` | 队列上限 50 |
| `remove_song` | `item_id` | 移除队列项 |
| `play` | `index?, position_ms?` | 播放 |
| `pause` | `position_ms?` | 暂停 |
| `seek` | `position_ms` | 拖动 |
| `next` / `prev` | — | 上/下一首 |
| `jump` | `index` | 播放指定位置 |

成功返回最新快照（同 state）。`version` 过期返回 `409`，`data` 附带最新快照，客户端应直接套用。

### POST /api/room/leave

请求：`{"room_id":1}`；房主退出即关闭房间（其他成员轮询会收到 409）。

## 6. 定时任务

### GET /api/cron/cleanup?key=你的cron_key

清理过期 token、登录失败记录、过期缓存、24h 无活动房间。返回各类清理数量。
