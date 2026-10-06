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
import { useDictStore } from '@/stores/dict'
import { fetchDevRequests } from '@/api/devreq'
import { DEV_PRIORITY_CLASS, DEV_PRIORITY_OPTIONS, DEV_STATUS_CLASS, DEV_STATUS_OPTIONS } from '@/constants/devreq'
import DevRequestActions from '@/components/DevRequestActions.vue'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import ColumnConfig from '@/components/ColumnConfig.vue'
import { fmtDateTime } from '@/utils/format'
import { useColumnWidths } from '@/composables/useColumnWidths'
import { useColumnConfig } from '@/composables/useColumnConfig'
import type { DevPriority, DevRequest, DevStatus } from '@/types'

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
  title: { label: '需求标题', minWidth: 180, overflow: true },
  moduleName: { label: '模块', width: 80 },
  priority: { label: '优先级', width: 78, align: 'center', sortable: true },
  status: { label: '状态', width: 100, align: 'center' },
  submitterName: { label: '提交人', width: 104 },
  owner: { label: '当前担当', minWidth: 120 },
  createdAt: { label: '提交时间', width: 150, sortable: true },
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

/** 按「列配置」的顺序与显隐生成实际渲染的列（阶段页不展示状态列） */
const devColumns = computed(() =>
  colcfg
    .ordered()
    .filter(
      (c) =>
        c.visible &&
        DEV_COL_DEFS[c.key] &&
        (c.key !== 'status' || showStatusColumn.value),
    )
    .map((c) => ({ key: c.key, ...DEV_COL_DEFS[c.key] })),
)

const stageLabel = computed(() => (props.mine ? '我的需求' : (props.stage ?? '全部需求')))
const stageDesc = computed(() => {
  switch (props.stage) {
    case '评审池':
      return '所有新需求在此等待评审，任一评审委员通过即进入需求池；驳回则需求作废。'
    case '需求池':
      return '评审通过的需求在此集结（按优先级排序），产品经理从中挑选任务认领设计。'
    case '设计中心':
      return '产品经理上传设计文档、指定开发分支与开发负责人。'
    case '开发中心':
      return '开发负责人按分支完成开发，提交开发完成后进入待发布。'
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
})

/** 筛选区折叠（记忆用户选择，与问题列表一致） */
const FILTER_COLLAPSE_KEY = 'command_dev_reqs_filter_collapsed'
const filterCollapsed = ref(localStorage.getItem(FILTER_COLLAPSE_KEY) === '1')
function toggleFilter(): void {
  filterCollapsed.value = !filterCollapsed.value
  localStorage.setItem(FILTER_COLLAPSE_KEY, filterCollapsed.value ? '1' : '0')
}

/** 已选筛选条件数量（折叠时提示用） */
const activeFilterCount = computed(() => {
  let n = 0
  if (filters.priority) n += 1
  if (filters.status) n += 1
  if (filters.moduleId) n += 1
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
      keyword: filters.keyword.trim() || undefined,
      priority: filters.priority || undefined,
      moduleId: filters.moduleId,
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

/** 环节 → 当前担当说明 */
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
    { key: 'moduleName', label: '模块' },
    { key: 'priority', label: '优先级' },
    { key: 'status', label: '状态' },
    { key: 'submitterName', label: '提交人' },
    { key: 'owner', label: '当前担当' },
    { key: 'createdAt', label: '提交时间' },
    { key: '操作', label: '操作', fixed: true },
  ])
  void dict.load()
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
        >
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
                <el-tooltip :content="row.reqNo" placement="top" effect="light" :show-after="200">
                  <span class="cell-code cell-nowrap">{{ row.reqNo }}</span>
                </el-tooltip>
              </template>

              <template v-else-if="c.key === 'title'">
                <span class="cell-ellipsis">{{ row.title }}</span>
              </template>

              <template v-else-if="c.key === 'moduleName'">
                <span class="cell-nowrap">{{ row.moduleName || '—' }}</span>
              </template>

              <template v-else-if="c.key === 'priority'">
                <span :class="DEV_PRIORITY_CLASS[row.priority as keyof typeof DEV_PRIORITY_CLASS]">{{ row.priority }}</span>
              </template>

              <template v-else-if="c.key === 'status'">
                <span :class="DEV_STATUS_CLASS[row.status as keyof typeof DEV_STATUS_CLASS]">{{ row.status }}</span>
              </template>

              <template v-else-if="c.key === 'submitterName'">
                <div class="cell-stack">
                  <span class="cell-nowrap">{{ row.submitterName }}</span>
                  <span class="cell-stack__sub">{{ row.submitterDepartmentName || '未分配部门' }}</span>
                </div>
              </template>

              <template v-else-if="c.key === 'owner'">
                <span class="cell-nowrap">{{ ownerLabel(row) }}</span>
              </template>

              <template v-else-if="c.key === 'createdAt'">
                <span class="cell-nowrap">{{ fmtDateTime(row.createdAt) }}</span>
              </template>
            </template>
          </el-table-column>

          <el-table-column label="操作" :width="colw.bind('操作', { width: 240 }).width" fixed="right" align="center">
            <template #default="{ row }">
              <div class="cell--actions">
                <DevRequestActions :req="row" link @refresh="load" />
                <el-button link type="primary" class="btn-action-detail" @click="goDetail(row)">详情</el-button>
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

.cell-stack {
  display: flex;
  flex-direction: column;
  justify-content: center;
  line-height: 1.35;
  gap: 2px;
}

.cell-stack__sub {
  font-size: 12px;
  color: var(--color-text-secondary);
  font-variant-numeric: tabular-nums;
}

/* 操作列详情按钮：与各列表页文字按钮写法一致 */
.btn-action-detail {
  justify-content: center;
  padding: 0;
}

/* 操作列：组件按钮组（display:contents 后视觉上并入本行）与「详情」之间的间距，
   与相邻文字按钮的 10px 间距保持一致（见 theme.css 的 link 按钮规则） */
.cell--actions .dev-actions--link + .btn-action-detail {
  margin-left: 10px;
}
</style>