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
  '质量审核员',
]

export const ROLE_ADMIN: Role = '管理员'
export const ROLE_SALES: Role = '销售员'
export const ROLE_SERVICE: Role = '售后服务人员'
export const ROLE_MANAGER: Role = '销售经理'
export const ROLE_COMMITTEE: Role = '评审委员'
export const ROLE_PM: Role = '产品经理'
export const ROLE_DEV: Role = '开发工程师'
export const ROLE_INSPECTOR: Role = '质量审核员'

/** 可处理售后问题的角色 */
export const HANDLE_ROLES: Role[] = [ROLE_SERVICE, ROLE_ADMIN]

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

/** 可认领/设计需求（产品经理 / 管理员） */
export const DESIGN_REQ_ROLES: Role[] = [ROLE_PM, ROLE_ADMIN]

/** 可开发作战（开发工程师 / 管理员） */
export const DEV_REQ_ROLES: Role[] = [ROLE_DEV, ROLE_ADMIN]

/** 可评审需求 */
export function canReviewReq(roles: Role[] | undefined): boolean {
  return hasAny(roles, REVIEW_REQ_ROLES)
}

/** 可认领/设计需求 */
export function canDesignReq(roles: Role[] | undefined): boolean {
  return hasAny(roles, DESIGN_REQ_ROLES)
}

/** 可开发作战 */
export function canDevReq(roles: Role[] | undefined): boolean {
  return hasAny(roles, DEV_REQ_ROLES)
}

export function isAdmin(roles: Role[] | undefined): boolean {
  return !!roles && roles.includes(ROLE_ADMIN)
}
