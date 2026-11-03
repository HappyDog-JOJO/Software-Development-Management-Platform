<script setup lang="ts">
/**
 * 排期池页（开发管理新增，原名「开发排期」）：
 * - 排期对象只能来自「评审通过」的需求（需求池 / 开发中心），已上线的需求不能选、也不会出现在这里
 * - 排好期的需求会出现在「任务池」，由开发工程师在那里领任务进入开发中心
 * - 一条需求只能排一次：已排期的需求不再出现在「添加排期」的候选里（plan_release_date 非空即已排期）
 * - 列表内可直接改 / 清空预计上线时间，也支持勾选后批量设置同一天
 * - 排期精度两种：精确到日（2026-11-17）与只到月（2026-11）——点单元格前的「日 / 月」小标签即可切换
 * - 已上线的需求自动移出排期（改由「发布上线」页承接）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { fetchSchedule, fetchScheduleCandidates, setScheduleDate } from '@/api/devreq'
import { DEV_PRIORITY_CLASS, DEV_PRIORITY_OPTIONS, DEV_STATUS_CLASS } from '@/constants/devreq'
import { useDictStore } from '@/stores/dict'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import { fmtDate, fmtDateTime } from '@/utils/format'
import type { DevPriority, DevRequest, DevScheduleSummary, DevStatus } from '@/types'

const router = useRouter()
const dict = useDictStore()

const today = fmtDate(new Date())
/** 本周 = 今天起 7 天内（含今天），用于概览卡与行内标签 */
const weekEnd = (() => {
  const d = new Date()
  d.setDate(d.getDate() + 6)
  return fmtDate(d)
})()

/** 排期精度：day=精确到日，month=只到月（后端把月排期存成该月 1 日） */
type PlanPrecision = 'day' | 'month'
const thisMonth = today.slice(0, 7)

/** 某月的最后一天（YYYY-MM-DD）：月排期用它判断逾期 / 本周 */
function lastDayOfMonth(monthKey: string): string {
  const [y, m] = monthKey.split('-').map(Number)
  return fmtDate(new Date(y, m, 0))
}

function isMonthRow(row: DevRequest): boolean {
  return row.planReleasePrecision === 'month'
}

/** 单元格里给 picker 的值：月排期 YYYY-MM，日排期 YYYY-MM-DD */
function planCellValue(row: DevRequest): string {
  const d = row.planReleaseDate ?? ''
  if (!d) return ''
  return isMonthRow(row) ? d.slice(0, 7) : d.slice(0, 10)
}

/** 排期展示文案：月排期显示 2026-11，日排期显示 2026-11-17 */
function planTextOf(date: string, precision: PlanPrecision): string {
  if (!date) return ''
  return precision === 'month' ? date.slice(0, 7) : date.slice(0, 10)
}

/** 这条需求的「实际到期日」：月排期按当月月末算 */
function planDueDate(row: DevRequest): string {
  const d = row.planReleaseDate ?? ''
  if (!d) return ''
  return isMonthRow(row) ? lastDayOfMonth(d.slice(0, 7)) : d.slice(0, 10)
}

const loading = ref(false)
const saving = ref(false)
const rows = ref<DevRequest[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const summary = ref<DevScheduleSummary>({ total: 0, thisWeek: 0, overdue: 0 })
const sortOrder = ref<'ASC' | 'DESC'>('ASC')

const filters = reactive<{
  keyword: string
  priority: DevPriority | ''
  status: string
  moduleId: number | ''
}>({
  keyword: '',
  priority: '',
  status: '',
  moduleId: '',
})

/** 已勾选（批量设置用；仅本页） */
const selectedIds = ref<number[]>([])
/** 批量设置的日期（与行内编辑分开，避免误改） */
const batchDate = ref('')
/** 批量设置的精度：默认精确到日 */
const batchPrecision = ref<PlanPrecision>('day')

function buildQuery(): Record<string, string | number> {
  const q: Record<string, string | number> = {
    page: page.value,
    pageSize: pageSize.value,
    sortOrder: sortOrder.value,
  }
  if (filters.keyword.trim()) q.keyword = filters.keyword.trim()
  if (filters.priority) q.priority = filters.priority
  if (filters.status) q.status = filters.status
  if (filters.moduleId !== '') q.moduleId = filters.moduleId
  return q
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchSchedule(buildQuery())
    rows.value = res.items
    total.value = res.total
    summary.value = res.summary ?? { total: 0, thisWeek: 0, overdue: 0 }
  } finally {
    loading.value = false
  }
}

