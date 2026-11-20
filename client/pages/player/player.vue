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
						:value="seeking ? dragValue : sliderValue"
						:max="sliderMax"
						:block-size="14"
						:disabled="!player.duration"
						active-color="#ff4d4f"
						background-color="rgba(255,255,255,0.25)"
						@changing="onChanging"
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
					<view class="ctrl main" :class="{ loading: player.loading }" @click="toggle">
						<text class="ctrl-icon large">{{ player.loading ? '…' : player.playing ? '❚❚' : '▶' }}</text>
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
					:scroll-with-animation="!userScrolling"
					:show-scrollbar="false"
					@touchstart="onUserScroll"
					@scroll="onLyricScroll"
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
	import { player, onTrackChange, toggle, next, prev, seek, setQuality as setQualityPref, cycleMode, playAt } from '@/store/player.js';
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
				dragValue: 0,
				userScrolling: false,
				scrollGuardTimer: null,
				userScrollPos: 0,
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
				return this.player.duration > 0 ? this.player.duration : 1;
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
				// 用户刚手动滚动过歌词：3 秒内不抢回，避免"刚滑就被拽回"的错觉
				if (this.userScrolling) return;
				const lineHeight = this.isTablet ? 64 : 48;
				this.applyAutoScroll(Math.max(0, idx * lineHeight));
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
			this.clearScrollGuard();
		},
		methods: {
			formatTime,
			toggle,
			next() {
				next(false);
			},
			prev,
			// 切换音质立即用新音质重载当前曲目（否则点了 chip 没有任何反馈）
			setQuality(q) {
				if (this.player.quality === q) return;
				setQualityPref(q);
				if (this.current) {
					playAt(this.player.index, this.player.currentTime, { force: true });
				}
				uni.showToast({ title: '已切换音质', icon: 'none' });
			},
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
			onChanging(e) {
				this.seeking = true;
				this.dragValue = e.detail.value;
			},
			onSeek(e) {
				this.seeking = false;
				seek(e.detail.value);
			},
			onUserScroll() {
				// 手指按下即视为手动接管：暂停自动跟随，3 秒无动作后恢复并重新对齐当前行
				this.userScrolling = true;
				this.clearScrollGuard();
				this.scrollGuardTimer = setTimeout(() => {
					this.scrollGuardTimer = null;
					this.userScrolling = false;
					const lineHeight = this.isTablet ? 64 : 48;
					this.applyAutoScroll(Math.max(0, this.activeLine * lineHeight));
				}, 3000);
			},
			onLyricScroll(e) {
				// 记录用户滚到的实际位置，便于恢复时判断是否需要重新对齐
				if (e && e.detail && typeof e.detail.scrollTop === 'number') {
					this.userScrollPos = e.detail.scrollTop;
				}
			},
			// scroll-view 的 scroll-top 是"值绑定"，值不变时不会滚动；相同目标需先偏移 1px
			applyAutoScroll(target) {
				const value = Math.max(0, target);
				if (Math.abs(this.scrollTop - value) < 2) {
					this.scrollTop = value > 0 ? value - 1 : 1;
					this.$nextTick(() => {
						this.scrollTop = value;
					});
				} else {
					this.scrollTop = value;
				}
			},
			clearScrollGuard() {
				if (this.scrollGuardTimer) {
					clearTimeout(this.scrollGuardTimer);
					this.scrollGuardTimer = null;
				}
				this.userScrolling = false;
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
				// 只开一层原生 loading：进度回调里反复 showLoading 会叠加遮罩，hideLoading 只关一层
				uni.showLoading({ title: '下载中…', mask: true });
				let ok = false;
				try {
					await downloadSong(this.current, q.quality);
					ok = true;
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '下载失败', icon: 'none' });
				} finally {
					uni.hideLoading();
				}
				if (ok) uni.showToast({ title: '下载完成', icon: 'success' });
			}
		}
	};
</script>

<style scoped>
	.player-page {
		height: 100vh;
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
		/* 纯装饰层，不能吞掉顶部/边缘的点击 */
		pointer-events: none;
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
		/* 矮屏/横屏自动收缩，给歌词与控制区留出空间 */
		width: clamp(240rpx, 40vh, 480rpx);
		height: clamp(240rpx, 40vh, 480rpx);
		border-radius: 50%;
		overflow: hidden;
		border: 12rpx solid rgba(255, 255, 255, 0.08);
		animation: spin 24s linear infinite;
		margin-top: 24rpx;
		flex-shrink: 0;
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

	/* 取地址/缓冲时给出可感知的反馈，避免"点了没反应" */
	.ctrl.main.loading {
		opacity: 0.65;
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