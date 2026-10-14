<script setup lang="ts">
/**
 * 需求列表页（按环节复用，样式与写法对齐问题列表页）。
 * stage 由路由 props 注入则按环节过滤；mine=true 时只看自己提交的。
 * - 顶部筛选：可折叠筛选面板（优先级 / 模块 / 状态 / 关键字）
 * - 列宽拖动记忆（colw）+ 列配置（colcfg，显隐与顺序），与问题列表一致
 * - 需求池默认按优先级排序（紧急 > 高 > 中 > 低，同级按提交先后）
 * - 行内操作按角色与状态动态渲染（文字按钮），操作列固定在最右
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import * as XLSX from 'xlsx'
import { useDictStore } from '@/stores/dict'
import { useAuthStore } from '@/stores/auth'
import { battlePublishDevRequest, developDevRequest, fetchDevRequests, fetchPingcodeOptions, setDevRequestPriority } from '@/api/devreq'
import type { PingcodeOption } from '@/api/devreq'
import { exportReleasedLog } from '@/api/log'
import { DEV_PRIORITY_CLASS, DEV_PRIORITY_OPTIONS, DEV_STATUS_CLASS, DEV_STATUS_OPTIONS } from '@/constants/devreq'
import { canDevReq, canReviewReq, isAdmin, ROLE_PM } from '@/constants/user'
import DevRequestActions from '@/components/DevRequestActions.vue'
import PingcodeSyncDialog from '@/components/PingcodeSyncDialog.vue'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import ColumnConfig from '@/components/ColumnConfig.vue'
import { fileStamp, fmtDateTime } from '@/utils/format'
import { useColumnWidths } from '@/composables/useColumnWidths'
import { useColumnConfig } from '@/composables/useColumnConfig'
import type { DevPriority, DevQuery, DevRequest, DevStatus } from '@/types'

const props = defineProps<{ stage?: DevStatus; mine?: boolean; released?: boolean }>()

const auth = useAuthStore()

/**
 * 「我的需求」页管理员的可见范围切换（仅管理员展示，记忆选择）：
 * all = 查看全部（默认，展示全公司所有人的需求），mine = 只看我的。
 */
const ADMIN_VIEW_KEY = 'command_dev_reqs_admin_view'
const adminView = ref<'all' | 'mine'>(
  localStorage.getItem(ADMIN_VIEW_KEY) === 'mine' ? 'mine' : 'all',
)
/** 仅管理员在「我的需求」页显示范围切换 */
const showScopeSwitch = computed(() => props.mine === true && auth.isAdmin)
/** 是否按「只看我的」过滤：管理员切到「只看我的」时为真，其余情况沿用路由的 mine 参数 */
const scopeMine = computed(() =>
  showScopeSwitch.value ? adminView.value === 'mine' : props.mine === true,
)

function onAdminViewChange(): void {
  localStorage.setItem(ADMIN_VIEW_KEY, adminView.value)
  page.value = 1
  void load()
}

/**
 * 本列表页的存储标识（列宽记忆 / 列配置共用）。
 * 本组件被 4 个环节页复用（我的需求/评审池/需求池/开发中心）：
 * 路由侧已给各页独立的组件实例（router-view 按 route.name 加 key），
 * 这里只需在实例创建时按当前页取一个固定标识，页与页之间互不影响。
 */
const tableId = (() => {
  if (props.released) return 'dev-released'
  if (props.mine) return 'dev-mine'
  switch (props.stage) {
    case '评审池':
      return 'dev-review'
    case '需求池':
      return 'dev-pool'
    case '开发中心':
      return 'dev-battle'
    default:
      return 'dev-all'
  }
})()

/** 表格列宽记忆（按「用户 + 页面」隔离，拖动后自动记住） */
const colw = useColumnWidths(tableId)

/** 列配置（按「用户 + 页面」隔离）：控制列的显隐与顺序 */
const colcfg = useColumnConfig(tableId)

/**
 * 列定义表：key → 渲染属性。
 * 列宽记忆直接以列 key 为键（与 useColumnWidths 的列 prop 一一对应）。
 */