function onSearch(): void {
  page.value = 1
  void load()
}

function onReset(): void {
  filters.keyword = ''
  filters.priority = ''
  filters.status = ''
  filters.moduleId = ''
  sortOrder.value = 'ASC'
  page.value = 1
  void load()
}

function onPageChange(v: number): void {
  page.value = v
  void load()
}

function onSizeChange(v: number): void {
  pageSize.value = v
  page.value = 1
  void load()
}

function onSelectionChange(list: DevRequest[]): void {
  selectedIds.value = list.map((r) => r.id)
}

function goDetail(row: DevRequest): void {
  void router.push('/dev/reqs/' + row.id)
}

function isOverdue(row: DevRequest): boolean {
  // 月排期整月内都不算逾期，过了月末才算
  return !!row.planReleaseDate && planDueDate(row) < today
}

function isThisWeek(row: DevRequest): boolean {
  const d = row.planReleaseDate ?? ''
  if (!d) return false
  // 月排期只要和「今天起 7 天」有交集就算本周：结束日 >= 今天 且 起始日 <= 周末
  return planDueDate(row) >= today && d <= weekEnd
}

function rowClass({ row }: { row: DevRequest }): string {
  return isOverdue(row) ? 'row-overdue' : ''
}

/** 预期日期不能早于今天（后端二次校验） */
function disabledDate(d: Date): boolean {
  return fmtDate(d) < today
}

/** 月选择器：早于当月的月份不可选 */
function disabledMonth(d: Date): boolean {
  return fmtDate(d).slice(0, 7) < thisMonth
}

/** 切换精度时把已选值换算成对应格式，避免留下不合法的旧值 */
function convertPlanValue(v: string, to: PlanPrecision): string {
  if (!v) return ''
  if (to === 'month') return v.slice(0, 7)
  // 月 → 日：落在该月 1 日；若已经过去就顺延到今天
  const first = v.slice(0, 7) + '-01'
  return first < today ? today : first
}

function onBatchPrecisionChange(): void {
  batchDate.value = convertPlanValue(batchDate.value, batchPrecision.value)
}

/** 统一提交：date 为空字符串 = 取消排期 */
async function saveDates(ids: number[], date: string, okTip: string): Promise<void> {
  if (!ids.length) {
    ElMessage.warning('请先勾选需求')
    return
  }
  saving.value = true
  try {
    const res = await setScheduleDate(ids, date)
    if (res.updated > 0) {
      ElMessage.success(okTip + '（' + res.updated + ' 条）')
    } else {
      ElMessage.info('预计上线时间没有变化')
    }
    await load()
  } finally {
    saving.value = false
  }
}

/** 行内改日期 */
async function onRowDate(row: DevRequest, v: string | null): Promise<void> {
  const date = v ?? ''
  // 与当前展示值比（月排期是 YYYY-MM），没变就不提交
  if (date === planCellValue(row)) return
  if (!date) {
    await ElMessageBox.confirm(
      '清空后这条需求会回到「可排期」候选里，之后可以重新安排。确定取消这条排期吗？',
      '取消排期',
      { confirmButtonText: '确定取消', cancelButtonText: '再想想', type: 'warning' },
    )
    await saveDates([row.id], '', '已取消排期')
    return
  }
  await saveDates([row.id], date, '预计上线时间已保存')
}

