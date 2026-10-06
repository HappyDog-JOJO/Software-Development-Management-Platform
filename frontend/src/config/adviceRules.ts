/** 建议规则阈值（可配置）：与后端 reports.php 的实现保持一致 */

export const ADVICE_RULES = {
  /** 某模块问题占比 ≥ 20% */
  moduleRatio: 20,
  /** 某销售无价值问题占比 ≥ 50% 且提问数 ≥ 5 */
  worthlessRatio: 50,
  worthlessMinTotal: 5,
  /** 某部门人均提交量 ≥ 全局人均 × 2 */
  deptPerCapitaFactor: 2,
  /** 未完成（非「已处理」）占比 ≥ 30% */
  pendingRatio: 30,
  /** 有价值问题占比 < 20% */
  valuableRateMin: 20,
  /** 平均处理时长 > 48 小时 */
  avgHandleHoursMax: 48,
  /** 某处理人处理的问题数占全局 ≥ 40% */
  handlerRatio: 40,
}

/** 规则说明文案（报表页展示口径） */
export const ADVICE_RULE_LABELS = [
  `模块问题占比 ≥ ${ADVICE_RULES.moduleRatio}%：该模块做专项整改`,
  `销售无价值占比 ≥ ${ADVICE_RULES.worthlessRatio}% 且提问数 ≥ ${ADVICE_RULES.worthlessMinTotal}：开展提问规范培训`,
  `部门人均提交量 ≥ 全局人均 × ${ADVICE_RULES.deptPerCapitaFactor}：排查共性问题或流程缺陷`,
  `未完成占比 ≥ ${ADVICE_RULES.pendingRatio}%：评估售后人力与响应流程`,
  `有价值占比 < ${ADVICE_RULES.valuableRateMin}%：统一提问模板与引导`,
  `平均处理时长 > ${ADVICE_RULES.avgHandleHoursMax} 小时：设置超时提醒与分级响应`,
  `处理人处理的问题数占比 ≥ ${ADVICE_RULES.handlerRatio}%：分担与备份，避免单点`,
]
