<script setup lang="ts">

/**
 * 输单分析页（销售分析 · 多角度分析丢单原因）。
 * 顶部按时间段筛选（快捷/自定义），全部图表与指标都在「筛选 + 可见范围」后的数据集上计算：
 * - 销售员：只统计本人登记的输单
 * - 销售经理 / 管理员：全量
 * 统计口径见每张卡片右上角「?」。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useDictStore } from '@/stores/dict'
import { useLossAnalysisStore } from '@/stores/salesloss'
import { REPORT_QUICK_RANGES } from '@/constants/issue'
import { quickRange } from '@/utils/format'
import MetricCard from '@/components/MetricCard.vue'
import PieChart from '@/components/charts/PieChart.vue'
import RankBarChart from '@/components/charts/RankBarChart.vue'
import TrendLineChart from '@/components/charts/TrendLineChart.vue'
import StackedBarChart from '@/components/charts/StackedBarChart.vue'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import { CHART_COLORS } from '@/components/charts/chartTheme'
import type { LossRankItem, LostOrderQuery, RankItem } from '@/types'

const auth = useAuthStore()
const dict = useDictStore()
const store = useLossAnalysisStore()

const quickRangeValue = ref<'today' | 'week' | 'days30' | 'month' | 'custom'>('days30')

const filters = reactive<LostOrderQuery>({
  dateStart: '',
  dateEnd: '',
  lossTypeId: undefined,
  lossStageId: undefined,
})

const selfScoped = computed(() => !auth.isManager && !auth.isAdmin)

/** 单量 / 金额 切换：同一批分类数据同时带 count 与报价金额 */
const rankMetric = ref<'count' | 'quote'>('count')

function toRankItems(list: LossRankItem[] | undefined, limit = 10): RankItem[] {
  if (!list || !list.length) {
    return []
  }
  const rows = rankMetric.value === 'quote'
    ? [...list].sort((a, b) => b.quoteTotal - a.quoteTotal)
    : [...list]
  return rows.slice(0, limit).map((it) => ({
    name: rankMetric.value === 'quote' ? `${it.name}（${formatMoney(it.quoteTotal)}）` : it.name,
    value: rankMetric.value === 'quote' ? Math.round(it.quoteTotal) : it.value,
    extra: null,
  }))
}

function formatMoney(v: number): string {
  return v.toLocaleString('zh-CN', { maximumFractionDigits: 0 })
}

const overview = computed(() => store.overview)

const lossTypeData = computed(() => toRankItems(store.dims?.lossTypes, 12))
const stageData = computed(() => toRankItems(store.dims?.lossStages, 12))
const industryData = computed(() => toRankItems(store.dims?.industries, 10))
const scaleData = computed(() => (store.dims?.companyScales ?? []).map((it) => ({ name: it.name, value: it.value })))
const budgetData = computed(() => (store.dims?.budgetMatches ?? []).map((it) => ({ name: it.name, value: it.value })))
const remoteDemoData = computed(() => store.dims?.remoteDemoDist ?? [])
const customizedData = computed(() => store.dims?.customizedDist ?? [])

/** 丢单阶段 × 输单类型 堆叠柱状图 */
const crossChart = computed(() => {
  const cross = store.cross
  if (!cross) {
    return { categories: [] as string[], series: [] as Array<{ name: string; data: number[] }> }
  }
  return {
    categories: cross.lossStages,
    series: cross.lossTypes.map((type) => ({
      name: type,
      data: cross.lossStages.map((stage) => {
        const hit = cross.matrix.find((m) => m.lossStage === stage && m.lossType === type)
        return hit ? hit.value : 0
      }),
    })),
  }
})

/** 是否远程演示 × 输单类型 堆叠柱状图 */
const demoChart = computed(() => {
  const cross = store.cross
  if (!cross) {
    return { categories: [] as string[], series: [] as Array<{ name: string; data: number[] }> }
  }
  const demos = ['远程演示', '未远程演示']
  return {
    categories: cross.lossTypes,
    series: demos.map((demo) => ({
      name: demo,
      data: cross.lossTypes.map((type) => {
        const hit = cross.demoMatrix.find((m) => m.lossType === type && m.demo === demo)
        return hit ? hit.value : 0
      }),
    })),
  }
})

