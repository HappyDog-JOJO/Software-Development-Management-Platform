<script setup lang="ts">

/**
 * 报表统计页（售后服务人员 / 销售经理 / 管理员）。
 * 四屏：概览卡片 → 六张排行榜 → 多维度分析 → 行动建议。
 * 每张卡片右上角 tooltip 说明统计口径；销售经理范围锁定本部门。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import { useDeptStore } from '@/stores/user'
import { useDictStore } from '@/stores/dict'
import { useReportStore } from '@/stores/report'
import { setTraining, fetchLearning } from '@/api/report'
import { canHandle } from '@/constants/user'
import { REPORT_QUICK_RANGES } from '@/constants/issue'
import { ADVICE_RULE_LABELS } from '@/config/adviceRules'
import { METRIC_TIPS } from '@/config/metricTips'
import { quickRange, fmtDateTime } from '@/utils/format'
import MetricCard from '@/components/MetricCard.vue'
import RankBarChart from '@/components/charts/RankBarChart.vue'
import PieChart from '@/components/charts/PieChart.vue'
import ValueTag from '@/components/ValueTag.vue'
import TrendLineChart from '@/components/charts/TrendLineChart.vue'
import StackedBarChart from '@/components/charts/StackedBarChart.vue'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import { CHART_COLORS } from '@/components/charts/chartTheme'
import type { RankItem, ReportQuery } from '@/types'
import { useColumnWidths } from '@/composables/useColumnWidths'

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('reports-profile')

const auth = useAuthStore()
const dict = useDictStore()
const deptStore = useDeptStore()
const store = useReportStore()
const router = useRouter()

const quickRangeValue = ref<'today' | 'week' | 'days30' | 'month' | 'custom'>('days30')

const filters = reactive<ReportQuery>({
  dateStart: '',
  dateEnd: '',
  departmentId: undefined,
  moduleId: undefined,
})

const deptLocked = computed(() => auth.isManager)
const lockedDeptName = computed(() => auth.user?.departmentName || '本部门')

/** 可标记培训状态（售后服务人员 / 管理员） */
const canTrain = computed(() => canHandle(auth.roles))

/** 标记 / 取消「已培训」：显式选择 已培训 / 未培训（按单选值提交，幂等） */
async function markTraining(it: { id: number }, status: number): Promise<void> {
  try {
    await setTraining(it.id, status)
    ElMessage.success(status === 1 ? '已标记为已培训' : '已标记为未培训')
    await store.loadAll()
  } catch {
    // 失败由请求层统一提示
  }
}

const overview = computed(() => store.overview)

const emptyRanks: RankItem[] = []

function ranksOf(key: keyof NonNullable<typeof store.ranks>): RankItem[] {
  return store.ranks ? store.ranks[key] : emptyRanks
}

/** 交叉矩阵 → 按模块堆叠的部门柱状图数据 */
const crossChartData = computed(() => {
  const cross = store.cross
  if (!cross) {
    return { categories: [] as string[], series: [] as Array<{ name: string; data: number[] }> }
  }
  const categories = cross.departments
  const series = cross.modules.map((mod) => ({
    name: mod,
    data: categories.map((dept) => {
      const hit = cross.matrix.find((m) => m.department === dept && m.module === mod)
      return hit ? hit.value : 0
    }),
  }))
  return { categories, series }
})

const trendDates = computed(() => store.cross?.trend.map((t) => t.date) ?? [])
const trendSeries = computed(() => [
  { name: '已处理', data: store.cross?.trend.map((t) => t.handled) ?? [], color: CHART_COLORS.green },
  { name: '未完成（含待处理/处理中/未处理/已驳回）', data: store.cross?.trend.map((t) => t.unhandled) ?? [], color: CHART_COLORS.yellow },
  { name: '提交总量', data: store.cross?.trend.map((t) => t.total) ?? [], color: CHART_COLORS.primary, area: true },
])

async function load(): Promise<void> {
  if (!filters.dateStart || !filters.dateEnd) {
    const r = quickRange('days30')
    filters.dateStart = r.dateStart
    filters.dateEnd = r.dateEnd
  }
  store.setFilters({
    ...filters,
    // 销售经理范围由服务端按角色限制，不下发部门参数
    departmentId: deptLocked.value ? undefined : filters.departmentId,
  })
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
  filters.moduleId = undefined
  filters.departmentId = undefined
  quickRangeValue.value = 'days30'
  const r = quickRange('days30')
  filters.dateStart = r.dateStart
  filters.dateEnd = r.dateEnd
  void load()
}

