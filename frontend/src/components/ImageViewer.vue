<script setup lang="ts">
/**
 * 图片预览弹层（页面内弹窗，不打开新标签页）。
 * - 支持缩放（按钮 / 滚轮）、拖拽平移、左右切换、ESC 关闭
 * - 由 utils/attachment.ts 的 openAttachment 通过单例命令式调用，
 *   这样表格/抽屉里点击图片都不必各自维护弹窗状态。
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

interface PreviewItem {
  url: string
  name: string
}

const visible = ref(false)
const items = ref<PreviewItem[]>([])
const index = ref(0)

/** 缩放倍数与平移量 */
const scale = ref(1)
const offsetX = ref(0)
const offsetY = ref(0)
/** 拖拽中状态 */
const dragging = ref(false)
let startX = 0
let startY = 0
let baseX = 0
let baseY = 0

const current = computed<PreviewItem | null>(() => items.value[index.value] ?? null)
const canPrev = computed(() => index.value > 0)
const canNext = computed(() => index.value < items.value.length - 1)
const transform = computed(() => `translate(${offsetX.value}px, ${offsetY.value}px) scale(${scale.value})`)

function resetTransform(): void {
  scale.value = 1
  offsetX.value = 0
  offsetY.value = 0
}

/**
 * 打开预览。
 * @param list 预览集合（含当前项）；单张时只传一项
 * @param startIndex 起始下标
 */
function open(list: PreviewItem[], startIndex = 0): void {
  items.value = list.filter((x) => !!x.url)
  if (!items.value.length) {
    return
  }
  index.value = Math.min(Math.max(0, startIndex), items.value.length - 1)
  resetTransform()
  visible.value = true
}

function close(): void {
  visible.value = false
  items.value = []
  index.value = 0
  resetTransform()
}

function prev(): void {
  if (!canPrev.value) {
    return
  }
  index.value -= 1
  resetTransform()
}

function next(): void {
  if (!canNext.value) {
    return
  }
  index.value += 1
  resetTransform()
}

function zoomIn(): void {
  scale.value = Math.min(5, Number((scale.value + 0.25).toFixed(2)))
}

function zoomOut(): void {
  scale.value = Math.max(0.25, Number((scale.value - 0.25).toFixed(2)))
}

function fitScreen(): void {
  resetTransform()
}

/** 图片上按下开始拖拽（缩放后可平移查看细节） */
function onMouseDown(e: MouseEvent): void {
  if (scale.value <= 1) {
    return
  }
  dragging.value = true
  startX = e.clientX
  startY = e.clientY
  baseX = offsetX.value
  baseY = offsetY.value
  e.preventDefault()
}

function onMouseMove(e: MouseEvent): void {
  if (!dragging.value) {
    return
  }
  offsetX.value = baseX + (e.clientX - startX)
  offsetY.value = baseY + (e.clientY - startY)
}

function onMouseUp(): void {
  dragging.value = false
}

/** 滚轮缩放（阻止页面跟着滚动） */
function onWheel(e: WheelEvent): void {
  e.preventDefault()
  if (e.deltaY < 0) {
    zoomIn()
  } else {
    zoomOut()
  }
}

function onKeydown(e: KeyboardEvent): void {
  if (!visible.value) {
    return
  }
  if (e.key === 'Escape') {
    close()
  } else if (e.key === 'ArrowLeft') {
    prev()
  } else if (e.key === 'ArrowRight') {
    next()
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('mousemove', onMouseMove)
  window.addEventListener('mouseup', onMouseUp)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('mousemove', onMouseMove)
  window.removeEventListener('mouseup', onMouseUp)
})

// 切换图片后确保重置
watch(index, () => {
  void nextTick(() => resetTransform())
})

