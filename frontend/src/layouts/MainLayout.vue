<script setup lang="ts">
/**
 * 主布局：顶栏 + 夜色侧边栏（菜单按角色动态渲染）+ 内容区。
 * 内容区保持浅色，深色仅出现在框架装饰层。
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import { useDictStore } from '@/stores/dict'
import { useDeptStore } from '@/stores/user'
import { useSiteConfigStore } from '@/stores/siteConfig'
import { fetchBootstrap } from '@/api/bootstrap'
import { fetchNotifyUnread, readNotify } from '@/api/notify'
import { notifyPermission, requestNotifyPermission, useNotifyPolling } from '@/composables/useNotify'
import { fmtDateTime } from '@/utils/format'
import type { NotificationItem } from '@/types'
import { MENU, type MenuNode } from '@/router'
import { APP_VERSION } from '@/constants/version'
import RoleTag from '@/components/RoleTag.vue'
import MoonLoader from '@/components/illustrations/MoonLoader.vue'
import ChangePasswordDialog from '@/views/auth/ChangePasswordDialog.vue'

const auth = useAuthStore()
const dict = useDictStore()
const deptStore = useDeptStore()
const siteConfig = useSiteConfigStore()
const route = useRoute()
const router = useRouter()

const changePwdVisible = ref(false)
const isNarrow = ref(false)
const menuCollapsed = ref(false)

/** 消息通知：轮询未弹过系统通知的消息 → 浏览器 Notification 弹 Windows 通知 */
const { unread: notifyUnread, refresh: refreshNotify } = useNotifyPolling()
const notifyPanel = ref<NotificationItem[]>([])
const bellLoading = ref(false)
const permState = ref(notifyPermission())

const permTip = computed(() => {
  if (permState.value === 'unsupported') {
    return '当前浏览器不支持系统通知，仅能在消息中心查看'
  }
  if (permState.value === 'granted') {
    return '桌面通知已开启（页面打开着即可收到，可最小化）'
  }
  if (permState.value === 'denied') {
    return '桌面通知已被浏览器拒绝，请在地址栏状图标里重新允许'
  }
  return '开启后可在 Windows 通知中心收到提醒（需页面保持打开）'
})

async function enableNotify(): Promise<void> {
  const res = await requestNotifyPermission()
  permState.value = res
  if (res === 'granted') {
    ElMessage.success('桌面通知已开启')
  } else if (res === 'denied') {
    ElMessage.warning('已拒绝通知权限，可在浏览器地址栏的锁形图标里重新允许')
  }
}

async function loadNotifyPanel(): Promise<void> {
  bellLoading.value = true
  try {
    const res = await fetchNotifyUnread()
    notifyPanel.value = res.items
  } catch {
    // 请求层已统一提示，这里不重复
  } finally {
    bellLoading.value = false
  }
}

async function markAllRead(): Promise<void> {
  if (!notifyPanel.value.some((x) => !x.isRead)) {
    ElMessage.info('没有未读消息')
    return
  }
  await readNotify({ all: true })
  await Promise.all([loadNotifyPanel(), refreshNotify()])
  ElMessage.success('已全部标记为已读')
}

async function goNotifyItem(item: NotificationItem): Promise<void> {
  if (!item.isRead) {
    try {
      await readNotify({ id: item.id })
      item.isRead = true
      notifyUnread.value = Math.max(0, notifyUnread.value - 1)
    } catch {
      // 标记失败不影响跳转
    }
  }
  if (item.link) {
    void router.push(item.link)
  }
}

function goNotifyCenter(): void {
  void router.push('/notifications')
}

/** 按角色过滤菜单 */
const visibleMenu = computed<MenuNode[]>(() => MENU.filter((m) => auth.hasRole(m.roles)))

const menuGroups = computed(() => {
  const groups: Array<{ name: string; items: MenuNode[] }> = []
  const plain: MenuNode[] = []
  visibleMenu.value.forEach((m) => {
    if (m.group) {
      let g = groups.find((x) => x.name === m.group)
      if (!g) {
        g = { name: m.group, items: [] }
        groups.push(g)
      }
      g.items.push(m)
    } else {
      plain.push(m)
    }
  })
  return { plain, groups }
})

const activePath = computed(() => {
  if (route.path.startsWith('/issues/') && route.path !== '/issues' && route.path !== '/issues/create') {
    return '/issues'
  }
  return route.path
})

