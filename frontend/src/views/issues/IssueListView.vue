<script setup lang="ts">

/**
 * 问题列表页（核心页）。
 * - 顶部筛选：时间范围（快捷/自定义）、提交人、提交部门、所属模块、状态、价值、关键字
 * - 表格列：编号、提交人、部门、模块、描述（截断+tooltip）、附件数、提交时间、状态/价值 Tag、处理人/时间、操作
 * - 操作按钮按角色与状态动态渲染；销售员仅见「查看详情」
 * - 展示权限外条数提示条（服务端返回 filteredOut）
 */
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import * as XLSX from 'xlsx'
import { useAuthStore } from '@/stores/auth'
import { useDeptStore } from '@/stores/user'
import { useDictStore } from '@/stores/dict'
import { useIssueStore } from '@/stores/issue'
import { exportIssueLog } from '@/api/log'
import { deleteIssue, fetchHandlers, updateIssueStatus, type HandlerOption } from '@/api/issue'
import { canDevReq, canExport, canHandle, isAdmin as isAdminRole } from '@/constants/user'
import { ISSUE_QUICK_RANGES, STATUS_OPTIONS } from '@/constants/issue'
import { fileStamp, fmtDateTime, quickRange } from '@/utils/format'
import IssueStatusTag from '@/components/IssueStatusTag.vue'
import ValueTag from '@/components/ValueTag.vue'
import HandleDialog from '@/components/HandleDialog.vue'
import IssuePingcodeSyncDialog from '@/components/IssuePingcodeSyncDialog.vue'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import ColumnConfig from '@/components/ColumnConfig.vue'
import type { Issue, IssueQuery } from '@/types'
import { useColumnWidths } from '@/composables/useColumnWidths'
import { useColumnConfig, type ColumnMeta } from '@/composables/useColumnConfig'

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('issues-list')

/** 列配置（按当前用户隔离）：控制列的显隐与顺序 */
const colcfg = useColumnConfig('issues-list')
const colcfgRef = ref<InstanceType<typeof ColumnConfig> | null>(null)

function colOk(key: string): boolean {
  return colcfg.isVisible(key)
}

function labelOf(key: string): string {
  return colcfg.metas.value.find((m) => m.key === key)?.label || key
}

/**
 * 列定义表：key → 渲染属性。
 * 说明：colwKey 与 useColumnWidths 的键保持一致（历史键含中文，不能改，否则丢失已存的列宽）。
 */
interface ColDef {
  label: string
  colwKey: string
  width?: number
  minWidth?: number
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
  overflow?: boolean
}

const ISSUE_COL_DEFS: Record<string, ColDef> = {
  issueNo: { label: '问题编号', colwKey: 'issueNo', width: 224 },
  submitterName: { label: '提交人', colwKey: 'submitterName', width: 96 },
  submitterDepartmentName: { label: '提交部门', colwKey: 'submitterDepartmentName', width: 116 },
  moduleName: { label: '所属模块', colwKey: 'moduleName', width: 112 },
  categoryName: { label: '问题分类', colwKey: 'categoryName', width: 112 },
  description: { label: '问题描述', colwKey: 'description', width: 360, overflow: true },
  attachmentCount: { label: '附件', colwKey: 'attachmentCount', width: 78, align: 'center' },
  createdAt: { label: '提交时间', colwKey: 'createdAt', width: 158, sortable: true, align: 'center' },
  status: { label: '问题状态', colwKey: 'status', width: 112, align: 'center' },
  valueName: { label: '问题价值', colwKey: 'valueName', width: 126, align: 'center' },
  handlerName: { label: '处理人', colwKey: 'handlerName', width: 100 },
  handledAt: { label: '处理时间', colwKey: 'handledAt', width: 158, sortable: true, align: 'center' },
  handleRemark: { label: '处理备注', colwKey: '处理备注', width: 240, overflow: true },
  pingcodeTask: { label: 'PingCode', colwKey: 'pingcodeTask', width: 140, align: 'center' },
}

/** 按“列配置”的顺序与显隐生成实际渲染的列（顺序调整才能真正作用到表格） */
const issueColumns = computed(() =>
  colcfg
    .ordered()
    .filter((c) => c.visible && ISSUE_COL_DEFS[c.key])
    .map((c) => ({ key: c.key, ...ISSUE_COL_DEFS[c.key] })),
)