/** 切换单条需求的排期精度：日 ⇄ 月（后端按传参格式判断精度） */
async function onTogglePrecision(row: DevRequest): Promise<void> {
  const d = row.planReleaseDate ?? ''
  if (!d) return
  if (isMonthRow(row)) {
    // 月 → 日：落到该月 1 日；已经过去就顺延到今天
    const first = d.slice(0, 7) + '-01'
    await saveDates([row.id], first < today ? today : first, '已改为精确到日')
  } else {
    await saveDates([row.id], d.slice(0, 7), '已改为只到月')
  }
}

/** 列表「取消排期」 */
async function onClear(row: DevRequest): Promise<void> {
  await ElMessageBox.confirm(
    '确定取消「' + row.title + '」的排期吗？取消后它会重新出现在「添加排期」的候选里。',
    '取消排期',
    { confirmButtonText: '确定取消', cancelButtonText: '再想想', type: 'warning' },
  )
  await saveDates([row.id], '', '已取消排期')
}

/** 批量设置：勾选后统一写入同一天 */
async function onBatchSet(): Promise<void> {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选要排期的需求')
    return
  }
  if (!batchDate.value) {
    ElMessage.warning('请先选择预计上线时间')
    return
  }
  const batchText = planTextOf(batchDate.value, batchPrecision.value)
    + (batchPrecision.value === 'month' ? '（只到月）' : '')
  await ElMessageBox.confirm(
    '把勾选的 ' + selectedIds.value.length + ' 条需求的预计上线时间统一设为 ' + batchText + '？',
    '批量设置上线时间',
    { confirmButtonText: '确定', cancelButtonText: '取消', type: 'info' },
  )
  await saveDates(selectedIds.value, batchDate.value, '预计上线时间已保存')
}

/* ==================== 添加排期（候选弹窗）==================== */

const addVisible = ref(false)
const candLoading = ref(false)
const candRows = ref<DevRequest[]>([])
const candTotal = ref(0)
const candPage = ref(1)
const candPageSize = ref(10)
const candSelection = ref<DevRequest[]>([])
const newDate = ref('')
/** 添加排期时选的精度：默认精确到日 */
const newPrecision = ref<PlanPrecision>('day')

function onNewPrecisionChange(): void {
  newDate.value = convertPlanValue(newDate.value, newPrecision.value)
}
const candFilters = reactive<{ keyword: string; priority: DevPriority | '' }>({
  keyword: '',
  priority: '',
})

async function loadCandidates(): Promise<void> {
  candLoading.value = true
  try {
    const params: Record<string, string | number> = {
      page: candPage.value,
      pageSize: candPageSize.value,
    }
    if (candFilters.keyword.trim()) params.keyword = candFilters.keyword.trim()
    if (candFilters.priority) params.priority = candFilters.priority
    const res = await fetchScheduleCandidates(params)
    candRows.value = res.items
    candTotal.value = res.total
  } finally {
    candLoading.value = false
  }
}

function openAdd(): void {
  addVisible.value = true
  candPage.value = 1
  candPageSize.value = 10
  candSelection.value = []
  newDate.value = ''
  newPrecision.value = 'day'
  candFilters.keyword = ''
  candFilters.priority = ''
  void loadCandidates()
}

function onCandSearch(): void {
  candPage.value = 1
  void loadCandidates()
}

function onCandPage(v: number): void {
  candPage.value = v
  void loadCandidates()
}

function onCandSelection(list: DevRequest[]): void {
  candSelection.value = list
}

async function onConfirmAdd(): Promise<void> {
  const ids = candSelection.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先勾选要排期的需求')
    return
  }
  if (!newDate.value) {
    ElMessage.warning(newPrecision.value === 'month' ? '请选择预计上线月份' : '请选择预计上线时间')
    return
  }
  await saveDates(ids, newDate.value, '已加入排期')
  addVisible.value = false
  page.value = 1
  await load()
}

const selectedCount = computed(() => selectedIds.value.length)

onMounted(() => {
  void load()
})
</script>


