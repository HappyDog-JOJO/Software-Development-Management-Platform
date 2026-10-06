<script setup lang="ts">
/** 堆叠柱状图：部门 × 模块 交叉分析 */
import { computed } from 'vue'
import type { EChartsOption } from 'echarts'
import BaseChart from './BaseChart.vue'
import { CHART_COLORS, PALETTE, axisStyle, tooltipStyle } from './chartTheme'

const props = withDefaults(
  defineProps<{
    categories: string[]
    series: Array<{ name: string; data: number[] }>
    height?: number
    yName?: string
  }>(),
  { height: 320, yName: '条' },
)

const hasData = computed(() => props.categories.length > 0 && props.series.some((s) => s.data.some((v) => v > 0)))

const option = computed<EChartsOption>(() => ({
  grid: { left: 8, right: 16, top: 40, bottom: 8, containLabel: true },
  tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, ...tooltipStyle() },
  legend: {
    top: 0,
    type: 'scroll',
    icon: 'circle',
    itemWidth: 8,
    itemHeight: 8,
    textStyle: { color: CHART_COLORS.axisText, fontSize: 12 },
  },
  xAxis: {
    type: 'category',
    data: props.categories,
    ...axisStyle(),
    axisLabel: { color: CHART_COLORS.axisText, fontSize: 12, interval: 0, rotate: props.categories.length > 6 ? 20 : 0 },
  },
  yAxis: { type: 'value', name: props.yName, nameTextStyle: { color: CHART_COLORS.axisText, fontSize: 11 }, minInterval: 1, ...axisStyle() },
  series: props.series.map((s, i) => ({
    name: s.name,
    type: 'bar',
    stack: 'total',
    data: s.data,
    barMaxWidth: 28,
    itemStyle: {
      color: PALETTE[i % PALETTE.length],
      borderRadius: i === props.series.length - 1 ? [4, 4, 0, 0] : 0,
    },
    emphasis: { focus: 'series' },
  })),
}))
</script>

<template>
  <BaseChart :option="option" :height="height" :empty="!hasData" empty-text="暂无交叉分析数据" />
</template>
