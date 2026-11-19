<template>
	<view v-if="player.current && !player.remote" class="mini-player" @click="openPlayer">
		<image class="cover" :src="player.current.cover || '/static/cover-placeholder.png'" mode="aspectFill" />
		<view class="info">
			<text class="name ellipsis">{{ player.current.name }}</text>
			<text class="sub ellipsis">{{ player.current.artist || '未知歌手' }}</text>
		</view>
		<view class="progress-track">
			<view class="progress-value" :style="{ width: percent + '%' }"></view>
		</view>
		<view class="btn" @click.stop="toggle">
			<text class="btn-icon">{{ player.loading ? '…' : player.playing ? '❚❚' : '▶' }}</text>
		</view>
		<view class="btn" @click.stop="next">
			<text class="btn-icon">▶▶</text>
		</view>
	</view>
</template>

<script>
	import { player, toggle, next } from '@/store/player.js';

	export default {
		name: 'MiniPlayer',
		setup() {
			return { player };
		},
		computed: {
			percent() {
				if (!this.player.duration) return 0;
				return Math.min(100, (this.player.currentTime / this.player.duration) * 100);
			}
		},
		methods: {
			toggle,
			next() {
				next(false);
			},
			openPlayer() {
				uni.navigateTo({ url: '/pages/player/player' });
			}
		}
	};
</script>

<style scoped>
	.mini-player {
		position: fixed;
		left: 16rpx;
		right: 16rpx;
		/* tabbar 高度 + 安全区 */
		bottom: calc(env(safe-area-inset-bottom) + 110rpx);
		background: #ffffff;
		border-radius: 20rpx;
		box-shadow: 0 6rpx 24rpx rgba(31, 35, 41, 0.12);
		display: flex;
		align-items: center;
		padding: 14rpx 20rpx;
		z-index: 90;
		overflow: hidden;
	}

	.cover {
		width: 76rpx;
		height: 76rpx;
		border-radius: 12rpx;
		flex-shrink: 0;
		background: #f0f1f4;
	}

	.info {
		flex: 1;
		min-width: 0;
		margin-left: 16rpx;
		display: flex;
		flex-direction: column;
	}

	.name {
		font-size: 28rpx;
		color: #1f2329;
	}

	.sub {
		font-size: 22rpx;
		color: #8f959e;
		margin-top: 4rpx;
	}

	.btn {
		width: 64rpx;
		height: 64rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		border-radius: 50%;
		background: #f5f6f8;
		margin-left: 12rpx;
		flex-shrink: 0;
	}

	.btn-icon {
		font-size: 24rpx;
		color: #ff4d4f;
		line-height: 1;
	}

	.progress-track {
		position: absolute;
		left: 0;
		right: 0;
		bottom: 0;
		height: 4rpx;
		background: #f0f1f4;
	}

	.progress-value {
		height: 100%;
		background: #ff4d4f;
	}

	@media (min-width: 768px) {
		.mini-player {
			left: 50%;
			right: auto;
			width: 640px;
			transform: translateX(-50%);
			bottom: 24px;
			padding: 10px 16px;
		}
		.cover {
			width: 48px;
			height: 48px;
		}
	}
</style>
