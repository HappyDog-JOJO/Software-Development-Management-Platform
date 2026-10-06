/**
 * 附件（图片/文档）的查看与下载规则：
 * - 图片类型：可点击直接预览（**页面内弹窗**，不打开新标签页）
 * - 其他文档：不允许在线查看，必须走下载（HTML5 download 属性强制下载）
 *
 * 图片预览通过全局单例 ImageViewer 实现：
 * 各页面/组件只要调用 openAttachment() 即可，无需各自维护弹窗状态。
 * 单例由 main.ts 挂载的 <ImageViewer> 提供，并通过 registerImageViewer() 注册。
 */

export interface ImagePreviewItem {
  url: string
  name: string
}

export interface ImageViewerApi {
  open: (list: ImagePreviewItem[], startIndex?: number) => void
  close: () => void
}

/** 全局图片预览实例（由 App 层注册） */
let viewer: ImageViewerApi | null = null

/** 注册全局图片预览实例（应用启动时调用一次） */
export function registerImageViewer(api: ImageViewerApi | null): void {
  viewer = api
}

/**
 * 兜底预览：全局预览组件尚未就绪时（极少见），
 * 用临时 DOM 弹层展示图片，仍然不跳转页面、不新开标签页。
 */
function fallbackPreview(url: string, name: string): void {
  const mask = document.createElement('div')
  mask.setAttribute(
    'style',
    'position:fixed;inset:0;z-index:3000;display:flex;align-items:center;justify-content:center;' +
      'background:rgba(24,28,44,.72);cursor:zoom-out',
  )
  const img = document.createElement('img')
  img.src = url
  img.alt = name
  img.setAttribute('style', 'max-width:84vw;max-height:80vh;border-radius:8px;box-shadow:0 18px 48px rgba(0,0,0,.36)')
  mask.appendChild(img)
  mask.addEventListener('click', () => mask.remove())
  document.addEventListener(
    'keydown',
    function onEsc(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        mask.remove()
        document.removeEventListener('keydown', onEsc)
      }
    },
  )
  document.body.appendChild(mask)
}

/** 是否为可直接预览的图片 */
export function isPreviewableImage(mimeType: string, name = ''): boolean {
  if (mimeType && mimeType.startsWith('image/')) {
    return true
  }
  return /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(name)
}

/**
 * 打开附件：
 * - 图片 → 页面内弹窗预览（不新开标签页）
 * - 其他 → 强制下载（不进入在线查看）
 * 返回实际执行的动作，便于调用方提示。
 *
 * @param url 附件地址
 * @param mimeType MIME 类型
 * @param name 文件名
 * @param siblings 同组图片（可选）：传入后弹窗内可用左右键切换
 */
export async function openAttachment(
  url: string,
  mimeType: string,
  name: string,
  siblings?: ImagePreviewItem[],
): Promise<'preview' | 'download'> {
  if (isPreviewableImage(mimeType, name)) {
    const list = siblings && siblings.length ? siblings : [{ url, name }]
    const startIndex = Math.max(0, list.findIndex((x) => x.url === url))
    if (viewer) {
      viewer.open(list, startIndex)
    } else {
      // 兜底：预览组件尚未挂载时（极少见）用临时弹层，仍不跳转页面
      fallbackPreview(url, name)
    }
    return 'preview'
  }
  await downloadAttachment(url, name)
  return 'download'
}

/**
 * 强制下载指定文件。
 * 用 <a download> 而非 window.open：避免浏览器把 pdf/docx 直接内嵌打开，
 * 满足「其他文档之类不能查看，要允许下载」的要求。
 */
export async function downloadAttachment(url: string, name: string): Promise<void> {
  try {
    const res = await fetch(url, { credentials: 'same-origin' })
    if (!res.ok) {
      throw new Error('HTTP ' + res.status)
    }
    const blob = await res.blob()
    const objectUrl = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = objectUrl
    a.download = name || 'download'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    // 交给浏览器触发下载后再回收
    setTimeout(() => URL.revokeObjectURL(objectUrl), 2000)
  } catch {
    // 兜底：同源直链 + download 属性
    const a = document.createElement('a')
    a.href = url
    a.download = name || 'download'
    a.rel = 'noopener'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
  }
}
