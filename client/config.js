// 全局配置：服务器地址等
// 正式服务器地址不写进仓库：复制 config.local.example.js 为 config.local.js 后填写
const DEFAULT_BASE_URL = 'https://your-domain.com/api';

// 读取私密配置（config.local.js 已被 .gitignore 忽略，不会提交）
function localBaseUrl() {
	try {
		const modules = import.meta.glob('./config.local.js', { eager: true });
		const mod = modules['./config.local.js'];
		if (mod && typeof mod.LOCAL_BASE_URL === 'string' && /^https?:\/\//.test(mod.LOCAL_BASE_URL)) {
			return mod.LOCAL_BASE_URL.replace(/\/+$/, '');
		}
	} catch (e) {}
	return '';
}

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
	return localBaseUrl() || devBaseUrl() || DEFAULT_BASE_URL;
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
