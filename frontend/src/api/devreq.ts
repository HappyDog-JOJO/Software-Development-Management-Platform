import { http } from './request'
import type {
  Attachment,
  DevCandidate,
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
  priority: string
  attachments?: DevAttachmentPayload[]
}

export function createDevRequest(payload: CreateDevRequestPayload): Promise<{ id: number; reqNo: string }> {
  return http.post<{ id: number; reqNo: string }>('/api.php?r=devreqs/create', payload)
}

/** 修改需求（提交人本人/管理员；仅评审池阶段） */
export function updateDevRequest(id: number, payload: CreateDevRequestPayload): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/update', { id, ...payload })
}

/** 删除需求（提交人本人删自己的/管理员删全部；仅评审池阶段） */
export function deleteDevRequest(id: number): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/delete', { id })
}

/** 评审（评审委员）：action = 通过 | 驳回（驳回 = 作废终态） */
/** 评审（评审委员）：action = 通过（备注选填）| 待定（备注必填，暂留评审池）| 驳回（备注必填，作废终态） */
export function reviewDevRequest(
  id: number,
  action: '通过' | '待定' | '驳回',
  payload: { comment?: string; attachments?: DevAttachmentPayload[] } = {},
): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/review', { id, action, ...payload })
}

/** 产品经理认领：需求池 → 设计中心 */
export function claimDevRequest(id: number): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/claim', { id })
}

/** 设计中心完成设计 → 开发中心（设计文档为网址） */
export interface DesignPayload {
  designUrl: string
  designDoc: string
  branch: string
  devLeaderId: number
}

export function designDevRequest(id: number, payload: DesignPayload): Promise<null> {
  return http.post<null>('/api.php?r=devreqs/design', { id, ...payload })
}

/** 开发中心发布上线（负责人/管理员，支持批量）：开发中心 → 已上线（记录上线时间与操作人） */
export function battlePublishDevRequest(ids: number[]): Promise<{ affected: number; skipped: number }> {
  return http.post<{ affected: number; skipped: number }>('/api.php?r=devreqs/battle-publish', { ids })
}

/** 负责人候选（开发工程师 / 管理员） */
export function fetchDevCandidates(): Promise<DevCandidate[]> {
  return http.get<DevCandidate[]>('/api.php?r=devreqs/devs')
}

/** 设计人候选（产品经理 / 管理员，开发中心筛选用） */
export function fetchPmCandidates(): Promise<DevCandidate[]> {
  return http.get<DevCandidate[]>('/api.php?r=devreqs/pms')
}

export type { Attachment, UploadResult }
