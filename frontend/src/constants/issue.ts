/** 问题状态与价值的语义定义（颜色只在 token 层派生） */

import type { IssueStatus } from '@/types'

/** 状态 → pill 样式类 */
export const STATUS_CLASS: Record<IssueStatus, string> = {
  待处理: 'pill pill--primary',
  处理中: 'pill pill--yellow',
  已处理: 'pill pill--green',
  未处理: 'pill pill--grey',
  已驳回: 'pill pill--red',
}

/** 状态 → 图标语义（不得只靠颜色传达状态） */
export const STATUS_ICON: Record<IssueStatus, string> = {
  待处理: '待办',
  处理中: '进行中',
  已处理: '完成',
  未处理: '未完成',
  已驳回: '已驳回',
}

/**
 * 从价值名称解析分值（1-5 分制）。
 * 兼容旧三档名称。用于样式/配色分档，避免字典改名后样式失效。
 * 返回 null 表示无法识别（未评级或自定义名称）。
 */
export function parseValueScore(valueName: string | null | undefined): number | null {
  if (!valueName) {
    return null
  }
  const name = valueName.trim()
  // 兼容旧名称
  if (name === '很有价值') {
    return 5
  }
  if (name === '比较有价值') {
    return 4
  }
  if (name === '毫无价值') {
    return 1
  }
  const m = name.match(/(\d+(?:\.\d+)?)/)
  return m ? Number(m[1]) : null
}

/** 价值 → 分值档位：high(4-5) / mid(3) / low(1-2) / unrated */
export function valueLevel(valueName: string | null | undefined): 'high' | 'mid' | 'low' | 'unrated' {
  const score = parseValueScore(valueName)
  if (score === null) {
    return 'unrated'
  }
  if (score >= 4) {
    return 'high'
  }
  if (score <= 2) {
    return 'low'
  }
  return 'mid'
}

/** 价值 → pill 样式类（按分值分档；未评级为空心描边） */
export function valueClass(valueName: string | null | undefined): string {
  switch (valueLevel(valueName)) {
    case 'high':
      return 'pill pill--value-high'
    case 'mid':
      return 'pill pill--value-mid'
    case 'low':
      return 'pill pill--value-none'
    default:
      return 'pill pill--value-unrated'
  }
}

/** 角色 → pill 样式类 */
export function roleClass(role: string): string {
  return role === '管理员' ? 'pill pill--pink' : 'pill pill--primary'
}

/** 操作类型 → pill 样式类 */
export function logTypeClass(type: string): string {
  switch (type) {
    case '登录':
    case '退出':
      return 'pill pill--primary'
    case '注册':
    case '改密':
      return 'pill pill--pink'
    case '提交问题':
      return 'pill pill--green'
    case '问题处理':
      return 'pill pill--yellow'
    case '用户管理':
    case '部门维护':
    case '字典维护':
      return 'pill pill--grey'
    case '导出':
      return 'pill pill--red'
    case '提交需求':
      return 'pill pill--green'
    case '需求评审':
      return 'pill pill--yellow'
    case '需求开发':
      return 'pill pill--pink'
    case '需求发布':
      return 'pill pill--green'
    default:
      return 'pill pill--grey'
  }
}

/** 问题状态选项 */
export const STATUS_OPTIONS: Array<{ label: IssueStatus; value: IssueStatus }> = [
  { label: '待处理', value: '待处理' },
  { label: '处理中', value: '处理中' },
  { label: '已处理', value: '已处理' },
  { label: '未处理', value: '未处理' },
  { label: '已驳回', value: '已驳回' },
]

/** 快捷时间范围 */
export interface QuickRange {
  label: string
  value: 'today' | 'week' | 'month' | 'days30' | 'custom'
}

export const ISSUE_QUICK_RANGES: QuickRange[] = [
  { label: '今日', value: 'today' },
  { label: '近 7 天', value: 'week' },
  { label: '本月', value: 'month' },
  { label: '自定义', value: 'custom' },
]

export const REPORT_QUICK_RANGES: QuickRange[] = [
  { label: '今日', value: 'today' },
  { label: '近 7 天', value: 'week' },
  { label: '近 30 天', value: 'days30' },
  { label: '本月', value: 'month' },
  { label: '自定义', value: 'custom' },
]

/** 操作类型枚举（日志筛选） */
export const LOG_TYPES = [
  '登录',
  '退出',
  '注册',
  '改密',
  '提交问题',
  '问题处理',
  '用户管理',
  '部门维护',
  '字典维护',
  '导出',
]
