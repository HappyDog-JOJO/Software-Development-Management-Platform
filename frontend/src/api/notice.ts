import { http } from './request'
import type { Notice, PageResult } from '@/types'

/** 公告接口 */

/** 最新一条启用中的公告（所有登录用户可读；无公告时 notice 为 null） */
export function fetchLatestNotice(): Promise<{ notice: Notice | null }> {
  return http.get<{ notice: Notice | null }>('/api.php?r=notice/latest')
}

/** 公告列表（仅管理员） */
export function fetchNotices(params: { page?: number; pageSize?: number; keyword?: string } = {}): Promise<PageResult<Notice>> {
  return http.get<PageResult<Notice>>('/api.php?r=notice/list', params)
}

/** 新增/编辑公告（仅管理员） */
export function saveNotice(payload: {
  id?: number
  title?: string
  content: string
  status?: number
}): Promise<Notice> {
  return http.post<Notice>('/api.php?r=notice/save', payload)
}

/** 启用/停用公告（仅管理员） */
export function toggleNotice(id: number, status: number): Promise<null> {
  return http.post<null>('/api.php?r=notice/status', { id, status })
}

/** 删除公告（仅管理员） */
export function deleteNotice(id: number): Promise<null> {
  return http.post<null>('/api.php?r=notice/delete', { id })
}