/* —— 菜单分组折叠:默认全部收起,点击组标题切换;当前所在组自动展开 —— */
const MENU_GROUPS_KEY = 'command_menu_groups_expanded'
const expandedGroups = reactive<Record<string, boolean>>(readExpandedGroups())

function readExpandedGroups(): Record<string, boolean> {
  try {
    return JSON.parse(localStorage.getItem(MENU_GROUPS_KEY) || '{}') as Record<string, boolean>
  } catch {
    return {}
  }
}

function persistExpandedGroups(): void {
  localStorage.setItem(MENU_GROUPS_KEY, JSON.stringify(expandedGroups))
}

function toggleGroup(name: string): void {
  expandedGroups[name] = !expandedGroups[name]
  persistExpandedGroups()
}

// 进入/切换页面时自动展开该页所在分组，其余保持收起（避免全部铺开）
watch(
  activePath,
  (path) => {
    const g = MENU.find((m) => m.path === path)?.group
    if (g && !expandedGroups[g]) {
      expandedGroups[g] = true
      persistExpandedGroups()
    }
  },
  { immediate: true },
)

const currentTitle = computed(() => (route.meta.title as string) || '软件开发管理平台')

function checkNarrow(): void {
  isNarrow.value = window.innerWidth < 900
  if (isNarrow.value) {
    menuCollapsed.value = true
  }
}

onMounted(() => {
  checkNarrow()
  window.addEventListener('resize', checkNarrow)
  void loadBootstrap()
})

onUnmounted(() => {
  window.removeEventListener('resize', checkNarrow)
})

watch(
  () => route.path,
  () => {
    // 路由切换时收起窄屏菜单
    if (isNarrow.value) {
      menuCollapsed.value = true
    }
  },
)

async function onLogout(): Promise<void> {
  await ElMessageBox.confirm('确定要退出登录吗？', '退出登录', {
    confirmButtonText: '退出',
    cancelButtonText: '取消',
    type: 'warning',
  })
  await auth.logout()
  dict.reset()
  siteConfig.reset()
  await router.replace('/login')
}

function goProfile(): void {
  void router.push('/profile')
}

function openChangePassword(): void {
  changePwdVisible.value = true
}

const menuIconMap: Record<string, string> = {
  edit: 'M4 20h4l10-10-4-4L4 16v4Zm13.7-13.3 1.3-1.3a1 1 0 0 1 1.4 0l1.2 1.2a1 1 0 0 1 0 1.4l-1.3 1.3-2.6-2.6Z',
  list: 'M4 5h16v2H4V5Zm0 6h16v2H4v-2Zm0 6h10v2H4v-2Z',
  duty: 'M7 2v2h10V2h2v2h1a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1V2h2Zm13 8H4v8h16v-8ZM7 13h3v3H7v-3Zm5 0h3v3h-3v-3Z',
  chart: 'M4 19h16v2H4v-2ZM6 16V9h3v7H6Zm5 0V5h3v11h-3Zm5 0v-4h3v4h-3Z',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14Z',
  dept: 'M12 3 3 8v2h18V8l-9-5ZM5 12v6H3v2h18v-2h-2v-6h-2v6h-2v-6h-2v6h-2v-6H9v6H7v-6H5Z',
  dict: 'M5 4h14v3H5V4Zm0 6h14v3H5v-3Zm0 6h9v3H5v-3Z',
  log: 'M6 3h9l4 4v14H6V3Zm8 1.5V8h3.5L14 4.5ZM8 12h8v1.6H8V12Zm0 4h8v1.6H8V16Z',
  notice: 'M4 4h16v12H8l-4 4V4Zm3 3h10v1.8H7V7Zm0 3.4h7v1.8H7v-1.8Z',
  review: 'M5 3h14a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm2 4v2h6V7H7Zm0 4v2h10v-2H7Zm0 4v2h10v-2H7Z',
  pool: 'M12 3c4.4 0 8 1.3 8 3s-3.6 3-8 3-8-1.3-8-3 3.6-3 8-3Zm8 6v4c0 1.7-3.6 3-8 3s-8-1.3-8-3V9c1.8 1.2 4.7 2 8 2s6.2-.8 8-2Zm0 7v3c0 1.7-3.6 3-8 3s-8-1.3-8-3v-3c1.8 1.2 4.7 2 8 2s6.2-.8 8-2Z',
  design: 'M4 4h16v12H4V4Zm2 2v8h12V6H6Zm2 10v2H6v2h12v-2h-2v-2H8Z',
  battle: 'M9.4 16.6 4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4Zm5.2 0 4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4Z',
  release: 'M2 21 23 12 2 3v7l15 2-15 2v7Z',
  headset: 'M12 3a8 8 0 0 0-8 8v5a3 3 0 0 0 3 3h1a1 1 0 0 0 1-1v-6a1 1 0 0 0-1-1H6v-1a6 6 0 0 1 12 0v1h-2a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h1a3 3 0 0 0 3-3v-5a8 8 0 0 0-8-8Z',
  kanban: 'M3 4h5v16H3V4Zm6.5 0h5v10h-5V4ZM16 4h5v13h-5V4Z',
  gear: 'M19.4 13a7.5 7.5 0 0 0 .1-1 7.5 7.5 0 0 0-.1-1l2.1-1.6a.5.5 0 0 0 .1-.7l-2-3.4a.5.5 0 0 0-.6-.2l-2.5 1a7.7 7.7 0 0 0-1.7-1l-.4-2.6a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 0-.5.4l-.4 2.7a7.7 7.7 0 0 0-1.7 1l-2.5-1a.5.5 0 0 0-.6.2l-2 3.4a.5.5 0 0 0 .1.7L4.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6a.5.5 0 0 0-.1.7l2 3.4c.1.2.4.3.6.2l2.5-1a7.7 7.7 0 0 0 1.7 1l.4 2.6c0 .3.2.5.5.5h4c.2 0 .4-.2.5-.4l.4-2.7a7.7 7.7 0 0 0 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.4a.5.5 0 0 0-.1-.7L19.4 13ZM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z',
}

