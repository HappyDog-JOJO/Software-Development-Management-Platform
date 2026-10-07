<script setup lang="ts">

/**
 * 用户管理页（仅管理员）。
 * 列表、筛选、新增/编辑、分配部门、调整角色、启停、重置密码、逻辑删除、批量操作。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import * as userApi from '@/api/user'
import { useDeptStore, useUserStore } from '@/stores/user'
import { ROLES } from '@/constants/user'
import { fmtDateTime } from '@/utils/format'
import RoleTag from '@/components/RoleTag.vue'
import AvatarUpload from '@/components/AvatarUpload.vue'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import ColumnConfig from '@/components/ColumnConfig.vue'
import type { Role, User } from '@/types'
import { useColumnWidths } from '@/composables/useColumnWidths'
import { useColumnConfig } from '@/composables/useColumnConfig'

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('system-users')

/** 列配置（按当前用户隔离）：控制列的显隐与顺序 */
const colcfg = useColumnConfig('system-users')

function colOk(key: string): boolean {
  return colcfg.isVisible(key)
}

function labelOf(key: string): string {
  return colcfg.metas.value.find((m) => m.key === key)?.label || key
}

/** 列定义（colwKey 与 useColumnWidths 历史键保持一致，避免丢失已存列宽） */
interface UserColDef {
  label: string
  colwKey: string
  width?: number
  minWidth?: number
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
  overflow?: boolean
}

const USER_COL_DEFS: Record<string, UserColDef> = {
  avatar: { label: '头像', colwKey: '头像', width: 76, align: 'center' },
  account: { label: '账号', colwKey: 'account', width: 108 },
  name: { label: '姓名', colwKey: 'name', width: 80 },
  phone: { label: '联系电话', colwKey: 'phone', width: 122 },
  departmentName: { label: '所属部门', colwKey: 'departmentName', width: 104 },
  role: { label: '角色', colwKey: 'role', width: 182 },
  createdAt: { label: '注册时间', colwKey: 'createdAt', width: 116, sortable: true, align: 'center' },
  status: { label: '状态', colwKey: '状态', width: 76, align: 'center' },
}

/** 按列配置的顺序与显隐动态生成列 */
const userColumns = computed(() =>
  colcfg
    .ordered()
    .filter((c) => c.visible && USER_COL_DEFS[c.key])
    .map((c) => ({ key: c.key, ...USER_COL_DEFS[c.key] })),
)

const store = useUserStore()
const deptStore = useDeptStore()

const selected = ref<User[]>([])
const filters = reactive<userApi.UserQuery>({ keyword: '', departmentId: undefined, role: '', status: '' })

// 新增/编辑弹窗
const dialogVisible = ref(false)
const dialogMode = ref<'create' | 'edit'>('create')
const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive({
  id: 0,
  account: '',
  name: '',
  phone: '',
  avatar: '',
  departmentId: undefined as number | undefined,
  roles: ['销售员'] as Role[],
})

const rules: FormRules = {
  account: [
    { required: true, message: '请输入账号', trigger: 'blur' },
    { min: 2, max: 20, message: '账号需 2-20 位', trigger: 'blur' },
    {
      pattern: /^[A-Za-z0-9_\u4e00-\u9fa5]+$/,
      message: '账号只能包含中英文、数字与下划线',
      trigger: 'blur',
    },
  ],
  roles: [{ required: true, message: '请至少选择一个角色', trigger: 'change' }],
  phone: [{ pattern: /^1[3-9]\d{9}$/, message: '手机号格式不正确', trigger: 'blur' }],
}

// 分配部门弹窗（批量）
const deptDialogVisible = ref(false)
const targetDeptId = ref<number | undefined>(undefined)
// 分配部门弹窗（行内单个用户）
const singleDeptVisible = ref(false)
const singleDeptTarget = ref<User | null>(null)
const singleDeptId = ref<number | undefined>(undefined)
const assigning = ref(false)
// 调整角色弹窗
const roleDialogVisible = ref(false)
const targetRoles = ref<Role[]>(['销售员'])

