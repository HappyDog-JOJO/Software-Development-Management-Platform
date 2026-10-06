import { onMounted, onUnmounted, ref, type Ref } from 'vue'
import { fetchNotifyPending, markNotified } from '@/api/notify'
import type { NotificationItem } from '@/types'
import router from '@/router'

/**
 * 消息通知：轮询服务端未弹过的消息，用浏览器 Notification 弹 Windows 系统通知。
 *
 * 前提说明：网页只能通过浏览器发出系统通知，因此要求本站页面处于打开状态
 * （最小化 / 后台标签页均可）；关掉浏览器后收不到，这是浏览器安全模型决定的。
 * 首次使用需用户点一次「开启桌面通知」授权（见顶栏铃铛菜单）。
 */

const POLL_MS = 20000

/** 浏览器是否支持系统通知 */
export function notifySupported(): boolean {
  return typeof window !== 'undefined' && 'Notification' in window
}

/** 当前授权状态：granted / denied / default */
export function notifyPermission(): NotificationPermission | 'unsupported' {
  if (!notifySupported()) {
    return 'unsupported'
  }
  return Notification.permission
}

/** 请求授权（必须在用户手势中调用，例如点击按钮） */
export async function requestNotifyPermission(): Promise<NotificationPermission | 'unsupported'> {
  if (!notifySupported()) {
    return 'unsupported'
  }
  if (Notification.permission === 'granted' || Notification.permission === 'denied') {
    return Notification.permission
  }
  try {
    return await Notification.requestPermission()
  } catch {
    return Notification.permission
  }
}

/** 弹一条系统通知；点击后聚焦页面并跳转到对应位置 */
function showDesktopNotify(item: NotificationItem): void {
  if (!notifySupported() || Notification.permission !== 'granted') {
    return
  }
  try {
    const n = new Notification(item.title || '软件开发管理平台', {
      body: item.content,
      tag: 'qly-notify-' + item.id,
      icon: '/favicon-32.png',
    })
    n.onclick = () => {
      window.focus()
      if (item.link) {
        void router.push(item.link)
      }
      n.close()
    }
  } catch {
    // 个别浏览器在非安全上下文下构造 Notification 会抛错，忽略即可
  }
}

export interface NotifyPollingResult {
  /** 未读总数（响应式） */
  unread: Ref<number>
  /** 最近拉取到的消息（供铃铛面板刷新） */
  latest: Ref<NotificationItem[]>
  refresh: () => Promise<void>
}

/**
 * 启动轮询。返回的 unread/latest 供顶栏铃铛展示；
 * 组件卸载时自动停止。
 */
export function useNotifyPolling(): NotifyPollingResult {
  const unread = ref(0)
  const latest = ref<NotificationItem[]>([])
  let timer: number | null = null

  async function tick(): Promise<void> {
    try {
      const res = await fetchNotifyPending(10)
      unread.value = res.unread
      if (res.items.length) {
        res.items.forEach(showDesktopNotify)
        // 回执：避免同一条消息反复弹通知
        await markNotified(res.items.map((x) => x.id))
        latest.value = [...res.items, ...latest.value].slice(0, 20)
      }
    } catch {
      // 网络异常静默重试，不打断使用
    }
  }

  onMounted(() => {
    void tick()
    timer = window.setInterval(() => void tick(), POLL_MS)
  })

  onUnmounted(() => {
    if (timer !== null) {
      window.clearInterval(timer)
      timer = null
    }
  })

  return { unread, latest, refresh: tick }
}
