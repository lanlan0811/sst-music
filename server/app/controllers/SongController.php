<?php

declare(strict_types=1);

/**
 * 搜索 / 播放直链 / 歌词（游客可用）
 */
class SongController
{
    public static function search(): void
    {
        $platform = str_param($_GET, 'platform', 'kuwo');
        $keyword = str_param($_GET, 'keyword');
        $page = int_param($_GET, 'page', 1);
        json_out(SourceClient::search($platform, $keyword, $page));
    }

    public static function url(): void
    {
        $platform = str_param($_GET, 'platform', 'kuwo');
        $id = str_param($_GET, 'id');
        $quality = str_param($_GET, 'quality');
        // refresh=1：客户端播放失败后强制重取，绕过短缓存里的临期直链
        $refresh = str_param($_GET, 'refresh') === '1';
        if ($id === '') {
            fail(400, '缺少歌曲 id');
        }
        json_out(SourceClient::getUrl($platform, $id, $quality, $refresh));
    }

    public static function lyric(): void
    {
        $platform = str_param($_GET, 'platform', 'kuwo');
        $id = str_param($_GET, 'id');
        if ($id === '') {
            fail(400, '缺少歌曲 id');
        }
        json_out(['lrc' => SourceClient::getLyric($platform, $id)]);
    }
}
