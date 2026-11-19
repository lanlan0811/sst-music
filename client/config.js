// 全局配置：服务器地址等
// 打包前把 DEFAULT_BASE_URL 改为你的线上地址（不要以 / 结尾）
const DEFAULT_BASE_URL = 'https://your-domain.com/api';

// 本地开发：H5 在 localhost 运行时默认指向本机后端（php -S 127.0.0.1:8100 -t public）
function devBaseUrl() {
	// #ifdef H5
	try {
		const h = window.location.hostname;
		if (h === 'localhost' || h === '127.0.0.1') {
			return 'http://127.0.0.1:8100/api';
		}
	} catch (e) {}
	// #endif
	return '';
}

const KEY_BASE_URL = 'sst_base_url';

export function getBaseUrl() {
	try {
		const saved = uni.getStorageSync(KEY_BASE_URL);
		if (saved && /^https?:\/\//.test(saved)) {
			return saved.replace(/\/+$/, '');
		}
	} catch (e) {}
	return devBaseUrl() || DEFAULT_BASE_URL;
}

export function setBaseUrl(url) {
	const clean = String(url || '').trim().replace(/\/+$/, '');
	uni.setStorageSync(KEY_BASE_URL, clean);
}

export const APP_NAME = '随身听';
export const QUALITY_LABELS = { flac: '无损', '320': '320K', '128': '128K' };
export const PLATFORMS = [
	{ value: 'kuwo', label: '酷我' },
	{ value: 'wyy', label: '网易' }
];