interface DevColDef {
  label: string
  width?: number
  minWidth?: number
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
  overflow?: boolean
}

const DEV_COL_DEFS: Record<string, DevColDef> = {
  reqNo: { label: '需求编号', width: 200 },
  title: { label: '需求标题', minWidth: 180 },
  pingcodeTask: { label: 'PingCode', width: 110 },
  assigneeName: { label: '负责人', width: 100 },
  moduleName: { label: '模块', width: 80 },
  priority: { label: '优先级', width: 78, align: 'center', sortable: true },
  status: { label: '状态', width: 100, align: 'center' },
  submitterName: { label: '提交人', width: 96 },
  submitterDepartmentName: { label: '提交部门', width: 116 },
  createdAt: { label: '提交时间', width: 150, sortable: true, align: 'center' },
  releasedAt: { label: '上线时间', width: 150, sortable: true, align: 'center' },
}

const dict = useDictStore()
const router = useRouter()
const route = useRoute()

const loading = ref(false)
const items = ref<DevRequest[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(10)
const showStatusColumn = computed(() => !props.stage)
/** 开发中心页：展示 PingCode 列与批量发布（其余页面不展示） */
const isBattleStage = computed(() => props.stage === '开发中心')
/** 需求池页：展示批量「开发」操作（开发工程师/管理员） */
const isPoolStage = computed(() => props.stage === '需求池')
/** 批量勾选适用于需求池/开发中心，且需具备开发权限（开发工程师/管理员） */
const canBatchDevelop = computed(() => canDevReq(auth.roles))
/** 选择列可选状态：需求池页=需求池，开发中心页=开发中心 */
function isSelectable(r: DevRequest): boolean {
  return isPoolStage.value ? r.status === '需求池' : r.status === '开发中心'
}
/** 发布上线记录页：展示上线时间列 + 时间筛选，不展示操作列 */
const isReleasedStage = computed(() => props.released === true)

/**
 * 按「列配置」的顺序与显隐生成实际渲染的列。
 * 条件列：状态列只在「我的需求」等未按环节过滤的页面展示；
 * PingCode 列在「开发中心」与「发布上线」展示；上线时间列仅在「发布上线」记录页展示。
 */
const devColumns = computed(() =>
  colcfg
    .ordered()
    .filter((c) => {
      if (!c.visible || !DEV_COL_DEFS[c.key]) {
        return false
      }
      if (c.key === 'status' && !showStatusColumn.value) {
        return false
      }
      // PingCode 同步列：开发中心 + 发布上线记录页展示
      if (c.key === 'pingcodeTask' && !isBattleStage.value && !isReleasedStage.value) {
        return false
      }
      // 负责人列：开发中心 + 发布上线记录页展示
      if (c.key === 'assigneeName' && !isBattleStage.value && !isReleasedStage.value) {
        return false
      }
      if (c.key === 'releasedAt' && !isReleasedStage.value) {
        return false
      }
      return true
    })
    .map((c) => ({ key: c.key, ...DEV_COL_DEFS[c.key] })),
)

const stageLabel = computed(() => {
  if (showScopeSwitch.value && adminView.value === 'all') {
    return '全部需求'
  }
  return props.mine ? '我的需求' : (props.stage ?? '全部需求')
})
const stageDesc = computed(() => {
  if (showScopeSwitch.value && adminView.value === 'all') {
    return '管理员视角：全公司所有人的需求与最新进度（可切换为「只看我的」）。'
  }
  switch (props.stage) {
    case '评审池':
      return '所有新需求在此等待评审：通过即进入需求池，待定暂留本池（可再改判），驳回则需求作废。'
    case '需求池':
      return '评审通过的需求在此集结（按优先级排序），开发工程师从中挑选任务点「开发」进入开发中心。'
    case '开发中心':
      return '开发工程师完成开发后点「发布上线」立即上线（开发工程师或管理员，可批量）。'
    case '已上线':
      return props.released
        ? '已上线需求记录（按上线时间倒序）：支持按上线时间筛选。'
        : '已上线需求。'
    default:
      return '您提交的全部需求与最新进度。'
  }
})

const filters = reactive({
  keyword: '',
  priority: '' as DevPriority | '',
  status: '' as DevStatus | '',
  moduleId: undefined as number | undefined,
  /** 负责人筛选（PingCode 成员 id） */
  assigneeId: '' as string,
  /** 上线时间范围（发布上线记录页） */
  releasedStart: '',
  releasedEnd: '',
})

/** PingCode 项目成员（用于「负责人」筛选下拉；开发中心/发布上线记录页） */
const assigneeOptions = ref<PingcodeOption[]>([])
/** 当前页是否展示「负责人」筛选与列：开发中心 + 发布上线记录页 */
const showAssignee = computed(() => isBattleStage.value || isReleasedStage.value)
async function loadAssigneeOptions(): Promise<void> {
  if (assigneeOptions.value.length) return
  try {
    const res = await fetchPingcodeOptions()
    assigneeOptions.value = res.members
  } catch {
    assigneeOptions.value = []
  }
}

/** 需求池可批量「开发」的勾选行 */
const developableSelected = computed(() => selectedRows.value.filter((r) => r.status === '需求池'))

/** 批量开发（需求池）：勾选后逐条「开发」（进入开发中心，开发工程师/管理员） */
const batchDevelopBusy = ref(false)
async function onBatchDevelop(): Promise<void> {
  const ids = developableSelected.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先勾选要开发的需求（仅需求池状态）')
    return
  }
  await ElMessageBox.confirm(
    `确定将勾选的 ${ids.length} 条需求进入开发吗？确认后它们将进入开发中心。`,
    '批量开发',
    { confirmButtonText: '确定开发', cancelButtonText: '取消', type: 'info' },
  )
  batchDevelopBusy.value = true
  try {
    let ok = 0
    let fail = 0
    for (const id of ids) {
      try {
        await developDevRequest(id)
        ok += 1
      } catch {
        fail += 1
      }
    }
    ElMessage.success(`已进入开发中心 ${ok} 条${fail > 0 ? `，${fail} 条失败` : ''}`)
    selectedRows.value = []
    await load()
  } finally {
    batchDevelopBusy.value = false
  }
}

