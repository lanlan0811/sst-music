<template>
	<view class="room-page">
		<!-- 未登录 -->
		<view v-if="!user.loggedIn" class="guest">
			<text class="guest-title">登录后和朋友一起听</text>
			<text class="guest-sub">双人共享歌单，实时同步播放</text>
			<view class="btn-primary guest-btn" @click="goLogin">去登录</view>
		</view>

		<!-- 未进房 -->
		<view v-else-if="!room" class="lobby">
			<view class="lobby-card">
				<text class="lobby-title">一起听</text>
				<text class="lobby-sub">和好友共享一个歌单，播放进度实时同步</text>

				<view class="join-row">
					<input v-model="code" class="code-input" placeholder="输入 6 位邀请码" :maxlength="6" />
					<view class="btn-primary join-btn" @click="joinByCode">加入</view>
				</view>

				<view class="divider"><text class="divider-text">或 邀请好友</text></view>
				<view v-if="!friends.length" class="empty">还没有好友，先去添加好友吧</view>
				<view v-for="f in friends" :key="f.id" class="friend-row">
					<view class="avatar">{{ initial(f.nickname) }}</view>
					<text class="friend-name ellipsis">{{ f.nickname }}</text>
					<view class="invite-btn" @click="createRoom(f)">邀请</view>
				</view>
				<view class="btn-ghost manage-btn" @click="goFriends">管理好友</view>
			</view>
		</view>

		<!-- 房间中 -->
			<view v-else class="room">
				<view class="room-head">
					<view class="room-info">
						<text class="room-title">一起听房间</text>
						<view class="room-code-row">
							<text class="room-code">邀请码 {{ room.code }}</text>
							<text class="copy-btn" @click="copyCode">复制</text>
						</view>
					</view>
					<view class="room-head-right">
						<view class="sync-state" :class="syncClass">
							<text class="sync-dot"></text>
							<text class="sync-text">{{ syncText }}</text>
						</view>
						<view class="add-btn" @click="goSearch">去首页加歌</view>
					</view>
				</view>

				<view class="body" :class="{ 'is-tablet': isTablet }">
				<!-- 左：播放控制 -->
				<view class="player-col">
					<view class="now-card">
						<image
							v-if="currentSong"
							class="now-cover"
							:src="currentSong.cover || '/static/cover-placeholder.png'"
							mode="aspectFill"
						/>
						<view v-else class="now-cover placeholder-cover"><text>♪</text></view>
						<view class="now-info">
							<text class="now-name ellipsis">{{ currentSong ? currentSong.name : '队列还是空的' }}</text>
							<text class="now-artist ellipsis">{{ currentSong ? currentSong.artist : '在首页搜索并加入歌曲' }}</text>
							<text class="now-by" v-if="currentSong">由 {{ adderName(currentSong.added_by) }} 添加</text>
						</view>
					</view>

					<view class="members">
						<view class="member">
							<view class="avatar small">{{ initial(members.owner && members.owner.nickname) }}</view>
							<text class="member-name ellipsis">{{ members.owner ? members.owner.nickname : '房主' }}</text>
							<text class="member-tag">房主</text>
						</view>
						<view class="member">
							<view class="avatar small guest-avatar">{{ members.guest ? initial(members.guest.nickname) : '?' }}</view>
							<text class="member-name ellipsis">{{ members.guest ? members.guest.nickname : '等待好友加入' }}</text>
						</view>
					</view>

					<view class="progress-area">
						<slider
							class="slider"
							:value="sliderValue"
							:max="sliderMax"
							:block-size="14"
							active-color="#ff4d4f"
							background-color="#e8eaed"
							@change="onSeek"
						/>
						<view class="time-row">
							<text class="time">{{ formatTime(displayPosition) }}</text>
							<text class="time">{{ formatTime(currentDuration) }}</text>
						</view>
					</view>

					<view class="controls">
						<view class="ctrl" @click="command('prev')"><text class="ctrl-icon">⏮</text></view>
						<view class="ctrl main" @click="togglePlay">
							<text class="ctrl-icon large">{{ state.is_playing ? '❚❚' : '▶' }}</text>
						</view>
						<view class="ctrl" @click="command('next')"><text class="ctrl-icon">⏭</text></view>
					</view>

					<view class="leave-btn" @click="leaveRoom">退出房间</view>
				</view>

				<!-- 右：共享队列 -->
				<view class="queue-col">
					<view class="queue-head">
						<text class="queue-title">共享歌单</text>
						<text class="queue-count">{{ queue.length }}/50</text>
					</view>
					<view v-if="!queue.length" class="empty">还没有歌曲<br />去首页搜索，点「＋」加入一起听</view>
					<scroll-view v-else scroll-y class="queue-scroll">
						<view
							v-for="(item, i) in queue"
							:key="item.id"
							class="queue-item"
							:class="{ active: i === state.queue_index }"
							@click="command('jump', { index: i })"
						>
							<text class="queue-index">{{ i + 1 }}</text>
							<view class="queue-main">
								<text class="queue-name ellipsis">{{ item.name }}</text>
								<text class="queue-sub ellipsis">{{ item.artist }}</text>
							</view>
							<text class="queue-del" @click.stop="removeSong(item)">✕</text>
						</view>
					</scroll-view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import { api } from '@/utils/request.js';
	import { user } from '@/store/user.js';
	import { setRemoteMode, player, playAt, stop } from '@/store/player.js';
	import * as audio from '@/utils/audio.js';
	import { getScreenInfo } from '@/utils/layout.js';
	import { formatTime } from '@/utils/lyrics.js';

	const POLL_INTERVAL = 1500;

	export default {
		data() {
			return {
				user,
				room: null,
				state: { queue_index: -1, is_playing: false, position_ms: 0, state_at: 0 },
				queue: [],
				members: { owner: null, guest: null },
				version: -1,
				code: '',
				friends: [],
				isTablet: false,
				pollTimer: null,
				tickTimer: null,
				tick: 0,
				localOffset: 0,
				syncState: 'idle',
				playingIndexLoaded: -1,
				lastCommandAt: 0
			};
		},
		computed: {
			currentSong() {
				if (this.state.queue_index < 0 || this.state.queue_index >= this.queue.length) return null;
				return this.queue[this.state.queue_index];
			},
			isOwner() {
				return !!(this.members.owner && user.info && this.members.owner.id === user.info.id);
			},
			syncText() {
				return { idle: '同步中', synced: '已同步', calibrating: '校准进度…' }[this.syncState] || '同步中';
			},
			syncClass() {
				return this.syncState;
			},
			currentDuration() {
				if (this.player.duration) return this.player.duration;
				return (this.currentSong && this.currentSong.duration ? this.currentSong.duration * 1000 : 0);
			},
			sliderMax() {
				return Math.max(1, this.currentDuration);
			},
			sliderValue() {
				return Math.min(this.displayPosition, this.sliderMax);
			},
			displayPosition() {
				// tick 为 500ms 一次的响应式计数器，保证播放中时间持续前进
				this.tick;
				if (this.state.is_playing && this.state.state_at) {
					const elapsed = Date.now() - this.state.state_at + this.localOffset;
					return Math.max(0, this.state.position_ms + elapsed);
				}
				return Math.max(0, this.state.position_ms);
			},
			player() {
				return player;
			}
		},
		onShow() {
			if (!user.loggedIn) return;
			this.isTablet = getScreenInfo().isTablet;
			const saved = uni.getStorageSync('sst_room_id');
			if (saved) {
				this.resume(saved);
			} else {
				this.loadFriends();
			}
			uni.$on('sst_add_to_room', this.handleExternalAdd);
			uni.$on('sst_remote_ended', this.handleRemoteEnded);
			uni.$on('sst_remote_toggle', this.handleRemoteToggle);
		},
		onHide() {
			this.stopPolling();
			uni.$off('sst_add_to_room', this.handleExternalAdd);
			uni.$off('sst_remote_ended', this.handleRemoteEnded);
			uni.$off('sst_remote_toggle', this.handleRemoteToggle);
		},
		onUnload() {
			this.stopPolling();
			uni.$off('sst_add_to_room', this.handleExternalAdd);
			uni.$off('sst_remote_ended', this.handleRemoteEnded);
			uni.$off('sst_remote_toggle', this.handleRemoteToggle);
		},
		methods: {
			formatTime,
			initial(name) {
				return (name || '?').slice(0, 1).toUpperCase();
			},
			adderName(id) {
				if (!this.members.owner) return '好友';
				if (this.members.owner.id === id) return this.members.owner.nickname;
				if (this.members.guest && this.members.guest.id === id) return this.members.guest.nickname;
				return '好友';
			},
			goLogin() {
				uni.navigateTo({ url: '/pages/login/login' });
			},
			goFriends() {
				uni.navigateTo({ url: '/pages/friends/friends' });
			},
			goSearch() {
				uni.switchTab({ url: '/pages/index/index' });
			},
			async loadFriends() {
				try {
					const data = await api.friends();
					this.friends = data.list || [];
				} catch (e) {}
			},
			async createRoom(f) {
				uni.showLoading({ title: '创建房间…', mask: true });
				try {
					const room = await api.roomCreate(f.id);
					uni.hideLoading();
					this.enterRoom({ room_id: room.room_id, code: room.code });
					uni.showToast({ title: `邀请码 ${room.code}`, icon: 'none', duration: 3000 });
				} catch (e) {
					uni.hideLoading();
				}
			},
			async joinByCode() {
				const code = this.code.trim().toUpperCase();
				if (!/^[A-Z0-9]{6}$/.test(code)) {
					uni.showToast({ title: '请输入 6 位邀请码', icon: 'none' });
					return;
				}
				try {
					const data = await api.roomJoin(code);
					this.enterRoom({ room_id: data.room_id, code });
				} catch (e) {}
			},
			async resume(roomId) {
				// 用 v=-2 强制拉全量
				try {
					const data = await api.roomState(roomId, -2);
					if (!data || data.changed === false || !data.code) {
						uni.removeStorageSync('sst_room_id');
						this.loadFriends();
						return;
					}
					this.enterRoom({ room_id: roomId, code: data.code }, data);
				} catch (e) {
					uni.removeStorageSync('sst_room_id');
					this.loadFriends();
				}
			},
			enterRoom(roomRef, initialSnapshot) {
				this.room = roomRef;
				uni.setStorageSync('sst_room_id', roomRef.room_id);
				uni.setStorageSync('sst_room_code', roomRef.code);
				setRemoteMode(true);
				this.version = -1;
				this.playingIndexLoaded = -1;
				if (initialSnapshot) {
					this.applySnapshot(initialSnapshot);
				}
				this.loadFriends();
				this.startPolling();
			},
			applySnapshot(snap) {
				if (!snap || snap.changed === false) return;
				const prevVersion = this.version;
				this.version = snap.version;
				const prevIndex = this.state.queue_index;
				const wasPlaying = this.state.is_playing;
				this.state = snap.state;
				this.queue = snap.queue || [];
				this.members = snap.members || { owner: null, guest: null };
				if (snap.code) this.room.code = snap.code;
				// 服务器时间与本机时间差
				const now = Date.now();
				this.localOffset = (snap.server_time || now) - now;
				this.syncQueuePlayback(prevIndex);
			},
			syncQueuePlayback(prevIndex) {
				// 把房间队列转成播放器队列（元数据一致）
				const playList = this.queue.map((q) => ({
					id: q.song_id,
					platform: q.platform,
					name: q.name,
					artist: q.artist,
					album: q.album,
					cover: q.cover,
					duration: q.duration,
					qualities: q.qualities || []
				}));
				const state = this.state;
				player.queue = playList;
				if (!playList.length) {
					stop();
					this.playingIndexLoaded = -1;
					this.syncState = 'synced';
					return;
				}
				const idx = Math.max(0, Math.min(state.queue_index, playList.length - 1));
				const target = this.computeTargetPosition();

				// 触发加载：切曲、队列长度变化、或尚未加载
				const needLoad = this.playingIndexLoaded !== idx || !player.current;
				if (needLoad) {
					this.playingIndexLoaded = idx;
					this.syncState = 'calibrating';
					playAt(idx, target).then(() => {
						if (!this.state.is_playing) audio.pause();
						this.syncState = 'synced';
					});
					return;
				}
				// 同曲目：对齐播放状态/进度
				const drift = Math.abs(player.currentTime - target);
				if (drift > 1500) {
					this.syncState = 'calibrating';
					audio.seek(target);
					setTimeout(() => (this.syncState = 'synced'), 300);
				} else {
					this.syncState = 'synced';
				}
				if (state.is_playing && !player.playing && !player.loading) {
					audio.play();
				}
				if (!state.is_playing && player.playing) {
					audio.pause();
				}
			},
			computeTargetPosition() {
				if (this.state.is_playing && this.state.state_at) {
					return Math.max(0, this.state.position_ms + (Date.now() - this.state.state_at + this.localOffset));
				}
				return Math.max(0, this.state.position_ms);
			},
			startPolling() {
				this.stopPolling();
				const tick = async () => {
					if (!this.room) return;
					try {
						const data = await api.roomState(this.room.room_id, this.version);
						if (data && data.changed === false) return;
						if (data && data.changed) this.applySnapshot(data);
					} catch (e) {
						if (e && (e.code === 409 || e.code === 404 || e.code === 403)) {
							// 房间已关闭 / 已被移出
							this.exitLocal();
							uni.showToast({ title: '房间已关闭', icon: 'none' });
						}
					}
				};
				this.pollTimer = setInterval(tick, POLL_INTERVAL);
				// 独立计时器驱动进度显示（轮询返回 changed:false 时也能前进）
				this.tickTimer = setInterval(() => {
					this.tick = (this.tick + 1) % 1000000;
				}, 500);
				tick();
			},
			stopPolling() {
				if (this.pollTimer) {
					clearInterval(this.pollTimer);
					this.pollTimer = null;
				}
				if (this.tickTimer) {
					clearInterval(this.tickTimer);
					this.tickTimer = null;
				}
			},
			async command(action, payload = {}) {
				if (!this.room) return;
				if (Date.now() - this.lastCommandAt < 300) return;
				this.lastCommandAt = Date.now();
				try {
					const snap = await api.roomCommand(this.room.room_id, this.version, action, payload);
					this.applySnapshot(snap);
				} catch (e) {
					if (e && e.code === 409 && e.data) {
						this.applySnapshot(e.data);
						uni.showToast({ title: '已同步到最新状态', icon: 'none' });
					} else if (e && (e.code === 403 || e.code === 404)) {
						this.exitLocal();
						uni.showToast({ title: '房间已关闭', icon: 'none' });
					} else if (e && e.msg) {
						uni.showToast({ title: e.msg, icon: 'none' });
					}
				}
			},
			togglePlay() {
				if (!this.queue.length) {
					uni.showToast({ title: '共享歌单是空的', icon: 'none' });
					return;
				}
				// 以服务器推算位置为准，避免本机音频未就绪时上传 0
				const pos = this.displayPosition;
				if (this.state.is_playing) {
					this.command('pause', { position_ms: pos });
				} else {
					this.command('play', { index: this.state.queue_index, position_ms: pos });
				}
			},
			onSeek(e) {
				this.command('seek', { position_ms: e.detail.value });
			},
			addSong(song) {
				return this.command('add_song', {
					platform: song.platform,
					song_id: String(song.id),
					name: song.name,
					artist: song.artist || '',
					album: song.album || '',
					cover: song.cover || '',
					duration: song.duration || 0,
					qualities: song.qualities || []
				});
			},
			handleExternalAdd(song) {
				if (!this.room) {
					uni.showToast({ title: '进入一起听房间后才能加歌', icon: 'none' });
					return;
				}
				this.addSong(song).then(() => {
					uni.showToast({ title: '已加入共享歌单', icon: 'none' });
				});
			},
			handleRemoteEnded() {
				if (!this.room || !this.queue.length) return;
				// 播完自动下一首（由服务器统一推进，所有端以服务器状态为准）
				this.command('next');
			},
			handleRemoteToggle() {
				if (!this.room) return;
				this.togglePlay();
			},
			removeSong(item) {
				uni.showModal({
					title: '移出歌单',
					content: `确定把「${item.name}」移出共享歌单吗？`,
					success: (res) => {
						if (res.confirm) this.command('remove_song', { item_id: item.id });
					}
				});
			},
			copyCode() {
				uni.setClipboardData({
					data: this.room.code,
					success: () => uni.showToast({ title: '邀请码已复制', icon: 'none' })
				});
			},
			leaveRoom() {
				uni.showModal({
					title: '退出房间',
					content: '退出后将不再同步播放，' + (this.isOwner ? '你是房主，退出会关闭房间。' : '确定退出吗？'),
					success: async (res) => {
						if (!res.confirm) return;
						try {
							await api.roomLeave(this.room.room_id);
						} catch (e) {}
						this.exitLocal();
					}
				});
			},
			exitLocal() {
				this.stopPolling();
				uni.removeStorageSync('sst_room_id');
				uni.removeStorageSync('sst_room_code');
				setRemoteMode(false);
				stop();
				this.room = null;
				this.queue = [];
				this.state = { queue_index: -1, is_playing: false, position_ms: 0, state_at: 0 };
				this.version = -1;
				this.playingIndexLoaded = -1;
				this.loadFriends();
			}
		}
	};
