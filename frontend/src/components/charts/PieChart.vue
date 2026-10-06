<script setup lang="ts">
/** 饼图：用于问题价值分布（未评级单独一色） */
import { computed } from 'vue'
import type { EChartsOption } from 'echarts'
import BaseChart from './BaseChart.vue'
import { CHART_COLORS, valueColorOf, tooltipStyle } from './chartTheme'

const props = withDefaults(
  defineProps<{
    data: Array<{ name: string; value: number }>
    height?: number
    donut?: boolean
  }>(),
  { height: 300, donut: true },
)

const hasData = computed(() => props.data.some((d) => d.value > 0))

const option = computed<EChartsOption>(() => ({
  tooltip: { trigger: 'item', ...tooltipStyle(), formatter: '{b}：{c} 条（{d}%）' },
  legend: {
    bottom: 0,
    icon: 'circle',
    itemWidth: 8,
    itemHeight: 8,
    textStyle: { color: CHART_COLORS.axisText, fontSize: 12 },
  },
  // 价值名称可能为「1分~5分」：内部会按名称或分值分档取色
  color: props.data.map((d) => valueColorOf(d.name)),
  series: [
    {
      type: 'pie',
      radius: props.donut ? ['46%', '68%'] : '68%',
      center: ['50%', '46%'],
      avoidLabelOverlap: true,
      itemStyle: { borderColor: '#FFFFFF', borderWidth: 2, borderRadius: 4 },
      label: { color: CHART_COLORS.axisText, fontSize: 12, formatter: '{b} {d}%' },
      labelLine: { lineStyle: { color: CHART_COLORS.grid } },
      data: props.data,
    },
  ],
}))
</script>

<template>
  <BaseChart :option="option" :height="height" :empty="!hasData" empty-text="暂无价值分布数据" />
</template>
