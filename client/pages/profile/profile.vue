<template>
	<PageWrap>
		<view class="profile">
			<!-- 用户卡片 -->
			<view class="user-card">
				<view class="avatar">{{ avatarText }}</view>
				<view class="user-main">
					<text class="nickname ellipsis">{{ user.info ? user.info.nickname || user.info.username : '未登录' }}</text>
					<text class="username">{{ user.loggedIn ? '@' + user.info.username : '登录后可加好友、一起听' }}</text>
				</view>
				<view v-if="!user.loggedIn" class="login-btn" @click="goLogin">登录 / 注册</view>
			</view>

			<!-- 功能列表 -->
			<view class="menu">
				<view class="menu-item" @click="goFriends">
					<text class="menu-icon">♫</text>
					<text class="menu-text">我的好友</text>
					<text class="menu-arrow">›</text>
				</view>
				<view class="menu-item" @click="goDownloads">
					<text class="menu-icon">⤓</text>
					<text class="menu-text">我的下载</text>
					<text class="menu-arrow">›</text>
				</view>
				<view class="menu-item" @click="editNickname">
					<text class="menu-icon">✎</text>
					<text class="menu-text">修改昵称</text>
					<text class="menu-arrow">›</text>
				</view>
				<view class="menu-item" @click="editServer">
					<text class="menu-icon">⚙</text>
					<text class="menu-text">服务器地址</text>
					<text class="menu-value ellipsis">{{ baseUrl }}</text>
					<text class="menu-arrow">›</text>
				</view>
			</view>

			<!-- 音质偏好 -->
			<view class="section">
				<text class="section-title">默认播放音质</text>
				<view class="quality-options">
					<view
						v-for="q in qualityOptions"
						:key="q.value"
						class="quality-option"
						:class="{ active: player.quality === q.value }"
						@click="chooseQuality(q.value)"
					>
						<text class="q-label">{{ q.label }}</text>
						<text class="q-desc">{{ q.desc }}</text>
					</view>
				</view>
				<text class="hint">实际音质取决于音源是否提供，缺失时自动降级</text>
			</view>

			<!-- 退出登录 -->
			<view v-if="user.loggedIn" class="logout-btn" @click="logout">退出登录</view>

			<view class="footer">
				<text class="footer-text">随身听 v1.0.0</text>
				<text class="footer-text">音源来自第三方公开资源，仅供个人学习使用</text>
			</view>

			<MiniPlayer />
		</view>
	</PageWrap>
</template>

<script>
	import PageWrap from '@/components/PageWrap.vue';
	import MiniPlayer from '@/components/MiniPlayer.vue';
	import { user, logoutLocal, updateUserInfo } from '@/store/user.js';
	import { player, setQuality } from '@/store/player.js';
	import { api } from '@/utils/request.js';
	import { getBaseUrl, setBaseUrl } from '@/config.js';

	export default {
		components: { PageWrap, MiniPlayer },
		data() {
			return {
				user,
				player,
				baseUrl: getBaseUrl(),
				qualityOptions: [
					{ value: 'flac', label: '无损', desc: 'FLAC 优先' },
					{ value: '320', label: '320K', desc: 'MP3 高品' },
					{ value: '128', label: '128K', desc: '省流量' }
				]
			};
		},
		computed: {
			avatarText() {
				const name = this.user.info ? this.user.info.nickname || this.user.info.username : '?';
				return (name || '?').slice(0, 1).toUpperCase();
			}
		},
		onShow() {
			this.baseUrl = getBaseUrl();
		},
		methods: {
			goLogin() {
				uni.navigateTo({ url: '/pages/login/login' });
			},
			goFriends() {
				if (!this.user.loggedIn) return this.goLogin();
				uni.navigateTo({ url: '/pages/friends/friends' });
			},
			goDownloads() {
				uni.switchTab({ url: '/pages/downloads/downloads' });
			},
			chooseQuality(q) {
				setQuality(q);
				uni.showToast({ title: '已设为默认音质', icon: 'none' });
			},
			editNickname() {
				if (!this.user.loggedIn) return this.goLogin();
				uni.showModal({
					title: '修改昵称',
					editable: true,
					placeholderText: '输入新昵称（最多 16 字）',
					success: async (res) => {
						if (!res.confirm || !res.content) return;
						try {
							await api.updateProfile(res.content.trim());
							updateUserInfo({ nickname: res.content.trim() });
							uni.showToast({ title: '已保存', icon: 'success' });
						} catch (e) {}
					}
				});
			},
			editServer() {
				uni.showModal({
					title: '服务器地址',
					editable: true,
					placeholderText: 'https://域名/api',
					success: (res) => {
						if (!res.confirm || !res.content) return;
						const url = res.content.trim();
						if (!/^https?:\/\/.+/.test(url)) {
							uni.showToast({ title: '地址需以 http(s):// 开头', icon: 'none' });
							return;
						}
						setBaseUrl(url);
						this.baseUrl = getBaseUrl();
						uni.showToast({ title: '已保存', icon: 'success' });
					}
				});
			},
			async logout() {
				uni.showModal({
					title: '退出登录',
					content: '确定退出当前账号吗？',
					success: async (res) => {
						if (!res.confirm) return;
						try {
							await api.logout();
						} catch (e) {}
						logoutLocal();
						uni.showToast({ title: '已退出', icon: 'none' });
					}
				});
			}
		}
	};