<template>
  <div class="page">
    <div class="scope-notice">
      <span aria-hidden="true">i</span>
      排期池：只从「评审通过」的需求里安排上线时间；已上线的需求不能排期、也不会出现在这里，同一条需求只会排一次。设置后需求会进入「任务池」，开发人员在那里领任务。
    </div>

    <!-- 概览：已排期 / 本周内上线 / 已逾期 -->
    <div class="kpi-row">
      <div class="kpi">
        <span class="kpi__label">已排期</span>
        <span class="kpi__value">{{ summary.total }}</span>
        <span class="kpi__hint">条待上线需求</span>
      </div>
      <div class="kpi">
        <span class="kpi__label">本周内上线</span>
        <span class="kpi__value kpi__value--primary">{{ summary.thisWeek }}</span>
        <span class="kpi__hint">{{ today }} 至 {{ weekEnd }}</span>
      </div>
      <div class="kpi">
        <span class="kpi__label">已逾期</span>
        <span class="kpi__value kpi__value--danger">{{ summary.overdue }}</span>
        <span class="kpi__hint">预计上线时间已过</span>
      </div>
    </div>

    <!-- 筛选 -->
    <div class="moon-card moon-card--tight">
      <div class="filter-inline">
        <div class="filter-inline__item">
          <span class="filter-inline__label">优先级</span>
          <el-select v-model="filters.priority" placeholder="全部优先级" clearable style="width: 132px" @change="onSearch">
            <el-option v-for="p in DEV_PRIORITY_OPTIONS" :key="p" :label="p" :value="p" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">模块</span>
          <el-select v-model="filters.moduleId" placeholder="全部模块" clearable style="width: 140px" @change="onSearch">
            <el-option v-for="it in dict.allOf('module')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">状态</span>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 132px" @change="onSearch">
            <el-option label="需求池" value="需求池" />
            <el-option label="开发中心" value="开发中心" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">排序</span>
          <el-select v-model="sortOrder" style="width: 150px" @change="onSearch">
            <el-option label="上线时间近→远" value="ASC" />
            <el-option label="上线时间远→近" value="DESC" />
          </el-select>
        </div>
        <div class="filter-inline__item filter-inline__item--keyword">
          <el-input
            v-model="filters.keyword"
            placeholder="关键字（编号 / 标题 / 描述）"
            style="width: 220px"
            clearable
            @keyup.enter="onSearch"
            @change="onSearch"
          >
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
        </div>
        <div class="filter-inline__item filter-inline__actions">
          <el-button @click="onReset">重置</el-button>
          <el-button type="primary" @click="onSearch">查询</el-button>
        </div>
      </div>
    </div>

    <!-- 排期表 -->
    <div class="moon-card moon-card--tight">
      <div class="toolbar toolbar--actions-only">
        <div class="toolbar__actions">
          <el-button type="primary" @click="openAdd">添加排期</el-button>
          <el-select v-model="batchPrecision" style="width: 108px" @change="onBatchPrecisionChange">
            <el-option label="精确到日" value="day" />
            <el-option label="只到月" value="month" />
          </el-select>
          <el-date-picker
            v-model="batchDate"
            :type="batchPrecision === 'month' ? 'month' : 'date'"
            :placeholder="batchPrecision === 'month' ? '批量设置上线月份' : '批量设置上线时间'"
            :value-format="batchPrecision === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD'"
            :disabled-date="batchPrecision === 'month' ? disabledMonth : disabledDate"
            style="width: 176px"
          />
          <el-button :disabled="!selectedCount || !batchDate" :loading="saving" @click="onBatchSet">
            批量设置
          </el-button>
          <span v-if="selectedCount" class="select-hint">已选 {{ selectedCount }} 条</span>
          <el-button text @click="load">刷新</el-button>
        </div>
      </div>

      <el-table
        v-loading="loading"
        :data="rows"
        row-key="id"
        :row-class-name="rowClass"
        style="width: 100%"
        @selection-change="onSelectionChange"
      >
        <el-table-column type="selection" width="44" />

        <el-table-column label="需求编号" width="180">
          <template #default="{ row }">
            <el-button link type="primary" class="link-code" @click="goDetail(row)">
              {{ row.reqNo }}
            </el-button>
          </template>
        </el-table-column>

        <el-table-column label="需求标题" min-width="260" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="cell-title">{{ row.title }}</span>
          </template>
        </el-table-column>

        <el-table-column label="模块" width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.moduleName || '—' }}</template>
        </el-table-column>

        <el-table-column label="优先级" width="96" align="center">
          <template #default="{ row }">
            <span :class="DEV_PRIORITY_CLASS[row.priority as DevPriority]">{{ row.priority }}</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="104" align="center">
          <template #default="{ row }">
            <span :class="DEV_STATUS_CLASS[row.status as DevStatus]">{{ row.status }}</span>
          </template>
        </el-table-column>

        <el-table-column label="预计上线时间" width="260" align="center">
          <template #default="{ row }">
            <div class="plan-cell">
              <el-tooltip
                :content="isMonthRow(row) ? '只排到月（整月内上线），点击改为精确到日' : '精确到日，点击改为只到月'"
                placement="top"
              >
                <el-tag
                  class="plan-cell__mode"
                  size="small"
                  effect="plain"
                  :type="isMonthRow(row) ? 'info' : 'primary'"
                  @click="onTogglePrecision(row)"
                >
                  {{ isMonthRow(row) ? '月' : '日' }}
                </el-tag>
              </el-tooltip>
              <el-date-picker
                :model-value="planCellValue(row)"
                :type="isMonthRow(row) ? 'month' : 'date'"
                :placeholder="isMonthRow(row) ? '设置月份' : '设置日期'"
                :value-format="isMonthRow(row) ? 'YYYY-MM' : 'YYYY-MM-DD'"
                :disabled-date="isMonthRow(row) ? disabledMonth : disabledDate"
                style="width: 138px"
                @update:model-value="(v: string | null) => onRowDate(row, v)"
              />
              <el-tag v-if="isOverdue(row)" type="danger" size="small" effect="plain">逾期</el-tag>
              <el-tag v-else-if="isThisWeek(row)" type="warning" size="small" effect="plain">本周</el-tag>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="设置人" width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.planReleaseBy || '—' }}</template>
        </el-table-column>

        <el-table-column label="设置时间" width="150">
          <template #default="{ row }">
            <span class="cell-nowrap">{{ fmtDateTime(row.planReleaseAt) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="140" fixed="right" align="center">
          <template #default="{ row }">
            <el-button link type="primary" @click="goDetail(row)">详情</el-button>
            <el-button link type="danger" @click="onClear(row)">取消排期</el-button>
          </template>
        </el-table-column>

        <template #empty>
          <EmptyIllustration
            text="还没有排期"
            sub-text="点左上角「添加排期」，从评审通过的需求里挑选并设定预计上线时间"
          />
        </template>
      </el-table>

      <div class="pager">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :current-page="page"
          :page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="onPageChange"
          @size-change="onSizeChange"
        />
      </div>
    </div>

    <!-- 添加排期：候选 = 评审通过 + 未上线 + 未排期 -->
    <el-dialog v-model="addVisible" title="添加排期" width="960px" destroy-on-close>
      <div class="add-tip">
        只显示「评审通过、尚未上线、且还没有排期」的需求（同一条需求只能排一次）。
      </div>

      <div class="add-filter">
        <el-input
          v-model="candFilters.keyword"
          placeholder="搜索编号 / 标题"
          style="width: 240px"
          clearable
          @keyup.enter="onCandSearch"
          @change="onCandSearch"
        >
          <template #prefix><el-icon><Search /></el-icon></template>
        </el-input>
        <el-select
          v-model="candFilters.priority"
          placeholder="全部优先级"
          clearable
          style="width: 132px"
          @change="onCandSearch"
        >
          <el-option v-for="p in DEV_PRIORITY_OPTIONS" :key="p" :label="p" :value="p" />
        </el-select>
        <span class="add-filter__count">共 {{ candTotal }} 条可排期</span>
      </div>

      <el-table
        v-loading="candLoading"
        :data="candRows"
        height="320"
        row-key="id"
        @selection-change="onCandSelection"
      >
        <el-table-column type="selection" width="44" />
        <el-table-column label="需求编号" width="180" prop="reqNo" />
        <el-table-column label="需求标题" min-width="240" show-overflow-tooltip prop="title" />
        <el-table-column label="模块" width="120">
          <template #default="{ row }">{{ row.moduleName || '—' }}</template>
        </el-table-column>
        <el-table-column label="优先级" width="90" align="center">
          <template #default="{ row }">
            <span :class="DEV_PRIORITY_CLASS[row.priority as DevPriority]">{{ row.priority }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <span :class="DEV_STATUS_CLASS[row.status as DevStatus]">{{ row.status }}</span>
          </template>
        </el-table-column>
        <template #empty>
          <EmptyIllustration
            :size="110"
            text="没有可排期的需求"
            sub-text="评审通过且尚未上线的需求会出现在这里"
          />
        </template>
      </el-table>

      <div class="add-pager">
        <el-pagination
          small
          background
          layout="total, prev, pager, next"
          :total="candTotal"
          :current-page="candPage"
          :page-size="candPageSize"
          @current-change="onCandPage"
        />
      </div>

      <div class="add-date">
        <span class="add-date__label">预计上线时间</span>
        <el-select v-model="newPrecision" style="width: 108px" @change="onNewPrecisionChange">
          <el-option label="精确到日" value="day" />
          <el-option label="只到月" value="month" />
        </el-select>
        <el-date-picker
          v-model="newDate"
          :type="newPrecision === 'month' ? 'month' : 'date'"
          :placeholder="newPrecision === 'month' ? '选择预计上线月份' : '选择预计上线日期'"
          :value-format="newPrecision === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD'"
          :disabled-date="newPrecision === 'month' ? disabledMonth : disabledDate"
          style="width: 180px"
        />
        <span class="add-date__hint">
          已选 {{ candSelection.length }} 条，确定后统一写入{{ newPrecision === 'month' ? '同一个月（只到月）' : '同一天' }}
        </span>
      </div>

      <template #footer>
        <el-button @click="addVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="onConfirmAdd">确定排期</el-button>
      </template>
    </el-dialog>
  </div>
</template>


<style scoped>
/* 概览卡：三块，数值优先 */
.kpi-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.kpi {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 14px 16px;
  border-radius: var(--radius-card);
  background: var(--color-bg-card);
  border: 1px solid var(--color-border-soft);
}

.kpi__label {
  font-size: 13px;
  color: var(--color-text-secondary);
}

.kpi__value {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
  color: var(--color-text-primary);
}

.kpi__value--primary {
  color: var(--color-primary);
}

.kpi__value--danger {
  color: var(--color-danger);
}

.kpi__hint {
  font-size: 12px;
  color: var(--color-text-secondary);
}

/* 筛选行：与需求列表同一套写法 */
.filter-inline {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 20px;
}

.filter-inline__item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-inline__label {
  font-size: 13px;
  color: var(--color-text-secondary);
  white-space: nowrap;
}

.filter-inline__actions {
  margin-left: auto;
}

.select-hint {
  font-size: 12px;
  color: var(--color-text-secondary);
}

.link-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
}

.cell-title {
  display: inline-block;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cell-nowrap {
  white-space: nowrap;
}

.plan-cell {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}

/* 精度标签：点击在「日 / 月」之间切换，做得小一点不抢日期 */
.plan-cell__mode {
  flex: 0 0 auto;
  height: 22px;
  padding: 0 5px;
  font-size: 11px;
  cursor: pointer;
}

/* 逾期行：浅红底，一眼能扫到 */
:deep(.row-overdue) td {
  background: rgba(220, 84, 84, 0.06);
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

/* 添加排期弹窗 */
.add-tip {
  margin-bottom: 12px;
  padding: 8px 12px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  font-size: 13px;
  color: var(--color-text-secondary);
}

.add-filter {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.add-filter__count {
  margin-left: auto;
  font-size: 13px;
  color: var(--color-text-secondary);
}

.add-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 8px;
}

.add-date {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px dashed var(--color-border-soft);
}

.add-date__label {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.add-date__hint {
  font-size: 12px;
  color: var(--color-text-secondary);
}
</style>
