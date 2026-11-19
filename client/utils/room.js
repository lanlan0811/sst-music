// 一起听房间的跨页面操作（首页加歌等），直接走 API，不依赖房间页是否在前台
import { api } from '@/utils/request.js';
import { getToken } from '@/utils/request.js';

export function getCurrentRoomId() {
	try {
		const id = uni.getStorageSync('sst_room_id');
		return id ? String(id) : '';
	} catch (e) {
		return '';
	}
}

/**
 * 把歌曲加入共享歌单
 * @returns {Promise<{ok:boolean,msg:string,data?:any}>}
 */
export async function addSongToRoom(song) {
	if (!getToken()) {
		return { ok: false, msg: '请先登录' };
	}
	const roomId = getCurrentRoomId();
	if (!roomId) {
		return { ok: false, msg: '请先在「一起听」创建或加入房间' };
	}
	try {
		const snap = await api.roomCommand(roomId, -1, 'add_song', {
			platform: song.platform,
			song_id: String(song.id),
			name: song.name || '',
			artist: song.artist || '',
			album: song.album || '',
			cover: song.cover || '',
			duration: song.duration || 0,
			qualities: song.qualities || []
		});
		return { ok: true, msg: '已加入共享歌单', data: snap };
	} catch (e) {
		if (e && (e.code === 403 || e.code === 404 || e.code === 409) && /房间/.test(e.msg || '')) {
			uni.removeStorageSync('sst_room_id');
			uni.removeStorageSync('sst_room_code');
		}
		return { ok: false, msg: (e && e.msg) || '加入失败' };
	}
}
