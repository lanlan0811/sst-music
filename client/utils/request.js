import { getBaseUrl } from '@/config.js';

const KEY_TOKEN = 'sst_token';

export function getToken() {
	try {
		return uni.getStorageSync(KEY_TOKEN) || '';
	} catch (e) {
		return '';
	}
}

export function setToken(token) {
	uni.setStorageSync(KEY_TOKEN, token || '');
}

export function clearToken() {
	uni.removeStorageSync(KEY_TOKEN);
}

let redirecting = false;

function toLogin() {
	if (redirecting) return;
	redirecting = true;
	setTimeout(() => (redirecting = false), 1500);
	clearToken();
	const pages = getCurrentPages();
	const cur = pages.length ? pages[pages.length - 1].route : '';
	if (cur !== 'pages/login/login') {
		uni.navigateTo({ url: '/pages/login/login' });
	}
}

/**
 * 统一请求封装
 * @param {Object} opts { url, method, data, auth(是否必须登录), silent(失败不弹toast), timeout(毫秒) }
 * @returns Promise<data>
 */
export function request(opts) {
	const { url, method = 'GET', data = {}, auth = false, silent = false, timeout = 20000 } = opts;
	const token = getToken();
	if (auth && !token) {
		toLogin();
		return Promise.reject({ code: 401, msg: '请先登录' });
	}
	return new Promise((resolve, reject) => {
		uni.request({
			url: getBaseUrl() + url,
			method,
			data,
			header: {
				'Content-Type': 'application/json',
				...(token ? { Authorization: 'Bearer ' + token } : {})
			},
			timeout,
			success: (res) => {
				const body = res.data || {};
				if (res.statusCode === 401) {
					if (auth) toLogin();
					if (!silent) {
						uni.showToast({ title: body.msg || '请先登录', icon: 'none' });
					}
					reject({ code: 401, msg: body.msg || '请先登录' });
					return;
				}
				if (res.statusCode >= 200 && res.statusCode < 400 && body.code === 0) {
					resolve(body.data);
					return;
				}
				const err = { code: body.code || res.statusCode, msg: body.msg || '请求失败', data: body.data };
				if (!silent) {
					uni.showToast({ title: err.msg, icon: 'none' });
				}
				reject(err);
			},
			fail: (e) => {
				const raw = String((e && e.errMsg) || '');
				const isTimeout = /timeout|abort/i.test(raw);
				const msg = isTimeout ? '网络超时，请稍后重试' : '网络异常，请检查网络或服务器地址';
				if (!silent) {
					uni.showToast({ title: msg, icon: 'none' });
				}
				reject({ code: -1, msg, raw });
			}
		});
	});
}

export const api = {
	register: (username, password) => request({ url: '/auth/register', method: 'POST', data: { username, password } }),
	login: (username, password) => request({ url: '/auth/login', method: 'POST', data: { username, password } }),
	logout: () => request({ url: '/auth/logout', method: 'POST', auth: true, silent: true }),
	me: () => request({ url: '/user/me', auth: true, silent: true }),
	updateProfile: (nickname) => request({ url: '/user/profile', method: 'PUT', data: { nickname }, auth: true }),
	search: (platform, keyword, page) =>
		request({
			url: `/search?platform=${platform}&keyword=${encodeURIComponent(keyword)}&page=${page}`,
			timeout: 15000,
			silent: true
		}),
	songUrl: (platform, id, quality, opts = {}) =>
		request({
			url:
				`/song/url?platform=${platform}&id=${encodeURIComponent(id)}&quality=${quality || ''}` +
				(opts.force ? '&refresh=1' : ''),
			timeout: 15000,
			silent: true
		}),
	songLyric: (platform, id) =>
		request({ url: `/song/lyric?platform=${platform}&id=${encodeURIComponent(id)}`, timeout: 15000, silent: true }),
	friends: () => request({ url: '/friends', auth: true }),
	friendRequests: () => request({ url: '/friends/requests', auth: true }),
	addFriend: (username) => request({ url: '/friends/request', method: 'POST', data: { username }, auth: true }),
	handleFriend: (request_id, accept) =>
		request({ url: '/friends/handle', method: 'POST', data: { request_id, accept }, auth: true }),
	removeFriend: (id) => request({ url: `/friends/${id}`, method: 'DELETE', auth: true }),
	roomCreate: (friend_id) => request({ url: '/room/create', method: 'POST', data: { friend_id }, auth: true }),
	roomJoin: (code) => request({ url: '/room/join', method: 'POST', data: { code }, auth: true }),
	roomState: (room_id, v) =>
		request({ url: `/room/state?room_id=${room_id}&v=${v}`, auth: true, silent: true }),
	roomCommand: (room_id, version, action, payload) =>
		request({
			url: '/room/command',
			method: 'POST',
			data: { room_id, version, action, payload },
			auth: true,
			silent: true
		}),
	roomLeave: (room_id) => request({ url: '/room/leave', method: 'POST', data: { room_id }, auth: true })
};
