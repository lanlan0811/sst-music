<template>
	<view class="player-page">
		<view class="bg-blur" :style="bgStyle"></view>
		<view class="status-bar-spacer"></view>

		<view class="top-bar">
			<view class="icon-btn" @click="goBack">‹</view>
			<view class="top-title">
				<text class="top-name ellipsis">{{ current ? current.name : '未在播放' }}</text>
				<text class="top-sub ellipsis">{{ current ? current.artist || '未知歌手' : '选择一首歌开始' }}</text>
			</view>
			<view class="icon-btn" @click="showModeMenu">☰</view>
		</view>

		<view class="player-body" :class="{ 'is-tablet': isTablet }">
			<!-- 左栏：封面 + 控制 -->
			<view class="left-col">
				<view class="cover-wrap" :class="{ spinning: player.playing && !isTablet, 'paused': !player.playing }">
					<image class="cover" :src="current && current.cover ? current.cover : '/static/cover-placeholder.png'" mode="aspectFill" />
				</view>

				<view class="meta">
					<text class="name ellipsis">{{ current ? current.name : '未在播放' }}</text>
					<text class="artist ellipsis">{{ current ? current.artist || '未知歌手' : '' }}</text>
					<view v-if="current" class="quality-row">
						<text
							v-for="q in current.qualities || []"
							:key="q.quality"
							class="quality-chip"
							:class="{ active: player.quality === q.quality }"
							@click="setQuality(q.quality)"
						>
							{{ q.label }}
						</text>
					</view>
				</view>

				<view class="progress-area">
					<slider
						class="slider"
						:value="sliderValue"
						:max="sliderMax"
						:block-size="14"
						active-color="#ff4d4f"
						background-color="rgba(255,255,255,0.25)"
						@change="onSeek"
					/>
					<view class="time-row">
						<text class="time">{{ formatTime(player.currentTime) }}</text>
						<text class="time">{{ formatTime(player.duration) }}</text>
					</view>
				</view>

				<view class="controls">
					<view class="ctrl" @click="toggleMode">
						<text class="ctrl-icon small">{{ modeIcon }}</text>
					</view>
					<view class="ctrl" @click="prev">
						<text class="ctrl-icon">⏮</text>
					</view>
					<view class="ctrl main" @click="toggle">
						<text class="ctrl-icon large">{{ player.playing ? '❚❚' : '▶' }}</text>
					</view>
					<view class="ctrl" @click="next">
						<text class="ctrl-icon">⏭</text>
					</view>
					<view class="ctrl" @click="downloadCurrent">
						<text class="ctrl-icon small">⤓</text>
					</view>
				</view>
			</view>

			<!-- 右栏（平板并排）/ 下方（手机）：歌词 -->
			<view class="lyric-col">
				<scroll-view
					class="lyric-scroll"
					scroll-y
					:scroll-top="scrollTop"
					:scroll-with-animation="true"
					:show-scrollbar="false"
				>
					<view class="lyric-padding"></view>
					<view
						v-for="(line, i) in lyricLines"
						:key="i"
						class="lyric-line"
						:class="{ active: i === activeLine }"
						@click="seekToLine(line)"
					>
						<text class="lyric-text">{{ line.text }}</text>
						<text v-if="line.translation" class="lyric-trans">{{ line.translation }}</text>
					</view>
					<view v-if="!lyricLines.length" class="lyric-empty">{{ lyricLoading ? '歌词加载中…' : '暂无歌词' }}</view>
					<view class="lyric-padding"></view>
				</scroll-view>
			</view>
		</view>

		<QualityPicker
			:visible="pickerVisible"
			title="选择下载音质"
			:qualities="(current && current.qualities) || []"
			@close="pickerVisible = false"
			@pick="onDownloadQuality"
		/>
	</view>
</template>

