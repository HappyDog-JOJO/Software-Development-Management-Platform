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
import { useDictStore } from '@/stores/dict'
import { battlePublishDevRequest, fetchDevCandidates, fetchDevRequests, fetchPmCandidates } from '@/api/devreq'
import { DEV_PRIORITY_CLASS, DEV_PRIORITY_OPTIONS, DEV_STATUS_CLASS, DEV_STATUS_OPTIONS } from '@/constants/devreq'
import DevRequestActions from '@/components/DevRequestActions.vue'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import ColumnConfig from '@/components/ColumnConfig.vue'
import { fmtDateTime } from '@/utils/format'
import { useColumnWidths } from '@/composables/useColumnWidths'
import { useColumnConfig } from '@/composables/useColumnConfig'
import type { DevCandidate, DevPriority, DevRequest, DevStatus } from '@/types'

const props = defineProps<{ stage?: DevStatus; mine?: boolean }>()

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('dev-reqs')

/** 列配置（按当前用户隔离）：控制列的显隐与顺序 */
const colcfg = useColumnConfig('dev-reqs')

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
  designUrl: { label: '设计文档', width: 110 },
  pmName: { label: '设计人', width: 100 },
  devLeaderName: { label: '负责人', width: 100 },
  moduleName: { label: '模块', width: 80 },
  priority: { label: '优先级', width: 78, align: 'center', sortable: true },
  status: { label: '状态', width: 100, align: 'center' },
  submitterName: { label: '提交人', width: 96 },
  submitterDepartmentName: { label: '提交部门', width: 116 },
  owner: { label: '处理人', minWidth: 120 },
  createdAt: { label: '提交时间', width: 150, sortable: true, align: 'center' },
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
/** 开发中心页：展示设计文档/设计人/负责人三列（其余页面不展示） */
const isBattleStage = computed(() => props.stage === '开发中心')

/**
 * 按「列配置」的顺序与显隐生成实际渲染的列。
 * 条件列：状态列只在「我的需求」等未按环节过滤的页面展示；
 * 处理人列在「我的需求」（提交人视角）与「评审池」（尚未分配负责人，只有待评审/待定）
 * 不展示——评审池的待定标记改在标题列展示，负责人明细看详情页；
 * 设计文档/设计人/负责人三列仅在「开发中心」展示（配合筛选）。
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
      if (c.key === 'owner' && (props.mine || props.stage === '评审池')) {
        return false
      }
      if (['designUrl', 'pmName', 'devLeaderName'].includes(c.key) && !isBattleStage.value) {
        return false
      }
      return true
    })
    .map((c) => ({ key: c.key, ...DEV_COL_DEFS[c.key] })),
)

const stageLabel = computed(() => (props.mine ? '我的需求' : (props.stage ?? '全部需求')))
const stageDesc = computed(() => {
  switch (props.stage) {
    case '评审池':
      return '所有新需求在此等待评审：通过即进入需求池，待定暂留本池（可再改判），驳回则需求作废。'
    case '需求池':
      return '评审通过的需求在此集结（按优先级排序），产品经理从中挑选任务认领设计。'
    case '设计中心':
      return '产品经理填写设计文档链接、指定开发分支与负责人。'
    case '开发中心':
      return '负责人按分支完成开发后点「发布上线」提交（负责人或管理员，可批量），进入发布上线列表等待正式发布。'
    case '待发布':
      return '开发完成的需求在此等待发布；评审委员执行发布后正式上线。'
    default:
      return '您提交的全部需求与最新进度。'
  }
})

const filters = reactive({
  keyword: '',
  priority: '' as DevPriority | '',
  status: '' as DevStatus | '',
  moduleId: undefined as number | undefined,
  pmId: undefined as number | undefined,
  devLeaderId: undefined as number | undefined,
})

/** 开发中心筛选候选：设计人（产品经理/管理员）与负责人（开发工程师/管理员） */
const pmOptions = ref<DevCandidate[]>([])
const leaderOptions = ref<DevCandidate[]>([])
async function loadCandidates(): Promise<void> {
  if (!isBattleStage.value) {
    return
  }
  try {
    const [pms, leaders] = await Promise.all([fetchPmCandidates(), fetchDevCandidates()])
    pmOptions.value = pms
    leaderOptions.value = leaders
  } catch {
    pmOptions.value = []
    leaderOptions.value = []
  }
}

/** 批量发布（开发中心）：勾选后一次提交到发布上线列表 */
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
    `确定将勾选的 ${ids.length} 条需求发布上线吗？发布后进入「发布上线」列表，等待正式发布。`,
    '批量发布上线',
    { confirmButtonText: '确定发布', cancelButtonText: '取消', type: 'warning' },
  )
  batchBusy.value = true
  try {
    const res = await battlePublishDevRequest(ids)
    ElMessage.success(`已提交 ${res.affected} 条到发布上线${res.skipped > 0 ? `，${res.skipped} 条已跳过` : ''}`)
    selectedRows.value = []
    await load()
  } finally {
    batchBusy.value = false
  }
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
  if (filters.pmId) n += 1
  if (filters.devLeaderId) n += 1
  if (filters.keyword) n += 1
  return n
})

