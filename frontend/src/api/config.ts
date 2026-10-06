import { http } from './request'

/** 站点级配置接口（灯牌 logo） */

export interface LogoConfig {
  /** 值班灯牌 logo 图片地址；空串表示使用内置默认造型 */
  duty_logo: string
  /** 公告灯牌 logo 图片地址；空串表示使用内置默认造型 */
  notice_logo: string
}

/** 读取灯牌 logo 配置（所有登录用户可读） */
export function fetchLogoConfig(): Promise<LogoConfig> {
  return http.get<LogoConfig>('/api.php?r=config/logos')
}

/** 保存某个灯牌 logo（仅管理员）；传空串表示恢复默认 */
export function saveLogoConfig(key: 'duty_logo' | 'notice_logo', url: string): Promise<LogoConfig> {
  return http.post<LogoConfig>('/api.php?r=config/logo/save', { key, url })
}