<script>
	import QualityPicker from '@/components/QualityPicker.vue';
	import { player, onTrackChange, toggle, next, prev, seek, setQuality, cycleMode } from '@/store/player.js';
	import { api } from '@/utils/request.js';
	import { parseLrc, findLineIndex, formatTime } from '@/utils/lyrics.js';
	import { getScreenInfo } from '@/utils/layout.js';
	import { downloadSong } from '@/utils/download.js';

	const MODE_ICONS = { list: '列表循环', single: '单曲循环', shuffle: '随机播放' };

	export default {
		components: { QualityPicker },
		data() {
			return {
				player,
				activeLine: -1,
				scrollTop: 0,
				lyricLines: [],
				lyricLoading: false,
				lyricPlatform: '',
				lyricId: '',
				isTablet: false,
				seeking: false,
				pickerVisible: false,
				unsubTrack: null
			};
		},
		computed: {
			current() {
				return this.player.current;
			},
			modeIcon() {
				return { list: '🔁', single: '🔂', shuffle: '🔀' }[this.player.mode] || '🔁';
			},
			sliderMax() {
				return Math.max(1, this.player.duration);
			},
			sliderValue() {
				return Math.min(this.player.currentTime, this.sliderMax);
			},
			bgStyle() {
				const cover = this.current && this.current.cover;
				return cover
					? { backgroundImage: `url(${cover})` }
					: { background: 'linear-gradient(160deg,#ff4d4f 0%,#1f2329 100%)' };
			},
			activeLineIndex() {
				return findLineIndex(this.lyricLines, this.player.currentTime);
			}
		},
		watch: {
			activeLineIndex(idx) {
				if (idx === this.activeLine) return;
				this.activeLine = idx;
				// 每行约 96rpx（平板 64px），居中偏移
				const lineHeight = this.isTablet ? 64 : 48;
				const target = Math.max(0, idx * lineHeight);
				this.scrollTop = target;
			},
			current: {
				handler() {
					this.loadLyric();
				}
			}
		},
		onLoad() {
			this.isTablet = getScreenInfo().isTablet;
			this.unsubTrack = onTrackChange(() => {
				this.lyricLines = [];
				this.activeLine = -1;
				this.loadLyric();
			});
			if (this.current) this.loadLyric();
			// 一起听时禁止本地切歌/换歌外部同步 —— 本页仅控制本地播放
		},
		onUnload() {
			if (this.unsubTrack) this.unsubTrack();
		},
		methods: {
			formatTime,
			toggle,
			next() {
				next(false);
			},
			prev,
			setQuality,
			toggleMode() {
				const mode = cycleMode();
				uni.showToast({ title: MODE_ICONS[mode] || mode, icon: 'none' });
			},
			showModeMenu() {
				uni.showActionSheet({
					itemList: ['列表循环', '单曲循环', '随机播放'],
					success: (res) => {
						const target = ['list', 'single', 'shuffle'][res.tapIndex];
						while (this.player.mode !== target) cycleMode();
						uni.showToast({ title: MODE_ICONS[target], icon: 'none' });
					}
				});
			},
			onSeek(e) {
				seek(e.detail.value);
			},
			seekToLine(line) {
				seek(line.time);
			},
			goBack() {
				uni.navigateBack();
			},
			async loadLyric() {
				const song = this.current;
				if (!song) return;
				if (this.lyricPlatform === song.platform && this.lyricId === String(song.id)) return;
				this.lyricPlatform = song.platform;
				this.lyricId = String(song.id);
				this.lyricLoading = true;
				try {
					const data = await api.songLyric(song.platform, song.id);
					const parsed = parseLrc(data.lrc || '');
					this.lyricLines = parsed.lines;
				} catch (e) {
					this.lyricLines = [];
				} finally {
					this.lyricLoading = false;
				}
			},
			downloadCurrent() {
				if (!this.current) return;
				this.pickerVisible = true;
			},
			async onDownloadQuality(q) {
				if (!this.current) return;
				uni.showLoading({ title: '准备下载…', mask: true });
				try {
					await downloadSong(this.current, q.quality, (percent) => {
						uni.showLoading({ title: `下载中 ${percent}%`, mask: true });
					});
					uni.hideLoading();
					uni.showToast({ title: '下载完成', icon: 'success' });
				} catch (e) {
					uni.hideLoading();
					uni.showToast({ title: (e && e.message) || '下载失败', icon: 'none' });
				}
			}
		}
	};
</script>