/** 一级菜单（分组）图标 */
const groupIconMap: Record<string, string> = {
  售后管理: 'headset',
  开发管理: 'kanban',
  系统管理: 'gear',
}

// 今日值班人（所有登录用户可见：仅展示，配置权归管理员）
const dutyToday = ref<{ onDuty: boolean; userName: string | null; date: string } | null>(null)
// 首屏基础数据是否已就绪（就绪前不渲染子页面，避免各页重复拉字典/部门）
const bootstrapped = ref(false)

/**
 * 首屏基础数据：一次请求拉齐 字典 + 部门 + 今日值班人，并回填当前用户。
 * 合并请求是为了适配单线程服务器（每次请求都要新建数据库连接）。
 * 必须先于子页面挂载完成，否则子页面会各自重复请求同一批数据。
 */
async function loadBootstrap(): Promise<void> {
  try {
    const data = await fetchBootstrap()
    dict.applyItems(data.dicts)
    deptStore.applyItems(data.departments)
    dutyToday.value = data.dutyToday
    if (data.user) {
      auth.applyUser(data.user)
    }
    // 灯牌 logo 配置：与首屏基础数据一并拉取（失败不影响页面）
    void siteConfig.load()
  } catch {
    dutyToday.value = null
  } finally {
    bootstrapped.value = true
  }
}
</script>