const auth = useAuthStore()
const dict = useDictStore()
const deptStore = useDeptStore()
const store = useIssueStore()
const router = useRouter()
const route = useRoute()

const handleVisible = ref(false)
const currentIssue = ref<Issue | null>(null)
const quickRangeValue = ref<'' | 'today' | 'week' | 'month' | 'custom'>('')

const filters = reactive<IssueQuery>({
  dateStart: '',
  dateEnd: '',
  submitter: '',
  departmentId: undefined,
  moduleId: undefined,
  categoryId: undefined,
  status: '',
  valueId: undefined,
  handler: '',
  keyword: '',
})

/** 处理人筛选选项（基于当前可见范围，带问题数） */
const handlerOptions = ref<HandlerOption[]>([])
async function loadHandlers(): Promise<void> {
  try {
    handlerOptions.value = await fetchHandlers()
  } catch {
    handlerOptions.value = []
  }
}

/** 筛选区折叠（记忆用户选择） */
const FILTER_COLLAPSE_KEY = 'aftersales_issues_filter_collapsed'
const filterCollapsed = ref(localStorage.getItem(FILTER_COLLAPSE_KEY) === '1')
function toggleFilter(): void {
  filterCollapsed.value = !filterCollapsed.value
  localStorage.setItem(FILTER_COLLAPSE_KEY, filterCollapsed.value ? '1' : '0')
}

/** 已选筛选条件数量（折叠时提示用） */
const activeFilterCount = computed(() => {
  let n = 0
  if (filters.dateStart || filters.dateEnd) n += 1
  if (filters.submitter) n += 1
  if (filters.departmentId) n += 1
  if (filters.moduleId) n += 1
  if (filters.categoryId) n += 1
  if (filters.status) n += 1
  if (filters.valueId) n += 1
  if (filters.handler) n += 1
  if (filters.keyword) n += 1
  return n
})

/** 销售经理：部门筛选锁定为本部门且置灰 */
const deptLocked = computed(() => auth.isManager)
const lockedDeptName = computed(() => auth.user?.departmentName || '本部门')

async function load(): Promise<void> {
  // 销售经理：部门范围由服务端按角色限制，此处不下发部门参数，
  // 以保证 filteredOut（不在可见范围的条数）能被正确计算并提示。
  const params: IssueQuery = {
    ...filters,
    departmentId: deptLocked.value ? undefined : filters.departmentId,
  }
  store.setFilters(params)
  await store.load()
  await nextTick()
  // 新提交记录高亮（一次性）
  if (store.highlightId) {
    store.highlightId = null
  }
}

