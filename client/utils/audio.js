// 单例音频控制器（InnerAudioContext 封装）
// 不直接持有业务状态，只负责播放引擎与事件分发

let ctx = null;
const listeners = {};

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
	if (startTime > 0) {
		// 部分机型需要等 canplay 再 seek
		const off = on('canplay', () => {
			try {
				c.seek(startTime);
			} catch (e) {}
			off();
		});
	}
	if (autoplay) c.play();
}

export function play() {
	getContext().play();
}

export function pause() {
	getContext().pause();
}

export function stop() {
	getContext().stop();
}

export function seek(ms) {
	try {
		getContext().seek(Math.max(0, ms / 1000));
	} catch (e) {}
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
