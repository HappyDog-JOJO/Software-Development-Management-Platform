import { computed, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 表格列配置（按「用户 + 表格」隔离存储）：控制列的**显示/隐藏**与**顺序**。
 *
 * 与 useColumnWidths 的区别：
 * - useColumnWidths 只管每列多宽；
 * - 本 composable 管「显示哪些列」和「列的先后顺序」，两者独立、可叠加使用。
 *
 * 存储：`aftersales_colcfg_<用户ID>_<表格标识>`（localStorage），
 * 因此每个用户各自一份，互不影响；换用户后会自动切到对应用户的配置。
 *
 * 约定：
 * - `fixed: true` 的列（如选择列、操作列）不参与配置：始终显示、不可隐藏、不可移动。
 * - 新版本新增的列若不在用户已存的顺序里，会**插入到默认顺序的相邻位置**
 *   （如「提交部门」紧跟「提交人」），保证升级后新列的出现位置符合页面默认布局、不会“新列莫名消失”。
 */

const STORAGE_PREFIX = 'aftersales_colcfg_'

export interface ColumnMeta {
  /** 与表格列一一对应的稳定键（用 prop，无 prop 用 label） */
  key: string
  /** 展示名（一般是列标题） */
  label: string
  /** 固定列：不参与显示/顺序配置，始终显示且位置固定 */
  fixed?: boolean
}

interface ConfigState {
  /** 列的展示顺序（只记录可配置列） */
  order: string[]
  /** 被隐藏的列键 */
  hidden: string[]
}

function readState(key: string): ConfigState {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) {
      return { order: [], hidden: [] }
    }
    const parsed = JSON.parse(raw) as unknown
    if (!parsed || typeof parsed !== 'object') {
      return { order: [], hidden: [] }
    }
    const p = parsed as Partial<ConfigState>
    const order = Array.isArray(p.order) ? p.order.filter((x): x is string => typeof x === 'string') : []
    const hidden = Array.isArray(p.hidden) ? p.hidden.filter((x): x is string => typeof x === 'string') : []
    return { order, hidden }
  } catch {
    // 存储损坏时按“无自定义”处理，不影响页面
    return { order: [], hidden: [] }
  }
}

export function useColumnConfig(tableKey: string) {
  const auth = useAuthStore()
  const currentUserId = (): string | number => auth.user?.id ?? 'anon'
  const keyOf = (uid: string | number): string => `${STORAGE_PREFIX}${uid}_${tableKey}`

  const storageKey = ref(keyOf(currentUserId()))
  const state = reactive<ConfigState>(readState(storageKey.value))
  /** 当前表格的全部可配置列（由页面提供，含新版本新增的列） */
  const metas = ref<ColumnMeta[]>([])

  /** 把（可能是另一个用户的）存储内容整体换成当前用户的 */
  function reloadForKey(): void {
    const next = keyOf(currentUserId())
    if (next === storageKey.value) {
      return
    }
    storageKey.value = next
    const fresh = readState(next)
    Object.keys(state).forEach((k) => delete (state as unknown as Record<string, unknown>)[k])
    Object.assign(state, fresh)
  }

  // 同一标签页内切换用户（登出→登录）时，切换到对应用户的列配置
  watch(() => auth.user?.id, reloadForKey)

  /** 页面挂载时登记列清单（用于补齐新列、剔除已删除的列） */
  function register(columns: ColumnMeta[]): void {
    metas.value = columns
    const keys = columns.filter((c) => !c.fixed).map((c) => c.key)
    // 1) 剔除已不存在的列
    const keptOrder = state.order.filter((k) => keys.includes(k))
    // 2) 新列插入到「默认顺序里前一个已存在列」的后面（保持升级前后布局一致）
    keys.forEach((k, idx) => {
      if (keptOrder.includes(k)) {
        return
      }
      let insertAt = 0
      for (let i = idx - 1; i >= 0; i -= 1) {
        const pos = keptOrder.indexOf(keys[i])
        if (pos >= 0) {
          insertAt = pos + 1
          break
        }
      }
      keptOrder.splice(insertAt, 0, k)
    })
    if (keptOrder.join(',') !== state.order.join(',')) {
      state.order = keptOrder
      persist()
    }
    const keptHidden = state.hidden.filter((k) => keys.includes(k))
    if (keptHidden.join(',') !== state.hidden.join(',')) {
      state.hidden = keptHidden
      persist()
    }
  }

  function persist(): void {
    try {
      localStorage.setItem(storageKey.value, JSON.stringify({ order: state.order, hidden: state.hidden }))
    } catch {
      // 隐私模式/存储写满：忽略，不影响当前会话
    }
  }

  /** 可配置列的最终顺序 */
  const configurableOrder = computed<string[]>(() => {
    const keys = metas.value.filter((c) => !c.fixed).map((c) => c.key)
    const ordered = state.order.filter((k) => keys.includes(k))
    keys.forEach((k) => {
      if (!ordered.includes(k)) {
        ordered.push(k)
      }
    })
    return ordered
  })

  /** 当前应展示的列键（按顺序，已过滤隐藏项） */
  const visibleKeys = computed<string[]>(() =>
    configurableOrder.value.filter((k) => !state.hidden.includes(k)),
  )

  function isVisible(key: string): boolean {
    const meta = metas.value.find((m) => m.key === key)
    if (meta?.fixed) {
      return true
    }
    return !state.hidden.includes(key)
  }

  /** 显示/隐藏某列（至少保留一列可见，避免整表空白） */
  function toggle(key: string): void {
    const meta = metas.value.find((m) => m.key === key)
    if (!meta || meta.fixed) {
      return
    }
    const idx = state.hidden.indexOf(key)
    if (idx >= 0) {
      state.hidden.splice(idx, 1)
    } else {
      if (visibleKeys.value.length <= 1) {
        return
      }
      state.hidden.push(key)
    }
    persist()
  }

  /** 把某列上移/下移一位 */
  function move(key: string, dir: -1 | 1): void {
    const order = [...configurableOrder.value]
    const i = order.indexOf(key)
    if (i < 0) {
      return
    }
    const j = i + dir
    if (j < 0 || j >= order.length) {
      return
    }
    const tmp = order[i]
    order[i] = order[j]
    order[j] = tmp
    state.order = order
    persist()
  }

  /** 直接设置顺序（拖拽排序用） */
  function setOrder(keys: string[]): void {
    const valid = configurableOrder.value
    const next = keys.filter((k) => valid.includes(k))
    valid.forEach((k) => {
      if (!next.includes(k)) {
        next.push(k)
      }
    })
    state.order = next
    persist()
  }

  /** 恢复默认：显示全部列、按代码默认顺序 */
  function reset(): void {
    state.hidden = []
    state.order = metas.value.filter((c) => !c.fixed).map((c) => c.key)
    persist()
  }

  /** 供页面渲染用：按配置顺序返回可配置列（含是否可见标记） */
  function ordered(): Array<ColumnMeta & { visible: boolean }> {
    const byKey = new Map(metas.value.map((m) => [m.key, m]))
    return configurableOrder.value
      .map((k) => byKey.get(k))
      .filter((m): m is ColumnMeta => !!m)
      .map((m) => ({ ...m, visible: !state.hidden.includes(m.key) }))
  }

  return {
    metas,
    state,
    visibleKeys,
    configurableOrder,
    register,
    isVisible,
    toggle,
    move,
    setOrder,
    reset,
    ordered,
    storageKey,
  }
}