function onQuickRange(v: string | number | boolean | undefined): void {
  const val = String(v) as 'today' | 'week' | 'month' | 'custom'
  quickRangeValue.value = val
  if (val === 'custom') {
    // 自定义：保留当前日期，由用户在日期选择器中指定
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
  filters.submitter = ''
  filters.departmentId = undefined
  filters.moduleId = undefined
  filters.categoryId = undefined
  filters.status = ''
  filters.valueId = undefined
  filters.handler = ''
  filters.keyword = ''
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
  filters.sortField = payload.prop === 'handledAt' ? 'handledAt' : 'createdAt'
  filters.sortOrder = payload.order === 'ascending' ? 'ASC' : 'DESC'
  void load()
}
function goDetail(row: Issue): void {
  void router.push(`/issues/${row.id}`)
}

function openHandle(row: Issue): void {
  currentIssue.value = row
  handleVisible.value = true
}

/** 操作列主操作文案：尚未有效处理（待处理/未处理/已驳回）→「处理」；处理中/已处理 →「追加处理」 */
function actionLabel(row: Issue): string {
  return ['待处理', '未处理', '已驳回'].includes(row.status) ? '处理' : '追加处理'
}

/** 操作列：有处理权限（售后/管理员）即显示主操作，按状态切换文案 */
function canAct(): boolean {
  return canHandle(auth.roles)
}

function onAction(row: Issue): void {
  openHandle(row)
}

/* ==================== PingCode 同步（开发工程师/管理员） ==================== */

/** 可同步到 PingCode 的角色（与开发管理一致：开发工程师 / 管理员） */
const canSyncPingcode = computed(() => canDevReq(auth.roles) || isAdminRole(auth.roles))

/** 同步弹窗：只支持单条（在操作栏逐条发起，不提供批量） */
const syncDialogVisible = ref(false)
const syncIds = ref<number[]>([])

/** 单条同步：带上该问题的标题/描述作为默认值 */
const syncDefaultTitle = ref('')
const syncDefaultDescription = ref('')

function onSyncOne(row: Issue): void {
  if (row.pingcodeTaskId) {
    ElMessage.info('该问题已同步到 PingCode')
    return
  }
  syncIds.value = [row.id]
  syncDefaultTitle.value = `【售后问题】${row.issueNo}`
  syncDefaultDescription.value = row.description
  syncDialogVisible.value = true
}

function onSyncDone(): void {
  void load()
}

/** 删除问题：仅管理员可见/可用；服务端同样强制校验角色 */
async function onDelete(row: Issue): Promise<void> {
  await ElMessageBox.confirm(
    `确定删除问题「${row.issueNo}」吗？该问题的处理记录、补充说明与附件会一并删除，且无法恢复。`,
    '删除问题',
    { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'warning' },
  )
  await deleteIssue(row.id)
  ElMessage.success('问题已删除')
  await load()
}

function onHandled(): void {
  void load()
  void loadHandlers()
}

/** 管理员：列表内直接修改问题状态 */
async function onStatusChange(row: Issue, status: string): Promise<void> {
  if (!status || status === row.status) {
    return
  }
  try {
    await updateIssueStatus(row.id, status)
    row.status = status as Issue['status']
    ElMessage.success('问题状态已更新')
  } catch {
    // 失败由请求层统一提示，回滚展示
    void load()
  }
}

/** 导出 Excel：按当前筛选导出，文件名含时间戳，导出动作写操作日志 */
async function onExport(): Promise<void> {
  if (!canExport(auth.roles)) {
    ElMessage.warning('当前角色无导出权限')
    return
  }
  const rows = store.list.map((r) => ({
    问题编号: r.issueNo,
    提交人: r.submitterName,
    提交部门: r.submitterDepartmentName || '未分配部门',
    所属模块: r.moduleName || '—',
    问题分类: r.categoryName || '未分类',
    问题描述: r.description,
    附件数: r.attachmentCount,
    提交时间: fmtDateTime(r.createdAt),
    问题状态: r.status,
    问题价值: r.valueName || '未评级',
    处理人: r.handlerName || '—',
    处理时间: fmtDateTime(r.handledAt),
    处理备注: r.handleRemark || '—',
  }))
  if (!rows.length) {
    ElMessage.warning('当前筛选没有可导出的数据')
    return
  }
  try {
    const sheet = XLSX.utils.json_to_sheet(rows)
    sheet['!cols'] = [
      { wch: 20 }, { wch: 10 }, { wch: 14 }, { wch: 12 }, { wch: 60 },
      { wch: 8 }, { wch: 18 }, { wch: 10 }, { wch: 12 }, { wch: 10 }, { wch: 18 }, { wch: 40 },
    ]
    const book = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(book, sheet, '问题列表')
    XLSX.writeFile(book, `问题列表_${fileStamp()}.xlsx`)
    await exportIssueLog(store.total)
    ElMessage.success('导出完成，导出动作已记入操作日志')
  } catch {
    ElMessage.error('导出失败，请重试')
  }
}

onMounted(async () => {
  // 登记可配置列（顺序即默认顺序；fixed 列不参与配置）
  colcfg.register([
    { key: 'issueNo', label: '问题编号' },
    { key: 'submitterName', label: '提交人' },
    { key: 'submitterDepartmentName', label: '提交部门' },
    { key: 'moduleName', label: '所属模块' },
    { key: 'categoryName', label: '问题分类' },
    { key: 'description', label: '问题描述' },
    { key: 'attachmentCount', label: '附件' },
    { key: 'createdAt', label: '提交时间' },
    { key: 'status', label: '问题状态' },
    { key: 'valueName', label: '问题价值' },
    { key: 'handlerName', label: '处理人' },
    { key: 'handledAt', label: '处理时间' },
    { key: 'handleRemark', label: '处理备注' },
    { key: 'pingcodeTask', label: 'PingCode' },
    { key: '操作', label: '操作', fixed: true },
  ])
  await Promise.all([dict.load(), deptStore.load(), loadHandlers(), load()])
})

watch(
  () => route.query,
  () => {
    // 支持从报表/建议跳转带入筛选（如 moduleId、submitter、status、departmentId）
    const q = route.query
    let touched = false
    if (q.moduleId) {
      filters.moduleId = Number(q.moduleId)
      touched = true
    }
    if (q.categoryId) {
      filters.categoryId = Number(q.categoryId)
      touched = true
    }
    if (q.submitter) {
      filters.submitter = String(q.submitter)
      touched = true
    }
    if (q.status) {
      filters.status = String(q.status)
      touched = true
    }
    if (q.departmentId) {
      filters.departmentId = Number(q.departmentId)
      touched = true
    }
    if (q.handler) {
      filters.handler = String(q.handler)
      touched = true
    }
    if (touched) {
      void load()
    }
  },
  { immediate: true },
)
</script>

<template>
  <div class="page">
    <!-- 权限外条数提示条 -->
    <div v-if="store.filteredOut > 0" class="scope-notice">
      <span aria-hidden="true">i</span>
      另有 {{ store.filteredOut }} 条问题不在您的可见范围（权限范围由服务端判定）
    </div>

    <!-- 筛选区：字段网格（每项竖排「标签在上、控件在下」）+ 底部操作行，可折叠 -->
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
        <div class="filter-inline__item filter-inline__item--range">
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
          <span class="filter-inline__label">提交人</span>
          <el-input v-model="filters.submitter" placeholder="姓名/账号" style="width: 148px" clearable @change="load" />
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">部门</span>
          <el-select
            v-model="filters.departmentId"
            :placeholder="deptLocked ? lockedDeptName : '全部部门'"
            :disabled="deptLocked"
            clearable
            style="width: 140px"
            @change="load"
          >
            <el-option v-for="d in deptStore.list" :key="d.id" :label="d.name" :value="d.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">模块</span>
          <el-select v-model="filters.moduleId" placeholder="全部模块" clearable style="width: 140px" @change="load">
            <el-option v-for="it in dict.allOf('module')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">分类</span>
          <el-select v-model="filters.categoryId" placeholder="全部分类" clearable style="width: 140px" @change="load">
            <el-option v-for="it in dict.allOf('category')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">状态</span>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 132px" @change="load">
            <el-option v-for="s in STATUS_OPTIONS" :key="s.value" :label="s.label" :value="s.value" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">价值</span>
          <el-select v-model="filters.valueId" placeholder="全部价值" clearable style="width: 132px" @change="load">
            <el-option v-for="it in dict.allOf('value')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">处理人</span>
          <el-select v-model="filters.handler" placeholder="全部处理人" clearable filterable style="width: 148px" @change="load">
            <el-option
              v-for="h in handlerOptions"
              :key="h.name"
              :label="h.name + '（' + h.count + '）'"
              :value="h.id + ':' + h.name"
            />
          </el-select>
        </div>
        <div class="filter-inline__item filter-inline__item--keyword">
          <el-input
            v-model="filters.keyword"
            placeholder="关键字（编号 / 描述）"
            style="width: 220px"
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
          <ColumnConfig ref="colcfgRef" :config="colcfg" title="问题列表" />
          <el-button
            v-permission="['售后服务人员', '销售经理', '管理员']"
            type="default"
            @click="onExport"
          >
            导出 Excel
          </el-button>
        </div>
      </div>

      <div class="moon-table">
        <el-table
        @header-dragend="colw.onDragend" border v-loading="store.loading" :data="store.list" row-key="id" stripe @sort-change="onSortChange">
          <template #empty>
            <EmptyIllustration :size="130" text="暂无问题数据" sub-text="可以调整筛选条件，或先去提交一条问题" />
          </template>

          <!-- 列按「列配置」的顺序与显隐动态渲染，因此调整顺序能真正作用到表格。
               注：操作列固定在最右，不参与配置。 -->
          <el-table-column
            v-for="c in issueColumns"
            :key="c.key"
            :prop="c.key"
            :label="c.label"
            v-bind="colw.bind(c.colwKey, c.width !== undefined ? { width: c.width } : { minWidth: c.minWidth })"
            :align="c.align || 'left'"
            :sortable="c.sortable ? 'custom' : false"
            :show-overflow-tooltip="!!c.overflow"
          >
            <template #default="{ row }">
              <template v-if="c.key === 'issueNo'">
                <el-tooltip content="点击查看详情" placement="top" effect="light" :show-after="200">
                  <a class="cell-code cell-code--link cell-nowrap" @click="goDetail(row)">{{ row.issueNo }}</a>
                </el-tooltip>
              </template>

              <template v-else-if="c.key === 'submitterName'">
                <span class="cell-nowrap">{{ row.submitterName }}</span>
              </template>

              <template v-else-if="c.key === 'submitterDepartmentName'">
                <span class="cell-nowrap">{{ row.submitterDepartmentName || '未分配部门' }}</span>
              </template>

              <template v-else-if="c.key === 'moduleName'">
                <span class="cell-nowrap">{{ row.moduleName || '—' }}</span>
              </template>

              <template v-else-if="c.key === 'categoryName'">
                <span v-if="row.categoryName" class="cell-nowrap">{{ row.categoryName }}</span>
                <span v-else class="text-placeholder">未分类</span>
              </template>

              <template v-else-if="c.key === 'description'">
                <span class="cell-ellipsis">{{ row.description }}</span>
              </template>

              <template v-else-if="c.key === 'attachmentCount'">
                <span v-if="row.attachmentCount" class="cell-attach">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M16.5 6.5v9a4.5 4.5 0 0 1-9 0V7a3 3 0 0 1 6 0v8.2a1.5 1.5 0 0 1-3 0V8h1.6v7.2a.2.2 0 0 0 .4 0V7a1.6 1.6 0 1 0-3.2 0v8.5a3 3 0 1 0 6 0v-9h1.2Z" />
                  </svg>
                  {{ row.attachmentCount }}
                </span>
                <span v-else class="text-placeholder">—</span>
              </template>

              <template v-else-if="c.key === 'createdAt'">
                <span class="cell-nowrap">{{ fmtDateTime(row.createdAt) }}</span>
              </template>

              <template v-else-if="c.key === 'status'">
                <!-- 管理员：tag 展示 + 点击弹出下拉（在列表内直接改）；其它角色：只读 tag -->
                <el-dropdown
                  v-if="auth.isAdmin"
                  trigger="click"
                  @command="(v: string) => onStatusChange(row, v)"
                >
                  <span class="status-edit">
                    <IssueStatusTag :status="row.status" />
                    <el-icon class="status-edit__caret"><ArrowDown /></el-icon>
                  </span>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <el-dropdown-item
                        v-for="s in STATUS_OPTIONS"
                        :key="s.value"
                        :command="s.value"
                        :class="{ 'is-active-status': s.value === row.status }"
                      >
                        <IssueStatusTag :status="s.value" />
                      </el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
                <IssueStatusTag v-else :status="row.status" />
              </template>

              <template v-else-if="c.key === 'valueName'">
                <ValueTag :value-name="row.valueName" />
              </template>

              <template v-else-if="c.key === 'handlerName'">
                <span v-if="row.handlerName" class="cell-nowrap">{{ row.handlerName }}</span>
                <span v-else class="text-placeholder">—</span>
              </template>

              <template v-else-if="c.key === 'handledAt'">
                <span v-if="row.handledAt" class="cell-nowrap">{{ fmtDateTime(row.handledAt) }}</span>
                <span v-else class="text-placeholder">暂未处理</span>
              </template>

              <template v-else-if="c.key === 'handleRemark'">
                <span v-if="row.handleRemark" class="cell-ellipsis">{{ row.handleRemark }}</span>
                <span v-else class="text-placeholder">—</span>
              </template>

              <!-- PingCode 同步状态：已同步显示工作项编号链接，未同步显示「未同步」 -->
              <template v-else-if="c.key === 'pingcodeTask'">
                <el-tooltip
                  v-if="row.pingcodeTaskId"
                  :content="`同步时间：${row.pingcodeSyncedAt ? fmtDateTime(row.pingcodeSyncedAt) : '—'}${row.pingcodeAssigneeName ? `｜BUG 处理人：${row.pingcodeAssigneeName}` : ''}`"
                  placement="top"
                  effect="light"
                  :show-after="200"
                >
                  <a
                    v-if="row.pingcodeTaskUrl"
                    class="cell-code cell-code--link cell-nowrap"
                    :href="row.pingcodeTaskUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                  >{{ row.pingcodeTaskNo || '查看任务' }}</a>
                  <span v-else class="cell-nowrap">{{ row.pingcodeTaskNo || '已同步' }}</span>
                </el-tooltip>
                <span v-else class="text-placeholder">未同步</span>
              </template>
            </template>
          </el-table-column>

          <el-table-column label="操作" :width="colw.bind('操作', { width: 240 }).width" fixed="right" align="center">
            <template #default="{ row }">
              <div class="cell--actions">
                <el-button
                  link
                  type="primary"
                  class="btn-action-main"
                  :class="{ 'is-hidden': !canAct() }"
                  @click="onAction(row)"
                >
                  {{ actionLabel(row) }}
                </el-button>
                <!-- 同步到 PingCode：仅开发工程师/管理员；已同步的不再显示（编号由「PingCode」列承载） -->
                <el-button
                  v-if="canSyncPingcode && !row.pingcodeTaskId"
                  link
                  type="primary"
                  class="btn-action-sync"
                  @click="onSyncOne(row)"
                >
                  同步到 PingCode
                </el-button>
                <el-button
                  v-if="auth.isAdmin"
                  link
                  type="danger"
                  class="btn-action-detail"
                  @click="onDelete(row)"
                >
                  删除
                </el-button>
              </div>
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

    <HandleDialog v-model="handleVisible" :issue="currentIssue" @saved="onHandled" />

    <!-- 同步到 PingCode：仅单条（在操作栏逐条发起） -->
    <IssuePingcodeSyncDialog
      v-model="syncDialogVisible"
      :ids="syncIds"
      :default-title="syncDefaultTitle"
      :default-description="syncDefaultDescription"
      @done="onSyncDone"
    />
  </div>
</template>

<style scoped>
/* 筛选面板：头部（标题 + 折叠开关）+ 内联字段行 */
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

/* 内联字段行：单行铺开，放不下才换行；每项“标签 + 控件”横排 */
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

/* 状态列：tag + 下拉触发区 */
.status-edit {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  outline: none;
}

.status-edit__caret {
  font-size: 12px;
  color: var(--color-text-placeholder);
}

.status-edit:hover .status-edit__caret {
  color: var(--color-primary);
}

/* 下拉项内直接展示 tag，当前状态高亮 */
.is-active-status {
  background: var(--color-primary-tint);
}

/* 操作按钮：推到行尾 */
.filter-inline__actions {
  margin-left: auto;
}

/* 窄屏下操作按钮可换行到下一行并右对齐 */
@media (max-width: 1200px) {
  .filter-inline__actions {
    margin-left: auto;
  }
}

/* 页头已移除，收紧首个卡片（或提示条）的顶部间距 */
.page > :first-child {
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.text-placeholder {
  color: var(--color-text-placeholder);
}

.cell-code {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.cell-attach {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-weight: 600;
  color: var(--color-text-regular);
}

/* 操作列按钮：统一为文字（link）按钮。主操作固定宽度，
 * 保证「处理/追加处理」与「删除」跨行对齐（详情入口已改为点击问题编号）。 */
.btn-action-main {
  width: 58px;
  justify-content: center;
  padding: 0;
}

/* 不可用时保留占位（不可见），保证「删除」跨行对齐 */
.btn-action-main.is-hidden {
  visibility: hidden;
}

.btn-action-detail {
  justify-content: center;
  padding: 0;
}

/* 同步到 PingCode：文字按钮，不给固定宽，让操作列自然适配 */
.btn-action-sync {
  justify-content: center;
  padding: 0;
  white-space: nowrap;
}

/* 操作列变宽后，保持按钮不换行挤压 */
.cell--actions {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: nowrap;
  gap: 4px;
}

/* 描述列：文本截断 + 附件数内联（避免占一整列） */
.desc-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.desc-cell .cell-ellipsis {
  flex: 1;
  min-width: 0;
}

.desc-cell__attach {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  flex: 0 0 auto;
  color: var(--color-text-secondary);
  font-size: 13px;
}
</style>
