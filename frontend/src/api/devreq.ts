import { http } from './request'
import type {
  Attachment,
  DevQuery,
  DevRequest,
  DevRequestDetail,
  PageResult,
  UploadResult,
} from '@/types'

/** 需求管理模块 · 需求研发流接口（方案见 docs/需求管理模块-需求方案.md） */

export interface DevAttachmentPayload {
  url: string
  name: string
  size: number
  mimeType: string
}

export function fetchDevRequests(params: DevQuery = {}): Promise<PageResult<DevRequest>> {
  return http.get<PageResult<DevRequest>>('/api.php?r=devreqs', params)
}

export function getDevRequest(id: number): Promise<DevRequestDetail> {
  return http.get<DevRequestDetail>('/api.php?r=devreqs/detail', { id })
}

export interface CreateDevRequestPayload {
  title: string
  description: string
  moduleId: number
  /** 提交时不再选择；修改接口显式传入才会更新（列表内调整由 priority 接口负责） */
  priority?: string
  attachments?: DevAttachmentPayload[]
}

export function createDevRequest(payload: CreateDevRequestPayload): Promise<{ id: number; reqNo: string }> {
  return http.post<{ id: number; reqNo: string }>('/api.php?r=devreqs/create', payload)
}

/** 修改需求（提交人本人/管理员；仅评审池阶段） */
export function updateDevRequest(id: number, payload: CreateDevRequestPayload): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/update', { id, ...payload })
}

/** 调整优先级（评审委员/产品经理/管理员；各列表行内直接修改，任意环节可调） */
export function setDevRequestPriority(id: number, priority: string): Promise<{ priority: string }> {
  return http.post<{ priority: string }>('/api.php?r=devreqs/priority', { id, priority })
}

/** 删除需求（提交人本人删自己的/管理员删全部；仅评审池阶段） */
export function deleteDevRequest(id: number): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/delete', { id })
}

/** 评审（评审委员）：action = 通过（备注选填）| 待定（备注必填，暂留评审池）| 驳回（备注必填，作废终态） */
export function reviewDevRequest(
  id: number,
  action: '通过' | '待定' | '驳回',
  payload: { comment?: string; attachments?: DevAttachmentPayload[] } = {},
): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/review', { id, action, ...payload })
}

/** 开发（认领开发）：需求池 → 开发中心（开发工程师 / 管理员点「开发」直接进入开发中心） */
export function developDevRequest(id: number): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/develop', { id })
}

/** 开发中心发布上线（开发工程师/管理员，支持批量）：开发中心 → 已上线（记录上线时间与操作人） */
export function battlePublishDevRequest(ids: number[]): Promise<{ affected: number; skipped: number }> {
  return http.post<{ affected: number; skipped: number }>('/api.php?r=devreqs/battle-publish', { ids })
}

/** 撤回需求（仅管理员）：把处理进度退回上一步（需求池→评审池 / 开发中心→需求池 / 已上线→开发中心） */
export function withdrawDevRequest(id: number): Promise<{ status: string }> {
  return http.post<{ status: string }>('/api.php?r=devreqs/withdraw', { id })
}

/** PingCode 同步弹窗需要的选项（优先级 / 状态 / 项目成员） */
export interface PingcodeOption {
  id: string
  name: string
  type?: string
  account?: string
}

export interface PingcodeOptions {
  projectName: string
  typeName: string
  priorities: PingcodeOption[]
  states: PingcodeOption[]
  members: PingcodeOption[]
}

/** 同步任务到 PingCode 的弹窗字段（可选；标题/描述缺省用需求内容） */
export interface PingcodeSyncFields {
  title?: string
  description?: string
  priorityId?: string
  assigneeId?: string
  stateId?: string
  startAt?: string
  dueAt?: string
  tags?: string[]
  /** 问题同步专用：auto=按 BUG 排班顺位（默认），manual=手动指定负责人（不推进顺位） */
  rosterMode?: 'auto' | 'manual'
}

/** 拉取 PingCode 同步弹窗的选项（带会话内记忆：同一页面会话内复用，避免重复请求） */
let pingcodeOptionsCache: PingcodeOptions | null = null
let pingcodeOptionsInflight: Promise<PingcodeOptions> | null = null

export function fetchPingcodeOptions(force = false): Promise<PingcodeOptions> {
  if (!force && pingcodeOptionsCache) {
    return Promise.resolve(pingcodeOptionsCache)
  }
  if (!force && pingcodeOptionsInflight) {
    return pingcodeOptionsInflight
  }
  const p = http
    .get<PingcodeOptions>('/api.php?r=devreqs/pingcode-options')
    .then((res) => {
      pingcodeOptionsCache = res
      pingcodeOptionsInflight = null
      return res
    })
    .catch((e) => {
      pingcodeOptionsInflight = null
      throw e
    })
  pingcodeOptionsInflight = p
  return p
}

/** 清空 PingCode 选项记忆（如同步完成后需要强制刷新时调用） */
export function clearPingcodeOptionsCache(): void {
  pingcodeOptionsCache = null
  pingcodeOptionsInflight = null
}

/** 搜索 PingCode 标签库（远程搜索，带 id 采用现有标签；查不到由后端按名建标签） */
export function searchPingcodeTags(keyword: string): Promise<PingcodeOption[]> {
  return http.get<PingcodeOption[]>('/api.php?r=devreqs/pingcode-tags', { keyword }, true)
}

/** 同步任务到 PingCode（开发工程师/管理员，支持批量；已同步的自动跳过）；可带弹窗字段 */
export function syncTaskDevRequest(
  ids: number[],
  fields?: PingcodeSyncFields,
): Promise<{ affected: number; skipped: number }> {
  return http.post<{ affected: number; skipped: number }>('/api.php?r=devreqs/sync-task', { ids, fields })
}

export type { Attachment, UploadResult }
