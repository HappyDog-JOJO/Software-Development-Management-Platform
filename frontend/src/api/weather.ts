import { http } from './request'
import type { WeatherToday } from '@/types'

/**
 * 今日天气（服务端代理 Open-Meteo，缓存 30 分钟）。
 * 失败时返回 available=false，页面不显示天气块即可，不影响登记。
 */
export function fetchWeatherToday(): Promise<WeatherToday> {
  return http.get<WeatherToday>('/api.php?r=weather/today', undefined, true)
}