/** 排行榜下钻：跳到问题列表并带上筛选 */
function drillIssues(params: Record<string, string | number>): void {
  void router.push({ path: '/issues', query: params })
}

/** 培训状态筛选变化：仅重新拉取学习榜 */
async function onLearningFilterChange(): Promise<void> {
  try {
    const ln = await fetchLearning(store.learningFilter === 'untrained'
      ? { trainingStatus: '0' }
      : store.learningFilter === 'trained'
        ? { trainingStatus: '1' }
        : {})
    store.learning = ln.items
    store.learningTotal = ln.total
    store.learningTrained = ln.trained
    store.learningUntrained = ln.untrained
    store.learningAll = ln.all
  } catch {
    // 失败由请求层统一提示
  }
}

/** 直接打开问题详情 */
function openIssue(id: number): void {
  void router.push(`/issues/${id}`)
}

function onAdviceJump(item: { type: string; params: Record<string, string | number> }): void {
  if (item.type === 'issues') {
    drillIssues(item.params)
    return
  }
  ElMessage.info('该建议已在当前报表范围内，可通过上方筛选进一步查看')
}

onMounted(async () => {
  await Promise.all([dict.load(), deptStore.load()])
  await load()
})
</script>

<template>
  <div class="page">
    <!-- 筛选 -->
    <div class="moon-card moon-card--tight">
      <div class="filter-bar">
        <div class="filter-bar__item">
          <span class="filter-bar__label">时间范围</span>
          <el-radio-group :model-value="quickRangeValue" @change="onQuickRange">
            <el-radio-button v-for="r in REPORT_QUICK_RANGES" :key="r.value" :value="r.value">{{ r.label }}</el-radio-button>
          </el-radio-group>
        </div>
        <div class="filter-bar__item">
          <el-date-picker
            v-model="filters.dateStart"
            type="date"
            placeholder="开始日期"
            value-format="YYYY-MM-DD"
            style="width: 171px"
            @change="load"
          />
          <span class="filter-bar__label">至</span>
          <el-date-picker
            v-model="filters.dateEnd"
            type="date"
            placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 171px"
            @change="load"
          />
        </div>
        <div class="filter-bar__item">
          <span class="filter-bar__label">部门</span>
          <el-select
            v-model="filters.departmentId"
            :placeholder="deptLocked ? lockedDeptName : '全部部门'"
            :disabled="deptLocked"
            clearable
            style="width: 183px"
            @change="load"
          >
            <el-option v-for="d in deptStore.list" :key="d.id" :label="d.name" :value="d.id" />
          </el-select>
        </div>
        <div class="filter-bar__item">
          <span class="filter-bar__label">所属模块</span>
          <el-select v-model="filters.moduleId" placeholder="全部模块" clearable style="width: 171px" @change="load">
            <el-option v-for="it in dict.allOf('module')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-bar__item filter-bar__item--actions">
          <el-button @click="onReset">重置</el-button>
          <el-button type="primary" @click="load">查询</el-button>
        </div>
      </div>
    </div>

    <!-- 第 1 屏：概览卡片 -->
    <div v-if="overview" class="metric-grid">
      <MetricCard label="问题总数" :value="overview.total" unit="条" :tip="METRIC_TIPS.total" accent="primary" />
      <MetricCard label="已处理" :value="overview.handled" unit="条" :tip="METRIC_TIPS.handled" accent="green" />
      <MetricCard label="处理中" :value="overview.processing" unit="条" :tip="METRIC_TIPS.processing" accent="yellow" />
      <MetricCard label="待处理" :value="overview.pending" unit="条" :tip="METRIC_TIPS.pending" accent="primary" />
      <MetricCard
        label="有价值问题占比"
        :value="overview.valuableRate"
        unit="%"
        :tip="METRIC_TIPS.valuableRate"
        accent="value"
        :hint="`5 分 ${overview.veryValuable} · 4 分 ${overview.fairlyValuable} · 已评级 ${overview.rated}`"
      />
      <MetricCard
        label="平均处理时长"
        :value="overview.avgHandleHours"
        unit="小时"
        :tip="METRIC_TIPS.avgHandleHours"
        accent="primary"
      />
    </div>

    <!-- 第 2 屏：六张排行榜 -->
    <div class="rank-grid">
      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            提问问题排行
            <el-tooltip :content="METRIC_TIPS.ask" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
          <el-button link type="primary" @click="drillIssues({})">查看明细</el-button>
        </div>
        <RankBarChart :data="ranksOf('ask')" value-label="提问数" :height="280" />
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            有价值问题排行
            <el-tooltip :content="METRIC_TIPS.valuable" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
          <el-button link type="primary" @click="drillIssues({})">查看明细</el-button>
        </div>
        <RankBarChart :data="ranksOf('valuable')" value-label="有价值数" :color="CHART_COLORS.pink" :height="280" />
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            无价值问题排行
            <el-tooltip :content="METRIC_TIPS.worthless" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
          <el-button link type="primary" @click="drillIssues({})">查看明细</el-button>
        </div>
        <RankBarChart :data="ranksOf('worthless')" value-label="无价值数" :color="CHART_COLORS.greyBlue" :height="280" />
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            模块问题排行
            <el-tooltip :content="METRIC_TIPS.module" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
          <el-button link type="primary" @click="drillIssues({})">查看明细</el-button>
        </div>
        <RankBarChart :data="ranksOf('module')" value-label="问题数" :height="280" />
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            部门提问题排行
            <el-tooltip :content="METRIC_TIPS.department" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
          <el-button link type="primary" @click="drillIssues({})">查看明细</el-button>
        </div>
        <RankBarChart :data="ranksOf('department')" value-label="问题数" :height="280" />
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            处理量排行
            <el-tooltip :content="METRIC_TIPS.handle" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
          <el-button link type="primary" @click="drillIssues({})">查看明细</el-button>
        </div>
        <RankBarChart :data="ranksOf('handle')" value-label="负责问题数" :color="CHART_COLORS.green" :height="280" />
      </div>
    </div>

    <!-- 第 3 屏：多维度分析 -->
    <div class="analysis-grid">
      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            问题趋势
            <el-tooltip :content="METRIC_TIPS.trend" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
        </div>
        <TrendLineChart :dates="trendDates" :series="trendSeries" :height="300" />
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            问题价值分布
            <el-tooltip :content="METRIC_TIPS.valueDist" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
        </div>
        <PieChart :data="store.cross?.valueDist ?? []" :height="300" />
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            部门 × 模块 交叉分析
            <el-tooltip :content="METRIC_TIPS.cross" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
        </div>
        <StackedBarChart :categories="crossChartData.categories" :series="crossChartData.series" :height="320" />
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="card-head">
          <div class="section-title">
            人员画像（提问数 / 有价值占比 / 无价值数）
            <el-tooltip :content="METRIC_TIPS.profile" placement="top" effect="light">
              <span class="tip-dot">?</span>
            </el-tooltip>
          </div>
        </div>
        <div v-if="store.cross?.profile.length" class="moon-table profile-table">
          <el-table
        @header-dragend="colw.onDragend" border :data="store.cross.profile" max-height="320">
            <el-table-column prop="name" label="销售员" :width="colw.bind('name', { width: 100 }).width" />
            <el-table-column prop="total" label="提问数" :width="colw.bind('total', { width: 84 }).width" align="right">
              <template #default="{ row }"><span class="num-strong">{{ row.total }}</span></template>
            </el-table-column>
            <el-table-column prop="valuable" label="有价值数" :width="colw.bind('valuable', { width: 94 }).width" align="right" />
            <el-table-column prop="worthless" label="无价值数" :width="colw.bind('worthless', { width: 94 }).width" align="right" />
            <el-table-column label="有价值占比" :min-width="colw.bind('有价值占比', { minWidth: 120 }).minWidth">
              <template #default="{ row }">
                <div class="profile-bar">
                  <div class="profile-bar__track">
                    <div class="profile-bar__fill" :style="{ width: `${Math.min(100, row.valuableRate)}%` }" />
                  </div>
                  <span class="num-strong">{{ row.valuableRate }}%</span>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </div>
        <EmptyIllustration v-else :size="120" text="暂无人员画像数据" />
      </div>
    </div>

    <!-- 第 4 屏：值得培训学习的问题（双轨：共性误区 + 优质范例） -->
    <div class="moon-card">
      <div class="card-head">
        <div class="section-title">
          值得培训学习的问题
          <el-tooltip :content="METRIC_TIPS.learning" placement="top" effect="light">
            <span class="tip-dot">?</span>
          </el-tooltip>
        </div>
        <el-radio-group v-model="store.learningFilter" size="small" @change="onLearningFilterChange">
          <el-radio-button value="untrained">未培训（{{ store.learningUntrained }}）</el-radio-button>
          <el-radio-button value="trained">已培训（{{ store.learningTrained }}）</el-radio-button>
          <el-radio-button value="all">全部（{{ store.learningAll }}）</el-radio-button>
        </el-radio-group>
      </div>

      <!-- 优质范例（高价值问题） -->
      <div class="learning-track">
        <div class="learning-track__head">
          <span class="learning-track__badge is-example">正面范例</span>
          <span class="learning-track__title">优质提问与处理范例</span>
          <span class="learning-track__desc">4-5 分的高价值问题（不含 BUG 类型），可标记已培训 / 未培训</span>
          <span v-if="store.learningTotal" class="section-title__hint">
            共 {{ store.learningTotal }} 条，展示前 20 条
          </span>
        </div>

        <EmptyIllustration
          v-if="!store.learning.length"
          :size="130"
          text="暂无优质范例"
          sub-text="评为 4-5 分的高价值问题会自动出现在这里"
        />
        <div v-else class="learning-list">
        <div v-for="it in store.learning" :key="it.id" class="learning-item">
          <div class="learning-item__head">
            <span class="learning-item__no">{{ it.issueNo }}</span>
            <ValueTag :value-name="it.valueName" />
            <span class="learning-item__meta">
              {{ it.submitterName }}
              <template v-if="it.submitterDepartmentName">（{{ it.submitterDepartmentName }}）</template>
              <template v-if="it.moduleName"> · {{ it.moduleName }}</template>
            </span>
            <span class="learning-item__spacer" />
            <span class="learning-train">
              <span class="learning-train__label">培训状态</span>
              <el-radio-group
                v-if="canTrain"
                :model-value="it.trainingStatus"
                size="small"
                @change="(v: string | number | boolean | undefined) => markTraining(it, Number(v))"
              >
                <el-radio-button :value="1">已培训</el-radio-button>
                <el-radio-button :value="0">未培训</el-radio-button>
              </el-radio-group>
              <span v-else class="pill" :class="it.trainingStatus === 1 ? 'pill--green' : 'pill--grey'">
                {{ it.trainingStatus === 1 ? '已培训' : '未培训' }}
              </span>
            </span>
            <el-button link type="primary" @click="openIssue(it.id)">查看详情</el-button>
          </div>

          <div class="learning-item__row">
            <span class="learning-item__label">问题描述</span>
            <span class="learning-item__text">{{ it.description }}</span>
          </div>
          <div class="learning-item__row">
            <span class="learning-item__label">处理备注</span>
            <span v-if="it.handleRemark" class="learning-item__text">{{ it.handleRemark }}</span>
            <span v-else class="text-placeholder">暂无处理备注</span>
          </div>
          <div class="learning-item__foot">
            <span v-if="it.handlerName">处理人：{{ it.handlerName }}</span>
            <span v-if="it.handledAt">处理时间：{{ fmtDateTime(it.handledAt) }}</span>
            <span>提交时间：{{ fmtDateTime(it.createdAt) }}</span>
          </div>
        </div>
        </div>
      </div>
    </div>

    <!-- 第 5 屏：行动建议 -->
    <div class="moon-card">
      <div class="section-title">
        行动建议
        <span class="section-title__hint">由规则引擎按阈值生成，命中即输出「结论 + 依据数据 + 建议动作」</span>
      </div>

      <EmptyIllustration
        v-if="!store.hasAdviceData"
        :size="130"
        text="暂无数据"
        sub-text="当前筛选范围内没有可分析的问题记录"
      />
      <EmptyIllustration
        v-else-if="!store.advice.length"
        :size="130"
        text="当前数据未发现明显异常"
        sub-text="各维度指标均未触发阈值规则，继续保持即可"
      />
      <div v-else class="advice-list">
        <div v-for="a in store.advice" :key="a.id" class="advice-item" :class="`is-${a.level}`">
          <div class="advice-item__head">
            <span class="advice-item__title">{{ a.title }}</span>
            <el-button link type="primary" @click="onAdviceJump(a.link)">查看关联数据</el-button>
          </div>
          <div class="advice-item__row"><span class="advice-item__label">结论</span>{{ a.conclusion }}</div>
          <div class="advice-item__row"><span class="advice-item__label">依据数据</span>{{ a.evidence }}</div>
          <div class="advice-item__row"><span class="advice-item__label">建议动作</span>{{ a.action }}</div>
        </div>
      </div>

      <el-collapse class="rule-collapse">
        <el-collapse-item name="rules">
          <template #title><span class="rule-collapse__title">规则阈值说明（可配置）</span></template>
          <ul class="rule-list">
            <li v-for="r in ADVICE_RULE_LABELS" :key="r">{{ r }}</li>
          </ul>
        </el-collapse-item>
      </el-collapse>
    </div>
  </div>
