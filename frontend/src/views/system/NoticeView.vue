<script setup lang="ts">

/**
 * 公告管理（仅管理员）。
 * - 发布 / 编辑 / 启用停用 / 删除
 * - 「发布中的最新一条」会显示在问题提交页的灯牌上（无启用中的公告则不展示）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { deleteNotice, fetchNotices, saveNotice, toggleNotice } from '@/api/notice'
import { fmtDateTime } from '@/utils/format'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import ColumnConfig from '@/components/ColumnConfig.vue'
import type { Notice } from '@/types'
import { useColumnWidths } from '@/composables/useColumnWidths'
import { useColumnConfig } from '@/composables/useColumnConfig'

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('system-notices')

/** 列配置（按当前用户隔离）：控制列的显隐与顺序 */
const colcfg = useColumnConfig('system-notices')

function colOk(key: string): boolean {
  return colcfg.isVisible(key)
}

function labelOf(key: string): string {
  return colcfg.metas.value.find((m) => m.key === key)?.label || key
}

/** 列定义（colwKey 与 useColumnWidths 历史键保持一致） */
interface NoticeColDef {
  label: string
  colwKey: string
  width?: number
  minWidth?: number
  align?: 'left' | 'center' | 'right'
  overflow?: boolean
}

const NOTICE_COL_DEFS: Record<string, NoticeColDef> = {
  title: { label: '标题', colwKey: 'title', width: 180 },
  content: { label: '公告内容', colwKey: 'content', minWidth: 320, overflow: true },
  publisherName: { label: '发布人', colwKey: 'publisherName', width: 100 },
  createdAt: { label: '发布时间', colwKey: 'createdAt', width: 158, align: 'center' },
  status: { label: '状态', colwKey: 'status', width: 92, align: 'center' },
}

/** 按列配置的顺序与显隐动态生成列 */
const noticeColumns = computed(() =>
  colcfg
    .ordered()
    .filter((c) => c.visible && NOTICE_COL_DEFS[c.key])
    .map((c) => ({ key: c.key, ...NOTICE_COL_DEFS[c.key] })),
)

