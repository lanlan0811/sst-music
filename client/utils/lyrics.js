// LRC 解析：支持 [mm:ss.xx] / [mm:ss.xxx]、一行多时间戳、翻译行合并
export function parseLrc(text) {
	const meta = {};
	const map = new Map();
	if (!text) return { meta, lines: [], offset: 0 };

	const lines = String(text).split(/\r?\n/);
	const timeRe = /\[(\d{1,3}):(\d{1,2})(?:[.:](\d{1,3}))?\]/g;

	for (const raw of lines) {
		const line = raw.trim();
		if (!line) continue;
		// 元信息 [ti:xxx]
		const metaMatch = line.match(/^\[(ti|ar|al|by|offset):(.*)\]\s*$/i);
		if (metaMatch) {
			meta[metaMatch[1].toLowerCase()] = metaMatch[2].trim();
			continue;
		}
		const stamps = [];
		let m;
		timeRe.lastIndex = 0;
		while ((m = timeRe.exec(line)) !== null) {
			const min = parseInt(m[1], 10);
			const sec = parseInt(m[2], 10);
			let frac = m[3] || '0';
			if (frac.length === 1) frac = frac + '00';
			else if (frac.length === 2) frac = frac + '0';
			stamps.push(min * 60000 + sec * 1000 + parseInt(frac, 10));
		}
		if (!stamps.length) continue;
		const content = line.replace(timeRe, '').trim();
		if (!content) continue;
		for (const t of stamps) {
			const key = Math.round(t / 10) * 10;
			const prev = map.get(key);
			if (prev) {
				if (prev !== content) prev.translation = prev.translation ? prev.translation + '\n' + content : content;
			} else {
				map.set(key, { time: t, text: content, translation: '' });
			}
		}
	}

	const parsed = Array.from(map.values()).sort((a, b) => a.time - b.time);
	const offset = parseInt(meta.offset || '0', 10) || 0;
	if (offset) {
		parsed.forEach((l) => (l.time = Math.max(0, l.time - offset)));
	}
	return { meta, lines: parsed, offset };
}

/**
 * 根据播放位置找到当前行下标（-1 表示尚无）
 */
export function findLineIndex(lines, timeMs) {
	if (!lines || !lines.length) return -1;
	let lo = 0;
	let hi = lines.length - 1;
	let ans = -1;
	while (lo <= hi) {
		const mid = (lo + hi) >> 1;
		if (lines[mid].time <= timeMs) {
			ans = mid;
			lo = mid + 1;
		} else {
			hi = mid - 1;
		}
	}
	return ans;
}

export function formatTime(ms) {
	const total = Math.max(0, Math.floor((ms || 0) / 1000));
	const m = Math.floor(total / 60);
	const s = total % 60;
	return `${m}:${s < 10 ? '0' : ''}${s}`;
}
