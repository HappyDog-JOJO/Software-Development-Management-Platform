<script setup lang="ts">
/** 折线图：问题趋势（可叠加已处理/未处理） */
import { computed } from 'vue'
import type { EChartsOption } from 'echarts'
import BaseChart from './BaseChart.vue'
import { CHART_COLORS, axisStyle, tooltipStyle } from './chartTheme'

const props = withDefaults(
  defineProps<{
    dates: string[]
    series: Array<{ name: string; data: number[]; color?: string; area?: boolean }>
    height?: number
  }>(),
  { height: 300 },
)

const hasData = computed(() => props.dates.length > 0 && props.series.some((s) => s.data.some((v) => v > 0)))

const option = computed<EChartsOption>(() => ({
  grid: { left: 8, right: 16, top: 32, bottom: 8, containLabel: true },
  tooltip: { trigger: 'axis', ...tooltipStyle() },
  legend: {
    top: 0,
    right: 0,
    icon: 'circle',
    itemWidth: 8,
    itemHeight: 8,
    textStyle: { color: CHART_COLORS.axisText, fontSize: 12 },
  },
  xAxis: {
    type: 'category',
    boundaryGap: false,
    data: props.dates.map((d) => d.slice(5)),
    ...axisStyle(),
  },
  yAxis: { type: 'value', minInterval: 1, ...axisStyle() },
  series: props.series.map((s, i) => ({
    name: s.name,
    type: 'line',
    smooth: true,
    symbol: 'circle',
    symbolSize: 5,
    data: s.data,
    lineStyle: { width: 2, color: s.color ?? CHART_COLORS.primary },
    itemStyle: { color: s.color ?? CHART_COLORS.primary },
    areaStyle: s.area
      ? {
          color: {
            type: 'linear',
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
              { offset: 0, color: `${s.color ?? CHART_COLORS.primary}33` },
              { offset: 1, color: `${s.color ?? CHART_COLORS.primary}05` },
            ],
          },
        }
      : undefined,
    emphasis: { focus: 'series' },
  })),
}))
</script>

<template>
  <BaseChart :option="option" :height="height" :empty="!hasData" empty-text="暂无趋势数据" />
</template>
