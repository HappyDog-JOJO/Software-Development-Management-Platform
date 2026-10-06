import { http } from './request'
import type { DictItem, DictType } from '@/types'

/** 字典维护接口 */

export function fetchDict(type?: DictType): Promise<DictItem[]> {
  return http.get<DictItem[]>('/api.php?r=dicts', type ? { type } : {})
}

export interface DictPayload {
  id?: number
  type: DictType
  name: string
  sort?: number
}

export function saveDictItem(payload: DictPayload): Promise<{ id: number }> {
  return http.post<{ id: number }>('/api.php?r=dicts/save', payload)
}

export function updateDictItem(payload: DictPayload): Promise<{ id: number }> {
  return http.post<{ id: number }>('/api.php?r=dicts/save', payload)
}

export function toggleDictItem(id: number, status: number): Promise<null> {
  return http.post<null>('/api.php?r=dicts/status', { id, status })
}

export function deleteDictItem(id: number): Promise<null> {
  return http.post<null>('/api.php?r=dicts/delete', { id })
}
