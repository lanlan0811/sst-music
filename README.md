# 随身听 sst-music

一款 Android 音乐 App：搜索、在线播放、歌词、离线下载，以及双人「一起听」。音源来自第三方公开站点 `flac.music.hi.cn`，由自建 PHP 后端代理转发。

- **客户端**：uni-app + Vue 3（HBuilderX 打包 Android，最低 API 31 / Android 12），手机与平板自适应
- **服务端**：PHP 8（零 Composer 依赖）+ MySQL 5.7/8.0，文件缓存，无 Redis
- **部署**：宝塔面板，2 核 2G 即可运行，详见 [docs/deploy-bt.md](docs/deploy-bt.md)
- **协议**：非商业开源协议，禁止商业使用，详见 [LICENSE](LICENSE)

## 功能

| 模块 | 说明 |
|---|---|
| 搜索 | 酷我 / 网易双平台切换，分页加载，记忆上次搜索 |
| 播放 | 全局单例播放器、迷你播放条、顺序/单曲/随机三种模式 |
| 音质 | FLAC / 320K / 128K 可选，缺省无损，音源不支持时自动降级 |
| 歌词 | LRC 解析、逐行高亮、翻译行合并、随播放自动滚动 |
| 下载 | App 端下载音乐与歌词到应用私有目录，离线播放优先使用本地文件 |
| 账号 | 注册 / 登录 / 登出 / 修改昵称，Token 有效期 30 天，登录失败限流 |
| 好友 | 按用户名添加、处理请求、删除好友，可看到好友是否正在一起听 |
| 一起听 | 双人共享歌单（上限 50 首），邀请码/邀请好友入房，播放进度 1.5s 同步 |

## 目录结构

```
sst-music/
├── client/                  # uni-app 客户端（HBuilderX 导入此目录）
│   ├── pages/               # index 搜索 / player 播放 / room 一起听 / friends 好友
│   │                        # downloads 下载 / profile 我的 / login 登录注册
│   ├── components/          # SongItem / MiniPlayer / QualityPicker / PageWrap
│   ├── store/               # user.js / player.js（Vue reactive，未使用 Pinia）
│   ├── utils/               # request 请求封装 / audio 播放引擎 / lyrics LRC 解析
│   │                        # download 下载 / room 房间操作 / layout 屏幕适配
│   ├── static/              # 图标、logo、封面占位图
│   ├── config.js            # 服务器地址解析逻辑与常量
│   ├── config.local.example.js # 本地配置模板（复制为 config.local.js 填写，不入库）
│   └── manifest.json        # 包名、权限、minSdk 31 / targetSdk 33
├── server/                  # PHP 后端
│   ├── public/index.php     # Web 根目录入口
│   ├── routes.php           # 全部路由
│   ├── app/
│   │   ├── bootstrap.php    # 配置加载、自动加载、异常处理、CORS、工具函数
│   │   ├── Db.php           # PDO 封装
│   │   ├── Auth.php         # Bearer Token 校验 / 下发 / 登录限流
│   │   ├── Router.php       # 轻量路由
│   │   ├── FileCache.php    # 文件缓存（搜索/直链/歌词）
│   │   ├── SourceClient.php # 音源站适配（搜索/详情/直链/歌词）
│   │   └── controllers/     # Auth / Song / Friend / Room / Cron
│   ├── sql/schema.sql       # 数据库结构（9 张表）
│   ├── config.example.php   # 配置模板
│   ├── cacert.pem           # 音源站 HTTPS 校验证书
│   └── deploy/nginx.conf    # Nginx 参考配置
└── docs/
    ├── api.md               # 接口文档
    └── deploy-bt.md         # 宝塔部署全流程
```

## 快速开始

### 后端

依赖 PHP 8 扩展：`curl`、`mbstring`、`openssl`、`pdo_mysql`。

```bash
cd server
cp config.example.php config.php        # 填写数据库账号密码、cron_key
mysql -u user -p sst_music < sql/schema.sql
php -S 127.0.0.1:8100 -t public
curl http://127.0.0.1:8100/api/health   # {"code":0,"msg":"ok",...}
```

