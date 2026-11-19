<template>
	<view class="song-item" @click="$emit('play', song)">
		<image class="cover" :src="song.cover || '/static/cover-placeholder.png'" mode="aspectFill" />
		<view class="main">
			<view class="title-row">
				<text class="name ellipsis">{{ song.name || '未知歌曲' }}</text>
				<text v-if="hasFlac" class="quality-tag q-flac">SQ</text>
				<text v-else-if="has320" class="quality-tag q-hq">HQ</text>
			</view>
			<text class="sub ellipsis">{{ subtitle }}</text>
		</view>
		<view class="actions" @click.stop>
			<view class="act" @click="$emit('download', song)">
				<text class="act-icon">⤓</text>
			</view>
			<view class="act" @click="$emit('add', song)">
				<text class="act-icon">＋</text>
			</view>
		</view>
	</view>
</template>

<script>
	export default {
		name: 'SongItem',
		props: {
			song: { type: Object, required: true },
			showAdd: { type: Boolean, default: true }
		},
		computed: {
			hasFlac() {
				return (this.song.qualities || []).some((q) => q.quality === 'flac');
			},
			has320() {
				return (this.song.qualities || []).some((q) => q.quality === '320');
			},
			subtitle() {
				const parts = [];
				if (this.song.artist) parts.push(this.song.artist);
				if (this.song.album) parts.push('《' + this.song.album + '》');
				return parts.join(' · ') || '未知歌手';
			}
		}
	};
</script>

<style scoped>
	.song-item {
		display: flex;
		align-items: center;
		padding: 20rpx 24rpx;
		background: #ffffff;
		border-radius: 20rpx;
		margin-bottom: 16rpx;
	}

	.cover {
		width: 96rpx;
		height: 96rpx;
		border-radius: 14rpx;
		background: #f0f1f4;
		flex-shrink: 0;
	}

	.main {
		flex: 1;
		min-width: 0;
		margin-left: 20rpx;
		display: flex;
		flex-direction: column;
		justify-content: center;
	}

	.title-row {
		display: flex;
		align-items: center;
	}

	.name {
		font-size: 30rpx;
		color: #1f2329;
		font-weight: 500;
		max-width: 420rpx;
	}

	.q-flac {
		color: #ff4d4f;
	}

	.q-hq {
		color: #fa8c16;
	}

	.sub {
		margin-top: 8rpx;
		font-size: 24rpx;
		color: #8f959e;
	}

	.actions {
		display: flex;
		align-items: center;
		flex-shrink: 0;
	}

	.act {
		width: 64rpx;
		height: 64rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		border-radius: 50%;
		background: #f5f6f8;
		margin-left: 12rpx;
	}

	.act-icon {
		font-size: 32rpx;
		color: #646a73;
		line-height: 1;
	}

	@media (min-width: 768px) {
		.song-item {
			padding: 12px 16px;
			margin-bottom: 10px;
		}
		.cover {
			width: 56px;
			height: 56px;
		}
		.name {
			font-size: 15px;
		}
	}
</style>
