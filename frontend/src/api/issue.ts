import { http } from './request'
import type {
  Attachment,
  HandleRecord,
  Issue,
  IssueDetail,
  IssueQuery,
  PageResult,
  UploadResult,
} from '@/types'

/** 问题与售后处理接口 */

export interface IssueAttachmentPayload {
  url: string
  name: string
  size: number
  mimeType: string
}

export interface CreateIssuePayload {
  description: string
  moduleId: number
  attachments?: IssueAttachmentPayload[]
}

export function fetchIssues(params: IssueQuery = {}): Promise<PageResult<Issue>> {
  return http.get<PageResult<Issue>>('/api.php?r=issues', params)
}

export function getIssue(id: number): Promise<IssueDetail> {
  return http.get<IssueDetail>('/api.php?r=issues/detail', { id })
}

export function createIssue(payload: CreateIssuePayload): Promise<{ id: number; issueNo: string }> {
  return http.post<{ id: number; issueNo: string }>('/api.php?r=issues/create', payload)
}

export interface HandlePayload {
  id: number
  handleStatusId: number
  remark: string
  valueId: number
  categoryId: number
  attachments?: IssueAttachmentPayload[]
}

export function handleIssue(payload: HandlePayload): Promise<{ recordId: number; status: string }> {
  return http.post<{ recordId: number; status: string }>('/api.php?r=issues/handle', payload)
}

export function fetchHandleRecords(issueId: number): Promise<HandleRecord[]> {
  return http.get<HandleRecord[]>('/api.php?r=issues/handles', { id: issueId })
}

/** 处理人选项（用于问题列表筛选）：基于当前可见范围统计 */
export interface HandlerOption {
  id: number
  name: string
  count: number
}

export function fetchHandlers(): Promise<HandlerOption[]> {
  return http.get<HandlerOption[]>('/api.php?r=issues/handlers')
}

export function appendIssue(id: number, content: string): Promise<null> {
  return http.post<null>('/api.php?r=issues/append', { id, content })
}

/** 删除问题（仅管理员；服务端会级联清理关联数据与上传文件） */
export function deleteIssue(id: number): Promise<null> {
  return http.post<null>('/api.php?r=issues/delete', { id })
}

/** 直接修改问题状态（仅管理员） */
export function updateIssueStatus(id: number, status: string): Promise<null> {
  return http.post<null>('/api.php?r=issues/status', { id, status })
}

export type { Attachment, UploadResult }
