<script setup lang="ts">
/** 横向排行柱状图（可复用）：用于六张排行榜 */
import { computed } from 'vue'
import type { EChartsOption } from 'echarts'
import BaseChart from './BaseChart.vue'
import { CHART_COLORS, axisStyle, tooltipStyle } from './chartTheme'
import type { RankItem } from '@/types'

const props = withDefaults(
  defineProps<{
    data: RankItem[]
    unit?: string
    color?: string
    height?: number
    valueLabel?: string
  }>(),
  { unit: '条', color: CHART_COLORS.primary, height: 300, valueLabel: '数量' },
)

const hasData = computed(() => props.data.length > 0)

const option = computed<EChartsOption>(() => {
  // 横向柱状图：ECharts y 轴从下往上，反转为「第一名在最上方」
  const rows = [...props.data].reverse()
  return {
    grid: { left: 96, right: 48, top: 12, bottom: 8, containLabel: false },
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      ...tooltipStyle(),
      formatter: (params: unknown) => {
        const arr = params as Array<{ name: string; value: number; dataIndex: number }>
        if (!arr.length) {
          return ''
        }
        const p = arr[0]
        const item = rows[p.dataIndex]
        const extra = item?.extra
        const split = extra
          ? `<div style="margin-top:4px;color:rgba(43,51,87,0.65);font-size:11px">其中：5 分 ${extra.very} · 4 分 ${extra.fairly}</div>`
          : ''
        return `<div style="font-weight:600">${p.name}</div><div style="margin-top:2px">${props.valueLabel}：${p.value} ${props.unit}</div>${split}`
      },
    },
    xAxis: {
      type: 'value',
      name: props.unit,
      nameTextStyle: { color: CHART_COLORS.axisText, fontSize: 11 },
      ...axisStyle(),
      minInterval: 1,
    },
    yAxis: {
      type: 'category',
      data: rows.map((r) => r.name),
      axisLine: { lineStyle: { color: CHART_COLORS.grid } },
      axisTick: { show: false },
      axisLabel: { color: CHART_COLORS.axisText, fontSize: 12, width: 88, overflow: 'truncate' },
    },
    series: [
      {
        type: 'bar',
        data: rows.map((r) => r.value),
        barWidth: 12,
        itemStyle: { color: props.color, borderRadius: [0, 6, 6, 0] },
        label: {
          show: true,
          position: 'right',
          color: 'rgba(43,51,87,0.65)',
          fontSize: 12,
          fontWeight: 600,
        },
      },
    ],
  }
})
</script>

<template>
  <BaseChart :option="option" :height="height" :empty="!hasData" empty-text="暂无排行数据" />
</template>