/** 批量发布（开发中心）：勾选后一次性发布上线（直接上线） */
const selectedRows = ref<DevRequest[]>([])
const batchBusy = ref(false)
function onSelectionChange(rows: DevRequest[]): void {
  selectedRows.value = rows
}
/** 只有「开发中心」状态的行参与批量发布（选择列已限制在本页，这里再兜底） */
const publishableSelected = computed(() => selectedRows.value.filter((r) => r.status === '开发中心'))
async function onBatchPublish(): Promise<void> {
  const ids = publishableSelected.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先勾选要发布的需求（仅开发中心状态）')
    return
  }
  await ElMessageBox.confirm(
    `确定将勾选的 ${ids.length} 条需求发布上线吗？发布后立即上线（记录上线时间），不可撤回。`,
    '批量发布上线',
    { confirmButtonText: '确定发布', cancelButtonText: '取消', type: 'warning' },
  )
  batchBusy.value = true
  try {
    const res = await battlePublishDevRequest(ids)
    ElMessage.success(`已上线 ${res.affected} 条${res.skipped > 0 ? `，${res.skipped} 条已跳过` : ''}`)
    selectedRows.value = []
    await load()
  } finally {
    batchBusy.value = false
  }
}

/** 批量同步任务到 PingCode（开发中心）：弹窗填写关键字段后一次同步；已同步的自动跳过 */
const syncBusy = ref(false)
const syncDialogVisible = ref(false)
/** 可同步的行：开发中心且尚未同步 */
const syncableSelected = computed(() =>
  selectedRows.value.filter((r) => r.status === '开发中心' && !r.pingcodeTaskId),
)
function onBatchSync(): void {
  const ids = syncableSelected.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先勾选要同步的需求（仅开发中心且未同步的）')
    return
  }
  if (syncBusy.value) return
  syncDialogVisible.value = true
}

function onSyncDone(): void {
  selectedRows.value = []
  void load()
}

