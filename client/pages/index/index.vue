<template>
	<PageWrap>
		<view class="home">
			<!-- 搜索区 -->
			<view class="search-section">
				<view class="platform-tabs">
					<view
						v-for="p in platforms"
						:key="p.value"
						class="platform-tab"
						:class="{ active: platform === p.value }"
						@click="switchPlatform(p.value)"
					>
						{{ p.label }}
					</view>
				</view>
				<view class="search-box">
					<text class="search-icon">⌕</text>
					<input
						v-model="keyword"
						class="search-input"
						placeholder="搜索歌曲、歌手"
						confirm-type="search"
						@confirm="doSearch"
					/>
					<view v-if="keyword" class="clear-btn" @click="clearKeyword">✕</view>
					<view class="search-btn" @click="doSearch">搜索</view>
				</view>
			</view>

			<!-- 快捷入口 -->
			<view class="quick-links">
				<view class="quick-item" @click="goFriends">
					<text class="quick-icon">♫</text>
					<text class="quick-text">好友</text>
				</view>
				<view class="quick-item" @click="goRoom">
					<text class="quick-icon">♬</text>
					<text class="quick-text">一起听</text>
				</view>
				<view class="quick-item" @click="goDownloads">
					<text class="quick-icon">⤓</text>
					<text class="quick-text">下载</text>
				</view>
			</view>

			<!-- 搜索结果 -->
			<view v-if="searched" class="results">
				<view class="results-head">
					<text class="results-title">搜索结果</text>
					<text class="results-count">{{ total }} 首</text>
				</view>

				<view v-if="loading && !list.length" class="empty">搜索中…</view>
				<view v-else-if="!list.length" class="empty">{{ emptyText }}</view>
				<view v-else class="song-grid">
					<SongItem
						v-for="song in list"
						:key="song.platform + '_' + song.id"
						:song="song"
						@play="playSong(song)"
						@download="openDownload"
						@add="addToRoom(song)"
					/>
				</view>
				<view v-if="list.length" class="load-more">
					<text v-if="loading">加载中…</text>
					<text v-else-if="hasMore" @click="loadMore">点击加载更多</text>
					<text v-else>没有更多了</text>
				</view>
			</view>

			<!-- 未搜索时的欢迎区 -->
			<view v-else class="welcome">
				<text class="welcome-title">搜索想听的歌</text>
				<text class="welcome-sub">支持酷我、网易两个音源平台，可切换重试</text>
				<view class="hot-tags">
					<view v-for="tag in hotTags" :key="tag" class="hot-tag" @click="quickSearch(tag)">{{ tag }}</view>
				</view>
			</view>

			<QualityPicker
				:visible="picker.visible"
				:title="picker.title"
				:qualities="picker.qualities"
				@close="picker.visible = false"
				@pick="onQualityPicked"
			/>

			<MiniPlayer />
		</view>
	</PageWrap>
</template>

<script>
	import PageWrap from '@/components/PageWrap.vue';
	import SongItem from '@/components/SongItem.vue';
	import MiniPlayer from '@/components/MiniPlayer.vue';
	import QualityPicker from '@/components/QualityPicker.vue';
	import { api } from '@/utils/request.js';
	import { PLATFORMS } from '@/config.js';
	import { setQueue, player, setRemoteMode } from '@/store/player.js';
	import { downloadSong, findDownload } from '@/utils/download.js';
	const LAST_QUERY = 'sst_last_query';

	export default {
		components: { PageWrap, SongItem, MiniPlayer, QualityPicker },
		data() {
			return {
				platforms: PLATFORMS,
				platform: 'kuwo',
				keyword: '',
				searched: false,
				loading: false,
				list: [],
				total: 0,
				page: 1,
				hasMore: false,
				lastQuery: '',
				picker: { visible: false, title: '选择音质', qualities: [], song: null, intent: 'play' },
				hotTags: ['晴天', '告白气球', 'Adele', '夜曲', '起风了', 'Hello']
			};
		},
		computed: {
			emptyText() {
				const p = this.platform === 'kuwo' ? '网易' : '酷我';
				return `没有找到「${this.lastQuery}」相关歌曲，试试切换到${p}平台或换个关键词`;
			}
		},
		onLoad() {
			try {
				const saved = uni.getStorageSync(LAST_QUERY);
				if (saved && saved.keyword) {
					this.platform = saved.platform || 'kuwo';
					this.keyword = saved.keyword;
					this.doSearch();
				}
			} catch (e) {}
		},
		methods: {
			switchPlatform(p) {
				if (this.platform === p) return;
				this.platform = p;
				if (this.searched && this.lastQuery) this.doSearch();
			},
			clearKeyword() {
				this.keyword = '';
				this.searched = false;
				this.list = [];
			},
			quickSearch(tag) {
				this.keyword = tag;
				this.doSearch();
			},
			async doSearch() {
				const kw = this.keyword.trim();
				if (!kw) {
					uni.showToast({ title: '请输入搜索关键词', icon: 'none' });
					return;
				}
				this.loading = true;
				this.searched = true;
				this.page = 1;
				this.lastQuery = kw;
				this.list = [];
				try {
					uni.setStorageSync(LAST_QUERY, { keyword: kw, platform: this.platform });
				} catch (e) {}
				try {
					const data = await api.search(this.platform, kw, 1);
					this.list = (data.list || []).map((s) => ({ ...s, platform: this.platform }));
					this.total = data.total || this.list.length;
					this.hasMore = this.list.length >= 20;
				} catch (e) {
					this.list = [];
				} finally {
					this.loading = false;
				}
			},
			async loadMore() {
				if (this.loading || !this.hasMore) return;
				await this.fetchPage(this.page + 1);
			},
			async fetchPage(page) {
				this.loading = true;
				try {
					const data = await api.search(this.platform, this.lastQuery, page);
					const more = (data.list || []).map((s) => ({ ...s, platform: this.platform }));
					this.list = this.list.concat(more);
					this.page = page;
					this.hasMore = more.length >= 20;
				} catch (e) {
				} finally {
					this.loading = false;
				}
			},
			playSong(song) {
				if (!(song.qualities || []).length) {
					uni.showToast({ title: '该歌曲暂无可用音质', icon: 'none' });
					return;
				}
				// 退出一起听本地接管，避免房间页与首页互相抢占播放器
				if (player.remote) setRemoteMode(false);
				const queue = this.list.filter((s) => (s.qualities || []).length).map((s) => ({ ...s }));
				const idx = queue.findIndex((s) => s.id === song.id && s.platform === song.platform);
				setQueue(queue, idx >= 0 ? idx : 0);
				uni.navigateTo({ url: '/pages/player/player' });
			},
			openDownload(song) {
				if (!(song.qualities || []).length) {
					uni.showToast({ title: '该歌曲暂无可用音质', icon: 'none' });
					return;
				}
				const local = findDownload(song.platform, song.id);
				if (local) {
					uni.showModal({
						title: '已下载',
						content: `已下载「${local.name}」，重新下载会覆盖旧文件，是否继续？`,
						success: (res) => {
							if (res.confirm) this.showQualityPicker(song);
						}
					});
					return;
				}
				this.showQualityPicker(song);
			},
			showQualityPicker(song) {
				this.picker = {
					visible: true,
					title: '选择下载音质',
					qualities: song.qualities || [],
					song,
					intent: 'download'
				};
			},
			async onQualityPicked(q) {
				const song = this.picker.song;
				if (!song) return;
				uni.showLoading({ title: '准备下载…', mask: true });
				try {
					await downloadSong(song, q.quality, (percent) => {
						uni.showLoading({ title: `下载中 ${percent}%`, mask: true });
					});
					uni.hideLoading();
					uni.showToast({ title: '下载完成', icon: 'success' });
				} catch (e) {
					uni.hideLoading();
					uni.showToast({ title: (e && e.message) || '下载失败', icon: 'none' });
				}
			},
			addToRoom(song) {
				uni.$emit('sst_add_to_room', song);
				uni.showToast({ title: '已加入一起听队列', icon: 'none' });
			},
			goFriends() {
				uni.navigateTo({ url: '/pages/friends/friends' });
			},
			goRoom() {
				uni.switchTab({ url: '/pages/room/room' });
			},
			goDownloads() {
				uni.switchTab({ url: '/pages/downloads/downloads' });
			}
		}
	};
