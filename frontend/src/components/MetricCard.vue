<script setup lang="ts">
/**
 * 指标卡片：报表概览用。
 * 右上角 tooltip 说明口径（分母），数值用圆润字重强调。
 */
defineProps<{
  label: string
  value: string | number
  unit?: string
  tip?: string
  accent?: 'primary' | 'pink' | 'green' | 'yellow' | 'grey' | 'red' | 'value'
  hint?: string
}>()
</script>

<template>
  <div class="metric-card moon-card moon-card--moonlit moon-card--hoverable">
    <div class="metric-card__head">
      <span class="metric-card__label">{{ label }}</span>
      <el-tooltip v-if="tip" :content="tip" placement="top" effect="light" :show-after="150">
        <span class="metric-card__tip" aria-label="统计口径">?</span>
      </el-tooltip>
    </div>
    <div class="metric-card__value" :class="`is-${accent || 'primary'}`">
      <span class="num-strong">{{ value }}</span>
      <span v-if="unit" class="metric-card__unit">{{ unit }}</span>
    </div>
    <div v-if="hint" class="metric-card__hint">{{ hint }}</div>
  </div>
</template>

<style scoped>
.metric-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 16px;
  min-width: 0;
}

.metric-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.metric-card__label {
  font-size: 14px;
  color: var(--color-text-regular);
}

.metric-card__tip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: var(--radius-pill);
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 11px;
  cursor: help;
  flex: 0 0 auto;
}

.metric-card__value {
  display: flex;
  align-items: baseline;
  gap: 4px;
  font-size: 28px;
  line-height: 1.2;
}

.metric-card__value.is-primary {
  color: var(--color-primary);
}

.metric-card__value.is-pink {
  color: #d0698f;
}

.metric-card__value.is-green {
  color: #3d9d7a;
}

.metric-card__value.is-yellow {
  color: #c08430;
}

.metric-card__value.is-grey {
  color: var(--color-greyblue);
}

.metric-card__value.is-red {
  color: var(--color-danger);
}

/* 有价值占比属正向指标，用暖色但非告警色 */
.metric-card__value.is-value {
  color: #6b4b86;
}

.metric-card__unit {
  font-size: 14px;
  color: var(--color-text-secondary);
}

.metric-card__hint {
  font-size: 13px;
  color: var(--color-text-secondary);
}
</style>
