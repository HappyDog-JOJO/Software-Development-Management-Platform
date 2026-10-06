<script setup lang="ts">

/**
 * 部门管理页（仅管理员）。
 * 新增/编辑/停用/删除；删除前置校验：有用户或问题引用时禁止删除，仅可停用。
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { deleteDepartment, saveDepartment, toggleDepartment } from '@/api/department'
import { useDeptStore } from '@/stores/user'
import { fmtDateTime } from '@/utils/format'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import type { Department } from '@/types'
import { useColumnWidths } from '@/composables/useColumnWidths'

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('system-departments')

const store = useDeptStore()

const dialogVisible = ref(false)
const dialogMode = ref<'create' | 'edit'>('create')
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({ id: 0, name: '', leader: '', sort: 0 })

const rules: FormRules = {
  name: [{ required: true, message: '请输入部门名称', trigger: 'blur' }],
}

function openCreate(): void {
  dialogMode.value = 'create'
  Object.assign(form, { id: 0, name: '', leader: '', sort: (store.list.length + 1) * 1 })
  dialogVisible.value = true
  void formRef.value?.clearValidate()
}

function openEdit(row: Department): void {
  dialogMode.value = 'edit'
  Object.assign(form, { id: row.id, name: row.name, leader: row.leader || '', sort: row.sort })
  dialogVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  submitting.value = true
  try {
    await saveDepartment({ id: form.id || undefined, name: form.name.trim(), leader: form.leader.trim(), sort: form.sort })
    ElMessage.success('部门已保存')
    dialogVisible.value = false
    // 强制刷新：不传 force 会被“已加载”短路，导致新增后列表看不到新数据
    await store.load(true)
  } finally {
    submitting.value = false
  }
}

async function onToggle(row: Department): Promise<void> {
  const next = row.status === 1 ? 0 : 1
  await ElMessageBox.confirm(
    next === 1 ? `确定启用部门「${row.name}」吗？` : `确定停用部门「${row.name}」吗？停用后该部门不再出现在新单据的下拉中，历史数据正常展示。`,
    next === 1 ? '启用部门' : '停用部门',
    { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' },
  )
  await toggleDepartment(row.id, next)
  ElMessage.success(next === 1 ? '部门已启用' : '部门已停用')
  await store.load(true)
}

async function onDelete(row: Department): Promise<void> {
  await ElMessageBox.confirm(
    `确定删除部门「${row.name}」吗？若该部门下已有用户或已被问题记录引用，系统会拒绝删除并提示改为停用。`,
    '删除部门',
    { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'warning' },
  )
  await deleteDepartment(row.id)
  ElMessage.success('部门已删除')
  await store.load(true)
}

onMounted(() => {
  void store.load()
})
</script>

<template>
  <div class="page">
    <div class="moon-card moon-card--tight">
      <!-- 卡片工具栏：主操作右对齐（与其它列表页一致） -->
      <div class="toolbar toolbar--actions-only">
        <div class="toolbar__actions">
          <el-button type="primary" @click="openCreate">新增部门</el-button>
        </div>
      </div>

      <div class="moon-table">
        <el-table
        @header-dragend="colw.onDragend" border v-loading="store.loading" :data="store.list" row-key="id" stripe>
          <template #empty>
            <EmptyIllustration :size="130" text="暂无部门" sub-text="点击右上角新增部门" />
          </template>

          <el-table-column prop="name" label="部门名称" :min-width="colw.bind('name', { minWidth: 168 }).minWidth" />
          <el-table-column prop="leader" label="负责人" :width="colw.bind('leader', { width: 130 }).width">
            <template #default="{ row }">{{ row.leader || '—' }}</template>
          </el-table-column>
          <el-table-column prop="userCount" label="人数" :width="colw.bind('userCount', { width: 94 }).width" align="right">
            <template #default="{ row }"><span class="num-strong">{{ row.userCount }}</span></template>
          </el-table-column>
          <el-table-column prop="sort" label="排序" :width="colw.bind('sort', { width: 94 }).width" align="right" />
          <el-table-column label="状态" :width="colw.bind('状态', { width: 109 }).width" align="center">
            <template #default="{ row }">
              <span :class="row.status === 1 ? 'pill pill--green' : 'pill pill--grey'">
                {{ row.status === 1 ? '启用' : '停用' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="createdAt" label="创建时间" :width="colw.bind('createdAt', { width: 165 }).width">
            <template #default="{ row }">{{ fmtDateTime(row.createdAt) }}</template>
          </el-table-column>
          <el-table-column label="操作" :width="colw.bind('操作', { width: 210 }).width" fixed="right" align="center">
            <template #default="{ row }">
                              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="primary" @click="onToggle(row)">
                {{ row.status === 1 ? '停用' : '启用' }}
              </el-button>
              <el-button link type="danger" @click="onDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>

    <el-dialog v-model="dialogVisible" :title="dialogMode === 'create' ? '新增部门' : '编辑部门'" width="460px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="部门名称" prop="name">
          <el-input v-model="form.name" placeholder="如：销售一部" maxlength="30" />
        </el-form-item>
        <el-form-item label="负责人">
          <el-input v-model="form.leader" placeholder="选填" maxlength="30" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="999" controls-position="right" />
          <div class="form-tip">数值越小越靠前。</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitForm">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
