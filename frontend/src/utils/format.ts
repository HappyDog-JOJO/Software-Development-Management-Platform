/** 时间与数值格式化工具 */

function pad(n: number): string {
  return n < 10 ? `0${n}` : String(n)
}

/** 统一展示格式 YYYY-MM-DD HH:mm */
export function fmtDateTime(value: string | null | undefined): string {
  if (!value) {
    return '—'
  }
  const d = new Date(value.replace(/-/g, '/'))
  if (Number.isNaN(d.getTime())) {
    return value
  }
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** 仅日期 YYYY-MM-DD */
export function fmtDate(value: Date | string): string {
  const d = typeof value === 'string' ? new Date(value.replace(/-/g, '/')) : value
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** 文件大小可读化 */
export function fmtSize(bytes: number): string {
  if (!bytes || bytes < 0) {
    return '0 B'
  }
  if (bytes < 1024) {
    return `${bytes} B`
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

/** 导出文件名时间戳 YYYYMMDD_HHmm */
export function fileStamp(d: Date = new Date()): string {
  return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}`
}

/** 计算快捷时间范围 */
export function quickRange(range: 'today' | 'week' | 'month' | 'days30', now: Date = new Date()): {
  dateStart: string
  dateEnd: string
} {
  const end = new Date(now)
  const start = new Date(now)
  if (range === 'today') {
    // 当日
  } else if (range === 'week') {
    start.setDate(start.getDate() - 6)
  } else if (range === 'month') {
    start.setDate(1)
  } else {
    start.setDate(start.getDate() - 29)
  }
  return { dateStart: fmtDate(start), dateEnd: fmtDate(end) }
}

/** 判断是否为图片类型 */
export function isImage(mimeType: string, name = ''): boolean {
  if (mimeType.startsWith('image/')) {
    return true
  }
  return /\.(png|jpe?g|gif|webp|bmp)$/i.test(name)
}