`config.php` 具体字段：

| 键 | 说明 |
|---|---|
| `db.*` | MySQL 连接信息 |
| `source.base_url` / `source.timeout` | 音源站地址与超时（默认 15s） |
| `token_ttl` | 登录态有效期（秒），默认 30 天 |
| `cron_key` | 定时清理接口密钥 |
| `runtime_dir` | 缓存目录，默认 `server/storage`（需可写） |

生产部署见 [docs/deploy-bt.md](docs/deploy-bt.md)。

### 客户端

1. HBuilderX → 文件 → 导入 → 选择 **`client/`** 目录（不是仓库根目录）
2. **HBuilderX 版本必须与手机端 SDK 一致**：使用 **5.34 或更高** 打开工程。用旧版本编译器打出的包，在较新的手机上会提示「本应用使用 HBuilderX x.xx 编译，而手机端 SDK 版本是 y.yy」，并可能引起运行时异常。仓库不锁定 HBuilderX 版本，升级后请注意重新打包，不要复用旧产物
3. 打包前清理 `client/unpackage/dist/build`、`client/unpackage/cache/wgt`、`client/unpackage/cache/apk`（均已被 `.gitignore` 忽略），避免旧版本编译产物被复用
4. 服务器地址**不写入仓库**：复制 `client/config.local.example.js` 为 `client/config.local.js`，把 `LOCAL_BASE_URL` 改为线上地址，例如 `https://music.example.com/api`（末尾不带 `/`）。`config.local.js` 已被 `.gitignore` 忽略，不会提交
5. 运行到浏览器调试，或「发行 → 原生 App - 云打包」生成 APK
6. 打包后校验：`client/unpackage/dist/build/app-plus/manifest.json` 中的 `compilerVersion` 应与本机 HBuilderX 版本一致，否则说明仍在用旧产物

本地联调：H5 在 `localhost` / `127.0.0.1` 访问时会自动指向 `http://127.0.0.1:8100/api`，无需改配置；后端已内置 CORS（`server/app/bootstrap.php` 的 `send_cors()`）。

地址优先级：App 内「我的 → 服务器地址」 > `config.local.js` > 本机调试地址 > `DEFAULT_BASE_URL`（占位默认值，仅作兜底，打包前请务必提供 `config.local.js`）。

## 接口概览

统一响应 `{"code":0,"msg":"ok","data":...}`，需要登录的接口带 `Authorization: Bearer <token>`。

| 分组 | 接口 |
|---|---|
| 基础 | `GET /api/health` |
| 账号 | `POST /api/auth/register`、`/login`、`/logout`；`GET /api/user/me`；`PUT /api/user/profile` |
| 音源 | `GET /api/search`、`/api/song/url`、`/api/song/lyric`（游客可用） |
| 好友 | `GET /api/friends`、`/requests`；`POST /request`、`/handle`；`DELETE /api/friends/{id}` |
| 一起听 | `POST /api/room/create`、`/join`、`/command`、`/leave`；`GET /api/room/state` |
| 定时 | `GET /api/cron/cleanup?key=<cron_key>` |

完整参数、返回示例与错误码见 [docs/api.md](docs/api.md)。

错误码约定：`400` 参数错误、`401` 未登录、`403` 无权限、`404` 不存在、`409` 冲突（含房间版本过期）、`429` 限流、`500` 服务端错误、`502` 音源站异常。

## 音源说明

音源站接口为 `POST https://flac.music.hi.cn/ajax.php?act=<act>`，签名 `sign` 绑定 `platform+songid+time`，无法离线复算，因此全部由后端 `SourceClient.php` 现取现用：

