<script>
	import { refreshUser } from '@/store/user.js';

	export default {
		onLaunch: function () {
			// 启动时静默校验 token（失败自动清除，不拦截游客浏览）
			refreshUser();
		},
		onShow: function () {},
		onHide: function () {}
	};
</script>

<style>
	/* 全局样式 */
	page {
		background-color: #f5f6f8;
		color: #1f2329;
		font-size: 28rpx;
		-webkit-tap-highlight-color: transparent;
	}

	view,
	text,
	image,
	scroll-view {
		box-sizing: border-box;
	}

	.page {
		min-height: 100vh;
		padding-bottom: env(safe-area-inset-bottom);
	}

	.page-inner {
		width: 100%;
		max-width: 1080px;
		margin: 0 auto;
		padding: 24rpx;
	}

	.card {
		background: #ffffff;
		border-radius: 24rpx;
	}

	.btn-primary {
		background: #ff4d4f;
		color: #ffffff;
		border-radius: 16rpx;
		font-size: 30rpx;
		line-height: 88rpx;
		height: 88rpx;
		text-align: center;
	}

	.btn-primary[disabled] {
		opacity: 0.6;
	}

	.btn-ghost {
		background: #fff1f0;
		color: #ff4d4f;
		border-radius: 16rpx;
		font-size: 28rpx;
		line-height: 80rpx;
		height: 80rpx;
		text-align: center;
	}

	.text-sub {
		color: #646a73;
	}

	.text-muted {
		color: #8f959e;
	}

	.ellipsis {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	.ellipsis-2 {
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}

	.empty {
		padding: 80rpx 0;
		text-align: center;
		color: #8f959e;
		font-size: 26rpx;
	}

	.quality-tag {
		display: inline-block;
		font-size: 20rpx;
		line-height: 1;
		padding: 4rpx 8rpx;
		border-radius: 6rpx;
		margin-left: 8rpx;
		border: 1px solid currentColor;
	}

	/* 平板断点 */
	@media (min-width: 768px) {
		.page-inner {
			padding: 32px;
		}
		.only-phone {
			display: none !important;
		}
	}

	@media (max-width: 767px) {
		.only-tablet {
			display: none !important;
		}
	}
</style>
