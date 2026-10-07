import { onBeforeUnmount, onMounted } from 'vue'

/**
 * 粘贴上传分发（供 AttachmentUpload 使用）。
 *
 * 全局只挂一个 window paste 监听：剪贴板里带文件（截图、复制的文件）时，
 * 交给「当前可见且启用」的上传组件接收；纯文本或不匹配的粘贴完全放行，
 * 不影响输入框的正常粘贴。
 *
 * 为什么要分发：同一页面可能同时挂载多个上传组件（如列表页 + 弹窗里的上传区），
 * 若每个实例各自监听，剪贴板文件会被同时上传多份。
 *
 * 归属规则：
 * 1. 粘贴落点在某个上传组件内部 → 由它接收；
 * 2. 输入区内同时含文本与文件（如从 Word 复制）→ 文本优先，放行不接管；
 * 3. 其余情况由注册顺序里最后一个「可见且启用」的组件接收（挂载晚、层级在上的优先）。
 *
 * 存储：组件卸载即注销；全部注销后移除 window 监听。
 */

export interface PasteFilesSink {
  /** 上传组件根元素（用于判断粘贴落点） */
  el: () => HTMLElement | null
  /** 是否可见（未挂载到文档时不参与接收） */
  isVisible: () => boolean
  /** 是否可用（disabled 时不参与接收） */
  isEnabled: () => boolean
  /** 接收剪贴板中的文件 */
  onFiles: (files: File[]) => void
}

const sinks: PasteFilesSink[] = []

/** 从剪贴板数据中提取文件（items 为主，部分浏览器只填 files） */
function extractFiles(e: ClipboardEvent): File[] {
  const dt = e.clipboardData
  if (!dt) {
    return []
  }
  const out: File[] = []
  for (const item of Array.from(dt.items ?? [])) {
    if (item.kind === 'file') {
      const f = item.getAsFile()
      if (f) {
        out.push(f)
      }
    }
  }
  if (!out.length && dt.files?.length) {
    out.push(...Array.from(dt.files))
  }
  return out
}

/** 扩展名兜底表（剪贴板文件常无扩展名，用 MIME 反推） */
const MIME_EXT: Record<string, string> = {
  'image/png': 'png',
  'image/jpeg': 'jpg',
  'image/gif': 'gif',
  'image/webp': 'webp',
  'image/bmp': 'bmp',
  'image/svg+xml': 'svg',
}

/**
 * 剪贴板文件规范化：截图多为通用名（image.png）或空名，
 * 后端按「文件名扩展名」识别类型（空扩展名会被拒），这里补可读名与正确扩展名；
 * 名称具体且带扩展名的（如从资源管理器复制的文件）原样保留。
 */
export function normalizePastedFiles(files: File[]): File[] {
  const d = new Date()
  const p = (n: number): string => String(n).padStart(2, '0')
  const stamp = `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`
  return files.map((f) => {
    const name = (f.name || '').trim()
    const extFromName = /\.([A-Za-z0-9]{1,8})$/.exec(name)?.[1]?.toLowerCase() ?? ''
    // 通用名判定：无扩展名的裸名，或 image.png / blob / clipboard 这类剪贴板默认名
    const generic = !name || /^(image|blob|paste|clipboard)(\.\w{1,8})?$/i.test(name)
    if (name && extFromName && !generic) {
      return f
    }
    const ext = extFromName || MIME_EXT[f.type] || 'png'
    const prefix = f.type.startsWith('image/') ? '截图' : '粘贴文件'
    return new File([f], `${prefix}_${stamp}.${ext}`, { type: f.type })
  })
}

function onWindowPaste(e: ClipboardEvent): void {
  // 已由更具体的处理器处理过（如富文本贴图组件）则不重复接管
  if (e.defaultPrevented) {
    return
  }
  const files = extractFiles(e)
  if (!files.length) {
    // 纯文本粘贴：放行，不干扰输入框
    return
  }
  const targetEl = e.target instanceof Element ? e.target : null
  // 输入区内「文本 + 文件」混合时以文本优先，避免吞掉正常文本粘贴
  const inEditable = !!targetEl?.closest(
    'input, textarea, [contenteditable]:not([contenteditable="false"])',
  )
  if (inEditable && (e.clipboardData?.getData('text/plain') ?? '') !== '') {
    return
  }
  let owner: PasteFilesSink | undefined
  if (targetEl) {
    owner = sinks.find((s) => s.isVisible() && s.isEnabled() && (s.el()?.contains(targetEl) ?? false))
  }
  if (!owner) {
    for (let i = sinks.length - 1; i >= 0; i -= 1) {
      const s = sinks[i]
      if (s.isVisible() && s.isEnabled()) {
        owner = s
        break
      }
    }
  }
  if (!owner) {
    return
  }
  // 确认有接收方后再阻止默认行为，避免文件二进制被插入到其它区域
  e.preventDefault()
  owner.onFiles(files)
}

export function usePasteFiles(sink: PasteFilesSink): void {
  onMounted(() => {
    sinks.push(sink)
    if (sinks.length === 1) {
      window.addEventListener('paste', onWindowPaste)
    }
  })
  onBeforeUnmount(() => {
    const i = sinks.indexOf(sink)
    if (i >= 0) {
      sinks.splice(i, 1)
    }
    if (!sinks.length) {
      window.removeEventListener('paste', onWindowPaste)
    }
  })
}