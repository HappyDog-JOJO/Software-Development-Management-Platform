<script setup lang="ts">

/**
 * 输单列表页（销售分析）。
 * - 顶部筛选：时间范围（快捷/自定义）、输单类型、丢单阶段、所属行业、企业规模、预算匹配、
 *   是否远程演示、是否定制化、业务员、关键字
 * - 表格列：编号、客户、丢单时间、各维度、报价、业务员、部门…；行内展开看输单原因全文
 * - 可见范围：销售员仅自己，销售经理/管理员全部（服务端判定，页面只负责提示）
 */
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import * as XLSX from 'xlsx'
import { useAuthStore } from '@/stores/auth'
import { useDictStore } from '@/stores/dict'
import { useLossStore } from '@/stores/salesloss'
import { deleteLostOrder } from '@/api/salesloss'
import { canDeleteLoss } from '@/constants/user'
import { ISSUE_QUICK_RANGES } from '@/constants/issue'
import { fileStamp, fmtDateTime, quickRange } from '@/utils/format'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import ColumnConfig from '@/components/ColumnConfig.vue'
import type { LostOrder, LostOrderQuery } from '@/types'
import { useColumnWidths } from '@/composables/useColumnWidths'
import { useColumnConfig, type ColumnMeta } from '@/composables/useColumnConfig'

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('sales-loss-list')

/** 列配置（按当前用户隔离）：控制列的显隐与顺序 */
const colcfg = useColumnConfig('sales-loss-list')
const colcfgRef = ref<InstanceType<typeof ColumnConfig> | null>(null)

interface ColDef {
  label: string
  colwKey: string
  width?: number
  minWidth?: number
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
  overflow?: boolean
}

const LOSS_COL_DEFS: Record<string, ColDef> = {
  lostNo: { label: '编号', colwKey: 'lostNo', width: 176 },
  customerName: { label: '客户名称', colwKey: 'customerName', width: 200, overflow: true },
  lostAt: { label: '丢单时间', colwKey: 'lostAt', width: 158, sortable: true, align: 'center' },
  lossTypeName: { label: '输单类型', colwKey: 'lossTypeName', width: 118, align: 'center' },
  lossStageName: { label: '丢单阶段', colwKey: 'lossStageName', width: 110, align: 'center' },
  industryName: { label: '所属行业', colwKey: 'industryName', width: 120 },
  companyScaleName: { label: '企业规模', colwKey: 'companyScaleName', width: 148 },
  budgetMatchName: { label: '预算匹配', colwKey: 'budgetMatchName', width: 116, align: 'center' },
  finalQuote: { label: '最终报价（元）', colwKey: 'finalQuote', width: 130, sortable: true, align: 'right' },
  remoteDemo: { label: '远程演示', colwKey: 'remoteDemo', width: 96, align: 'center' },
  customized: { label: '定制化', colwKey: 'customized', width: 88, align: 'center' },
  customerChoice: { label: '客户最终选择', colwKey: 'customerChoice', width: 180, overflow: true },
  salesmanName: { label: '业务员', colwKey: 'salesmanName', width: 100 },
  departmentName: { label: '部门', colwKey: 'departmentName', width: 116 },
  createdAt: { label: '提交时间', colwKey: 'createdAt', width: 158, sortable: true, align: 'center' },
}

const auth = useAuthStore()
const dict = useDictStore()
const store = useLossStore()
const router = useRouter()

const quickRangeValue = ref<'' | 'today' | 'week' | 'month' | 'custom'>('')

const filters = reactive<LostOrderQuery>({
  dateStart: '',
  dateEnd: '',
  lossTypeId: undefined,
  lossStageId: undefined,
  industryId: undefined,
  companyScaleId: undefined,
  budgetMatchId: undefined,
  remoteDemo: '',
  customized: '',
  salesman: '',
  keyword: '',
  sortField: 'lostAt',
  sortOrder: 'DESC',
})

/** 筛选区折叠（记忆用户选择） */
const FILTER_COLLAPSE_KEY = 'aftersales_loss_filter_collapsed'
const filterCollapsed = ref(localStorage.getItem(FILTER_COLLAPSE_KEY) === '1')
function toggleFilter(): void {
  filterCollapsed.value = !filterCollapsed.value
  localStorage.setItem(FILTER_COLLAPSE_KEY, filterCollapsed.value ? '1' : '0')
}

