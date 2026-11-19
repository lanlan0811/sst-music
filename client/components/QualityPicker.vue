<template>
	<view v-if="visible" class="mask" @click="close">
		<view class="sheet" @click.stop>
			<view class="sheet-head">
				<text class="sheet-title">{{ title }}</text>
				<text class="sheet-close" @click="close">✕</text>
			</view>
			<view v-if="!options.length" class="empty">暂无可选音质</view>
			<view v-for="q in options" :key="q.quality" class="option" @click="pick(q)">
				<text class="opt-label">{{ q.label || q.quality }}</text>
				<text class="opt-sub">{{ q.format.toUpperCase() }}{{ q.bitrate ? ' · ' + q.bitrate + 'kbps' : '' }}</text>
				<text v-if="q.size" class="opt-sub">{{ q.size }}</text>
			</view>
		</view>
	</view>
</template>

<script>
	export default {
		name: 'QualityPicker',
		props: {
			visible: { type: Boolean, default: false },
			title: { type: String, default: '选择音质' },
			qualities: { type: Array, default: () => [] }
		},
		computed: {
			options() {
				return this.qualities || [];
			}
		},
		methods: {
			close() {
				this.$emit('close');
			},
			pick(q) {
				this.$emit('pick', q);
				this.close();
			}
		}
	};
</script>

<style scoped>
	.mask {
		position: fixed;
		inset: 0;
		background: rgba(0, 0, 0, 0.45);
		display: flex;
		align-items: flex-end;
		z-index: 200;
	}

	.sheet {
		width: 100%;
		background: #ffffff;
		border-radius: 28rpx 28rpx 0 0;
		padding: 32rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));
		max-height: 70vh;
		overflow-y: auto;
	}

	.sheet-head {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 24rpx;
	}

	.sheet-title {
		font-size: 32rpx;
		font-weight: 600;
	}

	.sheet-close {
		font-size: 32rpx;
		color: #8f959e;
		padding: 8rpx;
	}

	.option {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 28rpx 16rpx;
		border-bottom: 1rpx solid #f0f1f4;
	}

	.opt-label {
		font-size: 30rpx;
		color: #1f2329;
	}

	.opt-sub {
		font-size: 24rpx;
		color: #8f959e;
		margin-left: auto;
		padding-left: 16rpx;
	}

	@media (min-width: 768px) {
		.mask {
			align-items: center;
			justify-content: center;
		}
		.sheet {
			width: 420px;
			border-radius: 12px;
			padding-bottom: 16px;
			max-height: 60vh;
		}
	}
</style>