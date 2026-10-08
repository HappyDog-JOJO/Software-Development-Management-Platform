/** 需求管理模块 · 需求状态/优先级语义（颜色只在 token 层派生） */

import type { DevPriority, DevStatus } from '@/types'

/** 主流程五站（时间线顺序；已驳回/已取消为分支终态） */
export const DEV_STAGES: DevStatus[] = ['评审池', '需求池', '设计中心', '开发中心', '已上线']

/** 全部状态（列表筛选用；「待发布」仅历史数据保留，不再作为筛选入口） */
export const DEV_STATUS_OPTIONS: DevStatus[] = [
  '评审池',
  '需求池',
  '设计中心',
  '开发中心',
  '已上线',
  '已驳回',
  '已取消',
]

/** 状态 → pill 样式类 */
export const DEV_STATUS_CLASS: Record<DevStatus, string> = {
  评审池: 'pill pill--yellow',
  需求池: 'pill pill--primary',
  设计中心: 'pill pill--primary',
  开发中心: 'pill pill--pink',
  待发布: 'pill pill--primary',
  已上线: 'pill pill--green',
  已驳回: 'pill pill--red',
  已取消: 'pill pill--grey',
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
