import { http } from './request'
import type {
  Attachment,
  DevAttachment,
  DevCandidate,
  DevQuery,
  DevRequest,
  DevRequestDetail,
  DevSubTask,
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
  priority: string
  attachments?: DevAttachmentPayload[]
}

export function createDevRequest(payload: CreateDevRequestPayload): Promise<{ id: number; reqNo: string }> {
  return http.post<{ id: number; reqNo: string }>('/api.php?r=devreqs/create', payload)
}

export function cancelDevRequest(id: number): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/cancel', { id })
}

/** 评审（评审委员）：action = 通过 | 驳回（驳回 = 作废终态） */
export function reviewDevRequest(
  id: number,
  action: '通过' | '驳回',
  payload: { comment?: string; attachments?: DevAttachmentPayload[] } = {},
): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/review', { id, action, ...payload })
}

/** 产品经理认领：需求池 → 设计中心 */
export function claimDevRequest(id: number): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/claim', { id })
}

/** 设计中心完成设计 → 开发中心 */
export interface DesignPayload {
  designDoc: string
  branch: string
  devLeaderId: number
  attachments?: DevAttachmentPayload[]
}

export function designDevRequest(id: number, payload: DesignPayload): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/design', { id, ...payload })
}

/** 发布上线（评审委员/管理员）：待发布 → 已上线 */
export function releaseDevRequest(id: number, comment = ''): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/release', { id, comment })
}

/** 开发工程师候选（指定开发负责人/子任务负责人） */
export function fetchDevCandidates(): Promise<DevCandidate[]> {
  return http.get<DevCandidate[]>('/api.php?r=devreqs/devs')
}

/** 创建子任务（开发负责人；分支自动继承主任务） */
export function createSubTask(payload: {
  requestId: number
  title: string
  description?: string
  assigneeId: number
}): Promise<{ id: number; subCount: number }> {
  return http.post<{ id: number; subCount: number }>('/api.php?r=subtasks/create', payload)
}

/** 子任务开工（待开发/需返工 → 开发中） */
export function startSubTask(id: number): Promise<null> {
  return http.post<null>('/api.php?r=subtasks/start', { id })
}

/** 子任务提交完成（全部完成时父任务自动进待发布） */
export function doneSubTask(
  id: number,
  doneNote: string,
  attachments: DevAttachmentPayload[] = [],
): Promise<{ done: number; total: number }> {
  return http.post<{ done: number; total: number }>('/api.php?r=subtasks/done', { id, doneNote, attachments })
}

export type { Attachment, UploadResult }
