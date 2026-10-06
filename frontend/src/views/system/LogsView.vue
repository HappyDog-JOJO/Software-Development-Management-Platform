<script setup lang="ts">

/**
 * 操作日志页（仅管理员）——只读。
 * 无编辑与删除入口；支持按操作人、操作类型、时间范围、关键字筛选。
 * （导出入口已按需求移除）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { fetchLogs } from '@/api/log'
import { LOG_TYPES, logTypeClass } from '@/constants/issue'
import { fmtDateTime, quickRange } from '@/utils/format'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import ColumnConfig from '@/components/ColumnConfig.vue'
import type { OperationLog } from '@/types'
import { useColumnWidths } from '@/composables/useColumnWidths'
import { useColumnConfig } from '@/composables/useColumnConfig'

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('system-logs')

/** 列配置（按当前用户隔离）：控制列的显隐与顺序 */
const colcfg = useColumnConfig('system-logs')

function colOk(key: string): boolean {
  return colcfg.isVisible(key)
}

function labelOf(key: string): string {
  return colcfg.metas.value.find((m) => m.key === key)?.label || key
}

/** 列定义（colwKey 与 useColumnWidths 历史键保持一致） */
interface LogColDef {
  label: string
  colwKey: string
  width?: number
  minWidth?: number
  align?: 'left' | 'center' | 'right'
  overflow?: boolean
}

const LOG_COL_DEFS: Record<string, LogColDef> = {
  createdAt: { label: '时间', colwKey: 'createdAt', width: 118 },
  account: { label: '操作人', colwKey: 'account', width: 106 },
  role: { label: '角色', colwKey: 'role', width: 132 },
  type: { label: '操作类型', colwKey: 'type', width: 110, align: 'center' },
  target: { label: '操作对象', colwKey: 'target', width: 176, overflow: true },
  content: { label: '操作内容', colwKey: 'content', minWidth: 240, overflow: true },
  ip: { label: 'IP', colwKey: 'ip', width: 130 },
}

/** 按列配置的顺序与显隐动态生成列 */
const logColumns = computed(() =>
  colcfg
    .ordered()
    .filter((c) => c.visible && LOG_COL_DEFS[c.key])
    .map((c) => ({ key: c.key, ...LOG_COL_DEFS[c.key] })),
)

const list = ref<OperationLog[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const loading = ref(false)

const filters = reactive({
  account: '',
  type: '',
  dateStart: '',
  dateEnd: '',
  keyword: '',
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchLogs({ ...filters, page: page.value, pageSize: pageSize.value })
    list.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function onQuick(v: 'today' | 'week' | 'month'): void {
  const r = quickRange(v)
  filters.dateStart = r.dateStart
  filters.dateEnd = r.dateEnd
  page.value = 1
  void load()
}

function onReset(): void {
  filters.account = ''
  filters.type = ''
  filters.dateStart = ''
  filters.dateEnd = ''
  filters.keyword = ''
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

/** 导出日志（已按需求移除入口；保留函数注释以便日后恢复） */

onMounted(async () => {
  // 登记可配置列（顺序即默认顺序）
  colcfg.register([
    { key: 'createdAt', label: '时间' },
    { key: 'account', label: '操作人' },
    { key: 'role', label: '角色' },
    { key: 'type', label: '操作类型' },
    { key: 'target', label: '操作对象' },
    { key: 'content', label: '操作内容' },
    { key: 'ip', label: 'IP' },
  ])
  await load()
})
</script>

<template>
  <div class="page">
    <div class="moon-card moon-card--tight">
      <div class="filter-bar">
        <div class="filter-bar__item">
          <span class="filter-bar__label">快捷</span>
          <el-button-group>
            <el-button @click="onQuick('today')">今日</el-button>
            <el-button @click="onQuick('week')">近 7 天</el-button>
            <el-button @click="onQuick('month')">本月</el-button>
          </el-button-group>
        </div>
        <div class="filter-bar__item">
          <span class="filter-bar__label">时间</span>
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
          <span class="filter-bar__label">操作人</span>
          <el-input v-model="filters.account" placeholder="账号/对象" style="width: 183px" clearable @change="load" />
        </div>
        <div class="filter-bar__item">
          <span class="filter-bar__label">操作类型</span>
          <el-select v-model="filters.type" placeholder="全部类型" clearable style="width: 171px" @change="load">
            <el-option v-for="t in LOG_TYPES" :key="t" :label="t" :value="t" />
          </el-select>
        </div>
        <div class="filter-bar__item">
          <span class="filter-bar__label">关键字</span>
          <el-input
            v-model="filters.keyword"
            placeholder="操作内容"
            style="width: 207px"
            clearable
            @keyup.enter="load"
            @change="load"
          />
        </div>
        <div class="filter-bar__item filter-bar__item--actions">
          <el-button @click="onReset">重置</el-button>
          <el-button type="primary" @click="load">查询</el-button>
        </div>
      </div>
    </div>

    <div class="moon-card moon-card--tight">
      <div class="toolbar toolbar--actions-only">
        <div class="toolbar__actions">
          <ColumnConfig :config="colcfg" title="操作日志" />
        </div>
      </div>

      <div class="moon-table">
        <el-table
        @header-dragend="colw.onDragend" border v-loading="loading" :data="list" row-key="id" stripe>
          <template #empty>
            <EmptyIllustration :size="130" text="暂无操作日志" sub-text="系统会在关键操作后自动记录日志" />
          </template>

          <!-- 列按「列配置」动态渲染：显隐与顺序均生效 -->
          <el-table-column
            v-for="c in logColumns"
            :key="c.key"
            :prop="c.key"
            :label="c.label"
            v-bind="colw.bind(c.colwKey, c.width !== undefined ? { width: c.width } : { minWidth: c.minWidth })"
            :align="c.align || 'left'"
            :show-overflow-tooltip="!!c.overflow"
          >
            <template #default="{ row }">
              <template v-if="c.key === 'createdAt'">
                <span class="cell-stack">
                  <span class="cell-nowrap">{{ row.createdAt.slice(0, 10) }}</span>
                  <span class="cell-stack__sub cell-nowrap">{{ row.createdAt.slice(11, 19) }}</span>
                </span>
              </template>

              <template v-else-if="c.key === 'role'">
                <span class="pill pill--primary">{{ row.role }}</span>
              </template>

              <template v-else-if="c.key === 'type'">
                <span :class="logTypeClass(row.type)">{{ row.type }}</span>
              </template>

              <template v-else-if="c.key === 'content'">
                <span class="cell-ellipsis">{{ row.content }}</span>
              </template>
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
/* 页头已移除，操作按钮独占一行并收紧顶部间距 */
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>