<template>
  <div class="main-layout" :class="{ 'is-narrow': isNarrow, 'is-collapsed': menuCollapsed }">
    <!-- 侧边栏：夜色底，主内容区保持浅色 -->
    <aside class="sidebar">
      <div class="sidebar__brand">
        <span class="sidebar__logo">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 2.6 4 7v10l8 4.4 8-4.4V7l-8-4.4Z" fill="#EAF2FF" opacity="0.9" />
            <path d="M12 8.4 7.6 10.8v4.4L12 17.6l4.4-2.4v-4.4L12 8.4Z" fill="#F2A0C0" />
          </svg>
        </span>
        <span v-show="!menuCollapsed" class="sidebar__title">软件开发管理平台</span>
      </div>

      <nav class="sidebar__menu">
        <router-link
          v-for="m in menuGroups.plain"
          :key="m.path"
          class="menu-item"
          :class="{ 'is-active': activePath === m.path }"
          :to="m.path"
        >
          <svg class="menu-item__icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path :d="menuIconMap[m.icon || 'list']" />
          </svg>
          <span v-show="!menuCollapsed" class="menu-item__text">{{ m.title }}</span>
        </router-link>

        <div
          v-for="g in menuGroups.groups"
          :key="g.name"
          class="menu-group"
          :class="{ 'is-open': expandedGroups[g.name] }"
        >
          <button
            v-show="!menuCollapsed"
            type="button"
            class="menu-group__title"
            :class="{ 'is-open': expandedGroups[g.name] }"
            :aria-expanded="expandedGroups[g.name] ? 'true' : 'false'"
            @click="toggleGroup(g.name)"
          >
            <svg class="menu-item__icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path :d="menuIconMap[groupIconMap[g.name] || 'list']" />
            </svg>
            <span class="menu-group__name">{{ g.name }}</span>
            <span class="menu-group__arrow" aria-hidden="true">{{ expandedGroups[g.name] ? '▾' : '▸' }}</span>
          </button>
          <template v-if="menuCollapsed || expandedGroups[g.name]">
            <router-link
              v-for="m in g.items"
              :key="m.path"
              class="menu-item"
              :class="{ 'is-active': activePath === m.path }"
              :to="m.path"
            >
              <svg class="menu-item__icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path :d="menuIconMap[m.icon || 'list']" />
              </svg>
              <span v-show="!menuCollapsed" class="menu-item__text">{{ m.title }}</span>
            </router-link>
          </template>
        </div>
      </nav>

      <button class="sidebar__toggle" type="button" :title="menuCollapsed ? '展开菜单' : '收起菜单'" @click="menuCollapsed = !menuCollapsed">
        {{ menuCollapsed ? '»' : '«' }}
      </button>

      <!-- 版本号：便于确认线上是否已更新到最新 -->
      <div v-show="!menuCollapsed" class="sidebar__version" :title="`当前版本 v${APP_VERSION}`">v{{ APP_VERSION }}</div>
    </aside>

    <div class="main-layout__body">
      <!-- 顶栏 -->
      <header class="topbar">
        <div class="topbar__left">
          <h1 class="topbar__title">{{ currentTitle }}</h1>
        </div>
        <div class="topbar__right">
          <div class="duty-chip" :class="dutyToday?.onDuty ? 'is-on' : 'is-off'" :title="`今日值班（${dutyToday?.date ?? ''}）`">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M7 2v2h10V2h2v2h1a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1V2h2Zm13 8H4v8h16v-8Z" />
            </svg>
            <span>今日值班：</span>
            <span class="duty-chip__name">{{ dutyToday?.onDuty ? dutyToday.userName : '未排班' }}</span>
          </div>
          <!-- 消息通知铃铛：未读角标 + 下拉面板 -->
          <el-popover placement="bottom-end" :width="360" trigger="click" @show="loadNotifyPanel">
            <template #reference>
              <button class="bell" type="button" :title="permTip">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path d="M12 2a6 6 0 0 0-6 6v3.6L4.3 15a1 1 0 0 0 .9 1.5h13.6a1 1 0 0 0 .9-1.5L18 11.6V8a6 6 0 0 0-6-6Zm0 20a3 3 0 0 0 3-3H9a3 3 0 0 0 3 3Z" />
                </svg>
                <span v-if="notifyUnread > 0" class="bell__badge">{{ notifyUnread > 99 ? '99+' : notifyUnread }}</span>
              </button>
            </template>

            <div class="bell-panel">
              <div class="bell-panel__head">
                <span class="bell-panel__title">消息通知</span>
                <el-button link type="primary" size="small" @click="markAllRead">全部已读</el-button>
              </div>

              <div v-if="permState !== 'granted'" class="bell-panel__perm">
                <span class="bell-panel__perm-text">{{ permTip }}</span>
                <el-button v-if="permState === 'default'" link type="primary" size="small" @click="enableNotify">开启桌面通知</el-button>
              </div>

              <div v-if="bellLoading" class="bell-panel__empty">加载中…</div>
              <div v-else-if="!notifyPanel.length" class="bell-panel__empty">暂无消息</div>
              <ul v-else class="bell-panel__list">
                <li
                  v-for="n in notifyPanel"
                  :key="n.id"
                  class="bell-panel__item"
                  :class="{ 'is-unread': !n.isRead, 'is-clickable': !!n.link }"
                  @click="goNotifyItem(n)"
                >
                  <span class="bell-panel__dot" aria-hidden="true" />
                  <div class="bell-panel__main">
                    <div class="bell-panel__ihead">
                      <span class="bell-panel__ititle">{{ n.title }}</span>
                      <span class="bell-panel__itime">{{ fmtDateTime(n.createdAt) }}</span>
                    </div>
                    <div class="bell-panel__ibody">{{ n.content }}</div>
                  </div>
                </li>
              </ul>

              <div class="bell-panel__foot">
                <el-button link type="primary" size="small" @click="goNotifyCenter">查看全部消息</el-button>
              </div>
            </div>
          </el-popover>

          <el-dropdown trigger="click">
            <div class="topbar__user">
              <el-avatar :size="30" :src="auth.user?.avatar || undefined" class="topbar__avatar">
                {{ (auth.user?.name || auth.user?.account || '?').slice(0, 1) }}
              </el-avatar>
              <span class="topbar__name">{{ auth.user?.name || auth.user?.account }}</span>
              <RoleTag v-if="auth.user" :roles="auth.user.roles" />
              <span class="topbar__caret">▾</span>
            </div>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item @click="goProfile">个人中心</el-dropdown-item>
                <el-dropdown-item @click="openChangePassword">修改密码</el-dropdown-item>
                <el-dropdown-item divided @click="onLogout">退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </header>

      <main class="main-layout__content">
        <div v-if="!bootstrapped" class="boot-loading">
          <MoonLoader text="正在加载基础数据…" />
        </div>
        <router-view v-else v-slot="{ Component }">
          <component :is="Component" />
        </router-view>
      </main>
    </div>

    <ChangePasswordDialog v-model="changePwdVisible" />
  </div>
