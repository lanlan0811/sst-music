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

    /**
     * @param float|null $deadline 绝对时间戳（microtime(true)），超出后不再发起新的尝试
     */
    private static function post(string $act, array $params, ?float $deadline = null): array
    {
        $base = rtrim((string)config('source.base_url', 'https://flac.music.hi.cn'), '/');
        $url = $base . '/ajax.php?act=' . rawurlencode($act);
        $body = http_build_query($params);
        $lastError = '';
        // 客户端请求超时为 15s，这里必须留出余量：单次最多 8s，整体不超过 2 次
        $timeout = (int)config('source.timeout', 8);
        if ($timeout < 3) {
            $timeout = 3;
        }
        if ($timeout > 8) {
            $timeout = 8;
        }

        // 音源站偶发 TLS 握手失败/超时：最多重试 1 次（共 2 次尝试）
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $attemptTimeout = $timeout;
            if ($deadline !== null) {
                $left = $deadline - microtime(true);
                if ($left <= 1) {
                    $lastError = $lastError !== '' ? $lastError : '处理超时';
                    break;
                }
                $attemptTimeout = (int)min($timeout, max(2, (int)ceil($left)));
            }
            $ch = curl_init($url);
            $options = [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $attemptTimeout,
                CURLOPT_CONNECTTIMEOUT => min(4, $attemptTimeout),
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
            $errno = curl_errno($ch);
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
                // 超时/连接失败重试大概率仍是超时，直接放弃，避免把客户端 15s 超时耗尽
                if ($errno === CURLE_OPERATION_TIMEDOUT || $errno === CURLE_COULDNT_CONNECT) {
                    break;
                }
            }
            if ($attempt < 2) {
                usleep(250000); // 0.25s 退避
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
            // 客户端请求超时为 15s：整体预算 12s，超出后不再发起新的尝试
            $deadline = microtime(true) + 12;
            // 源站偶发返回 -1（接口异常），重试最多 2 次
            $resp = null;
            for ($try = 1; $try <= 2; $try++) {
                try {
                    $resp = self::post('search', [
                        'platform' => $platform,
                        'keyword'  => $keyword,
                        'page'     => $page,
                        'size'     => 20,
                    ], $deadline);
                } catch (RuntimeException $e) {
                    error_log('[sst-music] search ' . $e->getMessage());
                    if ($try >= 2) {
                        fail(502, '搜索服务暂时不可用，请稍后重试或切换平台', 502);
                    }
                    $resp = null;
                }
                if ((int)($resp['code'] ?? -1) === 0) {
                    break;
                }
                if ($try < 2) {
                    usleep(250000);
                }
            }
            if ((int)($resp['code'] ?? -1) !== 0) {
                fail(502, '搜索失败，请稍后重试或切换平台', 502);
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
    public static function detail(string $platform, string $id, ?float $deadline = null): array
    {
        $platform = self::assertPlatform($platform);
        if ($id === '') {
            fail(400, '缺少歌曲 id');
        }
        $resp = self::post('song', ['platform' => $platform, 'id' => $id], $deadline);
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
     *
     * @param bool $refresh true 时跳过缓存读取（仍写回缓存），用于客户端播放失败后的强制重取
     */
    public static function getUrl(string $platform, string $id, string $quality = '', bool $refresh = false): array
    {
        $platform = self::assertPlatform($platform);
        $quality = $quality !== '' ? strtolower($quality) : 'flac';
        if (!isset(self::QUALITY_MAP[$quality])) {
            fail(400, 'quality 仅支持 flac / 320 / 128');
        }
        $cacheKey = implode(':', ['url', $platform, $id, $quality]);
        $ttl = 55;
        if (!$refresh) {
            $cached = FileCache::get($cacheKey);
            if (is_array($cached) && isset($cached['url'])) {
                // 返回真实剩余有效期，避免客户端拿到临期直链
                $elapsed = time() - (int)($cached['_cached_at'] ?? time());
                $cached['expires_in'] = max(0, $ttl - $elapsed);
                unset($cached['_cached_at']);
                return $cached;
            }
        }
        // 客户端请求超时为 15s：整体预算 12s
        $deadline = microtime(true) + 12;
        try {
            $detail = self::detail($platform, $id, $deadline);
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
            // 源站 getUrl 偶发 -1 / -3，重试（-3 时重新取 sign）；最多 2 次以控制总耗时
            $resp = null;
            for ($try = 1; $try <= 2; $try++) {
                if ($try > 1 && microtime(true) < $deadline - 2) {
                    $detail = self::detail($platform, $id, $deadline);
                }
                $resp = self::post('getUrl', [
                    'platform' => $platform,
                    'songid'   => $id,
                    'format'   => $chosen['format'],
                    'bitrate'  => (string)$chosen['bitrate'],
                    'time'     => $detail['time'],
                    'sign'     => $detail['sign'],
                ], $deadline);
                if ((int)($resp['code'] ?? -1) === 0 && !empty($resp['data']['url'])) {
                    break;
                }
                if ($try < 2) {
                    usleep(250000);
                }
            }
        } catch (RuntimeException $e) {
            error_log('[sst-music] getUrl ' . $e->getMessage());
            fail(502, '音源服务暂时不可用，请稍后重试', 502);
        }
        if ((int)($resp['code'] ?? -1) !== 0) {
            fail(502, '获取播放地址失败，请稍后重试', 502);
        }
        $d = $resp['data'] ?? [];
        if (empty($d['url'])) {
            fail(502, '音源未返回播放地址', 502);
        }
        $result = [
            'url'        => (string)$d['url'],
            'format'     => (string)($d['format'] ?? $chosen['format']),
            'bitrate'    => (int)($d['bitrate'] ?? $chosen['bitrate']),
            'duration'   => (int)($d['duration'] ?? $detail['song']['duration']),
            'quality'    => $chosen['quality'],
            'expires_in' => $ttl,
        ];
        FileCache::set($cacheKey, $result + ['_cached_at' => time()], $ttl);
        return $result;
    }

    /**
     * 取歌词（LRC 文本，可能为空字符串）
     */
    public static function getLyric(string $platform, string $id): string
    {
        $platform = self::assertPlatform($platform);
        $cacheKey = 'lyric:' . $platform . ':' . $id;
        return (string)FileCache::remember($cacheKey, 86400, function () use ($platform, $id) {
            // 与搜索/直链保持一致：整体 12s 预算，避免超过客户端 15s 超时
            $deadline = microtime(true) + 12;
            $detail = self::detail($platform, $id, $deadline);
            $resp = self::post('getLyric', [
                'platform' => $platform,
                'songid'   => $id,
                'time'     => $detail['time'],
                'sign'     => $detail['sign'],
            ], $deadline);
            if ((int)($resp['code'] ?? -1) !== 0) {
                return '';
            }
            $lrc = is_string($resp['data'] ?? null) ? $resp['data'] : '';
            return self::fixLyric($lrc);
        });
    }
}
