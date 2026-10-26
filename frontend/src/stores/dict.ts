import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { DictItem, DictType } from '@/types'
import * as dictApi from '@/api/dict'

/** 字典缓存：四类字典供全局下拉复用 */
export const useDictStore = defineStore('dict', () => {
  const items = ref<DictItem[]>([])
  const loaded = ref(false)
  const loading = ref(false)

  /** 按类型分组的启用字典项（供下拉使用） */
  const modules = computed(() => enabledOf('module'))
  const values = computed(() => enabledOf('value'))
  const handleStatuses = computed(() => enabledOf('handle'))
  const categories = computed(() => enabledOf('category'))
  // 销售分析（输单）五个维度
  const lossTypes = computed(() => enabledOf('loss_type'))
  const industries = computed(() => enabledOf('industry'))
  const companyScales = computed(() => enabledOf('company_scale'))
  const lossStages = computed(() => enabledOf('loss_stage'))
  const budgetMatches = computed(() => enabledOf('budget_match'))

  function enabledOf(type: DictType): DictItem[] {
    return items.value
      .filter((it) => it.type === type && it.status === 1)
      .sort((a, b) => a.sort - b.sort || a.id - b.id)
  }

  /** 含停用项（历史数据展示用） */
  function allOf(type: DictType): DictItem[] {
    return items.value.filter((it) => it.type === type).sort((a, b) => a.sort - b.sort || a.id - b.id)
  }

  function nameOf(id: number | null | undefined): string | null {
    if (!id) {
      return null
    }
    const hit = items.value.find((it) => it.id === id)
    return hit ? hit.name : null
  }

  async function load(force = false): Promise<void> {
    if (loaded.value && !force) {
      return
    }
    loading.value = true
    try {
      items.value = await dictApi.fetchDict()
      loaded.value = true
    } catch {
      // 字典属全局缓存：拉取失败（如登录态失效）时不抛错，由路由守卫统一处理跳转
      loaded.value = false
    } finally {
      loading.value = false
    }
  }

  /** 直接注入字典数据（首屏合并接口已返回时使用，避免重复请求） */
  function applyItems(list: DictItem[]): void {
    items.value = list
    loaded.value = true
  }

  function reset(): void {
    items.value = []
    loaded.value = false
  }

  return {
    items,
    loaded,
    loading,
    modules,
    values,
    handleStatuses,
    categories,
    lossTypes,
    industries,
    companyScales,
    lossStages,
    budgetMatches,
    enabledOf,
    allOf,
    nameOf,
    load,
    applyItems,
    reset,
  }
})