</script>

<style scoped>
	.room-page {
		min-height: 100vh;
		padding: 24rpx;
		padding-bottom: calc(env(safe-area-inset-bottom) + 140rpx);
	}

	.guest {
		display: flex;
		flex-direction: column;
		align-items: center;
		padding-top: 200rpx;
	}

	.guest-title {
		font-size: 36rpx;
		font-weight: 600;
	}

	.guest-sub {
		font-size: 26rpx;
		color: #8f959e;
		margin-top: 12rpx;
	}

	.guest-btn {
		margin-top: 48rpx;
		padding: 0 80rpx;
	}

	.lobby-card {
		background: #ffffff;
		border-radius: 24rpx;
		padding: 40rpx 32rpx;
	}

	.lobby-title {
		font-size: 40rpx;
		font-weight: 600;
		display: block;
	}

	.lobby-sub {
		font-size: 26rpx;
		color: #8f959e;
		margin-top: 10rpx;
		display: block;
	}

	.join-row {
		display: flex;
		gap: 16rpx;
		margin-top: 32rpx;
	}

	.code-input {
		flex: 1;
		height: 88rpx;
		background: #f5f6f8;
		border-radius: 14rpx;
		padding: 0 24rpx;
		font-size: 30rpx;
		letter-spacing: 4rpx;
		text-align: center;
	}

	.join-btn {
		width: 180rpx;
		line-height: 88rpx;
		text-align: center;
	}

	.divider {
		display: flex;
		align-items: center;
		margin: 40rpx 0 24rpx;
	}

	.divider::before,
	.divider::after {
		content: '';
		flex: 1;
		height: 1rpx;
		background: #f0f1f4;
	}

	.divider-text {
		padding: 0 20rpx;
		font-size: 24rpx;
		color: #b0b4ba;
	}

	.friend-row {
		display: flex;
		align-items: center;
		padding: 20rpx 8rpx;
	}

	.avatar {
		width: 80rpx;
		height: 80rpx;
		border-radius: 50%;
		background: #fff1f0;
		color: #ff4d4f;
		font-size: 32rpx;
		font-weight: 600;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
	}

	.avatar.small {
		width: 64rpx;
		height: 64rpx;
		font-size: 26rpx;
	}

	.guest-avatar {
		background: #f5f6f8;
		color: #8f959e;
	}

	.friend-name {
		flex: 1;
		margin-left: 20rpx;
		font-size: 30rpx;
	}

	.invite-btn {
		color: #ff4d4f;
		border: 1rpx solid #ff4d4f;
		font-size: 24rpx;
		padding: 0 28rpx;
		line-height: 56rpx;
		border-radius: 999rpx;
	}

	.manage-btn {
		margin-top: 24rpx;
	}

	.room-head {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 24rpx;
	}

	.room-title {
		font-size: 36rpx;
		font-weight: 600;
	}

	.room-code-row {
		display: flex;
		align-items: center;
		margin-top: 8rpx;
	}

	.room-code {
		font-size: 26rpx;
		color: #646a73;
		letter-spacing: 2rpx;
	}

	.copy-btn {
		font-size: 22rpx;
		color: #ff4d4f;
		margin-left: 16rpx;
		border: 1rpx solid #ff4d4f;
		padding: 2rpx 14rpx;
		border-radius: 999rpx;
	}

	.sync-state {
		display: flex;
		align-items: center;
		gap: 8rpx;
		padding: 8rpx 20rpx;
		border-radius: 999rpx;
		background: #ffffff;
	}

	.room-head-right {
		display: flex;
		align-items: center;
		gap: 16rpx;
	}

	.add-btn {
		font-size: 24rpx;
		color: #ff4d4f;
		background: #fff1f0;
		border-radius: 999rpx;
		padding: 10rpx 22rpx;
	}

	.sync-dot {
		width: 12rpx;
		height: 12rpx;
		border-radius: 50%;
		background: #8f959e;
	}

	.sync-state.synced .sync-dot {
		background: #22c55e;
	}

	.sync-state.calibrating .sync-dot {
		background: #f59e0b;
	}

	.sync-text {
		font-size: 22rpx;
		color: #646a73;
	}

	.body {
		display: flex;
		flex-direction: column;
		gap: 24rpx;
	}

	.player-col {
		background: #ffffff;
		border-radius: 24rpx;
		padding: 32rpx;
	}

	.now-card {
		display: flex;
		align-items: center;
	}

	.now-cover {
		width: 128rpx;
		height: 128rpx;
		border-radius: 20rpx;
		background: #f0f1f4;
		flex-shrink: 0;
	}

	.placeholder-cover {
		display: flex;
		align-items: center;
		justify-content: center;
		color: #c0c4cc;
		font-size: 44rpx;
	}

	.now-info {
		flex: 1;
		min-width: 0;
		margin-left: 24rpx;
		display: flex;
		flex-direction: column;
	}

	.now-name {
		font-size: 32rpx;
		font-weight: 600;
	}

	.now-artist {
		font-size: 26rpx;
		color: #646a73;
		margin-top: 8rpx;
	}

	.now-by {
		font-size: 22rpx;
		color: #b0b4ba;
		margin-top: 8rpx;
	}

	.members {
		display: flex;
		gap: 16rpx;
		margin-top: 24rpx;
	}

	.member {
		flex: 1;
		display: flex;
		align-items: center;
		background: #f5f6f8;
		border-radius: 16rpx;
		padding: 14rpx 18rpx;
	}

	.member-name {
		flex: 1;
		font-size: 24rpx;
		margin-left: 12rpx;
	}

	.member-tag {
		font-size: 20rpx;
		color: #ff4d4f;
	}

	.progress-area {
		margin-top: 28rpx;
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
		color: #8f959e;
	}

	.controls {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 48rpx;
		margin-top: 24rpx;
	}

	.ctrl {
		width: 96rpx;
		height: 96rpx;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.ctrl-icon {
		font-size: 44rpx;
		color: #1f2329;
	}

	.ctrl.main {
		width: 120rpx;
		height: 120rpx;
		border-radius: 50%;
		background: #ff4d4f;
		box-shadow: 0 8rpx 24rpx rgba(255, 77, 79, 0.35);
	}

	.ctrl-icon.large {
		font-size: 48rpx;
		color: #ffffff;
	}

	.leave-btn {
		margin-top: 28rpx;
		text-align: center;
		font-size: 26rpx;
		color: #8f959e;
		padding: 12rpx;
	}

	.queue-col {
		background: #ffffff;
		border-radius: 24rpx;
		padding: 28rpx;
	}

	.queue-head {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16rpx;
	}

	.queue-title {
		font-size: 30rpx;
		font-weight: 600;
	}

	.queue-count {
		font-size: 22rpx;
		color: #8f959e;
	}

	.queue-scroll {
		max-height: 520rpx;
	}

	.queue-item {
		display: flex;
		align-items: center;
		padding: 18rpx 12rpx;
		border-radius: 14rpx;
	}

	.queue-item.active {
		background: #fff1f0;
	}

	.queue-index {
		width: 48rpx;
		font-size: 24rpx;
		color: #b0b4ba;
		text-align: center;
	}

	.queue-item.active .queue-index {
		color: #ff4d4f;
	}

	.queue-main {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;
		margin-left: 8rpx;
	}

	.queue-name {
		font-size: 28rpx;
	}

	.queue-item.active .queue-name {
		color: #ff4d4f;
		font-weight: 600;
	}

	.queue-sub {
		font-size: 22rpx;
		color: #8f959e;
		margin-top: 4rpx;
	}

	.queue-del {
		color: #c0c4cc;
		font-size: 26rpx;
		padding: 8rpx 12rpx;
	}

	@media (min-width: 768px) {
		.room-page {
			max-width: 1080px;
			margin: 0 auto;
			padding: 24px;
		}
		.body.is-tablet {
			flex-direction: row;
			align-items: flex-start;
			gap: 24px;
		}
		.is-tablet .player-col {
			flex: 3;
			padding: 24px;
		}
		.is-tablet .queue-col {
			flex: 2;
			padding: 20px;
		}
		.is-tablet .queue-scroll {
			max-height: 560px;
		}
	}
</style>