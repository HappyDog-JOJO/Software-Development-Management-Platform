/** 需求管理模块 · 需求状态/优先级语义（颜色只在 token 层派生） */

import type { DevPriority, DevStatus } from '@/types'

/** 主流程四站（时间线顺序；已驳回/已取消为分支终态） */
export const DEV_STAGES: DevStatus[] = ['评审池', '需求池', '开发中心', '已上线']

/** 全部状态（列表筛选用；「待发布」仅历史数据保留，不再作为筛选入口） */
export const DEV_STATUS_OPTIONS: DevStatus[] = [
  '评审池',
  '需求池',
  '开发中心',
  '已上线',
  '已驳回',
  '已取消',
]

/** 状态 → pill 样式类 */
export const DEV_STATUS_CLASS: Record<DevStatus, string> = {
  评审池: 'pill pill--yellow',
  需求池: 'pill pill--primary',
  开发中心: 'pill pill--pink',
  待发布: 'pill pill--primary',
  已上线: 'pill pill--green',
  已驳回: 'pill pill--red',
  已取消: 'pill pill--grey',
}

/** el-tag 类型；'grey' 为自定义哨兵（el-tag 无灰色类型，渲染时搭配 .tag--grey 覆盖保持灰色语义） */
export type DevTagType = 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'grey'

/** 状态 → el-tag 类型（详情页头部标签等） */
export const DEV_STATUS_TAG: Record<DevStatus, DevTagType> = {
  评审池: 'warning',
  需求池: 'primary',
  开发中心: 'danger',
  待发布: 'primary',
  已上线: 'success',
  已驳回: 'danger',
  已取消: 'grey',
}

/** 优先级 → el-tag 类型（详情页头部标签等） */
export const DEV_PRIORITY_TAG: Record<DevPriority, DevTagType> = {
  紧急: 'danger',
  高: 'warning',
  中: 'primary',
  低: 'grey',
}

/** 优先级 → pill 样式类（紧急红 / 高橙 / 中蓝 / 低灰，对齐方案语义色） */
export const DEV_PRIORITY_CLASS: Record<DevPriority, string> = {
  紧急: 'pill pill--red',
  高: 'pill pill--pink',
  中: 'pill pill--primary',
  低: 'pill pill--grey',
}

/** 优先级选项（提交页与筛选用） */
export const DEV_PRIORITY_OPTIONS: DevPriority[] = ['紧急', '高', '中', '低']

/** 主流程进行到第几站（已驳回/已取消由调用方单独处理） */
export function devStageIndex(status: DevStatus): number {
  return DEV_STAGES.indexOf(status)
}

/** 需求是否处于分支终态（详情页用） */
export function isDevBranchStatus(status: DevStatus): boolean {
  return status === '已驳回' || status === '已取消'
}
