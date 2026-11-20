<template>
	<view class="friends-page">
		<view class="head-actions">
			<view class="add-box">
				<input v-model="addUsername" class="add-input" placeholder="输入对方账号添加好友" />
				<view class="add-btn" @click="sendRequest">添加</view>
			</view>
		</view>

		<!-- 好友请求 -->
		<view v-if="requests.incoming.length" class="section">
			<text class="section-title">新的好友请求</text>
			<view v-for="req in requests.incoming" :key="req.id" class="friend-item">
				<view class="avatar">{{ initial(req.nickname) }}</view>
				<view class="friend-main">
					<text class="friend-name">{{ req.nickname }}</text>
					<text class="friend-sub">@{{ req.username }}</text>
				</view>
				<view class="req-actions">
					<view class="req-btn accept" @click="handle(req, true)">同意</view>
					<view class="req-btn" @click="handle(req, false)">拒绝</view>
				</view>
			</view>
		</view>

		<!-- 好友列表 -->
		<view class="section">
			<text class="section-title">我的好友（{{ friends.length }}）</text>
			<view v-if="!friends.length" class="empty">还没有好友，输入账号添加吧</view>
			<view v-for="f in friends" :key="f.id" class="friend-item">
				<view class="avatar">{{ initial(f.nickname) }}</view>
				<view class="friend-main">
					<text class="friend-name">{{ f.nickname }}</text>
					<text class="friend-sub">@{{ f.username }}<text v-if="f.room_id" class="in-room"> · 正在一起听</text></text>
				</view>
				<view v-if="f.room_id" class="join-btn" @click="joinFriendRoom(f)">加入</view>
				<view v-else class="room-btn" @click="inviteListen(f)">一起听</view>
				<view class="del-btn" @click="removeFriend(f)">✕</view>
			</view>
		</view>

		<view v-if="requests.outgoing.length" class="section">
			<text class="section-title">等待对方同意</text>
			<view v-for="req in requests.outgoing" :key="req.id" class="friend-item">
				<view class="avatar pending">{{ initial(req.nickname) }}</view>
				<view class="friend-main">
					<text class="friend-name">{{ req.nickname }}</text>
					<text class="friend-sub">@{{ req.username }}</text>
				</view>
				<text class="pending-text">等待中…</text>
			</view>
		</view>
	</view>
</template>

<script>
	import { api } from '@/utils/request.js';
	import { user } from '@/store/user.js';

	export default {
		data() {
			return {
				addUsername: '',
				friends: [],
				requests: { incoming: [], outgoing: [] }
			};
		},
		onShow() {
			if (!user.loggedIn) {
				uni.navigateTo({ url: '/pages/login/login' });
				return;
			}
			this.loadAll();
		},
		methods: {
			initial(name) {
				return (name || '?').slice(0, 1).toUpperCase();
			},
			async loadAll() {
				try {
					const [fr, rq] = await Promise.all([api.friends(), api.friendRequests()]);
					this.friends = fr.list || [];
					this.requests = rq || { incoming: [], outgoing: [] };
				} catch (e) {}
			},
			async sendRequest() {
				const username = this.addUsername.trim();
				if (!username) {
					uni.showToast({ title: '请输入对方账号', icon: 'none' });
					return;
				}
				try {
					const res = await api.addFriend(username);
					this.addUsername = '';
					if (res.status === 'accepted') {
						uni.showToast({ title: '已成为好友', icon: 'success' });
					} else {
						uni.showToast({ title: '请求已发送', icon: 'none' });
					}
					this.loadAll();
				} catch (e) {}
			},
			async handle(req, accept) {
				try {
					await api.handleFriend(req.id, accept);
					uni.showToast({ title: accept ? '已添加好友' : '已拒绝', icon: 'none' });
					this.loadAll();
				} catch (e) {}
			},
			async removeFriend(f) {
				uni.showModal({
					title: '删除好友',
					content: `确定删除好友「${f.nickname}」吗？`,
					success: async (res) => {
						if (!res.confirm) return;
						try {
							await api.removeFriend(f.id);
							this.loadAll();
						} catch (e) {}
					}
				});
			},
			async inviteListen(f) {
				uni.showLoading({ title: '创建房间…', mask: true });
				let room = null;
				try {
					room = await api.roomCreate(f.id);
				} catch (e) {
					room = null; // 失败提示由 request 统一弹出
				} finally {
					uni.hideLoading();
				}
				if (!room) return;
				uni.showModal({
					title: '房间已创建',
					content: `邀请码：${room.code}\n把邀请码发给好友，或让好友在「一起听」页直接加入。`,
					confirmText: '去房间',
					success: (res) => {
						if (res.confirm) {
							uni.setStorageSync('sst_room_id', room.room_id);
							uni.switchTab({ url: '/pages/room/room' });
						}
					}
				});
			},
			async joinFriendRoom(f) {
				// 好友在房间中 → 直接尝试加入其房间（按房间码）
				uni.showModal({
					title: '加入一起听',
					editable: true,
					placeholderText: '请输入对方的 6 位邀请码',
					success: async (res) => {
						if (!res.confirm || !res.content) return;
						try {
							const data = await api.roomJoin(res.content.trim().toUpperCase());
							uni.setStorageSync('sst_room_id', data.room_id);
							uni.switchTab({ url: '/pages/room/room' });
						} catch (e) {}
					}
				});
			}
		}
	};
