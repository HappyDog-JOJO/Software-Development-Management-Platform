import { http } from './request'
import type { DutyCandidate, DutyMonthResult, DutyToday } from '@/types'

/** 售后值班排班接口 */

/** 按月取排班表（所有登录用户可读），返回当月排班 + 可值班人员 */
export function fetchDutyMonth(month: string): Promise<DutyMonthResult> {
  return http.get<DutyMonthResult>('/api.php?r=duty/list', { month })
}

/** 今日值班人（所有登录用户可读） */
export function fetchDutyToday(): Promise<DutyToday> {
  return http.get<DutyToday>('/api.php?r=duty/today')
}

/** 保存单日排班（管理员 / 售后服务人员） */
export function saveDuty(payload: {
  dutyDate: string
  userId: number
  remark?: string
}): Promise<{ dutyDate: string; userId: number; userName: string }> {
  return http.post<{ dutyDate: string; userId: number; userName: string }>('/api.php?r=duty/save', payload)
}

/** 清除某日排班 */
export function deleteDuty(dutyDate: string): Promise<null> {
  return http.post<null>('/api.php?r=duty/delete', { dutyDate })
}

/** 批量排班：在起止日期区间内逐日轮换（可只跳周日） */
export function batchDuty(payload: {
  startDate: string
  endDate: string
  userIds: number[]
  /** 跳过周日（公司单休，默认开启） */
  skipSunday?: number
  /** 额外跳过周六（双休时用） */
  skipSaturday?: number
}): Promise<{ saved: number; skipped: number; startDate: string; endDate: string }> {
  return http.post<{ saved: number; skipped: number; startDate: string; endDate: string }>('/api.php?r=duty/batch', payload)
}

export type { DutyCandidate, DutyMonthResult, DutyToday }