async function load(): Promise<void> {
  await store.load(filters)
  selected.value = []
}

function onSelectionChange(rows: User[]): void {
  selected.value = rows
}

function openCreate(): void {
  dialogMode.value = 'create'
  Object.assign(form, { id: 0, account: '', name: '', phone: '', avatar: '', departmentId: undefined, roles: ['销售员'] as Role[] })
  dialogVisible.value = true
  void formRef.value?.clearValidate()
}

function openEdit(row: User): void {
  dialogMode.value = 'edit'
  Object.assign(form, {
    id: row.id,
    account: row.account,
    name: row.name,
    phone: row.phone || '',
    avatar: row.avatar || '',
    departmentId: row.departmentId ?? undefined,
    roles: [...(row.roles ?? [])] as Role[],
  })
  dialogVisible.value = true
}

/** 行内直接给单个用户分配部门 */
function openAssignDept(row: User): void {
  singleDeptTarget.value = row
  singleDeptId.value = row.departmentId ?? undefined
  singleDeptVisible.value = true
}

async function confirmSingleDept(): Promise<void> {
  if (!singleDeptTarget.value) {
    return
  }
  assigning.value = true
  try {
    await userApi.assignDepartment([singleDeptTarget.value.id], singleDeptId.value ?? 0)
    ElMessage.success(singleDeptId.value ? '部门已分配' : '已设为未分配部门')
    singleDeptVisible.value = false
    await load()
  } finally {
    assigning.value = false
  }
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  submitting.value = true
  try {
    if (dialogMode.value === 'create') {
      await userApi.createUser({
        account: form.account.trim(),
        password: '123456',
        name: form.name.trim() || form.account.trim(),
        phone: form.phone.trim(),
        avatar: form.avatar,
        departmentId: form.departmentId,
        roles: form.roles,
      })
      ElMessage.success('用户已创建，初始密码为默认密码 123456')
    } else {
      await userApi.updateUser(form.id, {
        account: form.account.trim(),
        name: form.name.trim(),
        phone: form.phone.trim(),
        avatar: form.avatar,
        departmentId: form.departmentId ?? 0,
      })
      // 角色以多选形式提交
      await userApi.assignRole([form.id], form.roles)
      ElMessage.success('用户资料已更新')
    }
    dialogVisible.value = false
    await load()
  } finally {
    submitting.value = false
  }
}

function openDeptDialog(): void {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选要分配部门的用户')
    return
  }
  targetDeptId.value = undefined
  deptDialogVisible.value = true
}

async function confirmDept(): Promise<void> {
  if (!targetDeptId.value) {
    ElMessage.warning('请选择目标部门')
    return
  }
  await userApi.assignDepartment(selected.value.map((u) => u.id), targetDeptId.value)
  ElMessage.success('部门分配完成')
  deptDialogVisible.value = false
  await load()
}

function openRoleDialog(): void {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选要调整角色的用户')
    return
  }
  // 单选时回填该用户当前角色
  targetRoles.value = selected.value.length === 1 ? [...(selected.value[0].roles ?? [])] : ['销售员']
  roleDialogVisible.value = true
}

async function confirmRole(): Promise<void> {
  if (!targetRoles.value.length) {
    ElMessage.warning('请至少选择一个角色')
    return
  }
  await userApi.assignRole(selected.value.map((u) => u.id), targetRoles.value)
  ElMessage.success('角色调整完成，用户重新登录后按新角色生效')
  roleDialogVisible.value = false
  await load()
}

