import { reactive } from 'vue';
import * as audio from '@/utils/audio.js';
import { api } from '@/utils/request.js';

const KEY_QUALITY = 'sst_quality';
const KEY_MODE = 'sst_mode';

function loadQuality() {
	try {
		const q = uni.getStorageSync(KEY_QUALITY);
		return ['flac', '320', '128'].includes(q) ? q : 'flac';
	} catch (e) {
		return 'flac';
	}
}

function loadMode() {
	try {
		const m = uni.getStorageSync(KEY_MODE);
		return ['list', 'single', 'shuffle'].includes(m) ? m : 'list';
	} catch (e) {
		return 'list';
	}
}

export const player = reactive({
	queue: [],
	index: -1,
	playing: false,
	loading: false,
	currentTime: 0,
	duration: 0,
	quality: loadQuality(),
	mode: loadMode(),
	remote: false,
	get current() {
		return this.index >= 0 && this.index < this.queue.length ? this.queue[this.index] : null;
	}
});

let playToken = 0;
let pendingSeek = 0;
let remoteMode = false; // 一起听时由房间控制
const trackListeners = [];

export function onTrackChange(fn) {
	trackListeners.push(fn);
	return () => {
		const i = trackListeners.indexOf(fn);
		if (i >= 0) trackListeners.splice(i, 1);
	};
}

audio.on('timeupdate', (t) => {
	player.currentTime = Math.floor((t.currentTime || 0) * 1000);
	if (t.duration > 0) player.duration = Math.floor(t.duration * 1000);
});
audio.on('play', () => (player.playing = true));
audio.on('pause', () => (player.playing = false));
audio.on('stop', () => (player.playing = false));
audio.on('ended', () => {
	if (remoteMode) {
		// 一起听：由房间页广播"本曲结束"，由服务器决定下一首
		uni.$emit('sst_remote_ended');
		return;
	}
	next(true);
});
// 直链过期等错误：自动重取一次
let retried = false;
audio.on('error', async () => {
	if (remoteMode || !player.current) return;
	if (retried) {
		retried = false;
		player.playing = false;
		uni.showToast({ title: '播放失败，请重试', icon: 'none' });
		return;
	}
	retried = true;
	await playAt(player.index, player.currentTime, { retry: true });
	setTimeout(() => (retried = false), 3000);
});

export function setRemoteMode(on) {
	remoteMode = !!on;
	player.remote = remoteMode;
}

export function setQuality(q) {
	if (!['flac', '320', '128'].includes(q)) return;
	player.quality = q;
	uni.setStorageSync(KEY_QUALITY, q);
}

export function cycleMode() {
	const order = ['list', 'single', 'shuffle'];
	const idx = order.indexOf(player.mode);
	player.mode = order[(idx + 1) % order.length];
	uni.setStorageSync(KEY_MODE, player.mode);
	return player.mode;
}

function pickQuality(song, wanted) {
	const qs = (song.qualities || []).map((q) => q.quality);
	if (!qs.length) return wanted;
	if (qs.includes(wanted)) return wanted;
	return qs[qs.length - 1] || wanted;
}

/**
 * 播放队列中的第 index 首
 * @param {number} index
 * @param {number} positionMs 起播位置
 * @param {Object} opts { retry }
 */
export async function playAt(index, positionMs = 0, opts = {}) {
	if (index < 0 || index >= player.queue.length) return;
	const song = player.queue[index];
	player.index = index;
	player.currentTime = positionMs;
	player.duration = (song.duration || 0) * 1000;
	player.loading = true;
	const token = ++playToken;
	trackListeners.forEach((fn) => fn(song));
	notifyBar();

	// 本地下载优先
	const local = getLocalRecord(song.platform, song.id);
	if (local) {
		player.loading = false;
		audio.setSource(toLocalUrl(local.musicPath), { startTime: positionMs / 1000, autoplay: true });
		return;
	}
	try {
		const quality = pickQuality(song, player.quality);
		const data = await api.songUrl(song.platform, song.id, quality);
		if (token !== playToken) return;
		player.loading = false;
		audio.setSource(data.url, { startTime: positionMs / 1000, autoplay: true });
	} catch (e) {
		if (token !== playToken) return;
		player.loading = false;
		player.playing = false;
		uni.showToast({ title: (e && e.msg) || '获取播放地址失败', icon: 'none' });
	}
}

export function toggle() {
	if (!player.current) return;
	if (player.remote) {
		// 一起听模式下不直接控制，交由房间页发指令
		uni.$emit('sst_remote_toggle');
		return;
	}
	if (player.playing) {
		audio.pause();
	} else if (audio.getCurrentTime() > 0) {
		audio.play();
	} else {
		playAt(player.index, player.currentTime);
	}
}

export function seek(ms) {
	player.currentTime = ms;
	audio.seek(ms);
}

export function next(auto = false) {
	if (!player.queue.length) return;
	if (player.mode === 'single' && auto) {
		playAt(player.index, 0);
		return;
	}
	let idx;
	if (player.mode === 'shuffle') {
		idx = Math.floor(Math.random() * player.queue.length);
	} else {
		idx = player.index + 1;
		if (idx >= player.queue.length) idx = 0;
	}
	playAt(idx, 0);
}

export function prev() {
	if (!player.queue.length) return;
	let idx = player.index - 1;
	if (idx < 0) idx = player.queue.length - 1;
	playAt(idx, 0);
}

export function setQueue(list, startIndex = 0, positionMs = 0) {
	player.queue = list || [];
	if (player.queue.length) playAt(Math.min(startIndex, player.queue.length - 1), positionMs);
}

export function stop() {
	playToken++;
	audio.stop();
	player.playing = false;
	player.currentTime = 0;
}

// ---- 本地下载记录（供 player 与下载页共用） ----
export const DOWNLOAD_KEY = 'sst_downloads';

export function getLocalRecord(platform, id) {
	try {
		const list = uni.getStorageSync(DOWNLOAD_KEY) || [];
		return list.find((r) => r.platform === platform && String(r.id) === String(id)) || null;
	} catch (e) {
		return null;
	}
}

export function toLocalUrl(path) {
	// #ifdef APP-PLUS
	try {
		return plus.io.convertLocalFileSystemURL(path) || path;
	} catch (e) {
		return path;
	}
	// #endif
	return path;
}

// 迷你播放条/播放页需要的轻量广播
const barListeners = [];
export function onBarUpdate(fn) {
	barListeners.push(fn);
	return () => {
		const i = barListeners.indexOf(fn);
		if (i >= 0) barListeners.splice(i, 1);
	};
}
function notifyBar() {
	barListeners.forEach((fn) => fn());
}
