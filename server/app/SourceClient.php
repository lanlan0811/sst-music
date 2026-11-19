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
        $lastError = '';
        $timeout = (int)config('source.timeout', 15);

        // 音源站偶发 TLS 握手失败/超时：最多重试 2 次
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
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

            if ($raw !== false && $status === 200) {
                $data = json_decode((string)$raw, true);
                if (!is_array($data)) {
                    // 源站偶发返回孤立代理项（\udcXX），PHP 会整包拒绝解析；清洗后重试
                    $data = json_decode(self::sanitizeJson((string)$raw), true);
                }
                if (is_array($data)) {
                    return $data;
                }
                $lastError = '音源站返回数据无法解析';
            } else {
                $lastError = $raw === false ? $err : ('HTTP ' . $status);
            }
            if ($attempt < 3) {
                usleep(300000 * $attempt); // 0.3s / 0.6s 退避
            }
        }
        throw new RuntimeException('音源站请求失败: ' . ($lastError ?: '未知错误'));
    }

    /**
     * 清洗非法 JSON：源站偶发把某个字节写成「孤立代理项」转义（如 \udca6），
     * PHP 的 json_decode 会因整包非法而返回 null。处理方式：
     *  - 合法的 UTF-16 代理对（\ud83d\ude00 这类 emoji）原样保留
     *  - 孤立低位代理项（\udcXX）→ \u0001XX 标记，fixEncoding 再还原成原始字节 0xXX
     *  - 孤立高位代理项（\ud8XX）→ 替换为 '?'
     */
    private static function sanitizeJson(string $raw): string
    {
        $fixed = preg_replace_callback(
            '/\\\\u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}'
            . '|\\\\u[dD][c-fC-F]([0-9a-fA-F]{2})'
            . '|\\\\u[dD][89abAB][0-9a-fA-F]{2}/',
            static function (array $m): string {
                if (strlen($m[0]) === 12) {
                    return $m[0]; // 合法代理对
                }
                if (($m[1] ?? '') !== '') {
                    return '\\u0001' . strtolower($m[1]); // 低位代理项 → 字节标记
                }
                return '?'; // 孤立高位代理项
            },
            $raw
        );
        return is_string($fixed) ? $fixed : $raw;
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
     * 乱码修复：源站偶尔把 UTF-8 字节按 GBK 解码（如「江南」→「姹熷崡」）。
     * 注意「》」等字符被误解码后会变成 €(U+20AC) 等 GBK 无法回编码的符号，
     * 因此按 GB18030 回编码（它是 GBK 的超集，覆盖 €），再校验是否为合法 UTF-8。
     * sanitizeJson 放入的 \u0001xx 标记在此还原为原始字节（\x80-\xFF 段）。
     */
    private static function fixEncoding(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $marked = str_contains($s, "\u{0001}");
        if (!$marked && !preg_match('/[\x{3400}-\x{9FFF}]/u', $s)) {
            return $s;
        }
        foreach (['GB18030', 'GBK'] as $charset) {
            $fixed = @mb_convert_encoding($s, $charset, 'UTF-8');
            if (!is_string($fixed) || $fixed === '') {
                continue;
            }
            if ($marked) {
                $fixed = preg_replace_callback(
                    '/\x01([0-9a-f]{2})/',
                    static fn(array $m): string => chr(hexdec($m[1])),
                    $fixed
                );
                if (!is_string($fixed)) {
                    continue;
                }
            }
            if (!mb_check_encoding($fixed, 'UTF-8')) {
                continue;
            }
            // 修复结果必须仍含中文，且不含替换符，避免把正常中文误伤
            if (!preg_match('/[\x{3400}-\x{9FFF}]/u', $fixed) || str_contains($fixed, "\u{FFFD}")) {
                continue;
            }
            return $fixed;
        }
        return $s;
    }

    /**
     * LRC 歌词乱码修复：源站偶发整段歌词被按 GBK 误解码。
     * 逐行处理，正常中文/英文行经校验后保持原样，不会误伤。
     */
    private static function fixLyric(string $lrc): string
    {
        if ($lrc === '') {
            return $lrc;
        }
        $lines = preg_split('/\r\n|\n|\r/', $lrc);
        if (!is_array($lines)) {
            return $lrc;
        }
        $changed = false;
        foreach ($lines as $i => $line) {
            $fixed = self::fixEncoding($line);
            // 修复失败时清掉 sanitizeJson 留下的控制符标记，避免前端显示异常
            if (str_contains($fixed, "\u{0001}")) {
                $stripped = preg_replace('/\x01[0-9a-f]{2}/', '', $fixed);
                if (is_string($stripped)) {
                    $fixed = $stripped;
                }
            }
            if ($fixed !== $line) {
                $lines[$i] = $fixed;
                $changed = true;
            }
        }
        return $changed ? implode("\n", $lines) : $lrc;
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
            // 源站偶发返回 -1（接口异常），重试最多 3 次
            $resp = null;
            for ($try = 1; $try <= 3; $try++) {
                $resp = self::post('search', [
                    'platform' => $platform,
                    'keyword'  => $keyword,
                    'page'     => $page,
                    'size'     => 20,
                ]);
                if ((int)($resp['code'] ?? -1) === 0) {
                    break;
                }
                if ($try < 3) {
                    usleep(400000 * $try);
                }
            }
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
            // 源站 getUrl 偶发 -1 / -3，重试（-3 时重新取 sign）
            $resp = null;
            for ($try = 1; $try <= 3; $try++) {
                if ($try > 1) {
                    $detail = self::detail($platform, $id);
                }
                $resp = self::post('getUrl', [
                    'platform' => $platform,
                    'songid'   => $id,
                    'format'   => $chosen['format'],
                    'bitrate'  => (string)$chosen['bitrate'],
                    'time'     => $detail['time'],
                    'sign'     => $detail['sign'],
                ]);
                if ((int)($resp['code'] ?? -1) === 0 && !empty($resp['data']['url'])) {
                    break;
                }
                usleep(400000 * $try);
            }
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
            $lrc = is_string($resp['data'] ?? null) ? $resp['data'] : '';
            return self::fixLyric($lrc);
        });
    }
}
