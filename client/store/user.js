import { reactive } from 'vue';
import { api, getToken, setToken, clearToken } from '@/utils/request.js';

const KEY_USER = 'sst_user';

function loadUser() {
	try {
		const raw = uni.getStorageSync(KEY_USER);
		if (raw && typeof raw === 'object') return raw;
	} catch (e) {}
	return null;
}

export const user = reactive({
	token: getToken(),
	info: loadUser(),
	get loggedIn() {
		return !!this.token;
	}
});

export function saveSession(token, info) {
	user.token = token || '';
	user.info = info || null;
	setToken(token);
	uni.setStorageSync(KEY_USER, info || null);
}

export function updateUserInfo(info) {
	user.info = { ...(user.info || {}), ...(info || {}) };
	uni.setStorageSync(KEY_USER, user.info);
}

export function logoutLocal() {
	user.token = '';
	user.info = null;
	clearToken();
	uni.removeStorageSync(KEY_USER);
}

export async function refreshUser() {
	if (!user.token) return null;
	try {
		const data = await api.me();
		updateUserInfo(data);
		return data;
	} catch (e) {
		if (e && e.code === 401) logoutLocal();
		return null;
	}
}
