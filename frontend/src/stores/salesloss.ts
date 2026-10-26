import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { LossCross, LossDims, LossOverview, LossRanks, LostOrder, LostOrderQuery } from '@/types'
import * as lossApi from '@/api/salesloss'

/** 输单台账：筛选条件、分页、列表（与问题列表同一套做法） */
export const useLossStore = defineStore('salesloss', () => {
  const list = ref<LostOrder[]>([])
  const total = ref(0)
  const filteredOut = ref(0)
  const page = ref(1)
  const pageSize = ref(10)
  const loading = ref(false)
  const filters = ref<LostOrderQuery>({})
  const highlightId = ref<number | null>(null)

  async function load(extra: Partial<LostOrderQuery> = {}): Promise<void> {
    loading.value = true
    try {
      const params: LostOrderQuery = { ...filters.value, ...extra, page: page.value, pageSize: pageSize.value }
      const res = await lossApi.fetchLostOrders(params)
      list.value = res.items
      total.value = res.total
      filteredOut.value = res.filteredOut ?? 0
    } catch {
      // 拉取失败（如登录态失效）由请求层统一提示，此处保持现有列表不变
    } finally {
      loading.value = false
    }
  }

  /** 稳定序列化筛选条件（键排序 + 忽略空值）：仅条件真变化时才回到第 1 页 */
  function normalizeFilters(q: LostOrderQuery): string {
    const out: Record<string, unknown> = {}
    for (const k of Object.keys(q).sort()) {
      const v = (q as Record<string, unknown>)[k]
      if (v === undefined || v === null || v === '') {
        continue
      }
      out[k] = v
    }
    return JSON.stringify(out)
  }

  function setFilters(next: LostOrderQuery): void {
    const changed = normalizeFilters(filters.value) !== normalizeFilters(next)
    filters.value = { ...next }
    if (changed) {
      page.value = 1
    }
  }

  function reset(): void {
    list.value = []
    total.value = 0
    filteredOut.value = 0
    page.value = 1
    filters.value = {}
    highlightId.value = null
  }

  return { list, total, filteredOut, page, pageSize, loading, filters, highlightId, load, setFilters, reset }
})

/** 输单分析：概览 / 维度分布 / 交叉趋势 / 排行（一次并发拉取） */
export const useLossAnalysisStore = defineStore('saleslossAnalysis', () => {
  const filters = ref<LostOrderQuery>({})
  const overview = ref<LossOverview | null>(null)
  const dims = ref<LossDims | null>(null)
  const cross = ref<LossCross | null>(null)
  const ranks = ref<LossRanks | null>(null)
  const loading = ref(false)

  async function loadAll(): Promise<void> {
    loading.value = true
    try {
      const params = filters.value
      const [ov, dm, cr, rk] = await Promise.all([
        lossApi.fetchLossOverview(params),
        lossApi.fetchLossDims(params),
        lossApi.fetchLossCross(params),
        lossApi.fetchLossRanks(params),
      ])
      overview.value = ov
      dims.value = dm
      cross.value = cr
      ranks.value = rk
    } catch {
      // 失败由请求层统一提示
    } finally {
      loading.value = false
    }
  }

  function setFilters(next: LostOrderQuery): void {
    filters.value = { ...next }
  }

  function reset(): void {
    filters.value = {}
    overview.value = null
    dims.value = null
    cross.value = null
    ranks.value = null
  }

  return { filters, overview, dims, cross, ranks, loading, loadAll, setFilters, reset }
})