</template>

<style scoped>
.main-layout {
  display: flex;
  height: 100vh;
  overflow: hidden;
  background: var(--color-bg-page);
}

/* —— 侧边栏 —— */
.sidebar {
  position: relative;
  display: flex;
  flex-direction: column;
  width: 220px;
  flex: 0 0 auto;
  background: var(--color-night);
  transition: width var(--transition-slow);
}

.main-layout.is-collapsed .sidebar {
  width: 64px;
}

.sidebar__brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 18px 16px;
  border-bottom: 1px solid rgba(234, 242, 255, 0.12);
}

.sidebar__logo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.12);
  flex: 0 0 auto;
}

.sidebar__title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-on-night);
  white-space: nowrap;
}

.sidebar__menu {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 10px;
  overflow-y: auto;
  flex: 1;
  /* 菜单项较多时允许内部滚动：用细滚动条，避免 Windows 默认粗滚动条在深色底上非常突兀 */
  scrollbar-width: thin;
  scrollbar-color: rgba(234, 242, 255, 0.26) transparent;
}

.sidebar__menu::-webkit-scrollbar {
  width: 5px;
}

.sidebar__menu::-webkit-scrollbar-thumb {
  background: rgba(234, 242, 255, 0.24);
  border-radius: 999px;
}

.sidebar__menu::-webkit-scrollbar-thumb:hover {
  background: rgba(234, 242, 255, 0.42);
}

.sidebar__menu::-webkit-scrollbar-track {
  background: transparent;
}

.menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 34px;
  padding: 0 12px;
  border-radius: var(--radius-pill);
  color: var(--color-text-on-night-sub);
  font-size: 14px;
  white-space: nowrap;
  transition: background var(--transition-base), color var(--transition-base);
}

.menu-item:hover {
  background: rgba(234, 242, 255, 0.1);
  color: var(--color-text-on-night);
}

.menu-item.is-active {
  background: rgba(124, 140, 228, 0.42);
  color: #ffffff;
  font-weight: 500;
}

.menu-item__icon {
  flex: 0 0 auto;
}

.menu-group {
  display: flex;
  flex-direction: column;
  gap: 3px;
  margin-top: 12px;
}

.menu-group__title {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 34px;
  padding: 0 12px;
  border: 0;
  background: transparent;
  font-size: 14px;
  font-weight: 600;
  color: rgba(234, 242, 255, 0.82);
  text-align: left;
  cursor: pointer;
  transition: color var(--transition-base), background var(--transition-base);
}

.menu-group__title:hover {
  color: #fff;
}

.menu-group__title.is-open {
  color: #fff;
}

.menu-group__name {
  flex: 1 1 auto;
}

.menu-group__arrow {
  flex: 0 0 auto;
  font-size: 10px;
  line-height: 1;
  opacity: 0.7;
}

.sidebar__toggle {
  height: 34px;
  margin: 8px 10px 14px;
  border: 1px solid rgba(234, 242, 255, 0.16);
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--color-text-on-night-sub);
  font-size: 12px;
  cursor: pointer;
  transition: background var(--transition-base);
}

.sidebar__toggle:hover {
  background: rgba(234, 242, 255, 0.1);
  color: var(--color-text-on-night);
}

/* 侧边栏底部版本号（低对比度，仅作核对用） */
.sidebar__version {
  padding: 0 10px 12px;
  text-align: center;
  font-size: 11px;
  letter-spacing: 0.5px;
  color: rgba(234, 242, 255, 0.42);
  font-variant-numeric: tabular-nums;
}