<style scoped>
	.player-page {
		min-height: 100vh;
		background: #111318;
		color: #ffffff;
		position: relative;
		overflow: hidden;
		display: flex;
		flex-direction: column;
	}

	.bg-blur {
		position: absolute;
		inset: -80rpx;
		background-size: cover;
		background-position: center;
		filter: blur(60px) brightness(0.45);
		transform: scale(1.2);
		z-index: 0;
	}

	.status-bar-spacer {
		height: calc(var(--status-bar-height, 0px) + 16rpx);
	}

	.top-bar {
		position: relative;
		z-index: 1;
		display: flex;
		align-items: center;
		padding: 8rpx 24rpx;
	}

	.icon-btn {
		width: 72rpx;
		height: 72rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 44rpx;
		color: rgba(255, 255, 255, 0.85);
		flex-shrink: 0;
	}

	.top-title {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;
		align-items: center;
	}

	.top-name {
		font-size: 30rpx;
		font-weight: 500;
		max-width: 480rpx;
	}

	.top-sub {
		font-size: 22rpx;
		color: rgba(255, 255, 255, 0.55);
		margin-top: 4rpx;
		max-width: 480rpx;
	}

	.player-body {
		position: relative;
		z-index: 1;
		flex: 1;
		display: flex;
		flex-direction: column;
		padding: 24rpx 48rpx calc(env(safe-area-inset-bottom) + 32rpx);
		min-height: 0;
	}

	.cover-wrap {
		align-self: center;
		width: 480rpx;
		height: 480rpx;
		border-radius: 50%;
		overflow: hidden;
		border: 12rpx solid rgba(255, 255, 255, 0.08);
		animation: spin 24s linear infinite;
		margin-top: 24rpx;
	}

	.cover-wrap.paused {
		animation-play-state: paused;
	}

	.cover {
		width: 100%;
		height: 100%;
	}

	@keyframes spin {
		to {
			transform: rotate(360deg);
		}
	}

	.meta {
		display: flex;
		flex-direction: column;
		align-items: center;
		margin-top: 40rpx;
	}

	.name {
		font-size: 38rpx;
		font-weight: 600;
		max-width: 600rpx;
	}

	.artist {
		margin-top: 10rpx;
		font-size: 26rpx;
		color: rgba(255, 255, 255, 0.6);
		max-width: 600rpx;
	}

	.quality-row {
		display: flex;
		gap: 14rpx;
		margin-top: 18rpx;
	}

	.quality-chip {
		font-size: 22rpx;
		padding: 6rpx 18rpx;
		border-radius: 999rpx;
		border: 1px solid rgba(255, 255, 255, 0.3);
		color: rgba(255, 255, 255, 0.7);
	}

	.quality-chip.active {
		background: #ff4d4f;
		border-color: #ff4d4f;
		color: #ffffff;
	}

	.progress-area {
		margin-top: 32rpx;
	}

	.slider {
		margin: 0 8rpx;
	}

	.time-row {
		display: flex;
		justify-content: space-between;
		padding: 0 8rpx;
	}

	.time {
		font-size: 22rpx;
		color: rgba(255, 255, 255, 0.55);
	}

	.controls {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-top: 32rpx;
		padding: 0 8rpx;
	}

	.ctrl {
		width: 96rpx;
		height: 96rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		border-radius: 50%;
	}

	.ctrl-icon {
		font-size: 44rpx;
		color: rgba(255, 255, 255, 0.85);
	}

	.ctrl-icon.small {
		font-size: 34rpx;
		color: rgba(255, 255, 255, 0.65);
	}

	.ctrl.main {
		width: 128rpx;
		height: 128rpx;
		background: #ff4d4f;
		box-shadow: 0 8rpx 32rpx rgba(255, 77, 79, 0.45);
	}

	.ctrl-icon.large {
		font-size: 52rpx;
		color: #ffffff;
	}

	.lyric-col {
		flex: 1;
		min-height: 240rpx;
		margin-top: 32rpx;
		display: flex;
		overflow: hidden;
	}

	.lyric-scroll {
		flex: 1;
		height: 100%;
	}

	.lyric-padding {
		height: 120rpx;
	}

	.lyric-line {
		padding: 16rpx 24rpx;
		text-align: center;
		display: flex;
		flex-direction: column;
		align-items: center;
	}

	.lyric-text {
		font-size: 28rpx;
		color: rgba(255, 255, 255, 0.45);
		transition: color 0.2s;
	}

	.lyric-line.active .lyric-text {
		color: #ff6b6d;
		font-size: 32rpx;
		font-weight: 600;
	}

	.lyric-trans {
		font-size: 22rpx;
		color: rgba(255, 255, 255, 0.35);
		margin-top: 4rpx;
	}

	.lyric-line.active .lyric-trans {
		color: rgba(255, 155, 155, 0.8);
	}

	.lyric-empty {
		text-align: center;
		padding: 60rpx 0;
		font-size: 26rpx;
		color: rgba(255, 255, 255, 0.4);
	}

	/* 平板：左封面右歌词 */
	@media (min-width: 768px) {
		.player-body.is-tablet {
			flex-direction: row;
			gap: 48px;
			padding: 32px 48px;
			align-items: stretch;
		}
		.is-tablet .left-col {
			flex: 1;
			display: flex;
			flex-direction: column;
			justify-content: center;
		}
		.is-tablet .cover-wrap {
			width: 320px;
			height: 320px;
			border-radius: 24px;
			animation: none;
			margin-top: 0;
		}
		.is-tablet .lyric-col {
			flex: 1;
			margin-top: 0;
		}
		.is-tablet .lyric-line {
			padding: 10px 16px;
		}
		.is-tablet .lyric-text {
			font-size: 15px;
		}
		.is-tablet .lyric-line.active .lyric-text {
			font-size: 17px;
		}
	}
</style>