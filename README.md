# 随身听 sst-music

Android 音乐 App「随身听」：音源来自第三方公开站点 `flac.music.hi.cn`，支持搜索、播放、下载（音乐 + 歌词）和双人「一起听」。

- 客户端：uni-app Vue3（HBuilderX 打包 Android，最低 Android 12 / API 31），手机/平板自适应
- 服务端：PHP 8（无 Composer 依赖）+ MySQL
- 部署：宝塔面板，2 核 2G 可跑（详见部署文档）

## 目录结构

```
sst-music/
├── client/            # uni-app 客户端（HBuilderX 打开此目录）
│   ├── pages/         # index 搜索 / player 播放 / room 一起听 / friends 好友 / downloads 下载 / profile 我的 / login
│   ├── components/    # SongItem / MiniPlayer / QualityPicker / PageWrap
│   ├── store/         # user / player（Vue reactive，无 Pinia）
│   ├── utils/         # request / audio / lyrics / download / layout
│   ├── config.js      # 服务器地址（BASE_URL）
│   └── manifest.json  # 包名、权限、minSdk 31
├── server/            # PHP 后端
│   ├── public/        # Web 根目录（运行目录）
│   ├── app/           # bootstrap / Db / Auth / Router / FileCache / SourceClient / controllers
│   ├── sql/schema.sql # 数据库结构
│   ├── config.example.php
│   └── deploy/nginx.conf
└── docs/
    ├── deploy-bt.md   # 宝塔部署全流程
    └── api.md         # 接口文档
```

## 快速开始

### 后端

```bash
cd server
cp config.example.php config.php   # 填写数据库账号密码和 cron_key
mysql -u user -p sst_music < sql/schema.sql
php -S 127.0.0.1:8000 -t public
curl http://127.0.0.1:8000/api/health
```

> 需要 PHP 扩展：curl、mbstring、openssl、pdo_mysql。`cacert.pem` 已随仓库提供，用于音源站 HTTPS 校验。

生产部署见 [docs/deploy-bt.md](docs/deploy-bt.md)。

### 客户端

1. HBuilderX → 导入 → 选择 `client/` 目录
2. 修改 `client/config.js` 中的 `DEFAULT_BASE_URL` 为你的后端地址（如 `https://music.example.com/api`）
3. 运行到浏览器（H5）调试，或「发行 → 原生 App - 云打包」生成 APK

打包配置已在 `manifest.json` 设置好：`minSdkVersion=31`、`targetSdkVersion=33`，仅申请网络/唤醒锁权限（下载写入应用私有目录，无需存储权限）。

## 音源说明

音源站接口为 `POST https://flac.music.hi.cn/ajax.php?act=<act>`，签名 `sign` 绑定 `platform+songid+time`，无法离线复算，因此全部由后端 `SourceClient.php` 现取现用并做短缓存（直链 80s、歌词 24h、搜索 2min）。

站点只支持 `kuwo`（酷我）与 `wyy`（网易）两个平台；个别中文关键词在源站返回空结果或乱码，后端已做乱码修复，客户端提供平台切换与空结果提示。

## 一起听

- 双人房间，需先互加好友；房主创建房间生成 6 位邀请码，或直接邀请好友
- 共享歌单（最多 50 首），双方均可增删、切歌、暂停、拖动
- HTTP 轮询 1.5s 同步，服务器为唯一权威（`version` 乐观锁，漂移 >1.5s 自动校准）

## 免责声明

本项目仅用于个人学习与技术研究。音源来自第三方公开资源，请遵守音源站条款及当地法律法规，勿用于商业用途。