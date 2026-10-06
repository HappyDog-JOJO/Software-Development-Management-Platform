/** 图表色板与通用配置：从主色派生，禁止 ECharts 默认刺眼配色 */

export const CHART_COLORS = {
  primary: '#7C8CE4',
  pink: '#F2A0C0',
  green: '#5FC9A0',
  yellow: '#F5B961',
  soft: '#A8B4F0',
  lighter: '#C9D2F7',
  grid: '#EAEEF9',
  axisText: 'rgba(43,51,87,0.65)',
  greyBlue: '#8B95B8',
}

/** 报表统一色板（顺序即取值优先级） */
export const PALETTE = [
  CHART_COLORS.primary,
  CHART_COLORS.pink,
  CHART_COLORS.green,
  CHART_COLORS.yellow,
  CHART_COLORS.soft,
  CHART_COLORS.lighter,
  CHART_COLORS.greyBlue,
]

/**
 * 问题价值固定配色（与 Tag 语义一致）。
 * 按分值分档取值，不写死字典名称：5 分/4 分/3 分/1-2 分/未评级。
 * 具体名称命中时优先用名称映射（键为字典名称）。
 */
export const VALUE_COLOR: Record<string, string> = {
  // 旧名称兼容
  很有价值: CHART_COLORS.pink,
  比较有价值: CHART_COLORS.primary,
  毫无价值: CHART_COLORS.greyBlue,
  // 新分值名称（1-5 分）
  '5分': CHART_COLORS.pink,
  '4分': CHART_COLORS.primary,
  '3分': CHART_COLORS.soft,
  '2分': CHART_COLORS.greyBlue,
  '1分': CHART_COLORS.lighter,
  未评级: CHART_COLORS.lighter,
}

/** 按价值名称取色：先查名称映射，再回退到按分值分档 */
export function valueColorOf(name: string | null | undefined): string {
  if (!name) {
    return CHART_COLORS.lighter
  }
  const key = name.trim()
  if (VALUE_COLOR[key]) {
    return VALUE_COLOR[key]
  }
  const m = key.match(/(\d+(?:\.\d+)?)/)
  const score = m ? Number(m[1]) : null
  if (score === null) {
    return CHART_COLORS.lighter
  }
  if (score >= 5) {
    return CHART_COLORS.pink
  }
  if (score >= 4) {
    return CHART_COLORS.primary
  }
  if (score >= 3) {
    return CHART_COLORS.soft
  }
  if (score >= 2) {
    return CHART_COLORS.greyBlue
  }
  return CHART_COLORS.lighter
}

/** 通用坐标轴样式 */
export function axisStyle() {
  return {
    axisLine: { lineStyle: { color: CHART_COLORS.grid } },
    axisTick: { show: false },
    axisLabel: { color: CHART_COLORS.axisText, fontSize: 12 },
    splitLine: { lineStyle: { color: CHART_COLORS.grid } },
  }
}

/** 通用提示框样式 */
export function tooltipStyle() {
  return {
    backgroundColor: '#FFFFFF',
    borderColor: CHART_COLORS.grid,
    borderWidth: 1,
    padding: [8, 12],
    textStyle: { color: 'rgba(43,51,87,0.88)', fontSize: 12 },
    extraCssText: 'border-radius:8px;box-shadow:0 4px 16px rgba(124,140,228,0.16);',
  }
}
