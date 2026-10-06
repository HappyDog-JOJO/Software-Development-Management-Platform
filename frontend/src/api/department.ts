import { http } from './request'
import type { Department } from '@/types'

/** 部门维护接口 */

export function fetchDepartments(): Promise<Department[]> {
  return http.get<Department[]>('/api.php?r=departments')
}

/** 启用中的部门（公开接口，注册页未登录时选择部门用；只返回 id 与名称） */
export function fetchPublicDepartments(): Promise<Array<{ id: number; name: string }>> {
  return http.get<Array<{ id: number; name: string }>>('/api.php?r=departments/public', undefined, true)
}

export interface DepartmentPayload {
  id?: number
  name: string
  leader?: string
  sort?: number
}

export function saveDepartment(payload: DepartmentPayload): Promise<{ id: number }> {
  return http.post<{ id: number }>('/api.php?r=departments/save', payload)
}

export function updateDepartment(payload: DepartmentPayload): Promise<{ id: number }> {
  return http.post<{ id: number }>('/api.php?r=departments/save', payload)
}

export function toggleDepartment(id: number, status: number): Promise<null> {
  return http.post<null>('/api.php?r=departments/status', { id, status })
}

export function deleteDepartment(id: number): Promise<null> {
  return http.post<null>('/api.php?r=departments/delete', { id })
}
