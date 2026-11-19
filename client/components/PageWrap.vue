<template>
	<view class="page">
		<view class="page-inner">
			<slot />
		</view>
	</view>
</template>

<script>
	// 页面容器：统一内边距 + 平板最大宽度 + 底部为迷你播放条留白
	export default {
		name: 'PageWrap'
	};
</script>

<style scoped>
	.page {
		min-height: 100vh;
		padding-bottom: calc(env(safe-area-inset-bottom) + 160rpx);
	}
</style>