- 客户端**直接播放音源站直链**，后端不代理音频流；直链时效极短
- 直链缓存 **55 秒**（源站防盗链短时效）。客户端忽略缓存也行：播放失败时会自动**降级音质**（flac → 320 → 128）并带 `refresh=1` **强制重取**，绕开可能已临期的缓存直链
- 歌词缓存 **24 小时**，搜索缓存 **2 分钟**
- 单次源站请求超时 **8 秒**、最多 2 次尝试，接口整体预算 12 秒，以配合客户端 15 秒超时
- 音源站偶发 TLS 失败/返回 `-1`，内部重试并退避
- 部分中文关键词在源站返回空结果或乱码，后端已做可逆编码修复（GBK 误判还原），客户端提示切换平台或换关键词
- 仅支持 `kuwo`（酷我）与 `wyy`（网易）两个平台；封面统一转为 `https://`

## 一起听同步模型

- 服务器是唯一权威：`room_state` 保存 `queue_index / is_playing / position_ms / state_at / version`
- 客户端每 **1.5s** 轮询 `/api/room/state`，带上本地已知 `version`；未变化时服务端只返回 `{"changed":false}` 省流量
- 播放中当前进度 = `position_ms + (本机时间 + 时钟偏移 - state_at)`，漂移超过 **1.5s** 时自动 seek 校准
- 所有写操作带 `version` 乐观锁，冲突返回 `409` 并附带最新快照，客户端直接套用
- 房主退出即关闭房间，游客再轮询会收到 `409`；24 小时无活动的房间由定时任务关闭

## 定时清理

宝塔计划任务每日访问 `GET /api/cron/cleanup?key=<cron_key>`，清理：过期 token、超过 24h 的登录失败记录、过期 DB/文件缓存、24h 无活动的房间及其队列。

## 常见问题

| 现象 | 排查 |
|---|---|
| `/api/health` 404 | Nginx 运行目录未设为 `server/public`，或未配置伪静态 |
| 返回「服务端未配置」 | 未复制 `config.example.php` 为 `config.php` |
| 搜索 502 / SSL certificate problem | `cacert.pem` 缺失或未启用 openssl |
| 数据库连接被拒绝 | `config.php` 数据库信息错误或 MySQL 未启动 |
| H5 请求跨域失败 | 确认后端经 `bootstrap.php` 引导，CORS 头已输出 |
| 下载按钮无反应 | 下载仅支持 App 端（依赖 `plus.downloader`） |
| 音源接口失效 | 源站改版，只需调整 `server/app/SourceClient.php` |

## 开源协议

本项目采用自拟的 **非商业开源协议**（详见 [LICENSE](LICENSE)），核心两条：

**① 严禁商业化（强制性）**

- ❌ 任何商业用途一律禁止，**无任何例外**：售卖、付费上架应用商店、广告/内购/导流变现、付费托管或 SaaS、咨询与有偿定制、企业内部经营性使用、受委托代建代运维
- ❌ 不因“免费提供”“内测”“非公开”“仅学习交流”而豁免
- 确需商用须与作者另行签订书面商业授权协议

**② 二次改版必须完整开源并注明来源（强制性）**

- 任何分发（含打包 APK、二进制、容器镜像、在线服务）必须提供**完整可编译的对应源代码**（含全部修改），不得删减、混淆、加密，并公开可长期访问的获取地址
- 必须在 README、App「关于/我的」页、发布页面等处显著注明：原项目「随身听 sst-music」、原作者 **lanlan0811**、原仓库 <https://github.com/lanlan0811/sst-music>、本协议名称与链接
- 不得删除或替换原作者署名；改版可追加「本版本由 XXX 基于原项目修改」
- 不得暗示改版为官方原版或获得原作者背书
- 衍生作品须继续以本协议授权

**允许**：个人学习、研究、教学、公益与非营利项目可自由使用、修改、分发。

违反上述任一条款，授权立即自动终止，作者有权要求下架、公开致歉并追究法律责任。

## 免责声明

本项目仅用于个人学习与技术研究。代码按“现状”提供，不附带任何担保。音源来自第三方公开资源，音乐、歌词、封面等内容的版权归各音乐平台及权利人所有，不在本协议授权范围内；请遵守音源站条款及当地法律法规，勿用于商业用途。