</template>

<style scoped>
/* 页头已移除，操作按钮独占一行并收紧顶部间距 */
.metric-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 16px;
}

.rank-grid,
.analysis-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px;
}

.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.card-head .section-title {
  margin-bottom: 8px;
}

.tip-dot {
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
}

/* 人员画像进度条 */
.profile-bar {
  display: flex;
  align-items: center;
  gap: 8px;
}

.profile-bar__track {
  flex: 1;
  height: 6px;
  border-radius: var(--radius-pill);
  background: var(--color-bg-sub);
  overflow: hidden;
}

.profile-bar__fill {
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--color-primary-soft);
}

/* —— 培训：轨道标题 —— */
.learning-track {
  margin-bottom: var(--space-5);
}

.learning-track:last-child {
  margin-bottom: 0;
}

.learning-track__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--color-border-divider);
}

.learning-track__badge {
  padding: 2px 9px;
  border-radius: var(--radius-pill);
  font-size: 11.5px;
  font-weight: 600;
  flex: 0 0 auto;
}

.learning-track__badge.is-example {
  background: var(--color-success-tint);
  color: #2c7d5e;
}

.learning-track__title {
  font-size: 14.5px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.learning-track__desc {
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

/* 值得培训学习的问题 */
.learning-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.learning-item {
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  border: 1px solid var(--color-border-divider);
}

.learning-item__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}

.learning-item__no {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--color-text-primary);
  font-variant-numeric: tabular-nums;
}

