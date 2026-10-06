import type { App, Directive } from 'vue'
import { useAuthStore } from '@/stores/auth'
import type { Role } from '@/types'

/**
 * 权限指令：v-permission="['管理员','售后服务人员']"
 * 无权限时移除元素；加 .disable 修饰符时改为置灰并禁用。
 * 仅体验层控制，服务端在数据层强制过滤。
 */
const permission: Directive<HTMLElement, Array<Role | string>> = {
  mounted(el, binding) {
    const auth = useAuthStore()
    const roles = binding.value
    if (!roles || roles.length === 0) {
      return
    }
    if (!auth.hasRole(roles)) {
      if (binding.modifiers.disable) {
        el.classList.add('is-disabled')
        el.setAttribute('disabled', 'disabled')
        el.style.opacity = '0.5'
        el.style.cursor = 'not-allowed'
        el.title = '当前角色无此操作权限'
      } else {
        el.parentNode?.removeChild(el)
      }
    }
  },
}

export function setupPermissionDirective(app: App): void {
  app.directive('permission', permission)
}

export default permission
