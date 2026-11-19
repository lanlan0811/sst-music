import { getDownloadList } from '@/utils/download.js';

/**
 * 平板/手机断点判断（页面内辅助，样式主要靠 CSS 媒体查询）
 */
export function getScreenInfo() {
	let width = 375;
	let height = 667;
	try {
		const info = uni.getWindowInfo ? uni.getWindowInfo() : uni.getSystemInfoSync();
		width = info.windowWidth || info.screenWidth || width;
		height = info.windowHeight || info.screenHeight || height;
	} catch (e) {}
	return {
		width,
		height,
		isTablet: width >= 768,
		isLandscape: width > height
	};
}

export function downloadCount() {
	return getDownloadList().length;
}