/** 需求池默认按优先级排序，其余页面默认按提交时间倒序 */
const sortField = ref<'createdAt' | 'priority'>(props.stage === '需求池' ? 'priority' : 'createdAt')
const sortOrder = ref<'ASC' | 'DESC'>('DESC')

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchDevRequests({
      status: props.stage ?? (filters.status || undefined),
      mine: props.mine ? '1' : undefined,
      hidePending: showHidePending.value && hidePending.value ? '1' : undefined,
      keyword: filters.keyword.trim() || undefined,
      priority: filters.priority || undefined,
      moduleId: filters.moduleId,
      pmId: isBattleStage.value ? filters.pmId : undefined,
      devLeaderId: isBattleStage.value ? filters.devLeaderId : undefined,
      page: page.value,
      pageSize: pageSize.value,
      sortField: sortField.value,
      sortOrder: sortOrder.value,
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

function onSearch(): void {
  page.value = 1
  void load()
}

function onReset(): void {
  filters.keyword = ''
  filters.priority = ''
  filters.status = ''
  filters.moduleId = undefined
  filters.pmId = undefined
  filters.devLeaderId = undefined
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
  sortField.value = payload.prop === 'priority' ? 'priority' : 'createdAt'
  sortOrder.value = payload.order === 'ascending' ? 'ASC' : 'DESC'
  void load()
}

function goDetail(row: DevRequest): void {
  void router.push(`/dev/reqs/${row.id}`)
}

/** 环节 → 处理人说明：有专人负责时显示「角色：姓名」，等待接手或终态时显示环节状态 */
function ownerLabel(row: DevRequest): string {
  switch (row.status) {
    case '评审池':
      return '待评审'
    case '需求池':
      return '待认领'
    case '设计中心':
      return row.pmName ? `设计：${row.pmName}` : '待设计'
    case '开发中心':
      return row.devLeaderName ? `开发：${row.devLeaderName}` : '待开发'
    case '待发布':
      return '待发布'
    case '已上线':
      return '已上线'
    case '已驳回':
      return '评审驳回（作废）'
    default:
      return '已取消'
  }
}

onMounted(() => {
  // 登记可配置列（顺序即默认顺序；fixed 列不参与配置）
  colcfg.register([
    { key: 'reqNo', label: '需求编号' },
    { key: 'title', label: '需求标题' },
    { key: 'designUrl', label: '设计文档' },
    { key: 'pmName', label: '设计人' },
    { key: 'devLeaderName', label: '负责人' },
    { key: 'moduleName', label: '模块' },
    { key: 'priority', label: '优先级' },
    { key: 'status', label: '状态' },
    { key: 'submitterName', label: '提交人' },
    { key: 'submitterDepartmentName', label: '提交部门' },
    { key: 'owner', label: '处理人' },
    { key: 'createdAt', label: '提交时间' },
    { key: '操作', label: '操作', fixed: true },
  ])
  void dict.load()
  void loadCandidates()
  void load()
})

watch(
  () => props.stage,
  () => {
    page.value = 1
    if (props.stage === '需求池') {
      sortField.value = 'priority'
    }
    void load()
  },
)
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
        <!-- 开发中心：按设计人 / 负责人筛选 -->
        <div v-if="isBattleStage" class="filter-inline__item">
          <span class="filter-inline__label">设计人</span>
          <el-select v-model="filters.pmId" placeholder="全部设计人" clearable filterable style="width: 148px" @change="load">
            <el-option v-for="p in pmOptions" :key="p.id" :label="`${p.name}（${p.departmentName || p.account}）`" :value="p.id" />
          </el-select>
        </div>
        <div v-if="isBattleStage" class="filter-inline__item">
          <span class="filter-inline__label">负责人</span>
          <el-select v-model="filters.devLeaderId" placeholder="全部负责人" clearable filterable style="width: 148px" @change="load">
            <el-option v-for="d in leaderOptions" :key="d.id" :label="`${d.name}（${d.departmentName || d.account}）`" :value="d.id" />
          </el-select>
        </div>
        <div v-if="showStatusColumn" class="filter-inline__item">
          <span class="filter-inline__label">状态</span>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 132px" @change="load">
            <el-option v-for="s in DEV_STATUS_OPTIONS" :key="s" :label="s" :value="s" />
          </el-select>
        </div>
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
          <!-- 我的需求 / 评审池：隐藏评审待定的需求（默认开启，记忆选择） -->
          <el-switch
            v-if="showHidePending"
            v-model="hidePending"
            active-text="隐藏待定"
            :width="36"
            @change="onToggleHidePending"
          />
          <!-- 开发中心：批量发布上线 -->
          <el-button
            v-if="isBattleStage"
            type="primary"
            :disabled="!publishableSelected.length"
            :loading="batchBusy"
            @click="onBatchPublish"
          >批量发布上线{{ publishableSelected.length ? `（${publishableSelected.length}）` : '' }}</el-button>
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
          <el-table-column v-if="isBattleStage" type="selection" width="40" :selectable="(r: DevRequest) => r.status === '开发中心'" />

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
                <el-tooltip content="点击查看详情" placement="top" effect="light" :show-after="200">
                  <a class="cell-code cell-code--link cell-nowrap" @click="goDetail(row)">{{ row.reqNo }}</a>
                </el-tooltip>
              </template>

              <template v-else-if="c.key === 'title'">
                <span class="title-cell">
                  <el-tooltip :content="row.title" placement="top" effect="light" :show-after="200">
                    <span class="cell-ellipsis">{{ row.title }}</span>
                  </el-tooltip>
                  <!-- 评审池页处理人列隐藏后，待定标记改在此展示（悬停看备注） -->
                  <el-tooltip
                    v-if="props.stage === '评审池' && row.reviewPending"
                    :content="`待定备注：${row.reviewComment || ''}`"
                    placement="top"
                    effect="light"
                    :show-after="200"
                  >
                    <span class="owner-pending">待定</span>
                  </el-tooltip>
                </span>
              </template>

              <template v-else-if="c.key === 'moduleName'">
                <span class="cell-nowrap">{{ row.moduleName || '—' }}</span>
              </template>

              <!-- 开发中心专属列：设计文档链接（新窗口打开）/ 设计人 / 负责人 -->
              <template v-else-if="c.key === 'designUrl'">
                <a
                  v-if="row.designUrl"
                  class="cell-code cell-code--link cell-nowrap"
                  :href="row.designUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  title="打开设计文档"
                >设计文档</a>
                <span v-else class="text-placeholder">—</span>
              </template>

              <template v-else-if="c.key === 'pmName'">
                <span v-if="row.pmName" class="cell-nowrap">{{ row.pmName }}</span>
                <span v-else class="text-placeholder">—</span>
              </template>

              <template v-else-if="c.key === 'devLeaderName'">
                <span v-if="row.devLeaderName" class="cell-nowrap">{{ row.devLeaderName }}</span>
                <span v-else class="text-placeholder">—</span>
              </template>

              <template v-else-if="c.key === 'priority'">
                <span :class="DEV_PRIORITY_CLASS[row.priority as keyof typeof DEV_PRIORITY_CLASS]">{{ row.priority }}</span>
              </template>

              <template v-else-if="c.key === 'status'">
                <span class="status-cell">
                  <span :class="DEV_STATUS_CLASS[row.status as keyof typeof DEV_STATUS_CLASS]">{{ row.status }}</span>
                  <el-tooltip
                    v-if="row.reviewPending"
                    :content="`待定备注：${row.reviewComment || ''}`"
                    placement="top"
                    effect="light"
                    :show-after="200"
                  >
                    <span class="owner-pending">待定</span>
                  </el-tooltip>
                </span>
              </template>

              <template v-else-if="c.key === 'submitterName'">
                <span class="cell-nowrap">{{ row.submitterName }}</span>
              </template>

              <template v-else-if="c.key === 'submitterDepartmentName'">
                <span class="cell-nowrap">{{ row.submitterDepartmentName || '未分配部门' }}</span>
              </template>

              <template v-else-if="c.key === 'owner'">
                <el-tooltip
                  v-if="row.reviewPending"
                  :content="`待定备注：${row.reviewComment || ''}`"
                  placement="top"
                  effect="light"
                  :show-after="200"
                >
                  <span class="cell-nowrap owner-pending">待定</span>
                </el-tooltip>
                <span v-else class="cell-nowrap">{{ ownerLabel(row) }}</span>
              </template>

              <template v-else-if="c.key === 'createdAt'">
                <span class="cell-nowrap">{{ fmtDateTime(row.createdAt) }}</span>
              </template>
            </template>
          </el-table-column>

          <!-- 操作列：编号列可点击进详情，不再重复放「详情」按钮 -->
          <el-table-column label="操作" :width="colw.bind('操作', { width: 220 }).width" fixed="right" align="center">
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

/* 评审待定标记：黄色调（与状态胶囊 pill--yellow 同色系），tooltip 展示待定备注 */
.owner-pending {
  color: #9c6a17;
  font-weight: 500;
  cursor: help;
}

/* 空值占位（与各列表页一致） */
.text-placeholder {
  color: var(--color-text-placeholder);
}

/* 状态列：状态胶囊 + 可选的待定标记并排 */
.status-cell {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

/* 标题单元格：标题（超出省略，悬停看全文）+ 可选的待定标记 */
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
</style>