/** 筛选区折叠（记忆用户选择，与问题列表一致） */
const FILTER_COLLAPSE_KEY = 'command_dev_reqs_filter_collapsed'
const filterCollapsed = ref(localStorage.getItem(FILTER_COLLAPSE_KEY) === '1')
function toggleFilter(): void {
  filterCollapsed.value = !filterCollapsed.value
  localStorage.setItem(FILTER_COLLAPSE_KEY, filterCollapsed.value ? '1' : '0')
}

/** 隐藏待定开关（我的需求 / 评审池；记忆用户选择，默认开启=隐藏） */
const HIDE_PENDING_KEY = 'command_dev_reqs_hide_pending'
const hidePending = ref(localStorage.getItem(HIDE_PENDING_KEY) !== '0')
/** 开关适用的页面：我的需求 + 评审池（评审池待办以未评审的为主，待定默认收起） */
const showHidePending = computed(() => props.mine === true || props.stage === '评审池')
function onToggleHidePending(): void {
  localStorage.setItem(HIDE_PENDING_KEY, hidePending.value ? '1' : '0')
  page.value = 1
  void load()
}

/** 已选筛选条件数量（折叠时提示用） */
const activeFilterCount = computed(() => {
  let n = 0
  if (filters.priority) n += 1
  if (filters.status) n += 1
  if (filters.moduleId) n += 1
  if (filters.assigneeId) n += 1
  if (filters.releasedStart || filters.releasedEnd) n += 1
  if (filters.keyword) n += 1
  return n
})

/** 发布上线记录页默认按上线时间倒序；需求池默认按优先级；其余按提交时间倒序 */
const sortField = ref<'createdAt' | 'priority' | 'releasedAt'>(
  props.released ? 'releasedAt' : props.stage === '需求池' ? 'priority' : 'createdAt',
)
const sortOrder = ref<'ASC' | 'DESC'>('DESC')

