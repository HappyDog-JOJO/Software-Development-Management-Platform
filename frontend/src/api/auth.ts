import { http } from './request'
import type { LoginResult, LoginUser } from '@/types'

/** 认证相关接口 */

export function login(account: string, password: string): Promise<LoginResult> {
  return http.post<LoginResult>('/api.php?r=auth/login', { account, password })
}

export function logout(): Promise<null> {
  return http.post<null>('/api.php?r=auth/logout')
}

export interface RegisterPayload {
  account: string
  password: string
  phone: string
  avatar: string
  name?: string
  /** 所属部门（必填；仅允许启用中的部门） */
  departmentId: number
}

export function register(payload: RegisterPayload): Promise<LoginUser> {
  return http.post<LoginUser>('/api.php?r=auth/register', payload)
}

export function checkAccount(account: string): Promise<{ available: boolean }> {
  // 公开的账号可用性接口：未登录即可调用，只回「是否可用」，不暴露用户信息。
  // （早期版本复用了管理员专用的 users 接口，未登录会 401 并把注册页整页刷掉）
  return http.get<{ available: boolean }>('/api.php?r=auth/account-available', { account }, true)
}

export function changePassword(oldPassword: string, newPassword: string): Promise<null> {
  return http.post<null>('/api.php?r=auth/change-password', { oldPassword, newPassword })
}

export function getProfile(): Promise<LoginUser> {
  return http.get<LoginUser>('/api.php?r=auth/profile')
}

export interface ProfilePayload {
  name?: string
  phone?: string
  avatar?: string
}

export function updateProfile(payload: ProfilePayload): Promise<LoginUser> {
  return http.put<LoginUser>('/api.php?r=auth/profile', payload)
}
