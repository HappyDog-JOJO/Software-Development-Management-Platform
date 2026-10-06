import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { AdviceItem, CrossAnalysis, LearningItem, RankBundle, ReportOverview, ReportQuery } from '@/types'
import * as reportApi from '@/api/report'

/** 报表：筛选、概览、各排行榜、交叉分析、建议与学习榜 */
export const useReportStore = defineStore('report', () => {
  const filters = ref<ReportQuery>({})
  const overview = ref<ReportOverview | null>(null)
  const ranks = ref<RankBundle | null>(null)
  const cross = ref<CrossAnalysis | null>(null)
  const advice = ref<AdviceItem[]>([])
  const hasAdviceData = ref(true)
  /** 值得培训学习的问题（高价值优质范例） */
  const learning = ref<LearningItem[]>([])
  const learningTotal = ref(0)
  const learningTrained = ref(0)
  const learningUntrained = ref(0)
  const learningAll = ref(0)
  /** 培训状态筛选：''=全部 '0'=未培训 '1'=已培训 */
  const learningFilter = ref<'all' | 'untrained' | 'trained'>('untrained')
  const loading = ref(false)

  /** 学习榜筛选参数：默认只看未培训 */
  function learningApiParams(): ReportQuery & { trainingStatus?: string } {
    const base = { ...filters.value } as ReportQuery & { trainingStatus?: string }
    if (learningFilter.value === 'untrained') {
      base.trainingStatus = '0'
    } else if (learningFilter.value === 'trained') {
      base.trainingStatus = '1'
    }
    return base
  }

  async function loadAll(): Promise<void> {
    loading.value = true
    try {
      const params = filters.value
      const [ov, rk, cr, ad, ln] = await Promise.all([
        reportApi.fetchReportOverview(params),
        reportApi.fetchRanks(params),
        reportApi.fetchCrossAnalysis(params),
        reportApi.fetchAdvice(params),
        reportApi.fetchLearning(learningApiParams()),
      ])
      overview.value = ov
      ranks.value = rk
      cross.value = cr
      advice.value = ad.advice
      hasAdviceData.value = ad.hasData
      learning.value = ln.items
      learningTotal.value = ln.total
      learningTrained.value = ln.trained
      learningUntrained.value = ln.untrained
      learningAll.value = ln.all
    } catch {
      // 拉取失败（如权限不足或登录态失效）由请求层统一提示
    } finally {
      loading.value = false
    }
  }

  function setFilters(next: ReportQuery): void {
    filters.value = { ...next }
  }

  function reset(): void {
    filters.value = {}
    overview.value = null
    ranks.value = null
    cross.value = null
    advice.value = []
    learning.value = []
    learningTotal.value = 0
  }

  return { filters, overview, ranks, cross, advice, hasAdviceData, learning, learningTotal, learningTrained, learningUntrained, learningAll, learningFilter, loading, loadAll, setFilters, reset }
})
