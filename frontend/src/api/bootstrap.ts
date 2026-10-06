import { http } from './request'
import type { Department, DictItem, DutyToday, LoginUser } from '@/types'

/**
 * 首屏基础数据（合并接口）。
 * 把「字典 + 部门 + 今日值班人」合并为一次请求：
 * 单线程服务器下每次请求都要新建数据库连接，合并可显著缩短登录与首屏耗时。
 */
export interface BootstrapResult {
  user: LoginUser
  dicts: DictItem[]
  departments: Department[]
  dutyToday: DutyToday
}

export function fetchBootstrap(): Promise<BootstrapResult> {
  return http.get<BootstrapResult>('/api.php?r=bootstrap')
}
