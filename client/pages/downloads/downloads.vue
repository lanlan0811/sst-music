<template>
	<PageWrap>
		<view class="downloads">
			<view class="head">
				<text class="title">我的下载</text>
				<text class="count">{{ list.length }} 首</text>
			</view>

			<view v-if="!list.length" class="empty">还没有下载歌曲，去首页搜索吧</view>

			<view v-for="item in list" :key="item.platform + '_' + item.id" class="dl-item">
				<image class="cover" :src="item.cover || '/static/cover-placeholder.png'" mode="aspectFill" @click="playLocal(item)" />
				<view class="main" @click="playLocal(item)">
					<view class="title-row">
						<text class="name ellipsis">{{ item.name }}</text>
						<text class="quality-tag">{{ qualityLabel(item.quality) }}</text>
					</view>
					<text class="sub ellipsis">{{ item.artist }} · {{ item.format.toUpperCase() }}</text>
				</view>
				<view class="actions">
					<view class="act" @click="openLyric(item)">
						<text class="act-text">词</text>
					</view>
					<view class="act" @click="openFile(item)">
						<text class="act-text">开</text>
					</view>
					<view class="act danger" @click="remove(item)">
						<text class="act-text">删</text>
					</view>
				</view>
			</view>

			<view v-if="lyricVisible" class="lyric-mask" @click="lyricVisible = false">
				<view class="lyric-panel" @click.stop>
					<view class="lyric-head">
						<text class="lyric-title">{{ lyricTitle }}</text>
						<text class="lyric-close" @click="lyricVisible = false">✕</text>
					</view>
					<scroll-view scroll-y class="lyric-body">
						<text class="lyric-text">{{ lyricContent || '暂无歌词' }}</text>
					</scroll-view>
				</view>
			</view>

			<MiniPlayer />
		</view>
	</PageWrap>
</template>

<script>
	import PageWrap from '@/components/PageWrap.vue';
	import MiniPlayer from '@/components/MiniPlayer.vue';
	import { getDownloadList, removeDownload, openLyricFile, openMusicFile } from '@/utils/download.js';
	import { setQueue, player, setRemoteMode } from '@/store/player.js';
	import { QUALITY_LABELS } from '@/config.js';

	export default {
		components: { PageWrap, MiniPlayer },
		data() {
			return {
				list: [],
				lyricVisible: false,
				lyricContent: '',
				lyricTitle: ''
			};
		},
		onShow() {
			this.list = getDownloadList();
		},
		methods: {
			qualityLabel(q) {
				return QUALITY_LABELS[q] || q;
			},
			buildSong(item) {
				return {
					id: item.id,
					platform: item.platform,
					name: item.name,
					artist: item.artist,
					album: item.album,
					cover: item.cover,
					duration: item.duration,
					qualities: [{ quality: item.quality, label: this.qualityLabel(item.quality), format: item.format }]
				};
			},
			playLocal(item) {
				// 整个下载列表作为播放队列（player 优先读本地文件）
				if (player.remote) setRemoteMode(false);
				const queue = this.list.map((r) => this.buildSong(r));
				const idx = this.list.findIndex((r) => r.platform === item.platform && String(r.id) === String(item.id));
				setQueue(queue, idx >= 0 ? idx : 0);
				uni.navigateTo({ url: '/pages/player/player' });
			},
			async openLyric(item) {
				if (!item.lyricPath) {
					uni.showToast({ title: '该歌曲没有歌词文件', icon: 'none' });
					return;
				}
				// #ifdef APP-PLUS
				this.lyricTitle = item.name;
				this.lyricContent = '';
				this.lyricVisible = true;
				plus.io.resolveLocalFileSystemURL(item.lyricPath, (entry) => {
					entry.file((file) => {
						const reader = new plus.io.FileReader();
						reader.onloadend = (e) => {
							this.lyricContent = (e.target.result || '').replace(/\[[^\]]*\]/g, '').replace(/\n{2,}/g, '\n');
						};
						reader.readAsText(file, 'utf-8');
					});
				});
				// #endif
				// #ifndef APP-PLUS
				uni.showToast({ title: '仅 App 端支持打开歌词', icon: 'none' });
				// #endif
			},
			openFile(item) {
				uni.showActionSheet({
					itemList: ['用其他应用打开音乐', '用其他应用打开歌词'],
					success: (res) => {
						if (res.tapIndex === 0) openMusicFile(item);
						else openLyricFile(item);
					}
				});
			},
			remove(item) {
				uni.showModal({
					title: '删除下载',
					content: `确定删除「${item.name}」及其歌词文件吗？`,
					success: async (res) => {
						if (!res.confirm) return;
						await removeDownload(item);
						this.list = getDownloadList();
						uni.showToast({ title: '已删除', icon: 'success' });
					}
				});
			}
		}
	};
</script>

<style scoped>
	.head {
		display: flex;
		justify-content: space-between;
		align-items: baseline;
		margin-bottom: 24rpx;
	}

	.title {
		font-size: 36rpx;
		font-weight: 600;
	}

	.count {
		font-size: 24rpx;
		color: #8f959e;
	}

	.dl-item {
		display: flex;
		align-items: center;
		background: #ffffff;
		border-radius: 20rpx;
		padding: 20rpx 24rpx;
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
	}

	.title-row {
		display: flex;
		align-items: center;
	}

	.name {
		font-size: 30rpx;
		color: #1f2329;
		max-width: 380rpx;
	}

	.quality-tag {
		color: #ff4d4f;
		font-size: 20rpx;
		border: 1px solid #ff4d4f;
		border-radius: 6rpx;
		padding: 2rpx 8rpx;
		margin-left: 10rpx;
	}

	.sub {
		font-size: 24rpx;
		color: #8f959e;
		margin-top: 8rpx;
		display: block;
	}

	.actions {
		display: flex;
		gap: 12rpx;
		flex-shrink: 0;
	}

	.act {
		width: 64rpx;
		height: 64rpx;
		border-radius: 50%;
		background: #f5f6f8;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.act-text {
		font-size: 24rpx;
		color: #646a73;
	}

	.act.danger {
		background: #fff1f0;
	}

	.act.danger .act-text {
		color: #ff4d4f;
	}

	.lyric-mask {
		position: fixed;
		inset: 0;
		background: rgba(0, 0, 0, 0.45);
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 200;
		padding: 48rpx;
	}

	.lyric-panel {
		width: 100%;
		max-width: 680rpx;
		background: #ffffff;
		border-radius: 24rpx;
		padding: 32rpx;
		max-height: 70vh;
		display: flex;
		flex-direction: column;
	}

	.lyric-head {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 20rpx;
	}

	.lyric-title {
		font-size: 30rpx;
		font-weight: 600;
	}

	.lyric-close {
		color: #8f959e;
		font-size: 32rpx;
		padding: 8rpx;
	}

	.lyric-body {
		flex: 1;
		min-height: 0;
	}

	.lyric-text {
		font-size: 26rpx;
		line-height: 1.8;
		color: #646a73;
		white-space: pre-wrap;
	}

	@media (min-width: 768px) {
		.dl-item {
			padding: 12px 16px;
			margin-bottom: 10px;
		}
		.cover {
			width: 56px;
			height: 56px;
		}
	}
</style>