const activeFilterCount = computed(() => {
  let n = 0
  if (filters.dateStart || filters.dateEnd) n += 1
  if (filters.lossTypeId) n += 1
  if (filters.lossStageId) n += 1
  if (filters.industryId) n += 1
  if (filters.companyScaleId) n += 1
  if (filters.budgetMatchId) n += 1
  if (filters.remoteDemo) n += 1
  if (filters.customized) n += 1
  if (filters.salesman) n += 1
  if (filters.keyword) n += 1
  return n
})

/** 业务员筛选：销售员只能看自己，给出也没意义 → 仅销售经理/管理员显示 */
const showSalesmanFilter = computed(() => auth.isManager || auth.isAdmin)
const canDelete = computed(() => canDeleteLoss(auth.roles))
/** 销售员页头提示：当前统计/列表仅本人 */
const selfScoped = computed(() => !auth.isManager && !auth.isAdmin)

/** 按「列配置」的顺序与显隐生成实际渲染的列 */
const lossColumns = computed(() =>
  colcfg
    .ordered()
    .filter((c) => c.visible && LOSS_COL_DEFS[c.key])
    .map((c) => ({ key: c.key, ...LOSS_COL_DEFS[c.key] })),
)

async function load(): Promise<void> {
  store.setFilters({ ...filters })
  await store.load()
  await nextTick()
  if (store.highlightId) {
    store.highlightId = null
  }
}

function onQuickRange(v: string | number | boolean | undefined): void {
  const val = String(v) as 'today' | 'week' | 'month' | 'custom'
  quickRangeValue.value = val
  if (val === 'custom') {
    return
  }
  const r = quickRange(val as 'today' | 'week' | 'month')
  filters.dateStart = r.dateStart
  filters.dateEnd = r.dateEnd
  void load()
}

function onReset(): void {
  filters.dateStart = ''
  filters.dateEnd = ''
  filters.lossTypeId = undefined
  filters.lossStageId = undefined
  filters.industryId = undefined
  filters.companyScaleId = undefined
  filters.budgetMatchId = undefined
  filters.remoteDemo = ''
  filters.customized = ''
  filters.salesman = ''
  filters.keyword = ''
  filters.sortField = 'lostAt'
  filters.sortOrder = 'DESC'
  quickRangeValue.value = ''
  store.page = 1
  void load()
}

function onPageChange(p: number): void {
  store.page = p
  void load()
}

function onSizeChange(s: number): void {
  store.pageSize = s
  store.page = 1
  void load()
}

function onSortChange(payload: { prop: string; order: string | null }): void {
  if (!payload.order) {
    return
  }
  const map: Record<string, LostOrderQuery['sortField']> = {
    lostAt: 'lostAt',
    finalQuote: 'finalQuote',
    createdAt: 'createdAt',
  }
  filters.sortField = map[payload.prop] || 'lostAt'
  filters.sortOrder = payload.order === 'ascending' ? 'ASC' : 'DESC'
  void load()
}

function onEdit(row: LostOrder): void {
  void router.push({ path: '/sales/loss/create', query: { id: String(row.id) } })
}

async function onDelete(row: LostOrder): Promise<void> {
  await ElMessageBox.confirm(
    `确定删除「${row.customerName}」这条输单记录吗？删除后无法恢复。`,
    '删除输单',
    { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'warning' },
  )
  await deleteLostOrder(row.id)
  ElMessage.success('输单已删除')
  await load()
}

