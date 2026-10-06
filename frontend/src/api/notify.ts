import { http } from './request'
import type { NotificationItem, PageResult } from '@/types'

/** 站内消息通知接口 */

export interface PendingResult {
  items: NotificationItem[]
  unread: number
}

export interface UnreadResult {
  unread: number
  items: NotificationItem[]
}

/** 未弹过系统通知的消息（前端轮询此接口弹 Windows 通知） */
export function fetchNotifyPending(limit = 10): Promise<PendingResult> {
  return http.get<PendingResult>('/api.php?r=notify/pending', { limit }, true)
}

/** 回执：这些消息已弹出系统通知 */
export function markNotified(ids: number[]): Promise<null> {
  return http.post<null>('/api.php?r=notify/notified', { ids }, true)
}

/** 通知列表（消息中心分页） */
export function fetchNotifyList(params: { page?: number; pageSize?: number } = {}): Promise<PageResult<NotificationItem>> {
  return http.get<PageResult<NotificationItem>>('/api.php?r=notify/list', params)
}

/** 未读数量 + 最近 10 条（顶栏铃铛） */
export function fetchNotifyUnread(): Promise<UnreadResult> {
  return http.get<UnreadResult>('/api.php?r=notify/unread', {}, true)
}

/** 标记已读：传 id 标单条；all=true 标记全部 */
export function readNotify(payload: { id?: number; all?: boolean } = {}): Promise<null> {
  return http.post<null>('/api.php?r=notify/read', payload)
}
