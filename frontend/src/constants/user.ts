/** 角色与权限常量（与后端 inc/auth.php 保持一致） */

import type { Role } from '@/types'

export const ROLES: Role[] = [
  '销售员',
  '售后服务人员',
  '销售经理',
  '管理员',
  '评审委员',
  '产品经理',
  '开发工程师',
]

export const ROLE_ADMIN: Role = '管理员'
export const ROLE_SALES: Role = '销售员'
export const ROLE_SERVICE: Role = '售后服务人员'
export const ROLE_MANAGER: Role = '销售经理'
export const ROLE_COMMITTEE: Role = '评审委员'
export const ROLE_PM: Role = '产品经理'
export const ROLE_DEV: Role = '开发工程师'

/** 可处理售后问题的角色 */
export const HANDLE_ROLES: Role[] = [ROLE_SERVICE, ROLE_ADMIN]

/**
 * 角色权限从大到小的排序（用于只展示一个主角色）。
 * 依据：数据范围越宽、可操作模块越多的角色排越前；管理员最高。
 */
export const ROLE_PRIORITY: Role[] = [
  ROLE_ADMIN,
  ROLE_SERVICE,
  ROLE_MANAGER,
  ROLE_COMMITTEE,
  ROLE_PM,
  ROLE_DEV,
  ROLE_SALES,
]

/** 取权限最大的单个角色（多角色时用于顶栏等位置只展示一个） */
export function primaryRole(roles: Role[] | undefined): Role | undefined {
  if (!roles || roles.length === 0) {
    return undefined
  }
  for (const r of ROLE_PRIORITY) {
    if (roles.includes(r)) {
      return r
    }
  }
  return roles[0]
}

/** 可查看报表的角色 */
export const REPORT_ROLES: Role[] = [ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN]

/** 可导出数据的角色 */
export const EXPORT_ROLES: Role[] = [ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN]

/** 可维护排班的角色（仅管理员：排班对全员公开展示，但配置权归管理员） */
export const DUTY_ROLES: Role[] = [ROLE_ADMIN]

/** 多角色：拥有任一允许角色即为通过 */
function hasAny(roles: Role[] | undefined, allowed: Role[]): boolean {
  if (!roles || roles.length === 0) {
    return false
  }
  return roles.some((r) => allowed.includes(r))
}

export function canHandle(roles: Role[] | undefined): boolean {
  return hasAny(roles, HANDLE_ROLES)
}

export function canReport(roles: Role[] | undefined): boolean {
  return hasAny(roles, REPORT_ROLES)
}

export function canExport(roles: Role[] | undefined): boolean {
  return hasAny(roles, EXPORT_ROLES)
}

export function canManageDuty(roles: Role[] | undefined): boolean {
  return hasAny(roles, DUTY_ROLES)
}

/* ==================== 需求管理模块能力 ==================== */

/** 可评审需求（评审委员 / 管理员；发布上线同权限） */
export const REVIEW_REQ_ROLES: Role[] = [ROLE_COMMITTEE, ROLE_ADMIN]

/** 开发需求（需求池点「开发」进入开发中心；开发工程师 / 管理员） */
export const DEV_REQ_ROLES: Role[] = [ROLE_DEV, ROLE_ADMIN]

/** 可评审需求 */
export function canReviewReq(roles: Role[] | undefined): boolean {
  return hasAny(roles, REVIEW_REQ_ROLES)
}

/** 可开发（认领开发）需求 */
export function canDevReq(roles: Role[] | undefined): boolean {
  return hasAny(roles, DEV_REQ_ROLES)
}

/* ==================== 销售分析（输单）模块能力 ==================== */

/** 可访问销售分析（输单）的角色：销售员 / 销售经理 / 管理员 */
export const LOSS_ROLES: Role[] = [ROLE_SALES, ROLE_MANAGER, ROLE_ADMIN]

/** 可访问销售分析（输单） */
export function canViewLoss(roles: Role[] | undefined): boolean {
  return hasAny(roles, LOSS_ROLES)
}

/** 可删除输单（仅管理员；销售员/销售经理可编辑自己的单但不能删） */
export function canDeleteLoss(roles: Role[] | undefined): boolean {
  return isAdmin(roles)
}

export function isAdmin(roles: Role[] | undefined): boolean {
  return !!roles && roles.includes(ROLE_ADMIN)
}
