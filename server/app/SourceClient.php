<?php

declare(strict_types=1);

/**
 * 音源站适配：所有对外部站点 flac.music.hi.cn 的调用都集中在此
 *
 * 协议：POST {base}/ajax.php?act=<act>，x-www-form-urlencoded
 * sign 绑定 platform+songid+time，无法离线计算 → 每次直连前先用 act=song 刷新 sign
 */
class SourceClient
{
    private const PLATFORMS = ['kuwo', 'wyy'];

    /** 音质档 → [format, 接受的最低 bitrate] */
    private const QUALITY_MAP = [
        'flac' => ['format' => 'flac', 'bitrate' => 1000],
        '320'  => ['format' => 'mp3', 'bitrate' => 300],
        '128'  => ['format' => 'mp3', 'bitrate' => 0],
    ];

    private static function post(string $act, array $params): array
    {
        $base = rtrim((string)config('source.base_url', 'https://flac.music.hi.cn'), '/');
        $url = $base . '/ajax.php?act=' . rawurlencode($act);
        $body = http_build_query($params);

        $ch = curl_init($url);
        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)config('source.timeout', 15),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 12; Mobile)',
        ];
        if (is_file(APP_ROOT . '/cacert.pem')) {
            $options[CURLOPT_CAINFO] = APP_ROOT . '/cacert.pem';
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status !== 200) {
            throw new RuntimeException('音源站请求失败: ' . ($err ?: ('HTTP ' . $status)));
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('音源站返回数据无法解析');
        }
        return $data;
    }

    private static function assertPlatform(string $platform): string
    {
        $platform = strtolower($platform);
        if (!in_array($platform, self::PLATFORMS, true)) {
            fail(400, 'platform 仅支持 kuwo 或 wyy');
        }
        return $platform;
    }

    /**
     * 乱码修复：部分条目 UTF-8 字节被按 GBK 解码（如 "姹熷崡"），可逆则还原
     */
    private static function fixEncoding(string $s): string
    {
        if ($s === '' || !preg_match('/[\x{3400}-\x{9FFF}]/u', $s)) {
            return $s;
        }
        $fixed = @mb_convert_encoding($s, 'GBK', 'UTF-8');
        if (is_string($fixed) && $fixed !== '' && mb_check_encoding($fixed, 'UTF-8')) {
            if (preg_match('/[\x{3400}-\x{9FFF}]/u', $fixed)) {
                return $fixed;
            }
        }
        return $s;
    }

    private static function httpsCover(?string $url): string
    {
        $url = (string)$url;
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        return preg_replace('#^http://#i', 'https://', $url) ?: '';
    }

    /**
     * 源站 minfo → 音质列表（按音质从高到低）
     */
    private static function normalizeQualities(array $minfo): array
    {
        $out = [];
        foreach ($minfo as $m) {
            $format = strtolower((string)($m['format'] ?? ''));
            $bitrate = (int)($m['bitrate'] ?? 0);
            if ($format === 'flac') {
                $key = 'flac';
                $label = '无损';
            } elseif ($format === 'mp3' && $bitrate >= 300) {
                $key = '320';
                $label = '320K';
            } else {
                $key = '128';
                $label = '128K';
            }
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'quality' => $key,
                'format'  => $format,
                'bitrate' => $bitrate,
                'label'   => $label,
                'size'    => (string)($m['size'] ?? ''),
            ];
        }
        uksort($out, fn($a, $b) => array_search($a, ['flac', '320', '128'], true) <=> array_search($b, ['flac', '320', '128'], true));
        return array_values($out);
    }

    private static function normalizeSong(array $s): array
    {
        $name = self::fixEncoding((string)($s['name'] ?? ''));
        $artist = self::fixEncoding((string)($s['artist'] ?? ''));
        $album = self::fixEncoding((string)($s['album_name'] ?? ''));
        $qualities = self::normalizeQualities(is_array($s['minfo'] ?? null) ? $s['minfo'] : []);
        return [
            'id'        => (string)($s['id'] ?? ''),
            'name'      => $name,
            'artist'    => $artist,
            'album'     => $album,
            'cover'     => self::httpsCover($s['pic_url'] ?? null),
            'duration'  => (int)($s['duration'] ?? 0),
            'qualities' => $qualities,
        ];
    }

    /**
     * 搜索。返回 ['total'=>int, 'list'=>[ normalized song ]]
     */
    public static function search(string $platform, string $keyword, int $page = 1): array
    {
        $platform = self::assertPlatform($platform);
        $keyword = trim($keyword);
        if ($keyword === '') {
            fail(400, '请输入搜索关键词');
        }
        $page = max(1, $page);
        $cacheKey = 'search:' . $platform . ':' . $page . ':' . $keyword;
        return FileCache::remember($cacheKey, 120, function () use ($platform, $keyword, $page) {
            $resp = self::post('search', [
                'platform' => $platform,
                'keyword'  => $keyword,
                'page'     => $page,
                'size'     => 20,
            ]);
            if ((int)($resp['code'] ?? -1) !== 0) {
                fail(502, '搜索失败：' . (string)($resp['msg'] ?? '未知错误'), 502);
            }
            $data = $resp['data'] ?? [];
            $list = is_array($data['list'] ?? null) ? $data['list'] : [];
            return [
                'total' => (int)($data['total'] ?? count($list)),
                'list'  => array_map([self::class, 'normalizeSong'], $list),
            ];
        });
    }

    /**
     * 通过 act=song 获取最新 time/sign（返回归一化歌曲 + 原始 sign 信息）
     */
    public static function detail(string $platform, string $id): array
    {
        $platform = self::assertPlatform($platform);
        if ($id === '') {
            fail(400, '缺少歌曲 id');
        }
        $resp = self::post('song', ['platform' => $platform, 'id' => $id]);
        $song = $resp['data']['list'][0] ?? null;
        if (!is_array($song)) {
            fail(404, '歌曲不存在或音源暂不可用', 404);
        }
        return [
            'song' => self::normalizeSong($song),
            'time' => (string)($song['time'] ?? ''),
            'sign' => (string)($song['sign'] ?? ''),
        ];
    }

    /**
     * 取播放直链。quality: flac|320|128；缺省按可用的最高音质
     */
    public static function getUrl(string $platform, string $id, string $quality = ''): array
    {
        $platform = self::assertPlatform($platform);
        $quality = $quality !== '' ? strtolower($quality) : 'flac';
        if (!isset(self::QUALITY_MAP[$quality])) {
            fail(400, 'quality 仅支持 flac / 320 / 128');
        }
        $cacheKey = implode(':', ['url', $platform, $id, $quality]);
        return FileCache::remember($cacheKey, 80, function () use ($platform, $id, $quality) {
            $detail = self::detail($platform, $id);
            $qualities = $detail['song']['qualities'];
            $chosen = null;
            foreach ($qualities as $q) {
                if ($q['quality'] === $quality) {
                    $chosen = $q;
                    break;
                }
            }
            // 请求音质不可用 → 降级到最低可用音质
            if ($chosen === null) {
                $chosen = end($qualities) ?: null;
            }
            if ($chosen === null) {
                fail(404, '该歌曲暂无可用音质', 404);
            }
            $resp = self::post('getUrl', [
                'platform' => $platform,
                'songid'   => $id,
                'format'   => $chosen['format'],
                'bitrate'  => (string)$chosen['bitrate'],
                'time'     => $detail['time'],
                'sign'     => $detail['sign'],
            ]);
            if ((int)($resp['code'] ?? -1) !== 0) {
                fail(502, '获取播放地址失败：' . (string)($resp['msg'] ?? '未知错误'), 502);
            }
            $d = $resp['data'] ?? [];
            if (empty($d['url'])) {
                fail(502, '音源未返回播放地址', 502);
            }
            return [
                'url'        => (string)$d['url'],
                'format'     => (string)($d['format'] ?? $chosen['format']),
                'bitrate'    => (int)($d['bitrate'] ?? $chosen['bitrate']),
                'duration'   => (int)($d['duration'] ?? $detail['song']['duration']),
                'quality'    => $chosen['quality'],
                'expires_in' => 80,
            ];
        });
    }

    /**
     * 取歌词（LRC 文本，可能为空字符串）
     */
    public static function getLyric(string $platform, string $id): string
    {
        $platform = self::assertPlatform($platform);
        $cacheKey = 'lyric:' . $platform . ':' . $id;
        return (string)FileCache::remember($cacheKey, 86400, function () use ($platform, $id) {
            $detail = self::detail($platform, $id);
            $resp = self::post('getLyric', [
                'platform' => $platform,
                'songid'   => $id,
                'time'     => $detail['time'],
                'sign'     => $detail['sign'],
            ]);
            if ((int)($resp['code'] ?? -1) !== 0) {
                return '';
            }
            return is_string($resp['data'] ?? null) ? $resp['data'] : '';
        });
    }
}