const trendDates = computed(() => store.cross?.trend.map((t) => t.date) ?? [])
const trendCount = computed(() => [
  { name: '输单条数', data: store.cross?.trend.map((t) => t.total) ?? [], color: CHART_COLORS.primary, area: true },
])
const trendQuote = computed(() => [
  { name: '最终报价（元）', data: store.cross?.trend.map((t) => Math.round(t.quoteTotal)) ?? [], color: CHART_COLORS.pink },
])

const salesmanRank = computed(() => toRankItems(store.ranks?.salesmen, 20))
const deptRank = computed(() => toRankItems(store.ranks?.departments, 12))
const customerRank = computed(() => toRankItems(store.ranks?.customers, 10))

const hasAnyData = computed(() => (overview.value?.total ?? 0) > 0)

async function load(): Promise<void> {
  if (!filters.dateStart || !filters.dateEnd) {
    const r = quickRange('days30')
    filters.dateStart = r.dateStart
    filters.dateEnd = r.dateEnd
  }
  store.setFilters({ ...filters })
  await store.loadAll()
}

function onQuickRange(v: string | number | boolean | undefined): void {
  const val = String(v) as 'today' | 'week' | 'days30' | 'month' | 'custom'
  quickRangeValue.value = val
  if (val === 'custom') {
    return
  }
  const r = quickRange(val as 'today' | 'week' | 'month' | 'days30')
  filters.dateStart = r.dateStart
  filters.dateEnd = r.dateEnd
  void load()
}

function onReset(): void {
  filters.lossTypeId = undefined
  filters.lossStageId = undefined
  quickRangeValue.value = 'days30'
  const r = quickRange('days30')
  filters.dateStart = r.dateStart
  filters.dateEnd = r.dateEnd
  void load()
}

onMounted(async () => {
  await dict.load()
  await load()
})
</script>

