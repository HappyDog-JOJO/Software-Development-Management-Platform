import { http } from './request'
import type { BugDutyItem, BugDutyResult } from '@/types'
import type { PingcodeOption, PingcodeSyncFields } from './devreq'

/** BUG 修复排班（顺序轮值）接口 */

/** 读取顺序表 + 当前顺位 + PingCode 成员（候选人） */
export function fetchBugDuty(): Promise<BugDutyResult> {
  return http.get<BugDutyResult>('/api.php?r=bugduty/list')
}

/** 只拉 PingCode 项目成员（刷新用） */
export interface BugDutyMembersResult {
  members: PingcodeOption[]
  items: BugDutyItem[]
  current: BugDutyResult['current']
}

export function fetchBugDutyMembers(): Promise<BugDutyMembersResult> {
  return http.get<BugDutyMembersResult>('/api.php?r=bugduty/members')
}

/** 保存顺序表（整表覆盖；memberIds 为 PingCode 成员 ID，按顺位排列，保存后游标重置到第 1 位） */
export function saveBugDuty(memberIds: string[]): Promise<BugDutyResult> {
  return http.post<BugDutyResult>('/api.php?r=bugduty/save', { memberIds })
}

/** 重置游标到第 1 顺位 */
export function resetBugDuty(): Promise<BugDutyResult> {
  return http.post<BugDutyResult>('/api.php?r=bugduty/reset')
}

/** 问题同步到 PingCode：弹窗选项（优先级/状态/成员 + 当前 BUG 顺位） */
export interface IssuePingcodeOptions {
  projectName: string
  typeName: string
  priorities: PingcodeOption[]
  states: PingcodeOption[]
  members: PingcodeOption[]
  /** BUG 修复排班当前顺位（顺序表为空时为 null） */
  bugDuty: { userId: number; userName: string; position: number; memberId: string } | null
}

/**
 * 拉取问题同步弹窗选项（每次打开都取最新，保证顺位准确）。
 * silent=true 时不弹通用错误提示，由调用方统一展示一次（后端会返回具体原因）。
 */
export function fetchIssuePingcodeOptions(silent = false): Promise<IssuePingcodeOptions> {
  return http.get<IssuePingcodeOptions>('/api.php?r=issues/pingcode-options', undefined, silent)
}

/** 搜索 PingCode 标签 */
export function searchIssuePingcodeTags(keyword: string): Promise<PingcodeOption[]> {
  return http.get<PingcodeOption[]>('/api.php?r=issues/pingcode-tags', { keyword }, true)
}

/** 同步问题到 PingCode（已同步的自动跳过） */
export function syncIssuesToPingcode(
  ids: number[],
  fields?: PingcodeSyncFields,
): Promise<{ affected: number; skipped: number; bugDutyCurrent: BugDutyItem | null }> {
  return http.post<{ affected: number; skipped: number; bugDutyCurrent: BugDutyItem | null }>(
    '/api.php?r=issues/pingcode-sync',
    { ids, fields },
  )
}

export type { BugDutyItem, BugDutyResult }