/** 报价展示：未填报价显示 — */
function quoteText(v: number | null): string {
  if (v === null || v === undefined) {
    return '—'
  }
  return v.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** 导出 Excel：按当前筛选导出当前页数据 */
async function onExport(): Promise<void> {
  if (!store.list.length) {
    ElMessage.warning('当前筛选没有可导出的数据')
    return
  }
  const rows = store.list.map((r) => ({
    编号: r.lostNo,
    客户名称: r.customerName,
    丢单时间: fmtDateTime(r.lostAt),
    输单类型: r.lossTypeName || '—',
    丢单阶段: r.lossStageName || '—',
    所属行业: r.industryName || '—',
    企业规模: r.companyScaleName || '—',
    预算匹配: r.budgetMatchName || '—',
    最终报价元: r.finalQuote ?? '',
    是否远程演示: r.remoteDemo === 1 ? '是' : '否',
    是否涉及定制化: r.customized === 1 ? '是' : '否',
    客户最终选择: r.customerChoice || '—',
    输单原因描述: r.reason,
    业务员: r.salesmanName,
    部门: r.departmentName || '未分配部门',
    提交时间: fmtDateTime(r.createdAt),
  }))
  try {
    const sheet = XLSX.utils.json_to_sheet(rows)
    sheet['!cols'] = [
      { wch: 18 }, { wch: 26 }, { wch: 18 }, { wch: 14 }, { wch: 12 }, { wch: 14 },
      { wch: 16 }, { wch: 14 }, { wch: 12 }, { wch: 14 }, { wch: 14 }, { wch: 22 },
      { wch: 60 }, { wch: 12 }, { wch: 14 }, { wch: 18 },
    ]
    const book = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(book, sheet, '输单列表')
    XLSX.writeFile(book, `输单列表_${fileStamp()}.xlsx`)
    ElMessage.success('导出完成')
  } catch {
    ElMessage.error('导出失败，请重试')
  }
}

onMounted(async () => {
  colcfg.register([
    { key: 'lostNo', label: '编号' },
    { key: 'customerName', label: '客户名称' },
    { key: 'lostAt', label: '丢单时间' },
    { key: 'lossTypeName', label: '输单类型' },
    { key: 'lossStageName', label: '丢单阶段' },
    { key: 'industryName', label: '所属行业' },
    { key: 'companyScaleName', label: '企业规模' },
    { key: 'budgetMatchName', label: '预算匹配' },
    { key: 'finalQuote', label: '最终报价（元）' },
    { key: 'remoteDemo', label: '远程演示' },
    { key: 'customized', label: '定制化' },
    { key: 'customerChoice', label: '客户最终选择' },
    { key: 'salesmanName', label: '业务员' },
    { key: 'departmentName', label: '部门' },
    { key: 'createdAt', label: '提交时间' },
    { key: '操作', label: '操作', fixed: true } as ColumnMeta,
  ])
  await Promise.all([dict.load(), load()])
})
</script>

<template>
  <div class="page">
    <!-- 权限范围提示 -->
    <div v-if="store.filteredOut > 0" class="scope-notice">
      <span aria-hidden="true">i</span>
      另有 {{ store.filteredOut }} 条输单不在您的可见范围（销售员仅可见本人登记，服务端判定）
    </div>
    <div v-else-if="selfScoped" class="scope-notice scope-notice--info">
      <span aria-hidden="true">i</span>
      当前为销售员视角：只显示并统计本人登记的输单。
    </div>

    <!-- 筛选区 -->
    <div class="moon-card moon-card--tight filter-panel">
      <div class="filter-panel__head">
        <span class="filter-panel__title">筛选条件</span>
        <span v-if="filterCollapsed && activeFilterCount" class="filter-panel__summary">已选 {{ activeFilterCount }} 项</span>
        <el-button text class="filter-panel__toggle" @click="toggleFilter">
          {{ filterCollapsed ? '展开' : '收起' }}
          <el-icon><component :is="filterCollapsed ? 'ArrowDown' : 'ArrowUp'" /></el-icon>
        </el-button>
      </div>

      <div v-show="!filterCollapsed" class="filter-inline">
        <div class="filter-inline__item">
          <span class="filter-inline__label">时间</span>
          <el-radio-group :model-value="quickRangeValue" @change="onQuickRange">
            <el-radio-button v-for="r in ISSUE_QUICK_RANGES" :key="r.value" :value="r.value">{{ r.label }}</el-radio-button>
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
          <span class="filter-inline__label">行业</span>
          <el-select v-model="filters.industryId" placeholder="全部行业" clearable style="width: 140px" @change="load">
            <el-option v-for="it in dict.allOf('industry')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">规模</span>
          <el-select v-model="filters.companyScaleId" placeholder="全部规模" clearable style="width: 156px" @change="load">
            <el-option v-for="it in dict.allOf('company_scale')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">预算匹配</span>
          <el-select v-model="filters.budgetMatchId" placeholder="全部" clearable style="width: 132px" @change="load">
            <el-option v-for="it in dict.allOf('budget_match')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">远程演示</span>
          <el-select v-model="filters.remoteDemo" placeholder="不限" clearable style="width: 104px" @change="load">
            <el-option label="是" value="1" />
            <el-option label="否" value="0" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">定制化</span>
          <el-select v-model="filters.customized" placeholder="不限" clearable style="width: 104px" @change="load">
            <el-option label="是" value="1" />
            <el-option label="否" value="0" />
          </el-select>
        </div>
        <div v-if="showSalesmanFilter" class="filter-inline__item">
          <span class="filter-inline__label">业务员</span>
          <el-input v-model="filters.salesman" placeholder="姓名/账号" style="width: 140px" clearable @change="load" />
        </div>
        <div class="filter-inline__item">
          <el-input
            v-model="filters.keyword"
            placeholder="关键字（客户 / 最终选择 / 原因）"
            style="width: 248px"
            clearable
            @keyup.enter="load"
            @change="load"
          >
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
        </div>
        <div class="filter-inline__item filter-inline__actions">
          <el-button @click="onReset">重置</el-button>
          <el-button type="primary" @click="load">查询</el-button>
        </div>
      </div>
    </div>

    <!-- 表格 -->
    <div class="moon-card moon-card--tight">
      <div class="toolbar toolbar--actions-only">
        <div class="toolbar__actions">
          <ColumnConfig ref="colcfgRef" :config="colcfg" title="输单列表" />
          <el-button type="default" @click="onExport">导出 Excel</el-button>
          <el-button type="primary" @click="router.push('/sales/loss/create')">登记输单</el-button>
        </div>
      </div>

      <div class="moon-table">
        <el-table
          v-loading="store.loading"
          :data="store.list"
          row-key="id"
          border
          stripe
          @sort-change="onSortChange"
        >
          <el-table-column type="expand">
            <template #default="{ row }">
              <div class="loss-expand">
                <div class="loss-expand__row">
                  <span class="loss-expand__label">输单原因</span>
                  <span class="loss-expand__text">{{ row.reason }}</span>
                </div>
                <div class="loss-expand__row">
                  <span class="loss-expand__label">客户最终选择</span>
                  <span class="loss-expand__text">{{ row.customerChoice || '—' }}</span>
                </div>
              </div>
            </template>
          </el-table-column>

          <template #empty>
            <EmptyIllustration :size="130" text="暂无输单数据" sub-text="可以调整筛选条件，或先去登记一条输单" />
          </template>

          <el-table-column
            v-for="c in lossColumns"
            :key="c.key"
            :prop="c.key"
            :label="c.label"
            v-bind="colw.bind(c.colwKey, c.width !== undefined ? { width: c.width } : { minWidth: c.minWidth })"
            :align="c.align || 'left'"
            :sortable="c.sortable ? 'custom' : false"
            :show-overflow-tooltip="!!c.overflow"
          >
            <template #default="{ row }">
              <template v-if="c.key === 'lostNo'">
                <span class="cell-code">{{ row.lostNo }}</span>
              </template>
              <template v-else-if="c.key === 'lostAt'">
                <span class="cell-nowrap">{{ fmtDateTime(row.lostAt) }}</span>
              </template>
              <template v-else-if="c.key === 'finalQuote'">
                <span class="cell-code">{{ quoteText(row.finalQuote) }}</span>
              </template>
              <template v-else-if="c.key === 'remoteDemo'">
                <el-tag :type="row.remoteDemo === 1 ? 'success' : 'info'" effect="plain" size="small">
                  {{ row.remoteDemo === 1 ? '是' : '否' }}
                </el-tag>
              </template>
              <template v-else-if="c.key === 'customized'">
                <el-tag :type="row.customized === 1 ? 'warning' : 'info'" effect="plain" size="small">
                  {{ row.customized === 1 ? '是' : '否' }}
                </el-tag>
              </template>
              <template v-else-if="c.key === 'departmentName'">
                <span class="cell-nowrap">{{ row.departmentName || '未分配部门' }}</span>
              </template>
              <template v-else-if="c.key === 'createdAt'">
                <span class="cell-nowrap">{{ fmtDateTime(row.createdAt) }}</span>
              </template>
              <template v-else-if="c.key === 'customerName'">
                <span class="cell-nowrap">{{ row.customerName }}</span>
              </template>
              <template v-else>
                <span>{{ row[c.key] === '' || row[c.key] === null ? '—' : row[c.key] }}</span>
              </template>
            </template>
          </el-table-column>

          <el-table-column label="操作" width="132" fixed="right" align="center">
            <template #default="{ row }">
              <el-button link type="primary" @click="onEdit(row)">编辑</el-button>
              <el-button v-if="canDelete" link type="danger" @click="onDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <div class="pager">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="store.total"
          :current-page="store.page"
          :page-size="store.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="onPageChange"
          @size-change="onSizeChange"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.filter-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.filter-panel__head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.filter-panel__title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.filter-panel__summary {
  padding: 1px 8px;
  border-radius: var(--radius-pill);
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 12px;
}

.filter-panel__toggle {
  margin-left: auto;
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

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.cell-code {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.cell-nowrap {
  white-space: nowrap;
}

/* 行内展开：原因与客户最终选择全文 */
.loss-expand {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 4px 12px 8px;
}

.loss-expand__row {
  display: flex;
  gap: 10px;
  font-size: 13px;
  line-height: 1.7;
}

.loss-expand__label {
  flex: 0 0 88px;
  color: var(--color-text-secondary);
}

.loss-expand__text {
  color: var(--color-text-regular);
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
