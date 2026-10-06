import { reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * 表格列宽记忆（按「用户 + 表格」隔离存储）。
 *
 * 用法：
 *   const colw = useColumnWidths('users')            // 表格唯一标识
 *   <el-table @header-dragend="colw.onDragend">
 *   <el-table-column :width="colw.w('account', 108)" />
 *   <el-table-column :width="colw.w('remark', undefined)" min-width="240" />  // 原 min-width 列
 *
 * 说明：
 * - 存储键 = `aftersales_colw_<用户ID>_<表格标识>`，因此每个用户各自一份，互不影响。
 * - 只记录用户**手动拖动过**的列；未拖动过的列完全沿用代码里的默认宽度。
 * - 键优先用列的 prop，无 prop 时退化为 label，保证与列一一对应。
 * - 列宽最小 40px，避免被拖成 0 宽看不见。
 */

const STORAGE_PREFIX = 'aftersales_colw_'
const MIN_WIDTH = 40

type WidthMap = Record<string, number>

function readMap(key: string): WidthMap {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) {
      return {}
    }
    const parsed = JSON.parse(raw) as unknown
    if (!parsed || typeof parsed !== 'object') {
      return {}
    }
    const out: WidthMap = {}
    Object.entries(parsed as Record<string, unknown>).forEach(([k, v]) => {
      const n = Number(v)
      if (Number.isFinite(n) && n >= MIN_WIDTH) {
        out[k] = Math.round(n)
      }
    })
    return out
  } catch {
    // 存储损坏时按“无自定义”处理，不影响页面
    return {}
  }
}

export function useColumnWidths(tableKey: string) {
  const auth = useAuthStore()
  // 以用户 id 隔离；未登录（理论不会出现）时归入 anon，避免互相覆盖
  const currentUserId = (): string | number => auth.user?.id ?? 'anon'
  const keyOf = (uid: string | number) => `${STORAGE_PREFIX}${uid}_${tableKey}`

  // 当前用户对应的存储键（用户切换时会变）
  const storageKey = ref(keyOf(currentUserId()))
  const overrides = reactive<WidthMap>(readMap(storageKey.value))

  /** 把（可能是另一个用户的）存储内容整体换成当前用户的 */
  function reloadForKey(): void {
    const next = keyOf(currentUserId())
    if (next === storageKey.value) {
      return
    }
    storageKey.value = next
    const fresh = readMap(next)
    // 先清空再写入，保证不残留上一个用户的列宽
    Object.keys(overrides).forEach((k) => delete overrides[k])
    Object.assign(overrides, fresh)
  }

  // 同一标签页内切换用户（登出→登录，或账号被改）时，重新加载对应用户的列宽
  watch(() => auth.user?.id, reloadForKey)

  function persist(): void {
    try {
      localStorage.setItem(storageKey.value, JSON.stringify(overrides))
    } catch {
      // 隐私模式/存储满：忽略，不影响当前会话内的列宽调整
    }
  }

  /**
   * 列宽取值：有用户自定义则用自定义值，否则用代码里的默认值。
   * 对原本使用 min-width 的弹性列，默认值传 undefined，保持弹性行为。
   */
  function w(colKey: string, defaultWidth?: number): number | undefined {
    const v = overrides[colKey]
    return typeof v === 'number' && v >= MIN_WIDTH ? v : defaultWidth
  }

  /**
   * 生成可直接 v-bind 的列属性对象：
   * - 用户拖过该列 → 固定为用户宽度
   * - 否则 → 固定宽列用 width，弹性列保持 minWidth
   */
  function bind(colKey: string, def: { width?: number; minWidth?: number }): Record<string, number> {
    const custom = overrides[colKey]
    if (typeof custom === 'number' && custom >= MIN_WIDTH) {
      return { width: custom }
    }
    if (typeof def.width === 'number') {
      return { width: def.width }
    }
    if (typeof def.minWidth === 'number') {
      return { minWidth: def.minWidth }
    }
    return {}
  }

  /** 从 column 对象解析稳定键：prop → label */
  function columnKeyOf(column: { property?: string; label?: string }): string | undefined {
    const prop = column?.property
    if (prop) {
      return String(prop)
    }
    const label = column?.label
    return label ? String(label) : undefined
  }

  /** el-table 的 header-dragend 回调：(newWidth, oldWidth, column) */
  function onDragend(newWidth: number, _oldWidth: number, column: { property?: string; label?: string }): void {
    const k = columnKeyOf(column)
    if (!k) {
      return
    }
    const width = Math.max(MIN_WIDTH, Math.round(newWidth))
    overrides[k] = width
    persist()
  }

  /** 清空本表在本用户下的列宽记忆（供“恢复默认”使用） */
  function reset(): void {
    Object.keys(overrides).forEach((k) => delete overrides[k])
    try {
      localStorage.removeItem(storageKey.value)
    } catch {
      // 忽略
    }
  }

  return { widths: overrides, w, bind, onDragend, keyOf: columnKeyOf, reset, storageKey }
}