</script>

<style scoped>
	.search-section {
		position: sticky;
		top: 0;
		z-index: 10;
		background: #f5f6f8;
		padding-bottom: 16rpx;
	}

	.platform-tabs {
		display: flex;
		gap: 16rpx;
		margin-bottom: 20rpx;
	}

	.platform-tab {
		padding: 10rpx 32rpx;
		border-radius: 999rpx;
		background: #ffffff;
		color: #646a73;
		font-size: 26rpx;
	}

	.platform-tab.active {
		background: #ff4d4f;
		color: #ffffff;
	}

	.search-box {
		display: flex;
		align-items: center;
		background: #ffffff;
		border-radius: 999rpx;
		padding: 8rpx 8rpx 8rpx 28rpx;
	}

	.search-icon {
		font-size: 36rpx;
		color: #8f959e;
	}

	.search-input {
		flex: 1;
		height: 72rpx;
		font-size: 30rpx;
		margin-left: 12rpx;
	}

	.clear-btn {
		padding: 0 12rpx;
		color: #c0c4cc;
		font-size: 28rpx;
	}

	.search-btn {
		background: #ff4d4f;
		color: #ffffff;
		font-size: 28rpx;
		padding: 0 36rpx;
		line-height: 72rpx;
		border-radius: 999rpx;
	}

	.quick-links {
		display: flex;
		gap: 16rpx;
		margin: 8rpx 0 24rpx;
	}

	.quick-item {
		flex: 1;
		background: #ffffff;
		border-radius: 20rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 22rpx 0;
		gap: 10rpx;
	}

	.quick-icon {
		font-size: 30rpx;
		color: #ff4d4f;
	}

	.quick-text {
		font-size: 26rpx;
		color: #1f2329;
	}

	.results-head {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16rpx;
	}

	.results-title {
		font-size: 30rpx;
		font-weight: 600;
	}

	.results-count {
		font-size: 24rpx;
		color: #8f959e;
	}

	.load-more {
		text-align: center;
		color: #8f959e;
		font-size: 24rpx;
		padding: 24rpx 0 8rpx;
	}

	.welcome {
		margin-top: 80rpx;
		display: flex;
		flex-direction: column;
		align-items: center;
	}

	.welcome-title {
		font-size: 40rpx;
		font-weight: 600;
	}

	.welcome-sub {
		margin-top: 12rpx;
		font-size: 26rpx;
		color: #8f959e;
	}

	.hot-tags {
		display: flex;
		flex-wrap: wrap;
		gap: 16rpx;
		justify-content: center;
		margin-top: 40rpx;
	}

	.hot-tag {
		background: #ffffff;
		border-radius: 999rpx;
		padding: 12rpx 28rpx;
		font-size: 26rpx;
		color: #646a73;
	}

	@media (min-width: 768px) {
		.song-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 10px;
		}
		.search-section {
			padding-bottom: 12px;
		}
	}
</style>