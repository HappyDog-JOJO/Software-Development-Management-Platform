import { http } from './request'
import type { PageResult, Role, User } from '@/types'

/** 用户管理接口（管理员） */

export interface UserQuery {
  keyword?: string
  departmentId?: number
  role?: string
  status?: number | string
  page?: number
  pageSize?: number
}

export function fetchUsers(params: UserQuery = {}): Promise<PageResult<User>> {
  return http.get<PageResult<User>>('/api.php?r=users', params)
}

export interface UserCreatePayload {
  account: string
  password?: string
  name?: string
  phone?: string
  avatar?: string
  departmentId?: number
  roles: Role[]
}

export function createUser(payload: UserCreatePayload): Promise<User> {
  return http.post<User>('/api.php?r=users/create', payload)
}

export function updateUser(
  id: number,
  payload: { account?: string; name?: string; phone?: string; avatar?: string; departmentId?: number },
): Promise<User> {
  return http.post<User>('/api.php?r=users/update', { id, ...payload })
}

export function assignDepartment(ids: number[], departmentId: number): Promise<{ affected: number }> {
  return http.post<{ affected: number }>('/api.php?r=users/department', { ids, departmentId })
}

export function assignRole(ids: number[], roles: Role[]): Promise<{ affected: number; roles: Role[] }> {
  return http.post<{ affected: number; roles: Role[] }>('/api.php?r=users/role', { ids, roles })
}

export function toggleUserStatus(ids: number[], status: number): Promise<{ affected: number }> {
  return http.post<{ affected: number }>('/api.php?r=users/status', { ids, status })
}

export function resetPassword(id: number): Promise<{ defaultPassword: string }> {
  return http.post<{ defaultPassword: string }>('/api.php?r=users/reset-password', { id })
}

export function deleteUser(id: number): Promise<null> {
  return http.post<null>('/api.php?r=users/delete', { id })
}

export type BatchAction = 'department' | 'role' | 'status'

export function batchUpdate(
  ids: number[],
  action: BatchAction,
  value: number | string | Role[],
): Promise<{ affected: number }> {
  return http.post<{ affected: number }>('/api.php?r=users/batch', { ids, action, value })
}
