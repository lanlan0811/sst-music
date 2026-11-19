// 下载：音乐文件 + 歌词文件保存到应用私有目录（_downloads），无需存储权限
import { api } from '@/utils/request.js';
import { DOWNLOAD_KEY, toLocalUrl } from '@/store/player.js';

const DIR_MUSIC = '_downloads/music/';
const DIR_LYRIC = '_downloads/lyric/';

function ensureStorage() {
	return new Promise((resolve) => {
		// #ifdef APP-PLUS
		plus.io.requestFileSystem(plus.io.PRIVATE_DOC, (fs) => {
			fs.root.getDirectory('_downloads', { create: true }, () => {
				fs.root.getDirectory('_downloads/music', { create: true }, () => {
					fs.root.getDirectory('_downloads/lyric', { create: true }, () => resolve(true));
				});
			}, () => resolve(false));
		}, () => resolve(false));
		// #endif
		// #ifndef APP-PLUS
		resolve(false);
		// #endif
	});
}

export function getDownloadList() {
	try {
		const list = uni.getStorageSync(DOWNLOAD_KEY) || [];
		return list.slice().sort((a, b) => b.createdAt - a.createdAt);
	} catch (e) {
		return [];
	}
}

function saveDownloadList(list) {
	uni.setStorageSync(DOWNLOAD_KEY, list);
}

export function findDownload(platform, id) {
	return getDownloadList().find((r) => r.platform === platform && String(r.id) === String(id)) || null;
}

/**
 * 顺序执行任务（同一时间只跑一个下载，保持简洁稳定）
 */
let chain = Promise.resolve();
function enqueue(task) {
	const run = chain.then(task, task);
	chain = run.catch(() => {});
	return run;
}

function downloadFile(url, savePath, onProgress) {
	return new Promise((resolve, reject) => {
		// #ifdef APP-PLUS
		const task = plus.downloader.createDownload(url, { filename: savePath }, (d, status) => {
			if (status === 200 || status === 206) {
				resolve(d.filename);
			} else {
				reject(new Error('下载失败（' + status + '）'));
			}
		});
		task.addEventListener('statechanged', (d) => {
			if (d.state === 3 && d.totalSize > 0 && onProgress) {
				onProgress(Math.floor((d.downloadedSize / d.totalSize) * 100));
			}
		});
		task.start();
		// #endif
		// #ifndef APP-PLUS
		reject(new Error('仅支持 App 端下载'));
		// #endif
	});
}

function writeTextFile(path, text) {
	return new Promise((resolve, reject) => {
		// #ifdef APP-PLUS
		plus.io.requestFileSystem(plus.io.PRIVATE_DOC, (fs) => {
			fs.root.getFile(path, { create: true }, (entry) => {
				entry.createWriter((writer) => {
					writer.onwrite = () => resolve(entry.fullPath);
					writer.onerror = () => reject(new Error('歌词写入失败'));
					writer.write(text);
				}, () => reject(new Error('歌词写入失败')));
			}, () => reject(new Error('歌词写入失败')));
		}, () => reject(new Error('文件系统不可用')));
		// #endif
		// #ifndef APP-PLUS
		reject(new Error('仅支持 App 端下载'));
		// #endif
	});
}

/**
 * 下载歌曲（音乐 + 歌词）
 * @param {Object} song 归一化歌曲 {id,name,artist,album,cover,duration,qualities,platform}
 * @param {string} quality flac|320|128
 * @param {Function} onProgress (percent) => void
 */
export function downloadSong(song, quality, onProgress) {
	return enqueue(async () => {
		await ensureStorage();
		const urlData = await api.songUrl(song.platform, song.id, quality);
		const ext = urlData.format === 'flac' ? 'flac' : 'mp3';
		const base = `${song.platform}_${song.id}_${urlData.quality || quality}`;
		const musicPath = DIR_MUSIC + base + '.' + ext;

		const existing = findDownload(song.platform, song.id);
		if (existing && existing.quality === (urlData.quality || quality)) {
			throw new Error('已下载过该音质');
		}

		const realPath = await downloadFile(urlData.url, musicPath, onProgress);

		let lyricPath = '';
		try {
			const lyric = await api.songLyric(song.platform, song.id);
			if (lyric && lyric.lrc) {
				lyricPath = await writeTextFile(DIR_LYRIC + base + '.lrc', lyric.lrc);
			}
		} catch (e) {
			lyricPath = '';
		}

		const record = {
			platform: song.platform,
			id: String(song.id),
			name: song.name,
			artist: song.artist || '',
			album: song.album || '',
			cover: song.cover || '',
			duration: song.duration || urlData.duration || 0,
			quality: urlData.quality || quality,
			format: ext,
			musicPath: realPath || musicPath,
			lyricPath,
			createdAt: Date.now()
		};
		const list = getDownloadList().filter((r) => !(r.platform === record.platform && r.id === record.id));
		list.unshift(record);
		saveDownloadList(list);
		return record;
	});
}

/**
 * 删除下载（含文件）
 */
export function removeDownload(record) {
	return new Promise((resolve) => {
		// #ifdef APP-PLUS
		plus.io.requestFileSystem(plus.io.PRIVATE_DOC, (fs) => {
			const rm = (path, cb) => {
				if (!path) return cb();
				fs.root.getFile(path, { create: false }, (entry) => {
					entry.remove(cb, cb);
				}, cb);
			};
			rm(record.musicPath, () => rm(record.lyricPath, () => cbDone()));
		}, () => cbDone());
		// #endif
		// #ifndef APP-PLUS
		cbDone();
		// #endif
		function cbDone() {
			const list = getDownloadList().filter(
				(r) => !(r.platform === record.platform && String(r.id) === String(record.id))
			);
			saveDownloadList(list);
			resolve(true);
		}
	});
}

export function openLyricFile(record) {
	if (!record.lyricPath) {
		uni.showToast({ title: '该歌曲没有歌词文件', icon: 'none' });
		return;
	}
	// #ifdef APP-PLUS
	plus.runtime.openFile(toLocalUrl(record.lyricPath), {}, (e) => {
		uni.showToast({ title: '无法打开歌词文件', icon: 'none' });
	});
	// #endif
}

export function openMusicFile(record) {
	// #ifdef APP-PLUS
	plus.runtime.openFile(toLocalUrl(record.musicPath), {}, () => {
		uni.showToast({ title: '无法打开音乐文件', icon: 'none' });
	});
	// #endif
}