defineExpose({ open, close })
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="imgviewer" @click.self="close">
      <div class="imgviewer__stage" @click.self="close">
        <img
          v-if="current"
          class="imgviewer__img"
          :class="{ 'is-dragging': dragging }"
          :src="current.url"
          :alt="current.name"
          :style="{ transform }"
          draggable="false"
          @mousedown="onMouseDown"
          @wheel="onWheel"
          @click.self.stop
        />
        <div v-else class="imgviewer__empty">无法预览该附件</div>

        <!-- 左侧：上一张 -->
        <button v-if="canPrev" class="imgviewer__nav imgviewer__nav--prev" type="button" @click.stop="prev">‹</button>
        <!-- 右侧：下一张 -->
        <button v-if="canNext" class="imgviewer__nav imgviewer__nav--next" type="button" @click.stop="next">›</button>
      </div>

      <div class="imgviewer__bar" @click.stop>
        <span class="imgviewer__name cell-ellipsis">{{ current?.name }}</span>
        <span class="imgviewer__counter">{{ index + 1 }} / {{ items.length }}</span>
        <span class="imgviewer__spacer" />
        <button class="imgviewer__btn" type="button" title="缩小" @click="zoomOut">−</button>
        <span class="imgviewer__zoom">{{ Math.round(scale * 100) }}%</span>
        <button class="imgviewer__btn" type="button" title="放大" @click="zoomIn">＋</button>
        <button class="imgviewer__btn" type="button" title="适应窗口" @click="fitScreen">适应</button>
        <button class="imgviewer__btn imgviewer__btn--close" type="button" title="关闭（Esc）" @click="close">关闭</button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.imgviewer {
  position: fixed;
  inset: 0;
  z-index: 3000;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(24, 28, 44, 0.72);
}

.imgviewer__stage {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

.imgviewer__img {
  max-width: 84vw;
  max-height: 78vh;
  border-radius: var(--radius-control);
  box-shadow: 0 18px 48px rgba(0, 0, 0, 0.36);
  transition: transform 0.12s ease-out;
  user-select: none;
}

.imgviewer__img.is-dragging {
  transition: none;
  cursor: grabbing;
}

.imgviewer__empty {
  color: #fff;
  font-size: 14px;
  opacity: 0.8;
}

.imgviewer__nav {
  position: absolute;
  top: 50%;
  width: 44px;
  height: 60px;
  transform: translateY(-50%);
  border: none;
  border-radius: var(--radius-control);
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font-size: 30px;
  line-height: 1;
  cursor: pointer;
  transition: background var(--transition-base);
}

.imgviewer__nav:hover {
  background: rgba(255, 255, 255, 0.26);
}

.imgviewer__nav--prev {
  left: 22px;
}

.imgviewer__nav--next {
  right: 22px;
}

.imgviewer__bar {
  position: absolute;
  left: 50%;
  bottom: 26px;
  display: flex;
  align-items: center;
  gap: 10px;
  max-width: 82vw;
  padding: 8px 14px;
  transform: translateX(-50%);
  border-radius: var(--radius-pill);
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 10px 26px rgba(0, 0, 0, 0.24);
}

.imgviewer__name {
  max-width: 260px;
  font-size: 13px;
  color: var(--color-text-primary);
}

.imgviewer__counter {
  font-size: 12.5px;
  color: var(--color-text-secondary);
  font-variant-numeric: tabular-nums;
}

.imgviewer__spacer {
  width: 8px;
}

.imgviewer__zoom {
  min-width: 46px;
  text-align: center;
  font-size: 12.5px;
  color: var(--color-text-secondary);
  font-variant-numeric: tabular-nums;
}

.imgviewer__btn {
  padding: 4px 10px;
  border: 1px solid var(--color-border-divider);
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  color: var(--color-text-regular);
  font-size: 12.5px;
  cursor: pointer;
  transition: border-color var(--transition-base), color var(--transition-base);
}

.imgviewer__btn:hover {
  border-color: var(--color-primary-soft);
  color: var(--color-primary);
}

.imgviewer__btn--close {
  border-color: transparent;
  background: var(--color-primary);
  color: #fff;
}

.imgviewer__btn--close:hover {
  color: #fff;
  opacity: 0.9;
}
</style>
