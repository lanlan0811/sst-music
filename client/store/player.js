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
	buffering: false,
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
let remoteMode = false; // 一起听时由房间控制
const trackListeners = [];

// 播放失败自动降级：同一首歌最多重试 2 次（flac → 320 → 128）
const QUALITY_ORDER = ['flac', '320', '128'];
const retryState = { key: '', level: 0 };
let retrying = false;
let pendingQuality = ''; // 降级重试时临时使用的音质
let watchdogTimer = null;
const WATCHDOG_MS = 12000;

function songKey(song) {
	return song ? song.platform + '_' + song.id : '';
}

function clearWatchdog() {
	if (watchdogTimer) {
		clearTimeout(watchdogTimer);
		watchdogTimer = null;
	}
}

function armWatchdog(token) {
	clearWatchdog();
	watchdogTimer = setTimeout(() => {
		watchdogTimer = null;
		if (token !== playToken) return;
		if (!player.loading) return; // 已起播
		handlePlayFailure(token, { msg: '起播超时' });
	}, WATCHDOG_MS);
}

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
audio.on('canplay', () => {
	player.buffering = false;
	// 远程/暂停场景没有 play 事件，这里兜底结束 loading
	player.loading = false;
	clearWatchdog();
});
audio.on('play', () => {
	player.playing = true;
	player.loading = false;
	player.buffering = false;
	clearWatchdog();
});
audio.on('pause', () => {
	player.playing = false;
	player.loading = false;
	clearWatchdog();
});
// 注意：setSource 内部会调用 stop()，因此这里不能清 loading，否则起播动画立刻消失
audio.on('stop', () => {
	player.playing = false;
	player.buffering = false;
});
audio.on('waiting', () => (player.buffering = true));
audio.on('seeked', () => (player.buffering = false));
audio.on('ended', () => {
	if (remoteMode) {
		// 一起听：由房间页广播"本曲结束"，由服务器决定下一首
		uni.$emit('sst_remote_ended');
		return;
	}
	next(true);
});

/**
 * 播放失败处理：本机模式下自动降级音质并强制重取直链；
 * 一起听模式交给房间页轮询恢复，避免本机重试与房间同步互相打架。
 */
function handlePlayFailure(token, info) {
	if (token !== playToken) return;
	clearWatchdog();
	player.loading = false;
	player.buffering = false;
	if (remoteMode) {
		console.warn('[player] remote play failure', info);
		return;
	}
	const song = player.current;
	if (!song) {
		player.playing = false;
		return;
	}
	const key = songKey(song);
	if (retryState.key !== key) {
		retryState.key = key;
		retryState.level = 0;
	}
	const currentIdx = QUALITY_ORDER.indexOf(player.quality);
	const baseIdx = currentIdx >= 0 ? currentIdx : 0;
	// 即使已经是用户选的最低音质，也允许用 force 重取一次直链（绕过临期缓存）
	if (retryState.level < 2 && !retrying) {
		retrying = true;
		retryState.level++;
		const targetIdx = Math.min(baseIdx + retryState.level, QUALITY_ORDER.length - 1);
		pendingQuality = QUALITY_ORDER[targetIdx];
		playAt(player.index, player.currentTime, { retry: true, force: true })
			.catch(() => {})
			.finally(() => {
				setTimeout(() => (retrying = false), 800);
			});
		return;
	}
	retrying = false;
	player.playing = false;
	uni.showToast({ title: '播放失败，请检查网络后重试', icon: 'none' });
}

let lastSourceAt = 0;

audio.on('error', (e) => {
	const msg = e && (e.errMsg || e.errCode) ? e.errMsg || 'code ' + e.errCode : 'unknown';
	console.error('[player] audio error', msg);
	// 切歌瞬间的旧音源错误（<1s）不计入本首的失败统计
	if (Date.now() - lastSourceAt < 1000) return;
	handlePlayFailure(playToken, { msg });
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
 * @param {Object} opts { retry 失败后重试, force 绕过服务端短缓存 }
 */
export async function playAt(index, positionMs = 0, opts = {}) {
	if (index < 0 || index >= player.queue.length) return;
	const song = player.queue[index];
	const force = !!opts.force;
	player.index = index;
	player.currentTime = positionMs;
	player.duration = (song.duration || 0) * 1000;
	player.loading = true;
	player.buffering = false;
	// 用户主动起播（非自动重试）时重置该曲的降级计数，并清掉上首残留的降级音质
	if (!opts.retry) {
		retryState.key = songKey(song);
		retryState.level = 0;
		pendingQuality = '';
	}
	const token = ++playToken;
	trackListeners.forEach((fn) => fn(song));
	notifyBar();

	// 本地下载优先（离线可用，无需重试）
	const local = getLocalRecord(song.platform, song.id);
	if (local) {
		pendingQuality = '';
		lastSourceAt = Date.now();
		audio.setSource(toLocalUrl(local.musicPath), { startTime: positionMs / 1000, autoplay: true });
		armWatchdog(token);
		return;
	}
	try {
		// 降级重试时优先用 pendingQuality，其次用用户偏好
		const wanted = pendingQuality || player.quality;
		const quality = pickQuality(song, wanted);
		const data = await api.songUrl(song.platform, song.id, quality, { force });
		if (token !== playToken) return;
		// 起播成功后再清空降级音质，避免残留影响下一首
		pendingQuality = '';
		lastSourceAt = Date.now();
		audio.setSource(data.url, { startTime: positionMs / 1000, autoplay: true });
		// 取地址阶段已有 15s 请求超时，这里只监控"已下发音源但迟迟不出声"
		armWatchdog(token);
	} catch (e) {
		if (token !== playToken) return;
		pendingQuality = '';
		clearWatchdog();
		player.loading = false;
		player.playing = false;
		if (opts.retry) {
			// 第二次失败才提示，避免一次网络抖动就弹两次 toast
			uni.showToast({ title: (e && e.msg) || '播放失败，请稍后重试', icon: 'none' });
		} else {
			uni.showToast({ title: (e && e.msg) || '获取播放地址失败', icon: 'none' });
		}
	}
}

export function toggle() {
	if (!player.current) return;
	if (player.remote) {
		// 一起听模式下不直接控制，交由房间页发指令（房间页会给出 toast 反馈）
		uni.$emit('sst_remote_toggle');
		return;
	}
	if (player.loading) return; // 正在取地址/转圈，忽略连点
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
	clearWatchdog();
	audio.stop();
	player.playing = false;
	player.loading = false;
	player.buffering = false;
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