async function onToggleStatus(row: User): Promise<void> {
  const next = row.status === 1 ? 0 : 1
  await ElMessageBox.confirm(
    next === 1 ? `确定启用账号「${row.account}」吗？` : `确定禁用账号「${row.account}」吗？禁用后该账号无法登录。`,
    next === 1 ? '启用账号' : '禁用账号',
    { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' },
  )
  await userApi.toggleUserStatus([row.id], next)
  ElMessage.success(next === 1 ? '账号已启用' : '账号已禁用')
  await load()
}

async function onResetPassword(row: User): Promise<void> {
  await ElMessageBox.confirm(
    `确定将账号「${row.account}」的密码重置为默认密码吗？`,
    '重置密码',
    { confirmButtonText: '确定重置', cancelButtonText: '取消', type: 'warning' },
  )
  const res = await userApi.resetPassword(row.id)
  ElMessage.success(`密码已重置为 ${res.defaultPassword}，请通知该用户及时修改`)
}

async function onBatchStatus(status: number): Promise<void> {
  if (!selected.value.length) {
    ElMessage.warning('请先勾选用户')
    return
  }
  await userApi.batchUpdate(selected.value.map((u) => u.id), 'status', status)
  ElMessage.success(status === 1 ? '已批量启用' : '已批量禁用')
  await load()
}

async function onDelete(row: User): Promise<void> {
  await ElMessageBox.confirm(
    `确定删除账号「${row.account}」吗？若该用户已有业务数据（提交问题或处理记录），系统会拒绝删除并建议改为禁用。`,
    '删除用户',
    { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'warning' },
  )
  await userApi.deleteUser(row.id)
  ElMessage.success('用户已删除')
  await load()
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

function onReset(): void {
  filters.keyword = ''
  filters.departmentId = undefined
  filters.role = ''
  filters.status = ''
  store.page = 1
  void load()
}

onMounted(async () => {
  // 登记可配置列（顺序即默认顺序；选择列与操作列固定，不参与配置）
  colcfg.register([
    { key: 'avatar', label: '头像' },
    { key: 'account', label: '账号' },
    { key: 'name', label: '姓名' },
    { key: 'phone', label: '联系电话' },
    { key: 'departmentName', label: '所属部门' },
    { key: 'role', label: '角色' },
    { key: 'createdAt', label: '注册时间' },
    { key: 'status', label: '状态' },
    { key: '操作', label: '操作', fixed: true },
  ])
  await Promise.all([deptStore.load(), load()])
})
</script>

<template>
  <div class="page">
    <!-- 筛选 -->
    <div class="moon-card moon-card--tight">
      <div class="filter-bar">
        <div class="filter-bar__item">
          <span class="filter-bar__label">关键字</span>
          <el-input v-model="filters.keyword" placeholder="账号/姓名/电话" style="width: 207px" clearable @change="load" />
        </div>
        <div class="filter-bar__item">
          <span class="filter-bar__label">部门</span>
          <el-select v-model="filters.departmentId" placeholder="全部部门" clearable style="width: 183px" @change="load">
            <el-option v-for="d in deptStore.list" :key="d.id" :label="d.name" :value="d.id" />
          </el-select>
        </div>
        <div class="filter-bar__item">
          <span class="filter-bar__label">角色</span>
          <el-select v-model="filters.role" placeholder="全部角色" clearable style="width: 183px" @change="load">
            <el-option v-for="r in ROLES" :key="r" :label="r" :value="r" />
          </el-select>
        </div>
        <div class="filter-bar__item">
          <span class="filter-bar__label">状态</span>
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 146px" @change="load">
            <el-option label="启用" :value="1" />
            <el-option label="禁用" :value="0" />
          </el-select>
        </div>
        <div class="filter-bar__item filter-bar__item--actions">
          <el-button @click="onReset">重置</el-button>
          <el-button type="primary" @click="load">查询</el-button>
        </div>
      </div>
    </div>

    <div class="moon-card moon-card--tight">
      <!-- 卡片工具栏：左侧已选数量，右侧主操作 + 批量操作 -->
      <div class="toolbar">
        <div class="toolbar__meta">
          已选 <span class="num-strong">{{ selected.length }}</span> 个
        </div>
        <div class="toolbar__actions">
          <ColumnConfig :config="colcfg" title="用户管理" />
          <el-button type="primary" @click="openCreate">新增用户</el-button>
          <el-button :disabled="!selected.length" @click="openDeptDialog">批量分配部门</el-button>
          <el-button :disabled="!selected.length" @click="openRoleDialog">批量调整角色</el-button>
          <el-button :disabled="!selected.length" @click="onBatchStatus(1)">批量启用</el-button>
          <el-button :disabled="!selected.length" @click="onBatchStatus(0)">批量禁用</el-button>
        </div>
      </div>

      <div class="moon-table">
        <el-table
          border
          v-loading="store.loading"
          :data="store.list"
          row-key="id"
          stripe
          @selection-change="onSelectionChange"
          @header-dragend="colw.onDragend"
        >
          <template #empty>
            <EmptyIllustration :size="130" text="暂无用户" sub-text="可以点击右上角新增用户" />
          </template>

          <el-table-column type="selection" width="40" />
          <!-- 列按「列配置」动态渲染：显隐与顺序均生效；选择列与操作列固定不参与 -->
          <el-table-column
            v-for="c in userColumns"
            :key="c.key"
            :prop="c.key"
            :label="c.label"
            v-bind="colw.bind(c.colwKey, c.width !== undefined ? { width: c.width } : { minWidth: c.minWidth })"
            :align="c.align || 'left'"
            :sortable="c.sortable ? 'custom' : false"
            :show-overflow-tooltip="!!c.overflow"
          >
            <template #default="{ row }">
              <template v-if="c.key === 'avatar'">
                <el-avatar :size="40" :src="row.avatar || undefined">
                  {{ (row.name || row.account).slice(0, 1) }}
                </el-avatar>
              </template>

              <template v-else-if="c.key === 'account'">
                <span class="cell-nowrap">{{ row.account }}</span>
              </template>

              <template v-else-if="c.key === 'name'">
                <span class="cell-nowrap">{{ row.name }}</span>
              </template>

              <template v-else-if="c.key === 'phone'">
                <span class="cell-nowrap">{{ row.phone || '—' }}</span>
              </template>

              <template v-else-if="c.key === 'departmentName'">
                <span :class="row.departmentName ? 'cell-nowrap' : 'text-pending'">{{ row.departmentName || '未分配' }}</span>
              </template>

              <template v-else-if="c.key === 'role'">
                <RoleTag :roles="row.roles" />
              </template>

              <template v-else-if="c.key === 'createdAt'">
                <span class="cell-stack cell-stack--center">
                  <span class="cell-nowrap">{{ row.createdAt.slice(0, 10) }}</span>
                  <span class="cell-stack__sub cell-nowrap">{{ row.createdAt.slice(11, 16) }}</span>
                </span>
              </template>

              <template v-else-if="c.key === 'status'">
                <span :class="row.status === 1 ? 'pill pill--green' : 'pill pill--grey'">
                  {{ row.status === 1 ? '启用' : '禁用' }}
                </span>
              </template>
            </template>
          </el-table-column>
          <el-table-column v-if="colOk('操作')" label="操作" :width="colw.bind('操作', { width: 420 }).width" fixed="right" align="center">
            <template #default="{ row }">
              <div class="cell--actions">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="primary" @click="openAssignDept(row)">分配部门</el-button>
                <el-button link type="primary" @click="onToggleStatus(row)">
                  {{ row.status === 1 ? '禁用' : '启用' }}
                </el-button>
                <el-button link type="primary" @click="onResetPassword(row)">重置密码</el-button>
                <el-button link type="danger" @click="onDelete(row)">删除</el-button>
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

    <!-- 新增/编辑用户 -->
    <el-dialog v-model="dialogVisible" :title="dialogMode === 'create' ? '新增用户' : '编辑用户'" width="520px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="账号" prop="account">
          <el-input v-model="form.account" placeholder="登录账号" />
          <div class="form-tip">登录用的账号，支持 2-20 位中英文、数字与下划线；修改后请告知本人用新账号登录。</div>
        </el-form-item>
        <el-form-item label="姓名">
          <el-input v-model="form.name" placeholder="用于展示与统计" />
        </el-form-item>
        <el-form-item label="联系电话" prop="phone">
          <el-input v-model="form.phone" maxlength="11" placeholder="11 位手机号" />
        </el-form-item>
        <el-form-item label="所属部门">
          <el-select v-model="form.departmentId" placeholder="未分配（可稍后分配）" clearable style="width: 100%">
            <el-option v-for="d in deptStore.list" :key="d.id" :label="d.name" :value="d.id" />
          </el-select>
          <div class="form-tip">销售员必须分配部门后才能提交问题。</div>
        </el-form-item>
        <el-form-item label="角色" prop="roles">
          <el-select v-model="form.roles" multiple placeholder="可多选" style="width: 100%">
            <el-option v-for="r in ROLES" :key="r" :label="r" :value="r" />
          </el-select>
          <div class="form-tip">支持多角色，权限按并集计算（拥有任一角色允许即可）。</div>
        </el-form-item>
        <el-form-item label="头像">
          <AvatarUpload v-model="form.avatar" :size="76" :required="false" />
        </el-form-item>
        <div v-if="dialogMode === 'create'" class="form-tip">
          新建用户默认密码为 123456，请提醒该用户在「个人中心」自行修改密码。
        </div>
        <div v-else class="form-tip">
          修改角色后需用户重新登录（或刷新）生效。
        </div>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitForm">保存</el-button>
      </template>
    </el-dialog>

    <!-- 分配部门（单个用户） -->
    <el-dialog v-model="singleDeptVisible" title="分配部门" width="420px">
      <el-form label-width="90px">
        <el-form-item label="用户">
          <div class="readonly-field">
            {{ singleDeptTarget?.name }}（{{ singleDeptTarget?.account }}）
          </div>
        </el-form-item>
        <el-form-item label="所属部门">
          <el-select v-model="singleDeptId" placeholder="未分配" clearable style="width: 100%">
            <el-option v-for="d in deptStore.list" :key="d.id" :label="d.name" :value="d.id" />
          </el-select>
        </el-form-item>
        <div class="form-tip">清空并保存可将该用户设为未分配部门。分配后立即生效。</div>
      </el-form>
      <template #footer>
        <el-button @click="singleDeptVisible = false">取消</el-button>
        <el-button type="primary" :loading="assigning" @click="confirmSingleDept">保存</el-button>
      </template>
    </el-dialog>

    <!-- 分配部门（批量） -->
    <el-dialog v-model="deptDialogVisible" title="批量分配部门" width="420px">
      <el-form label-width="90px">
        <el-form-item label="目标部门">
          <el-select v-model="targetDeptId" placeholder="请选择部门" clearable style="width: 100%">
            <el-option v-for="d in deptStore.list" :key="d.id" :label="d.name" :value="d.id" />
          </el-select>
        </el-form-item>
        <div class="form-tip">已选 {{ selected.length }} 个用户，提交后立即生效。</div>
      </el-form>
      <template #footer>
        <el-button @click="deptDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmDept">确定</el-button>
      </template>
    </el-dialog>

    <!-- 调整角色 -->
    <el-dialog v-model="roleDialogVisible" title="批量调整角色" width="420px">
      <el-form label-width="90px">
        <el-form-item label="目标角色">
          <el-select v-model="targetRoles" multiple placeholder="可多选" style="width: 100%">
            <el-option v-for="r in ROLES" :key="r" :label="r" :value="r" />
          </el-select>
        </el-form-item>
        <div class="form-tip">
          已选 {{ selected.length }} 个用户。角色调整后数据可见范围实时生效（重新登录或刷新后按新角色渲染）。
        </div>
      </el-form>
      <template #footer>
        <el-button @click="roleDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmRole">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
/* 页头已移除，操作按钮独占一行并收紧顶部间距 */
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.cell-nowrap {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.cell-stack {
  display: flex;
  flex-direction: column;
  line-height: 1.3;
  font-variant-numeric: tabular-nums;
}

/* 时间列等居中单元格：两行结构整体居中 */
.cell-stack--center {
  align-items: center;
}

.cell-stack__sub {
  font-size: 12px;
  color: var(--color-text-secondary);
}

/* 未分配部门：提醒色，提示需管理员处理 */
.text-pending {
  color: #c08430;
  font-weight: 500;
}
</style>