/** 列表查询参数（列表加载与导出共用，保证导出范围与当前筛选一致） */
function buildQuery(): DevQuery {
  return {
    status: props.stage ?? (filters.status || undefined),
    mine: scopeMine.value ? '1' : undefined,
    hidePending: showHidePending.value && hidePending.value ? '1' : undefined,
    keyword: filters.keyword.trim() || undefined,
    priority: filters.priority || undefined,
    moduleId: filters.moduleId,
    assigneeId: showAssignee.value ? filters.assigneeId || undefined : undefined,
    releasedStart: isReleasedStage.value ? filters.releasedStart || undefined : undefined,
    releasedEnd: isReleasedStage.value ? filters.releasedEnd || undefined : undefined,
    sortField: sortField.value,
    sortOrder: sortOrder.value,
  }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchDevRequests({
      ...buildQuery(),
      page: page.value,
      pageSize: pageSize.value,
    })
    items.value = res.items
    total.value = res.total
  } catch {
    items.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

/**
 * 导出 Excel（发布上线记录页）：按当前筛选导出全部记录（不只当前页），
 * 导出动作写操作日志（与问题列表导出同模式）。
 */
const exporting = ref(false)
async function onExport(): Promise<void> {
  if (!items.value.length && total.value === 0) {
    ElMessage.warning('当前筛选没有可导出的数据')
    return
  }
  exporting.value = true
  try {
    // 循环拉取全部页（每页 200，上限保护 50 页 = 1 万条）
    const all: DevRequest[] = []
    let p = 1
    for (; p <= 50; p += 1) {
      const res = await fetchDevRequests({ ...buildQuery(), page: p, pageSize: 200 })
      all.push(...res.items)
      if (all.length >= res.total || res.items.length === 0) {
        break
      }
    }
    const rows = all.map((r) => ({
      需求编号: r.reqNo,
      需求标题: r.title,
      模块: r.moduleName || '—',
      优先级: r.priority,
      负责人: r.assigneeName || '—',
      提交人: r.submitterName,
      提交部门: r.submitterDepartmentName || '未分配部门',
      提交时间: fmtDateTime(r.createdAt),
      上线时间: fmtDateTime(r.releasedAt),
      发布人: r.releasedBy || '—',
    }))
    if (!rows.length) {
      ElMessage.warning('当前筛选没有可导出的数据')
      return
    }
    const sheet = XLSX.utils.json_to_sheet(rows)
    sheet['!cols'] = [
      { wch: 20 }, { wch: 28 }, { wch: 10 }, { wch: 8 }, { wch: 10 }, { wch: 10 },
      { wch: 14 }, { wch: 18 }, { wch: 18 }, { wch: 10 },
    ]
    const book = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(book, sheet, '发布上线记录')
    XLSX.writeFile(book, `发布上线记录_${fileStamp()}.xlsx`)
    await exportReleasedLog(rows.length)
    ElMessage.success(`导出完成（共 ${rows.length} 条），导出动作已记入操作日志`)
  } catch {
    ElMessage.error('导出失败，请重试')
  } finally {
    exporting.value = false
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
  filters.moduleId = undefined
  filters.assigneeId = ''
  filters.releasedStart = ''
  filters.releasedEnd = ''
  page.value = 1
  void load()
}

function onPageChange(p: number): void {
  page.value = p
  void load()
}

function onSizeChange(s: number): void {
  pageSize.value = s
  page.value = 1
  void load()
}

function onSortChange(payload: { prop: string; order: string | null }): void {
  if (!payload.order) {
    return
  }
  if (payload.prop === 'priority') {
    sortField.value = 'priority'
  } else if (payload.prop === 'releasedAt') {
    sortField.value = 'releasedAt'
  } else {
    sortField.value = 'createdAt'
  }
  sortOrder.value = payload.order === 'ascending' ? 'ASC' : 'DESC'
  void load()
}

function goDetail(row: DevRequest): void {
  void router.push(`/dev/reqs/${row.id}`)
}

/* —— 优先级行内调整（评审委员 / 产品经理 / 管理员；任意环节可调） —— */
/** 是否可调整优先级：与后端 devreqs/priority 接口权限保持一致（评审委员/产品经理/管理员） */
const canEditPriority = computed(() => canReviewReq(auth.roles) || auth.hasRole([ROLE_PM]))
const prioritySavingId = ref(0)

/** 优先级修改命令（下拉选中）；失败时提示并保持原值（load 重新拉取兜底） */
async function onPriorityChange(row: DevRequest, priority: string): Promise<void> {
  if (priority === row.priority) {
    return
  }
  prioritySavingId.value = row.id
  try {
    await setDevRequestPriority(row.id, priority)
    // 本地更新，避免整表刷新导致滚动位置丢失
    row.priority = priority as DevPriority
    ElMessage.success(`「${row.title}」优先级已调整为「${priority}」`)
  } catch {
    // 请求层已统一提示；重新拉取保证与服务端一致
    await load()
  } finally {
    prioritySavingId.value = 0
  }
}

/** 登记可配置列（顺序即默认顺序；fixed 列不参与配置）。页面切换（键变化）时需重新登记 */
function registerColumns(): void {
  colcfg.register([
    { key: 'reqNo', label: '需求编号' },
    { key: 'title', label: '需求标题' },
    { key: 'pingcodeTask', label: 'PingCode' },
    { key: 'assigneeName', label: '负责人' },
    { key: 'moduleName', label: '模块' },
    { key: 'priority', label: '优先级' },
    { key: 'status', label: '状态' },
    { key: 'submitterName', label: '提交人' },
    { key: 'submitterDepartmentName', label: '提交部门' },
    { key: 'createdAt', label: '提交时间' },
    { key: 'releasedAt', label: '上线时间' },
    { key: '操作', label: '操作', fixed: true },
  ])
}

onMounted(() => {
  registerColumns()
  void dict.load()
  void load()
})

watch(
  () => route.query,
  () => {
    // 支持跳转带入状态筛选（仅「我的需求」页生效）
    const q = route.query
    if (!props.stage && q.status) {
      filters.status = String(q.status) as DevStatus
      void load()
    }
  },
)
</script>

<template>
  <div class="page">
    <!-- 环节说明提示条（样式同问题列表的 scope-notice） -->
    <div class="scope-notice">
      <span aria-hidden="true">i</span>
      {{ stageLabel }}：{{ stageDesc }}
    </div>

    <!-- 筛选区：字段网格（每项「标签 + 控件」横排）+ 底部操作行，可折叠 -->
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
          <span class="filter-inline__label">优先级</span>
          <el-select v-model="filters.priority" placeholder="全部优先级" clearable style="width: 132px" @change="load">
            <el-option v-for="p in DEV_PRIORITY_OPTIONS" :key="p" :label="p" :value="p" />
          </el-select>
        </div>
        <div class="filter-inline__item">
          <span class="filter-inline__label">模块</span>
          <el-select v-model="filters.moduleId" placeholder="全部模块" clearable style="width: 140px" @change="load">
            <el-option v-for="it in dict.allOf('module')" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
        </div>
        <!-- 开发中心 / 发布上线记录页：按负责人筛选（PingCode 成员） -->
        <div v-if="showAssignee" class="filter-inline__item">
          <span class="filter-inline__label">负责人</span>
          <el-select
            v-model="filters.assigneeId"
            placeholder="全部负责人"
            clearable
            filterable
            style="width: 150px"
            @change="load"
            @visible-change="(v: boolean) => v && loadAssigneeOptions()"
          >
            <el-option v-for="m in assigneeOptions" :key="m.id" :label="m.name" :value="m.id" />
          </el-select>
        </div>
        <!-- 开发中心 / 需求池等：按状态筛选 -->
        <div v-if="showStatusColumn" class="filter-inline__item">
          <span class="filter-inline__label">状态</span>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 132px" @change="load">
            <el-option v-for="s in DEV_STATUS_OPTIONS" :key="s" :label="s" :value="s" />
          </el-select>
        </div>
        <!-- 发布上线记录页：按上线时间范围筛选 -->
        <template v-if="isReleasedStage">
          <div class="filter-inline__item">
            <span class="filter-inline__label">上线时间</span>
            <el-date-picker
              v-model="filters.releasedStart"
              type="date"
              placeholder="开始日期"
              value-format="YYYY-MM-DD"
              style="width: 148px"
              @change="load"
            />
            <span class="filter-inline__label">至</span>
            <el-date-picker
              v-model="filters.releasedEnd"
              type="date"
              placeholder="结束日期"
              value-format="YYYY-MM-DD"
              style="width: 148px"
              @change="load"
            />
          </div>
        </template>
        <div class="filter-inline__item filter-inline__item--keyword">
          <el-input
            v-model="filters.keyword"
            placeholder="关键字（编号 / 标题 / 描述）"
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
          <el-button type="primary" @click="onSearch">查询</el-button>
        </div>
      </div>
    </div>

    <!-- 表格 -->
    <div class="moon-card moon-card--tight">
      <div class="toolbar toolbar--actions-only">
        <div class="toolbar__actions">
          <!-- 我的需求（管理员）：查看全部 / 只看我的（默认查看全部，记忆选择） -->
          <el-radio-group
            v-if="showScopeSwitch"
            v-model="adminView"
            size="small"
            @change="onAdminViewChange"
          >
            <el-radio-button value="all">查看全部</el-radio-button>
            <el-radio-button value="mine">只看我的</el-radio-button>
          </el-radio-group>
          <!-- 我的需求 / 评审池：隐藏评审待定的需求（默认开启，记忆选择） -->
          <el-switch
            v-if="showHidePending"
            v-model="hidePending"
            active-text="隐藏待定"
            :width="36"
            @change="onToggleHidePending"
          />
          <!-- 需求池：批量开发（开发工程师/管理员，进入开发中心） -->
          <el-button
            v-if="isPoolStage && canBatchDevelop"
            type="primary"
            :disabled="!developableSelected.length"
            :loading="batchDevelopBusy"
            @click="onBatchDevelop"
          >批量开发{{ developableSelected.length ? `（${developableSelected.length}）` : '' }}</el-button>
          <!-- 开发中心：批量同步任务到 PingCode（弹窗填写关键字段） -->
          <el-button
            v-if="isBattleStage"
            :disabled="!syncableSelected.length"
            :loading="syncBusy"
            @click="onBatchSync"
          >批量同步任务{{ syncableSelected.length ? `（${syncableSelected.length}）` : '' }}</el-button>
          <!-- 开发中心：批量发布上线 -->
          <el-button
            v-if="isBattleStage"
            type="primary"
            :disabled="!publishableSelected.length"
            :loading="batchBusy"
            @click="onBatchPublish"
          >批量发布上线{{ publishableSelected.length ? `（${publishableSelected.length}）` : '' }}</el-button>
          <!-- 发布上线记录页：导出 Excel（按当前筛选导出全部） -->
          <el-button
            v-if="isReleasedStage"
            type="default"
            :loading="exporting"
            @click="onExport"
          >导出 Excel</el-button>
          <ColumnConfig :config="colcfg" title="需求列表" />
          <el-button @click="load">刷新</el-button>
        </div>
      </div>

      <div class="moon-table">
        <el-table
          @header-dragend="colw.onDragend"
          border
          stripe
          v-loading="loading"
          :data="items"
          row-key="id"
          @sort-change="onSortChange"
          @selection-change="onSelectionChange"
        >
          <el-table-column v-if="isPoolStage || isBattleStage" type="selection" width="40" :selectable="isSelectable" />

          <template #empty>
            <EmptyIllustration :size="130" text="暂无需求数据" sub-text="可以调整筛选条件，或先去提交一条需求" />
          </template>

          <!-- 列按「列配置」的顺序与显隐动态渲染，因此调整顺序能真正作用到表格。
               注：操作列固定在最右，不参与配置。 -->
          <el-table-column
            v-for="c in devColumns"
            :key="c.key"
            :prop="c.key"
            :label="c.label"
            v-bind="colw.bind(c.key, c.width !== undefined ? { width: c.width } : { minWidth: c.minWidth })"
            :align="c.align || 'left'"
            :sortable="c.sortable ? 'custom' : false"
            :show-overflow-tooltip="!!c.overflow"
          >
            <template #default="{ row }">
              <template v-if="c.key === 'reqNo'">
                <span class="reqno-cell">
                  <el-tooltip content="点击查看详情" placement="top" effect="light" :show-after="200">
                    <a class="cell-code cell-code--link cell-nowrap" @click="goDetail(row)">{{ row.reqNo }}</a>
                  </el-tooltip>
                  <!-- 评审待定标记：紧随需求编号展示（tag 样式，悬停看备注） -->
                  <el-tooltip
                    v-if="row.reviewPending"
                    :content="`待定备注：${row.reviewComment || ''}`"
                    placement="top"
                    effect="light"
                    :show-after="200"
                  >
                    <el-tag type="warning" effect="light" size="small" class="pending-tag">待定</el-tag>
                  </el-tooltip>
                </span>
              </template>

              <template v-else-if="c.key === 'title'">
                <span class="title-cell">
                  <el-tooltip :content="row.title" placement="top" effect="light" :show-after="200">
                    <span class="cell-ellipsis">{{ row.title }}</span>
                  </el-tooltip>
                </span>
              </template>

              <template v-else-if="c.key === 'moduleName'">
                <span class="cell-nowrap">{{ row.moduleName || '—' }}</span>
              </template>

              <!-- 负责人（同步到 PingCode 时选定的成员） -->
              <template v-else-if="c.key === 'assigneeName'">
                <span v-if="row.assigneeName" class="cell-nowrap">{{ row.assigneeName }}</span>
                <span v-else class="text-placeholder">—</span>
              </template>

              <!-- PingCode 同步状态（开发中心/发布上线展示：已同步显示工作项编号链接，未同步显示「未同步」） -->
              <template v-else-if="c.key === 'pingcodeTask'">
                <el-tooltip
                  v-if="row.pingcodeTaskId"
                  :content="row.pingcodeSyncedAt ? `同步时间：${fmtDateTime(row.pingcodeSyncedAt)}` : '已同步到 PingCode'"
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

              <template v-else-if="c.key === 'priority'">
                <!-- 可调整角色（评审委员/产品经理/管理员）：点击优先级下拉直接修改；其余角色只读展示 -->
                <el-dropdown
                  v-if="canEditPriority"
                  trigger="click"
                  :disabled="prioritySavingId === row.id"
                  @command="(v: string) => onPriorityChange(row, v)"
                >
                  <span class="priority-edit">
                    <span :class="DEV_PRIORITY_CLASS[row.priority as keyof typeof DEV_PRIORITY_CLASS]">{{ row.priority }}</span>
                    <el-icon class="priority-edit__caret"><ArrowDown /></el-icon>
                  </span>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <el-dropdown-item
                        v-for="p in DEV_PRIORITY_OPTIONS"
                        :key="p"
                        :command="p"
                        :class="{ 'is-active-priority': p === row.priority }"
                      >
                        <span :class="DEV_PRIORITY_CLASS[p]">{{ p }}</span>
                      </el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
                <span v-else :class="DEV_PRIORITY_CLASS[row.priority as keyof typeof DEV_PRIORITY_CLASS]">{{ row.priority }}</span>
              </template>

              <template v-else-if="c.key === 'status'">
                <span :class="DEV_STATUS_CLASS[row.status as keyof typeof DEV_STATUS_CLASS]">{{ row.status }}</span>
              </template>

              <template v-else-if="c.key === 'submitterName'">
                <span class="cell-nowrap">{{ row.submitterName }}</span>
              </template>

              <template v-else-if="c.key === 'submitterDepartmentName'">
                <span class="cell-nowrap">{{ row.submitterDepartmentName || '未分配部门' }}</span>
              </template>

              <template v-else-if="c.key === 'createdAt'">
                <span class="cell-nowrap">{{ fmtDateTime(row.createdAt) }}</span>
              </template>

              <!-- 发布上线记录页：上线时间（含操作人悬停提示） -->
              <template v-else-if="c.key === 'releasedAt'">
                <el-tooltip
                  v-if="row.releasedAt"
                  :content="`发布人：${row.releasedBy || '—'}`"
                  placement="top"
                  effect="light"
                  :show-after="200"
                >
                  <span class="cell-nowrap">{{ fmtDateTime(row.releasedAt) }}</span>
                </el-tooltip>
                <span v-else class="text-placeholder">—</span>
              </template>
            </template>
          </el-table-column>

          <!-- 操作列：编号列可点击进详情，不再重复放「详情」按钮。
               发布上线记录页（已上线终态）无操作，不展示该列。 -->
          <el-table-column
            v-if="!isReleasedStage"
            label="操作"
            :width="colw.bind('操作', { width: 220 }).width"
            fixed="right"
            align="center"
          >
            <template #default="{ row }">
              <div class="cell--actions">
                <DevRequestActions :req="row" link @refresh="load" />
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>

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

    <PingcodeSyncDialog
      v-model="syncDialogVisible"
      :ids="syncableSelected.map((r) => r.id)"
      @done="onSyncDone"
    />
  </div>
</template>

<style scoped>
/* 筛选面板：头部（标题 + 折叠开关）+ 内联字段行（与问题列表一致） */
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

/* 操作按钮：推到行尾 */
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

/* 空值占位（与各列表页一致） */
.text-placeholder {
  color: var(--color-text-placeholder);
}

/* 需求编号单元格：编号 + 待定 tag 并排（待定备注在 tag 的 tooltip 中展示） */
.reqno-cell {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

.reqno-cell .pending-tag {
  cursor: help;
}

/* 标题单元格：标题（超出省略，悬停看全文） */
.title-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.title-cell .cell-ellipsis {
  flex: 1;
  min-width: 0;
}

/* 优先级列：可调整角色点击胶囊下拉修改（与问题列表状态下拉同写法） */
.priority-edit {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  outline: none;
}

.priority-edit__caret {
  font-size: 12px;
  color: var(--color-text-placeholder);
}

.priority-edit:hover .priority-edit__caret {
  color: var(--color-primary);
}

/* 下拉项内直接展示胶囊，当前优先级高亮 */
.is-active-priority {
  background: var(--color-primary-tint);
}
</style>