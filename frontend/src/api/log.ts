import { http } from './request'
import type { PageResult, OperationLog, UploadResult } from '@/types'

/** 操作日志（只读） */

export interface LogQuery {
  account?: string
  type?: string
  dateStart?: string
  dateEnd?: string
  keyword?: string
  page?: number
  pageSize?: number
}

export function fetchLogs(params: LogQuery = {}): Promise<PageResult<OperationLog>> {
  return http.get<PageResult<OperationLog>>('/api.php?r=logs', params)
}

/** 附件/头像上传 */
export function uploadFile(file: File, type: 'attachment' | 'avatar' | 'logo' = 'attachment'): Promise<UploadResult> {
  const form = new FormData()
  form.append('file', file)
  form.append('type', type)
  return http.post<UploadResult>('/api.php?r=upload', form)
}

/** 记录导出动作（写操作日志，只读日志不支持编辑/删除） */
export function exportIssueLog(count: number): Promise<null> {
  return http.post<null>('/api.php?r=export/log', { kind: 'issues', count }, true)
}

export function exportReportLog(count: number): Promise<null> {
  return http.post<null>('/api.php?r=export/log', { kind: 'report', count }, true)
}

/** 发布上线记录导出（写操作日志） */
export function exportReleasedLog(count: number): Promise<null> {
  return http.post<null>('/api.php?r=export/log', { kind: 'released', count }, true)
}