.learning-item__meta {
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

.learning-item__spacer {
  flex: 1;
}

/* 培训状态选择器 */
.learning-train {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.learning-train__label {
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

.learning-item__row {
  display: flex;
  gap: 8px;
  margin-top: 4px;
  font-size: 13px;
  line-height: 1.7;
}

.learning-item__label {
  flex: 0 0 auto;
  width: 60px;
  color: var(--color-text-secondary);
}

.learning-item__text {
  flex: 1;
  min-width: 0;
  color: var(--color-text-regular);
  white-space: pre-wrap;
  word-break: break-word;
}

.learning-item__foot {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--color-border-divider);
  font-size: 12px;
  color: var(--color-text-placeholder);
  font-variant-numeric: tabular-nums;
}

/* 行动建议 */
.advice-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.advice-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 14px 16px;
  border-radius: var(--radius-card);
  background: var(--color-bg-sub);
  border-left: 3px solid var(--color-primary);
}

.advice-item.is-warning {
  border-left-color: var(--color-warning);
}

.advice-item.is-error {
  border-left-color: var(--color-danger);
}

.advice-item.is-info {
  border-left-color: var(--color-primary);
}

.advice-item__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.advice-item__title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.advice-item__row {
  font-size: 13px;
  line-height: 1.7;
  color: var(--color-text-regular);
}

.advice-item__label {
  display: inline-block;
  min-width: 56px;
  color: var(--color-text-secondary);
}

.rule-collapse {
  margin-top: 16px;
  --el-collapse-border-color: var(--color-border-divider);
}

.rule-collapse__title {
  font-size: 13px;
  color: var(--color-text-regular);
}

.rule-list {
  margin: 0;
  padding-left: 18px;
  font-size: 12.5px;
  line-height: 1.9;
  color: var(--color-text-secondary);
}

/* 响应式 */
@media (max-width: 1400px) {
  .metric-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 1200px) {
  .rank-grid,
  .analysis-grid,
  .advice-list {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 900px) {
  .metric-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
