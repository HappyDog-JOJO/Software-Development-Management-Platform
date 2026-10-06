import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Issue, IssueQuery } from '@/types'
import * as issueApi from '@/api/issue'

/** 问题列表：筛选条件、分页、列表与汇总 */
export const useIssueStore = defineStore('issue', () => {
  const list = ref<Issue[]>([])
  const total = ref(0)
  const filteredOut = ref(0)
  const page = ref(1)
  const pageSize = ref(10)
  const loading = ref(false)
  const filters = ref<IssueQuery>({})
  const highlightId = ref<number | null>(null)

  async function load(extra: Partial<IssueQuery> = {}): Promise<void> {
    loading.value = true
    try {
      const params: IssueQuery = { ...filters.value, ...extra, page: page.value, pageSize: pageSize.value }
      const res = await issueApi.fetchIssues(params)
      list.value = res.items
      total.value = res.total
      filteredOut.value = res.filteredOut ?? 0
    } catch {
      // 拉取失败（如登录态失效）由请求层统一提示，此处保持现有列表不变
    } finally {
      loading.value = false
    }
  }

  function resetFilters(): void {
    filters.value = {}
    page.value = 1
  }

  /**
   * 稳定序列化筛选条件（键排序 + 忽略空值），用于判断条件是否真的变了。
   * 必要性：列表页 load() 会先 setFilters 再 load，若无条件把 page 重置为 1，
   * 翻页时刚设置的页码会当场被冲掉（表现为「翻页按钮点了没反应」）。
   */
  function normalizeFilters(q: IssueQuery): string {
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

  /** 更新筛选条件：仅在条件真正变化时回到第 1 页（翻页时保持当前页） */
  function setFilters(next: IssueQuery): void {
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

  return {
    list,
    total,
    filteredOut,
    page,
    pageSize,
    loading,
    filters,
    highlightId,
    load,
    resetFilters,
    setFilters,
    reset,
  }
})
