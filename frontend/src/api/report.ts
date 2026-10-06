import { http } from './request'
import type { AdviceResult, CrossAnalysis, LearningResult, RankBundle, ReportOverview, ReportQuery } from '@/types'

/** 报表统计接口 */

export function fetchReportOverview(params: ReportQuery = {}): Promise<ReportOverview> {
  return http.get<ReportOverview>('/api.php?r=reports/overview', params)
}

export function fetchRanks(params: ReportQuery = {}): Promise<RankBundle> {
  return http.get<RankBundle>('/api.php?r=reports/ranks', params)
}

export function fetchCrossAnalysis(params: ReportQuery = {}): Promise<CrossAnalysis> {
  return http.get<CrossAnalysis>('/api.php?r=reports/cross', params)
}

export function fetchAdvice(params: ReportQuery = {}): Promise<AdviceResult> {
  return http.get<AdviceResult>('/api.php?r=reports/advice', params)
}

/** 值得培训学习的问题（高价值问题的优质范例） */
export function fetchLearning(params: ReportQuery & { trainingStatus?: string } = {}): Promise<LearningResult> {
  return http.get<LearningResult>('/api.php?r=reports/learning', params)
}

/** 标记/取消「已培训」 */
export function setTraining(id: number, status: number): Promise<null> {
  return http.post<null>('/api.php?r=reports/training', { id, status })
}
