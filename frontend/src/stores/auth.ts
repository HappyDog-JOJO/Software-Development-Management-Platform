import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { LoginUser, Role } from '@/types'
import * as authApi from '@/api/auth'
import { clearAuthStorage, getToken, setToken } from '@/api/request'
import { ROLE_ADMIN, ROLE_MANAGER, ROLE_SALES, ROLE_SERVICE } from '@/constants/user'

const USER_KEY = 'aftersales_user'

/**
 * 认证状态：token、登录用户、角色、权限判断 getter。
 * 登录态持久化到 localStorage，刷新不掉线。
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref<string>(getToken())
  const user = ref<LoginUser | null>(readCachedUser())

  function readCachedUser(): LoginUser | null {
    const raw = localStorage.getItem(USER_KEY)
    if (!raw) {
      return null
    }
    try {
      return JSON.parse(raw) as LoginUser
    } catch {
      return null
    }
  }

  function persistUser(u: LoginUser | null): void {
    if (u) {
      localStorage.setItem(USER_KEY, JSON.stringify(u))
    } else {
      localStorage.removeItem(USER_KEY)
    }
  }

  const role = computed<Role | undefined>(() => user.value?.roles?.[0])
  const roles = computed<Role[]>(() => user.value?.roles ?? [])
  const isLoggedIn = computed(() => !!token.value && !!user.value)
  const isAdmin = computed(() => roles.value.includes(ROLE_ADMIN))
  const isSales = computed(() => roles.value.includes(ROLE_SALES))
  const isService = computed(() => roles.value.includes(ROLE_SERVICE))
  const isManager = computed(() => roles.value.includes(ROLE_MANAGER))

  /** 权限判断：多角色取并集，拥有任一允许角色即通过 */
  function hasRole(rolesNeeded?: Array<Role | string>): boolean {
    if (!rolesNeeded || rolesNeeded.length === 0) {
      return true
    }
    return roles.value.some((r) => rolesNeeded.includes(r))
  }

  /** 数据范围：仅当只有销售经理（无销售员/售后/管理员）时才是本部门 */
  const isDeptScoped = computed(
    () =>
      roles.value.length > 0 &&
      !roles.value.includes(ROLE_SALES) &&
      !roles.value.includes(ROLE_SERVICE) &&
      !roles.value.includes(ROLE_ADMIN) &&
      roles.value.includes(ROLE_MANAGER),
  )

  /** 数据范围：销售经理且仅本部门时返回本部门 ID，否则 null（全部） */
  const scopeDepartmentId = computed<number | null>(() =>
    isDeptScoped.value ? (user.value?.departmentId ?? null) : null,
  )

  async function login(account: string, password: string): Promise<LoginUser> {
    const res = await authApi.login(account, password)
    token.value = res.token
    user.value = res.user
    setToken(res.token)
    persistUser(res.user)
    return res.user
  }

  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } catch {
      // 退出失败不阻塞本地清理
    }
    token.value = ''
    user.value = null
    clearAuthStorage()
  }

  async function fetchProfile(): Promise<LoginUser> {
    const u = await authApi.getProfile()
    user.value = u
    persistUser(u)
    return u
  }

  function applyUser(u: LoginUser): void {
    user.value = u
    persistUser(u)
  }

  return {
    token,
    user,
    role,
    roles,
    isLoggedIn,
    isAdmin,
    isSales,
    isService,
    isManager,
    isDeptScoped,
    hasRole,
    scopeDepartmentId,
    login,
    logout,
    fetchProfile,
    applyUser,
  }
})
