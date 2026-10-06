import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as configApi from '@/api/config'

/**
 * 站点级配置缓存（灯牌 logo）。
 * 管理员在「字典管理 → 灯牌 logo」上传的图片在这里全局共享；
 * 未配置（空串）时两个灯牌回退到内置卡通造型。
 */
export const useSiteConfigStore = defineStore('siteConfig', () => {
  const dutyLogo = ref('')
  const noticeLogo = ref('')
  const loaded = ref(false)

  function apply(data: { duty_logo: string; notice_logo: string }): void {
    dutyLogo.value = data.duty_logo || ''
    noticeLogo.value = data.notice_logo || ''
    loaded.value = true
  }

  /** 拉取配置；失败不抛错（灯牌只是展示，缺配置就用默认造型） */
  async function load(force = false): Promise<void> {
    if (loaded.value && !force) {
      return
    }
    try {
      apply(await configApi.fetchLogoConfig())
    } catch {
      loaded.value = false
    }
  }

  function reset(): void {
    dutyLogo.value = ''
    noticeLogo.value = ''
    loaded.value = false
  }

  return { dutyLogo, noticeLogo, loaded, load, apply, reset }
})