</script>

<style scoped>
	.user-card {
		display: flex;
		align-items: center;
		background: linear-gradient(135deg, #ff4d4f, #ff7a45);
		border-radius: 24rpx;
		padding: 36rpx 32rpx;
		margin-bottom: 28rpx;
	}

	.avatar {
		width: 104rpx;
		height: 104rpx;
		border-radius: 50%;
		background: rgba(255, 255, 255, 0.25);
		color: #ffffff;
		font-size: 44rpx;
		font-weight: 600;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
	}

	.user-main {
		flex: 1;
		min-width: 0;
		margin-left: 24rpx;
		display: flex;
		flex-direction: column;
	}

	.nickname {
		font-size: 36rpx;
		font-weight: 600;
		color: #ffffff;
	}

	.username {
		font-size: 24rpx;
		color: rgba(255, 255, 255, 0.75);
		margin-top: 8rpx;
	}

	.login-btn {
		background: #ffffff;
		color: #ff4d4f;
		font-size: 26rpx;
		padding: 0 28rpx;
		line-height: 60rpx;
		border-radius: 999rpx;
		flex-shrink: 0;
	}

	.menu {
		background: #ffffff;
		border-radius: 24rpx;
		overflow: hidden;
		margin-bottom: 28rpx;
	}

	.menu-item {
		display: flex;
		align-items: center;
		padding: 32rpx;
		border-bottom: 1rpx solid #f0f1f4;
	}

	.menu-item:last-child {
		border-bottom: none;
	}

	.menu-icon {
		font-size: 32rpx;
		color: #ff4d4f;
		width: 48rpx;
	}

	.menu-text {
		flex: 1;
		font-size: 30rpx;
		color: #1f2329;
	}

	.menu-value {
		font-size: 24rpx;
		color: #8f959e;
		max-width: 320rpx;
		margin-right: 12rpx;
	}

	.menu-arrow {
		color: #c0c4cc;
		font-size: 36rpx;
	}

	.section {
		background: #ffffff;
		border-radius: 24rpx;
		padding: 32rpx;
		margin-bottom: 28rpx;
	}

	.section-title {
		font-size: 30rpx;
		font-weight: 600;
		display: block;
		margin-bottom: 24rpx;
	}

	.quality-options {
		display: flex;
		gap: 16rpx;
	}

	.quality-option {
		flex: 1;
		border: 2rpx solid #e8eaed;
		border-radius: 16rpx;
		padding: 20rpx 0;
		display: flex;
		flex-direction: column;
		align-items: center;
	}

	.quality-option.active {
		border-color: #ff4d4f;
		background: #fff1f0;
	}

	.q-label {
		font-size: 28rpx;
		font-weight: 600;
		color: #1f2329;
	}

	.quality-option.active .q-label {
		color: #ff4d4f;
	}

	.q-desc {
		font-size: 20rpx;
		color: #8f959e;
		margin-top: 6rpx;
	}

	.hint {
		display: block;
		font-size: 22rpx;
		color: #8f959e;
		margin-top: 20rpx;
	}

	.logout-btn {
		background: #ffffff;
		border-radius: 24rpx;
		text-align: center;
		line-height: 96rpx;
		font-size: 30rpx;
		color: #ff4d4f;
		margin-bottom: 28rpx;
	}

	.footer {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8rpx;
		padding: 24rpx 0 8rpx;
	}

	.footer-text {
		font-size: 22rpx;
		color: #b0b4ba;
	}

	@media (min-width: 768px) {
		.profile {
			max-width: 720px;
			margin: 0 auto;
		}
		.user-card {
			padding: 24px;
		}
	}
</style>