<template>
  <div class="page">
    <!-- 顶部筛选：时间段 + 两个主维度 -->
    <div class="moon-card moon-card--tight filter-panel">
      <div class="filter-inline">
        <div class="filter-inline__item">
          <span class="filter-inline__label">时间</span>
          <el-radio-group :model-value="quickRangeValue" @change="onQuickRange">
            <el-radio-button v-for="r in REPORT_QUICK_RANGES" :key="r.value" :value="r.value">{{ r.label }}</el-radio-button>
          </el-radio-group>
        </div>
        <div class="filter-inline__item">
          <el-date-picker
            v-model="filters.dateStart"
            type="date"
            placeholder="开始日期"
            value-format="YYYY-MM-DD"
            style="width: 148px"
            @change="load"
          />
          <span class="filter-inline__label">至</span>
          <el-date-picker
            v-model="filters.dateEnd"
            type="date"
            placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 148px"
            @change="load"
          />
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">输单类型</span>
          <el-select v-model="filters.lossTypeId" placeholder="全部类型" clearable style="width: 148px" @change="load">
            <el-option v-for="it in dict.allOf('loss_type')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">丢单阶段</span>
          <el-select v-model="filters.lossStageId" placeholder="全部阶段" clearable style="width: 140px" @change="load">
            <el-option v-for="it in dict.allOf('loss_stage')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">看板</span>
          <el-radio-group v-model="rankMetric">
            <el-radio-button value="count">按单量</el-radio-button>
            <el-radio-button value="quote">按金额</el-radio-button>
          </el-radio-group>
        </div>
        <div class="filter-inline__item filter-inline__actions">
          <el-button @click="onReset">重置</el-button>
          <el-button type="primary" @click="load">分析</el-button>
        </div>
      </div>
      <div v-if="selfScoped" class="scope-notice scope-notice--info">
        <span aria-hidden="true">i</span>
        当前为销售员视角：只统计本人登记的输单。
      </div>
    </div>

    <!-- 概览 -->
    <div class="metric-grid">
      <MetricCard label="输单总数" :value="overview?.total ?? 0" unit="条" accent="primary" tip="口径：该时间段内「丢单时间」落在区间里的输单条数（分母）。" />
      <MetricCard
        label="报价总额"
        :value="formatMoney(overview?.quoteTotal ?? 0)"
        unit="元"
        accent="pink"
        tip="口径：所有输单的最终报价合计（元）；未填写报价按 0 计。"
      />
      <MetricCard
        label="平均报价"
        :value="formatMoney(overview?.quoteAvg ?? 0)"
        unit="元"
        accent="yellow"
        tip="口径：已填写报价（> 0）的输单平均报价（元）；未填写的不计入分母。"
        :hint="`已填报价 ${overview?.quoteCount ?? 0} 条`"
      />
      <MetricCard
        label="远程演示占比"
        :value="overview?.remoteDemoRate ?? 0"
        unit="%"
        accent="green"
        tip="口径：远程演示过的输单条数 ÷ 输单总数。"
        :hint="`演示过 ${overview?.remoteDemo ?? 0} 条`"
      />
      <MetricCard
        label="定制化占比"
        :value="overview?.customizedRate ?? 0"
        unit="%"
        accent="value"
        tip="口径：涉及定制化的输单条数 ÷ 输单总数。"
        :hint="`涉及 ${overview?.customized ?? 0} 条`"
      />
      <MetricCard
        label="涉及客户"
        :value="overview?.customerCount ?? 0"
        unit="家"
        accent="grey"
        tip="口径：去重后的客户名称数量（同名客户按一条计）。"
        :hint="`业务员 ${overview?.salesmanCount ?? 0} 人`"
      />
    </div>

    <div v-if="!hasAnyData" class="moon-card empty-block">
      <EmptyIllustration :size="150" text="该时间段还没有输单数据" sub-text="换一个时间段，或先去「输单登记」录入几条" />
    </div>

    <template v-else>
      <!-- 第一屏：原因分布（类型 / 阶段 / 行业） -->
      <div class="chart-grid">
        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">输单类型分布</span>
            <el-tooltip content="口径：按「输单类型」字典分组统计条数，反映丢单的主要原因分布。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <PieChart v-if="lossTypeData.length" :data="lossTypeData.map((it) => ({ name: it.name, value: it.value }))" :height="300" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>

        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">丢单阶段分布</span>
            <el-tooltip content="口径：按「丢单阶段」分组统计条数，反映单子是在哪个环节丢的。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <RankBarChart v-if="stageData.length" :data="stageData" unit="条" :height="300" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>

        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">所属行业 Top</span>
            <el-tooltip content="口径：按「所属行业」分组统计条数，降序展示。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <RankBarChart v-if="industryData.length" :data="industryData" unit="条" :height="300" :color="CHART_COLORS.pink" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>
      </div>

      <!-- 第二屏：客户画像（规模 / 预算匹配 / 演示与定制） -->
      <div class="chart-grid">
        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">企业规模分布</span>
            <el-tooltip content="口径：按「企业规模」分组统计条数，用于判断大客户流失情况。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <PieChart v-if="scaleData.length" :data="scaleData" :height="300" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>

        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">预算匹配分布</span>
            <el-tooltip content="口径：按「预算匹配」分组统计条数，反映报价与预算的错位程度。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <PieChart v-if="budgetData.length" :data="budgetData" :height="300" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>

        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">远程演示 / 定制化</span>
            <el-tooltip content="口径：远程演示与定制化的条数占比，演示过仍丢单说明问题不在产品展示环节。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <div class="dual-pie">
            <PieChart v-if="remoteDemoData.length" :data="remoteDemoData" :height="150" :donut="true" />
            <PieChart v-if="customizedData.length" :data="customizedData" :height="150" :donut="true" />
          </div>
          <div class="dual-pie__legend">
            <span>左：是否远程演示</span>
            <span>右：是否涉及定制化</span>
          </div>
        </div>
      </div>

      <!-- 第三屏：交叉分析 -->
      <div class="chart-grid chart-grid--wide">
        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">丢单阶段 × 输单类型</span>
            <el-tooltip content="口径：丢单阶段 × 输单类型 交叉计数，用于定位「某环节最常因何丢单」。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <StackedBarChart
            v-if="crossChart.categories.length"
            :categories="crossChart.categories"
            :series="crossChart.series"
            :height="340"
            y-name="条"
          />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>

        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">是否远程演示 × 输单类型</span>
            <el-tooltip
              content="口径：同一输单类型下，远程演示过 / 未演示过的条数对比（演示过仍丢单说明问题不在产品展示）。"
              placement="top"
              effect="light"
            >
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <StackedBarChart
            v-if="demoChart.categories.length"
            :categories="demoChart.categories"
            :series="demoChart.series"
            :height="340"
            y-name="条"
          />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>
      </div>

      <!-- 第四屏：趋势 -->
      <div class="chart-grid chart-grid--wide">
        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">输单趋势（条数）</span>
            <el-tooltip content="口径：按「丢单日期」聚合的输单条数趋势。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <TrendLineChart v-if="trendDates.length" :dates="trendDates" :series="trendCount" :height="280" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>

        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">丢单金额趋势（元）</span>
            <el-tooltip content="口径：按「丢单日期」聚合的最终报价金额趋势，反映金额层面的损失节奏。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <TrendLineChart v-if="trendDates.length" :dates="trendDates" :series="trendQuote" :height="280" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>
      </div>

      <!-- 第五屏：排行 -->
      <div class="chart-grid">
        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">业务员输单排行</span>
            <el-tooltip content="口径：按业务员分组统计输单条数，降序取前 20（销售员仅统计本人）。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <RankBarChart v-if="salesmanRank.length" :data="salesmanRank" unit="条" :height="320" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>

        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">部门输单排行</span>
            <el-tooltip content="口径：按登记时所属部门分组统计输单条数（部门变更不回溯历史）。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <RankBarChart v-if="deptRank.length" :data="deptRank" unit="条" :height="320" :color="CHART_COLORS.yellow" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>

        <div class="moon-card moon-card--moonlit chart-card">
          <div class="chart-card__head">
            <span class="chart-card__title">丢单客户 Top10</span>
            <el-tooltip content="口径：按客户名称分组统计输单条数，降序取前 10（多次丢单的客户需重点复盘）。" placement="top" effect="light">
              <span class="chart-card__tip">?</span>
            </el-tooltip>
          </div>
          <RankBarChart v-if="customerRank.length" :data="customerRank" unit="条" :height="320" :color="CHART_COLORS.green" />
          <EmptyIllustration v-else :size="100" text="暂无数据" />
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.filter-panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.filter-inline {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
}

.filter-inline__item {
  display: flex;
  align-items: center;
  gap: 6px;
}

.filter-inline__label {
  font-size: 13px;
  color: var(--color-text-secondary);
  white-space: nowrap;
}

.filter-inline__actions {
  margin-left: auto;
}

.metric-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--space-4);
}

.chart-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
  gap: var(--space-4);
}

.chart-grid--wide {
  grid-template-columns: repeat(auto-fit, minmax(460px, 1fr));
}

.chart-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
  min-width: 0;
}

.chart-card__head {
  display: flex;
  align-items: center;
  gap: 6px;
}

.chart-card__title {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.chart-card__tip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 15px;
  height: 15px;
  border-radius: 50%;
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 11px;
  cursor: help;
}

.dual-pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.dual-pie__legend {
  display: flex;
  justify-content: space-around;
  font-size: 12px;
  color: var(--color-text-secondary);
}

.empty-block {
  display: flex;
  justify-content: center;
  padding: 32px 0;
}
</style>
