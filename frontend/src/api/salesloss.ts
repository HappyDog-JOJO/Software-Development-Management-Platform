import { http } from './request'
import type {
  LossCross,
  LossDims,
  LossOverview,
  LossRanks,
  LostOrder,
  LostOrderQuery,
  PageResult,
} from '@/types'

/** 销售分析（输单）接口 */

export interface LostOrderPayload {
  customerName: string
  lostAt: string
  lossTypeId: number
  industryId: number
  companyScaleId: number
  lossStageId: number
  budgetMatchId: number
  /** 1 是 / 0 否 */
  remoteDemo: number
  customized: number
  finalQuote: number | null
  customerChoice: string
  reason: string
}

export function fetchLostOrders(params: LostOrderQuery = {}): Promise<PageResult<LostOrder>> {
  return http.get<PageResult<LostOrder>>('/api.php?r=salesloss/list', params)
}

export function getLostOrder(id: number): Promise<LostOrder> {
  return http.get<LostOrder>('/api.php?r=salesloss/detail', { id })
}


export function createLostOrder(payload: LostOrderPayload): Promise<{ id: number; lostNo: string }> {
  return http.post<{ id: number; lostNo: string }>('/api.php?r=salesloss/create', payload)
}

export function updateLostOrder(id: number, payload: LostOrderPayload): Promise<{ id: number; lostNo: string }> {
  return http.post<{ id: number; lostNo: string }>('/api.php?r=salesloss/update', { id, ...payload })
}

export function deleteLostOrder(id: number): Promise<null> {
  return http.post<null>('/api.php?r=salesloss/delete', { id })
}

export function fetchLossOverview(params: LostOrderQuery = {}): Promise<LossOverview> {
  return http.get<LossOverview>('/api.php?r=salesloss/overview', params)
}

export function fetchLossDims(params: LostOrderQuery = {}): Promise<LossDims> {
  return http.get<LossDims>('/api.php?r=salesloss/dims', params)
}

export function fetchLossCross(params: LostOrderQuery = {}): Promise<LossCross> {
  return http.get<LossCross>('/api.php?r=salesloss/cross', params)
}

export function fetchLossRanks(params: LostOrderQuery = {}): Promise<LossRanks> {
  return http.get<LossRanks>('/api.php?r=salesloss/ranks', params)
}
