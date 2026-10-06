import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Department, PageResult, User } from '@/types'
import * as userApi from '@/api/user'
import * as deptApi from '@/api/department'

/** 后台维护：用户与部门 */
export const useUserStore = defineStore('userAdmin', () => {
  const list = ref<User[]>([])
  const total = ref(0)
  const page = ref(1)
  const pageSize = ref(10)
  const loading = ref(false)
  /** 最近一次查询条件：写操作后按同一条件重新拉取，避免列表回退到无条件首页 */
  const lastFilters = ref<userApi.UserQuery>({})

  async function load(filters?: userApi.UserQuery): Promise<void> {
    if (filters) {
      lastFilters.value = { ...filters }
    }
    loading.value = true
    try {
      const res: PageResult<User> = await userApi.fetchUsers({
        ...lastFilters.value,
        page: page.value,
        pageSize: pageSize.value,
      })
      list.value = res.items
      total.value = res.total
    } catch {
      // 拉取失败（如权限不足或登录态失效）由请求层统一提示
    } finally {
      loading.value = false
    }
  }

  async function loadAllForSelect(): Promise<User[]> {
    const res = await userApi.fetchUsers({ page: 1, pageSize: 100 })
    return res.items
  }

  function reset(): void {
    list.value = []
    total.value = 0
    page.value = 1
    lastFilters.value = {}
  }

  return { list, total, page, pageSize, loading, lastFilters, load, loadAllForSelect, reset }
})

export const useDeptStore = defineStore('department', () => {
  const list = ref<Department[]>([])
  const loading = ref(false)
  const loaded = ref(false)

  /**
   * 拉取部门列表。
   * force=true 时必请求（新增/编辑/启停/删除后调用，保证写入立刻回显）；
   * 首屏合并接口（bootstrap）已注入数据且 force=false 时直接短路，避免重复请求——
   * 单线程服务器下每次请求都要新建数据库连接，重复请求会明显拖慢页面。
   */
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) {
      return
    }
    loading.value = true
    try {
      list.value = await deptApi.fetchDepartments()
      loaded.value = true
    } catch {
      // 部门列表拉取失败时不中断页面渲染，下拉暂空
      list.value = []
    } finally {
      loading.value = false
    }
  }

  /** 启用中的部门（供下拉使用） */
  function enabled(): Department[] {
    return list.value.filter((d) => d.status === 1)
  }

  /** 直接注入部门数据（首屏合并接口已返回时使用） */
  function applyItems(items: Department[]): void {
    list.value = items
    loaded.value = true
  }

  function nameOf(id: number | null | undefined): string | null {
    if (!id) {
      return null
    }
    const hit = list.value.find((d) => d.id === id)
    return hit ? hit.name : null
  }

  function reset(): void {
    list.value = []
    loaded.value = false
  }

  return { list, loading, loaded, load, applyItems, enabled, nameOf, reset }
})
