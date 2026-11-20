// 单例音频控制器（InnerAudioContext 封装）
// 不直接持有业务状态，只负责播放引擎与事件分发

let ctx = null;
const listeners = {};
// 每次 setSource 自增，用于忽略上一次遗留的 canplay/超时回调
let sourceToken = 0;

function emit(name, payload) {
	(listeners[name] || []).forEach((fn) => {
		try {
			fn(payload);
		} catch (e) {
			console.error('[audio] listener error', e);
		}
	});
}

export function on(name, fn) {
	if (!listeners[name]) listeners[name] = [];
	listeners[name].push(fn);
	return () => {
		listeners[name] = (listeners[name] || []).filter((f) => f !== fn);
	};
}

export function getContext() {
	if (ctx) return ctx;
	ctx = uni.createInnerAudioContext();
	ctx.obeyMuteSwitch = false;
	ctx.autoplay = false;
	ctx.onTimeUpdate(() => emit('timeupdate', { currentTime: ctx.currentTime, duration: ctx.duration }));
	ctx.onEnded(() => emit('ended'));
	ctx.onError((e) => emit('error', e));
	ctx.onPlay(() => emit('play'));
	ctx.onPause(() => emit('pause'));
	ctx.onStop(() => emit('stop'));
	ctx.onCanplay(() => emit('canplay'));
	ctx.onWaiting(() => emit('waiting'));
	ctx.onSeeked(() => emit('seeked'));
	return ctx;
}

export function setSource(src, { startTime = 0, autoplay = true, volume = 1 } = {}) {
	const c = getContext();
	c.stop();
	c.src = src;
	c.volume = volume;
	// 部分机型 stop() 后立刻 play() 不会启动，需等 canplay；同时用超时兜底，避免永远无声
	const token = ++sourceToken;
	let done = false;
	let timer = null;
	let offCanplay = null;
	const finish = (shouldSeek) => {
		if (done) return;
		done = true;
		if (timer) {
			clearTimeout(timer);
			timer = null;
		}
		if (offCanplay) {
			offCanplay();
			offCanplay = null;
		}
		// 已被更新的 setSource 取代：只做清理，不再操作播放器
		if (token !== sourceToken) return;
		if (shouldSeek && startTime > 0) {
			try {
				c.seek(startTime);
			} catch (e) {
				console.warn('[audio] seek failed', e);
			}
		}
		if (autoplay) {
			try {
				c.play();
			} catch (e) {
				console.warn('[audio] play failed', e);
			}
		}
	};
	offCanplay = on('canplay', () => finish(true));
	timer = setTimeout(() => finish(false), 8000);
}

export function play() {
	getContext().play();
}

export function pause() {
	sourceToken++; // 主动暂停后，未决的起播回调不应再自动 play
	getContext().pause();
}

export function stop() {
	sourceToken++; // 作废未决的 canplay/超时回调
	getContext().stop();
}

export function seek(ms) {
	try {
		getContext().seek(Math.max(0, ms / 1000));
	} catch (e) {
		console.warn('[audio] seek failed', e);
	}
}

export function setVolume(v) {
	try {
		getContext().volume = v;
	} catch (e) {}
}

export function setPlaybackRate(rate) {
	try {
		getContext().playbackRate = rate;
	} catch (e) {}
}

export function getDuration() {
	return ctx ? ctx.duration || 0 : 0;
}

export function getCurrentTime() {
	return ctx ? ctx.currentTime || 0 : 0;
}

export function isPlaying() {
	return ctx ? ctx.paused === false : false;
}

export function destroy() {
	if (ctx) {
		try {
			ctx.destroy();
		} catch (e) {}
		ctx = null;
	}
}
