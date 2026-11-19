<template>
	<view class="login-page">
		<view class="login-bg"></view>
		<view class="login-card">
			<view class="brand">
				<image class="logo" src="/static/cover-placeholder.png" mode="aspectFill" />
				<text class="app-name">随身听</text>
				<text class="app-slogan">想听的歌，随时在身边</text>
			</view>

			<view class="tabs">
				<view class="tab" :class="{ active: mode === 'login' }" @click="mode = 'login'">登录</view>
				<view class="tab" :class="{ active: mode === 'register' }" @click="mode = 'register'">注册</view>
			</view>

			<view class="form">
				<view class="field">
					<text class="label">账号</text>
					<input
						v-model="username"
						class="input"
						placeholder="3-20 位字母、数字或下划线"
						:maxlength="20"
						auto-blur
					/>
				</view>
				<view class="field">
					<text class="label">密码</text>
					<input v-model="password" class="input" password placeholder="至少 6 位" :maxlength="72" />
				</view>
				<view v-if="mode === 'register'" class="field">
					<text class="label">确认密码</text>
					<input v-model="password2" class="input" password placeholder="再次输入密码" :maxlength="72" />
				</view>

				<button class="btn-primary submit" :disabled="submitting" @click="submit">
					{{ submitting ? '请稍候…' : mode === 'login' ? '登录' : '注册并登录' }}
				</button>
				<view class="skip" @click="skip">暂不登录，先逛逛 →</view>
			</view>
		</view>
	</view>
</template>

<script>
	import { api } from '@/utils/request.js';
	import { saveSession } from '@/store/user.js';

	export default {
		data() {
			return {
				mode: 'login',
				username: '',
				password: '',
				password2: '',
				submitting: false
			};
		},
		methods: {
			validate() {
				if (this.mode === 'register') {
					if (!/^[A-Za-z0-9_]{3,20}$/.test(this.username)) {
						uni.showToast({ title: '账号需为 3-20 位字母、数字或下划线', icon: 'none' });
						return false;
					}
					if (this.password.length < 6) {
						uni.showToast({ title: '密码至少 6 位', icon: 'none' });
						return false;
					}
					if (this.password !== this.password2) {
						uni.showToast({ title: '两次输入的密码不一致', icon: 'none' });
						return false;
					}
				}
				if (!this.username || !this.password) {
					uni.showToast({ title: '请输入账号和密码', icon: 'none' });
					return false;
				}
				return true;
			},
			async submit() {
				if (this.submitting || !this.validate()) return;
				this.submitting = true;
				try {
					const data =
						this.mode === 'login'
							? await api.login(this.username, this.password)
							: await api.register(this.username, this.password);
					saveSession(data.token, data.user);
					uni.showToast({ title: this.mode === 'login' ? '登录成功' : '注册成功', icon: 'success' });
					setTimeout(() => this.backOrHome(), 500);
				} catch (e) {
					// request 已 toast
				} finally {
					this.submitting = false;
				}
			},
			skip() {
				this.backOrHome();
			},
			backOrHome() {
				const pages = getCurrentPages();
				if (pages.length > 1) {
					uni.navigateBack();
				} else {
					uni.switchTab({ url: '/pages/index/index' });
				}
			}
		}
	};
</script>

<style scoped>
	.login-page {
		min-height: 100vh;
		position: relative;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 48rpx;
	}

	.login-bg {
		position: absolute;
		inset: 0;
		background: linear-gradient(160deg, #fff1f0 0%, #f5f6f8 55%, #eef1f6 100%);
	}

	.login-card {
		position: relative;
		width: 100%;
		max-width: 420px;
		background: #ffffff;
		border-radius: 32rpx;
		padding: 56rpx 44rpx 40rpx;
		box-shadow: 0 12rpx 48rpx rgba(31, 35, 41, 0.08);
	}

	.brand {
		display: flex;
		flex-direction: column;
		align-items: center;
		margin-bottom: 40rpx;
	}

	.logo {
		width: 120rpx;
		height: 120rpx;
		border-radius: 28rpx;
		background: #ff4d4f;
	}

	.app-name {
		margin-top: 20rpx;
		font-size: 40rpx;
		font-weight: 600;
		color: #1f2329;
	}

	.app-slogan {
		margin-top: 8rpx;
		font-size: 24rpx;
		color: #8f959e;
	}

	.tabs {
		display: flex;
		background: #f5f6f8;
		border-radius: 16rpx;
		padding: 6rpx;
		margin-bottom: 32rpx;
	}

	.tab {
		flex: 1;
		text-align: center;
		font-size: 28rpx;
		color: #646a73;
		line-height: 64rpx;
		border-radius: 12rpx;
	}

	.tab.active {
		background: #ffffff;
		color: #ff4d4f;
		font-weight: 600;
		box-shadow: 0 2rpx 8rpx rgba(31, 35, 41, 0.06);
	}

	.field {
		margin-bottom: 24rpx;
	}

	.label {
		font-size: 24rpx;
		color: #646a73;
		margin-bottom: 10rpx;
		display: block;
	}

	.input {
		height: 88rpx;
		background: #f5f6f8;
		border-radius: 14rpx;
		padding: 0 24rpx;
		font-size: 30rpx;
	}

	.submit {
		margin-top: 16rpx;
		width: 100%;
	}

	.skip {
		margin-top: 28rpx;
		text-align: center;
		font-size: 26rpx;
		color: #8f959e;
	}
</style>