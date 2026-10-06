import axios, { type AxiosInstance, type AxiosRequestConfig } from 'axios'
import { ElMessage } from 'element-plus'
import type { ApiResponse } from '@/types'

/**
 * Axios 实例：统一 baseURL、鉴权头、错误处理与 401 跳登录。
 * 切换真实后端只需改 baseURL 与端点，页面与组件零改动。
 */
const service: AxiosInstance = axios.create({
  // 接口地址为站点根下的 /api.php?r=module/action；同域部署时 baseURL 留空即可
  baseURL: import.meta.env.VITE_API_BASE_URL || '',
  timeout: 30000,
})

const TOKEN_KEY = 'aftersales_token'
const USER_KEY = 'aftersales_user'

export function getToken(): string {
  return localStorage.getItem(TOKEN_KEY) || ''
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
}

/**
 * 清空登录态（token + 用户缓存）。
 * 必须要连用户缓存一起清：只清 token 会留下“半个登录态”，
 * 路由守卫仍认为已登录而放行，接口又持续 401，与登录页的“已登录回首页”
 * 互相推动，形成无限跳转（表现为“登录后页面不跳转”）。
 */
export function clearAuthStorage(): void {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
}

/**
 * 登录态失效时的统一处理：清缓存 + 整页重载到登录页。
 * 用重载（而不是改 hash）才能真正重建应用内存里的登录状态，
 * 避免守卫与登录页互相推动造成死循环。
 *
 * 防重入：并发请求同时拿到 401 时只跳转一次；
 * 已经在公开页（登录/注册）时不再跳转/刷新（否则会打断用户正在输入的表单）。
 */
let relogining = false

/** 公开页（登录/注册）不应被 401 强制跳转，否则会打断用户正在填的表单 */
function isPublicPage(): boolean {
  return location.hash.startsWith('#/login') || location.hash.startsWith('#/register')
}

function forceRelogin(): void {
  if (relogining) {
    return
  }
  if (isPublicPage()) {
    // 公开页：只清本地缓存，不跳转不刷新
    clearAuthStorage()
    return
  }
  relogining = true
  clearAuthStorage()
  const base = `${location.pathname}${location.search}`
  // replace 避免在历史里留下中间态；reload 确保 store 重新初始化
  location.replace(`${base}#/login`)
  location.reload()
}

service.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

service.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    const err = error as { response?: { status?: number } }
    if (err.response?.status === 401) {
      forceRelogin()
    }
    return Promise.reject(error)
  },
)

/** 统一请求：解析 { code, data, message }；code!==0 抛业务错误 */
async function request<T>(config: AxiosRequestConfig, silent = false): Promise<T> {
  try {
    const res = await service.request<ApiResponse<T>>(config)
    const body = res.data
    if (body && typeof body.code === 'number' && body.code !== 0) {
      if (!silent) {
        ElMessage.error(body.message || '操作失败')
      }
      const e = new Error(body.message || '操作失败') as Error & { code?: number }
      e.code = body.code
      throw e
    }
    return body.data
  } catch (error) {
    const err = error as { response?: { status?: number; data?: ApiResponse<null> }; message?: string }
    if (err.response?.status === 401) {
      ElMessage.error('登录已过期，请重新登录')
    } else if (err.response?.data?.message && !silent) {
      ElMessage.error(err.response.data.message)
    }
    throw error
  }
}

export const http = {
  get: <T>(url: string, params?: object, silent = false) =>
    request<T>({ url, method: 'GET', params }, silent),
  post: <T>(url: string, data?: unknown, silent = false) =>
    request<T>({ url, method: 'POST', data }, silent),
  put: <T>(url: string, data?: unknown, silent = false) =>
    request<T>({ url, method: 'PUT', data }, silent),
  delete: <T>(url: string, params?: object, silent = false) =>
    request<T>({ url, method: 'DELETE', params }, silent),
}

export { service }
export default service