const loading = ref(false)
const saving = ref(false)
const list = ref<Notice[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(10)
const keyword = ref('')

const dialogVisible = ref(false)
const dialogMode = ref<'create' | 'edit'>('create')
const formRef = ref<FormInstance>()
const form = reactive({ id: 0, title: '', content: '', status: 1 })

const rules: FormRules = {
  content: [
    { required: true, message: '请输入公告内容', trigger: 'blur' },
    { max: 2000, message: '内容不超过 2000 字', trigger: 'blur' },
  ],
  title: [{ max: 60, message: '标题不超过 60 字', trigger: 'blur' }],
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchNotices({ page: page.value, pageSize: pageSize.value, keyword: keyword.value.trim() })
    list.value = res.items
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  dialogMode.value = 'create'
  Object.assign(form, { id: 0, title: '', content: '', status: 1 })
  dialogVisible.value = true
  void formRef.value?.clearValidate()
}

function openEdit(row: Notice): void {
  dialogMode.value = 'edit'
  Object.assign(form, { id: row.id, title: row.title, content: row.content, status: row.status })
  dialogVisible.value = true
}

async function submit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  saving.value = true
  try {
    await saveNotice({
      id: form.id || undefined,
      title: form.title.trim(),
      content: form.content.trim(),
      status: form.status,
    })
    ElMessage.success(dialogMode.value === 'create' ? '公告已发布' : '公告已更新')
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function onToggle(row: Notice): Promise<void> {
  const next = row.status === 1 ? 0 : 1
  await toggleNotice(row.id, next)
  ElMessage.success(next === 1 ? '公告已启用' : '公告已停用')
  await load()
}

async function onDelete(row: Notice): Promise<void> {
  await ElMessageBox.confirm(
    `确定删除公告「${row.title || row.content.slice(0, 20)}」吗？删除后无法恢复。`,
    '删除公告',
    { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'warning' },
  )
  await deleteNotice(row.id)
  ElMessage.success('公告已删除')
  await load()
}

function onPageChange(p: number): void {
  page.value = p
  void load()
}

onMounted(async () => {
  // 登记可配置列（顺序即默认顺序；操作列固定）
  colcfg.register([
    { key: 'title', label: '标题' },
    { key: 'content', label: '公告内容' },
    { key: 'publisherName', label: '发布人' },
    { key: 'createdAt', label: '发布时间' },
    { key: 'status', label: '状态' },
    { key: '操作', label: '操作', fixed: true },
  ])
  await load()
})
</script>

<template>
  <div class="page">
    <div class="moon-card moon-card--tight">
      <div class="toolbar">
        <div class="toolbar__actions">
          <el-input
            v-model="keyword"
            placeholder="搜索标题或内容"
            style="width: 240px"
            clearable
            @change="load"
          />
        </div>
        <div class="toolbar__actions">
          <ColumnConfig :config="colcfg" title="公告管理" />
          <el-button type="primary" @click="openCreate">发布公告</el-button>
        </div>
      </div>

      <div class="moon-table">
        <el-table
        @header-dragend="colw.onDragend" border v-loading="loading" :data="list" row-key="id" stripe>
          <template #empty>
            <EmptyIllustration :size="130" text="暂无公告" sub-text="点击「发布公告」新增一条" />
          </template>

          <!-- 列按「列配置」动态渲染：显隐与顺序均生效 -->
          <el-table-column
            v-for="c in noticeColumns"
            :key="c.key"
            :prop="c.key"
            :label="c.label"
            v-bind="colw.bind(c.colwKey, c.width !== undefined ? { width: c.width } : { minWidth: c.minWidth })"
            :align="c.align || 'left'"
            :show-overflow-tooltip="!!c.overflow"
          >
            <template #default="{ row }">
              <template v-if="c.key === 'title'">
                <span v-if="row.title" class="cell-nowrap">{{ row.title }}</span>
                <span v-else class="text-placeholder">（无标题）</span>
              </template>

              <template v-else-if="c.key === 'content'">
                <span class="cell-ellipsis">{{ row.content }}</span>
              </template>

              <template v-else-if="c.key === 'publisherName'">
                <span class="cell-nowrap">{{ row.publisherName }}</span>
              </template>

              <template v-else-if="c.key === 'createdAt'">
                <span class="cell-nowrap">{{ fmtDateTime(row.createdAt) }}</span>
              </template>

              <template v-else-if="c.key === 'status'">
                <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="light" size="small">
                  {{ row.status === 1 ? '发布中' : '已停用' }}
                </el-tag>
              </template>
            </template>
          </el-table-column>
          <el-table-column v-if="colOk('操作')" label="操作" :width="colw.bind('操作', { width: 200 }).width" fixed="right" align="center">
            <template #default="{ row }">
              <div class="cell--actions">
                <el-button link type="primary" class="btn-action-detail" @click="openEdit(row)">编辑</el-button>
                <el-button link type="primary" class="btn-action-detail" @click="onToggle(row)">
                  {{ row.status === 1 ? '停用' : '启用' }}
                </el-button>
                <el-button link type="danger" class="btn-action-detail" @click="onDelete(row)">删除</el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <div class="pager">
        <el-pagination
          :current-page="page"
          :page-size="pageSize"
          :total="total"
          layout="total, prev, pager, next"
          background
          @current-change="onPageChange"
        />
      </div>
    </div>

    <div class="moon-card moon-card--tight">
      <div class="section-title">展示规则</div>
      <ul class="notice-rules">
        <li>发布中的公告会显示在「问题提交」页的灯牌上（位于今日值班灯牌下方）。</li>
        <li>有多条发布中的公告时，展示<b>最新发布</b>的那一条；全部停用或没有公告时，提交页不显示公告灯牌。</li>
        <li>「停用」可临时下架公告而保留记录，需要时再「启用」。</li>
      </ul>
    </div>

    <el-dialog
      v-model="dialogVisible"
      :title="dialogMode === 'create' ? '发布公告' : '编辑公告'"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
        <el-form-item label="标题" prop="title">
          <el-input v-model="form.title" maxlength="60" placeholder="选填，如「系统维护通知」" />
        </el-form-item>
        <el-form-item label="内容" prop="content">
          <el-input
            v-model="form.content"
            type="textarea"
            :rows="5"
            maxlength="2000"
            show-word-limit
            placeholder="请输入公告内容"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip" style="margin-left: 8px">开启后立即在问题提交页展示</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">
          {{ dialogMode === 'create' ? '发布' : '保存' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--space-3);
}

.notice-rules {
  margin: 0;
  padding-left: 18px;
  font-size: 13px;
  line-height: 1.9;
  color: var(--color-text-regular);
}
</style>