</script>

<style scoped>
	.friends-page {
		min-height: 100vh;
		padding: 24rpx;
	}

	.head-actions {
		margin-bottom: 24rpx;
	}

	.add-box {
		display: flex;
		align-items: center;
		background: #ffffff;
		border-radius: 16rpx;
		padding: 10rpx 10rpx 10rpx 24rpx;
	}

	.add-input {
		flex: 1;
		height: 72rpx;
		font-size: 28rpx;
	}

	.add-btn {
		background: #ff4d4f;
		color: #ffffff;
		font-size: 26rpx;
		padding: 0 32rpx;
		line-height: 64rpx;
		border-radius: 12rpx;
	}

	.section {
		margin-bottom: 28rpx;
	}

	.section-title {
		font-size: 26rpx;
		color: #8f959e;
		margin-bottom: 16rpx;
		display: block;
		padding-left: 8rpx;
	}

	.friend-item {
		display: flex;
		align-items: center;
		background: #ffffff;
		border-radius: 20rpx;
		padding: 24rpx;
		margin-bottom: 14rpx;
	}

	.avatar {
		width: 84rpx;
		height: 84rpx;
		border-radius: 50%;
		background: #fff1f0;
		color: #ff4d4f;
		font-size: 34rpx;
		font-weight: 600;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
	}

	.avatar.pending {
		background: #f5f6f8;
		color: #8f959e;
	}

	.friend-main {
		flex: 1;
		min-width: 0;
		margin-left: 20rpx;
		display: flex;
		flex-direction: column;
	}

	.friend-name {
		font-size: 30rpx;
		color: #1f2329;
	}

	.friend-sub {
		font-size: 24rpx;
		color: #8f959e;
		margin-top: 6rpx;
	}

	.in-room {
		color: #22c55e;
	}

	.req-actions {
		display: flex;
		gap: 12rpx;
	}

	.req-btn {
		font-size: 24rpx;
		padding: 0 24rpx;
		line-height: 56rpx;
		border-radius: 999rpx;
		background: #f5f6f8;
		color: #646a73;
	}

	.req-btn.accept {
		background: #ff4d4f;
		color: #ffffff;
	}

	.room-btn,
	.join-btn {
		font-size: 24rpx;
		color: #ff4d4f;
		border: 1rpx solid #ff4d4f;
		padding: 0 22rpx;
		line-height: 52rpx;
		border-radius: 999rpx;
		margin-left: 12rpx;
	}

	.join-btn {
		background: #fff1f0;
	}

	.del-btn {
		margin-left: 16rpx;
		color: #c0c4cc;
		font-size: 30rpx;
		padding: 8rpx;
	}

	.pending-text {
		font-size: 24rpx;
		color: #b0b4ba;
	}

	@media (min-width: 768px) {
		.friends-page {
			max-width: 720px;
			margin: 0 auto;
			padding: 24px;
		}
		.friend-item {
			padding: 14px 16px;
			margin-bottom: 8px;
		}
	}
</style>