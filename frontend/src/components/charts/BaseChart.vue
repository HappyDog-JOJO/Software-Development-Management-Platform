<script setup lang="ts">
/**
 * 图表容器：统一处理容器尺寸变化（resize）与实例销毁，禁止内存泄漏。
 * 数据为空时展示空态而非空白画布。
 */
import { nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import * as echarts from 'echarts'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'

const props = withDefaults(
  defineProps<{
    option: echarts.EChartsOption
    height?: number
    empty?: boolean
    emptyText?: string
    loading?: boolean
  }>(),
  { height: 280, empty: false, emptyText: '暂无数据', loading: false },
)

const containerRef = ref<HTMLDivElement | null>(null)
const chart = shallowRef<echarts.ECharts | null>(null)
let observer: ResizeObserver | null = null

function render(): void {
  if (!containerRef.value) {
    return
  }
  if (props.empty || props.loading) {
    if (chart.value) {
      chart.value.dispose()
      chart.value = null
    }
    return
  }
  if (!chart.value) {
    chart.value = echarts.init(containerRef.value)
  }
  chart.value.setOption(props.option, true)
  chart.value.resize()
}

onMounted(async () => {
  await nextTick()
  render()
  if (containerRef.value && typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(() => {
      chart.value?.resize()
    })
    observer.observe(containerRef.value)
  }
  window.addEventListener('resize', onResize)
})

function onResize(): void {
  chart.value?.resize()
}

watch(
  () => [props.option, props.empty, props.loading],
  async () => {
    await nextTick()
    render()
  },
  { deep: true },
)

onBeforeUnmount(() => {
  observer?.disconnect()
  observer = null
  window.removeEventListener('resize', onResize)
  chart.value?.dispose()
  chart.value = null
})
</script>

<template>
  <div class="chart-box" :style="{ height: `${height}px` }">
    <div v-if="loading" class="chart-box__state">
      <span class="form-tip">正在加载图表…</span>
    </div>
    <EmptyIllustration v-else-if="empty" :size="120" :text="emptyText" />
    <div v-else ref="containerRef" class="chart-box__canvas" />
  </div>
</template>

<style scoped>
.chart-box {
  position: relative;
  width: 100%;
}

.chart-box__canvas {
  width: 100%;
  height: 100%;
}

.chart-box__state {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
}
</style>