/* —— 主体 —— */
.main-layout__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  height: 56px;
  padding: 0 20px;
  background: var(--color-bg-container);
  border-bottom: 1px solid var(--color-border-divider);
  flex: 0 0 auto;
}

.topbar__title {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-primary);
}

/* 今日值班人标记 */
.topbar__right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.duty-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  height: 28px;
  padding: 0 12px;
  border-radius: var(--radius-pill);
  font-size: 12px;
  white-space: nowrap;
}

.duty-chip.is-on {
  color: #2c7d5e;
  background: var(--color-success-tint);
}

.duty-chip.is-off {
  color: var(--color-text-secondary);
  background: var(--color-bg-sub);
}

.duty-chip__name {
  font-weight: 600;
}
/* 消息通知铃铛 */
.bell {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--color-text-regular);
  cursor: pointer;
  transition: background var(--transition-base), color var(--transition-base);
}

.bell:hover {
  background: var(--color-bg-sub);
  color: var(--color-primary);
}

.bell__badge {
  position: absolute;
  top: 1px;
  right: 0;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  border-radius: 8px;
  background: #e4565f;
  color: #fff;
  font-size: 10.5px;
  line-height: 16px;
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.bell-panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--color-border-divider);
}

.bell-panel__title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.bell-panel__perm {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 8px;
  padding: 8px 10px;
  border-radius: var(--radius-control);
  background: var(--color-warning-tint, #fff7e6);
}

.bell-panel__perm-text {
  font-size: 12px;
  line-height: 1.5;
  color: var(--color-text-secondary);
}

.bell-panel__empty {
  padding: 22px 0;
  text-align: center;
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

.bell-panel__list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 320px;
  margin: 0;
  padding: 6px 0 0;
  overflow-y: auto;
  list-style: none;
}

.bell-panel__item {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 8px 10px;
  border-radius: var(--radius-control);
  transition: background var(--transition-base);
}

.bell-panel__item.is-clickable {
  cursor: pointer;
}

.bell-panel__item.is-clickable:hover {
  background: var(--color-primary-tint);
}

.bell-panel__dot {
  width: 7px;
  height: 7px;
  margin-top: 5px;
  border-radius: 50%;
  background: transparent;
  flex: 0 0 auto;
}

.bell-panel__item.is-unread .bell-panel__dot {
  background: var(--color-primary);
}

.bell-panel__main {
  flex: 1;
  min-width: 0;
}

.bell-panel__ihead {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
}

.bell-panel__ititle {
  font-size: 12.8px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.bell-panel__item.is-unread .bell-panel__ititle {
  color: var(--color-primary);
}

.bell-panel__itime {
  font-size: 11.5px;
  color: var(--color-text-secondary);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.bell-panel__ibody {
  margin-top: 3px;
  font-size: 12.3px;
  line-height: 1.55;
  color: var(--color-text-regular);
  word-break: break-word;
}

.bell-panel__foot {
  display: flex;
  justify-content: center;
  padding-top: 6px;
  margin-top: 4px;
  border-top: 1px solid var(--color-border-divider);
}

.topbar__user {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px 4px 4px;
  border-radius: var(--radius-pill);
  cursor: pointer;
  transition: background var(--transition-base);
}

.topbar__user:hover {
  background: var(--color-bg-sub);
}

.topbar__avatar {
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 13px;
}

.topbar__name {
  font-size: 13px;
  color: var(--color-text-primary);
}

.topbar__caret {
  font-size: 10px;
  color: var(--color-text-placeholder);
}

.main-layout__content {
  flex: 1;
  min-height: 0;
  min-width: 0;
  /* 只允许纵向滚动：横向溢出（灯牌摆动等动画）直接裁切，
   * 不产生横向滚动条。用 clip 而非 hidden，避免把该容器变成横向滚动容器。 */
  overflow-y: auto;
  overflow-x: clip;
  padding: 20px;
}

/* 首屏基础数据加载态（避免子页面重复拉取同一批数据） */
.boot-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 240px;
}

/* —— 窄屏：菜单收成图标条 —— */
@media (max-width: 900px) {
  .main-layout .sidebar {
    width: 64px;
  }
  .topbar {
    padding: 0 12px;
  }
  .topbar__name {
    display: none;
  }
  .main-layout__content {
    padding: 12px;
  }
}
</style>
