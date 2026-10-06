<script setup lang="ts">
/**
 * 需求详情页：六站步骤条 + 全量信息 + 子任务开发面板 + 附件 + 时间线。
 * 操作按钮由 DevRequestActions 按角色与状态动态渲染；
 * 开发中心内由开发负责人创建子任务，子任务负责人开工/提交完成。
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getDevRequest, createSubTask, doneSubTask, startSubTask, fetchDevCandidates } from '@/api/devreq'
import { useAuthStore } from '@/stores/auth'
import { isAdmin } from '@/constants/user'
import { DEV_PRIORITY_CLASS, DEV_STAGES, DEV_STATUS_CLASS, SUB_TASK_CLASS, devStageIndex } from '@/constants/devreq'
import { logTypeClass } from '@/constants/issue'
import DevRequestActions from '@/components/DevRequestActions.vue'
import AttachmentUpload from '@/components/AttachmentUpload.vue'
import { fmtDateTime, fmtSize, isImage } from '@/utils/format'
import { openAttachment } from '@/utils/attachment'
import type { Attachment, DevAttachment, DevCandidate, DevRequestDetail, DevSubTask } from '@/types'

const route = useRoute()
const auth = useAuthStore()

const loading = ref(false)
const detail = ref<DevRequestDetail | null>(null)

const reqId = computed(() => Number(route.params.id) || 0)
const myId = computed(() => auth.user?.id ?? 0)
const admin = computed(() => isAdmin(auth.user?.roles))
const isLeader = computed(() => !!detail.value && detail.value.devLeaderId === myId.value)
const canManageSubs = computed(() => isLeader.value || admin.value)

/** 步骤条进度：正常状态按六站序号；已驳回停在评审池；已取消不展示步骤条 */
const stepActive = computed(() => {
  if (!detail.value) return 0
  if (detail.value.status === '已驳回') return 0
  return devStageIndex(detail.value.status)
})
const isCancelled = computed(() => detail.value?.status === '已取消')

async function load(): Promise<void> {
  if (!reqId.value) return
  loading.value = true
  try {
    detail.value = await getDevRequest(reqId.value)
  } catch {
    detail.value = null
  } finally {
    loading.value = false
  }
}

/* —— 附件按场景分组 —— */
const sceneTitle: Record<string, string> = {
  submit: '需求附件',
  design: '设计文档',
  reject: '驳回说明附件',
  release: '上线材料',
}

const groupedAtts = computed(() => {
  const groups: Array<{ scene: string; title: string; items: DevAttachment[] }> = []
  if (!detail.value) return groups
  const atts = detail.value.attachments
  for (const scene of ['submit', 'design', 'reject', 'release']) {
    const items = atts.filter((a) => a.scene === scene)
    if (items.length) {
      groups.push({ scene, title: sceneTitle[scene] ?? scene, items })
    }
  }
  return groups
})

/** 附件点击：图片弹窗预览（同组图片可切换），其他文档下载 */
function onAttachmentClick(att: DevAttachment, siblings: DevAttachment[]): void {
  void openAttachment(
    att.url,
    att.mimeType,
    att.name,
    siblings.filter((s2) => isImage(s2.mimeType, s2.name)).map((s2) => ({ url: s2.url, name: s2.name })),
  )
}

/* —— 开发中心：子任务管理 —— */
const subDialogVisible = ref(false)
const subSubmitting = ref(false)
const subForm = reactive({ title: '', description: '', assigneeId: undefined as number | undefined })
const devs = ref<DevCandidate[]>([])

async function openSubDialog(): Promise<void> {
  subForm.title = ''
  subForm.description = ''
  subForm.assigneeId = undefined
  subDialogVisible.value = true
  if (!devs.value.length) {
    try {
      devs.value = await fetchDevCandidates()
    } catch {
      devs.value = []
    }
  }
}

async function onSubCreate(): Promise<void> {
  if (subForm.title.trim().length < 4) {
    ElMessage.warning('子任务标题至少 4 个字')
    return
  }
  if (!subForm.assigneeId) {
    ElMessage.warning('请选择子任务负责人')
    return
  }
  subSubmitting.value = true
  try {
    await createSubTask({
      requestId: reqId.value,
      title: subForm.title.trim(),
      description: subForm.description.trim(),
      assigneeId: subForm.assigneeId,
    })
    ElMessage.success('子任务已创建')
    subDialogVisible.value = false
    await load()
  } finally {
    subSubmitting.value = false
  }
}

async function onSubStart(sub: DevSubTask): Promise<void> {
  await startSubTask(sub.id)
  ElMessage.success('已开工')
  await load()
}

/* —— 子任务提交完成 —— */
const doneDialogVisible = ref(false)
const doneSubmitting = ref(false)
const doneTarget = ref<DevSubTask | null>(null)
const doneForm = reactive({ note: '', attachments: [] as Attachment[] })

function openDoneDialog(sub: DevSubTask): void {
  doneTarget.value = sub
  doneForm.note = ''
  doneForm.attachments = []
  doneDialogVisible.value = true
}

async function onDoneSubmit(): Promise<void> {
  if (!doneTarget.value) return
  if (doneForm.note.trim().length < 5) {
    ElMessage.warning('完成说明至少 5 个字')
    return
  }
  doneSubmitting.value = true
  try {
    const res = await doneSubTask(
      doneTarget.value.id,
      doneForm.note.trim(),
      doneForm.attachments.map((a) => ({ url: a.url, name: a.name, size: a.size, mimeType: a.mimeType })),
    )
    if (res.done === res.total) {
      ElMessage.success('全部子任务完成，任务自动进入待发布')
    } else {
      ElMessage.success(`子任务已完成（进度 ${res.done}/${res.total}）`)
    }
    doneDialogVisible.value = false
    await load()
  } finally {
    doneSubmitting.value = false
  }
}

async function onSubAction(sub: DevSubTask, action: 'start' | 'done'): Promise<void> {
  if (action === 'start') {
    await ElMessageBox.confirm(`确定开始「${sub.title}」吗？`, '开工确认', { type: 'info' })
    await onSubStart(sub)
  } else {
    openDoneDialog(sub)
  }
}

function canOperateSub(sub: DevSubTask): boolean {
  return admin.value || sub.assigneeId === myId.value
}

onMounted(load)
watch(reqId, load)
</script>

<template>
  <div class="page" v-loading="loading">
    <template v-if="detail">
      <!-- 步骤条 -->
      <div v-if="!isCancelled" class="moon-card steps-card">
        <el-steps :active="stepActive" align-center finish-status="success">
          <el-step v-for="(s, i) in DEV_STAGES" :key="s" :title="s" :description="stepActive === i && detail.status === '已驳回' ? '已作废' : ''" />
        </el-steps>
        <div class="steps-status">
          <span :class="DEV_STATUS_CLASS[detail.status as keyof typeof DEV_STATUS_CLASS]">{{ detail.status }}</span>
          <span v-if="detail.status === '已驳回'" class="reject-hint">需求未通过评审，已作废（如仍需要请重新提交新需求）</span>
        </div>
      </div>
      <el-alert v-else type="info" :closable="false" title="该需求已被提交人取消" style="margin-bottom: 14px" />

      <!-- 基本信息 -->
      <div class="moon-card info-card">
        <div class="info-head">
          <div class="info-title">
            <h2>{{ detail.title }}</h2>
            <span class="mono">{{ detail.reqNo }}</span>
          </div>
          <div class="info-actions">
            <DevRequestActions :req="detail" @refresh="load" />
          </div>
        </div>

        <div class="desc-block">{{ detail.description }}</div>

        <div class="meta-grid">
          <div class="meta-item">
            <span class="meta-label">状态</span>
            <span :class="DEV_STATUS_CLASS[detail.status as keyof typeof DEV_STATUS_CLASS]">{{ detail.status }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">优先级</span>
            <span :class="DEV_PRIORITY_CLASS[detail.priority as keyof typeof DEV_PRIORITY_CLASS]">{{ detail.priority }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">所属模块</span>
            <span>{{ detail.moduleName || '—' }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">提交人</span>
            <span>{{ detail.submitterName }}（{{ detail.submitterDepartmentName || '未分配部门' }}）</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">提交时间</span>
            <span>{{ fmtDateTime(detail.createdAt) }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">评审</span>
            <span>
              {{ detail.reviewerName ? `${detail.reviewerName} · ${fmtDateTime(detail.reviewedAt ?? '')}` : '待评审' }}
              <em v-if="detail.reviewComment" class="meta-note">{{ detail.reviewComment }}</em>
            </span>
          </div>
          <div class="meta-item">
            <span class="meta-label">产品经理</span>
            <span>{{ detail.pmName || '待认领' }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">开发分支</span>
            <span class="mono">{{ detail.branch || '—' }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">开发负责人</span>
            <span>{{ detail.devLeaderName || '—' }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">上线</span>
            <span>
              {{ detail.releasedAt ? `${fmtDateTime(detail.releasedAt)}（发布：${detail.releasedBy ?? '—'}）` : '未上线' }}
            </span>
          </div>
        </div>

        <div v-if="detail.designDoc" class="design-doc">
          <div class="design-doc__title">设计说明（{{ detail.pmName }}）</div>
          <div class="design-doc__body">{{ detail.designDoc }}</div>
        </div>
      </div>

      <!-- 附件 -->
      <div v-if="groupedAtts.length" class="moon-card atts-card">
        <h3 class="card-title">附件</h3>
        <div v-for="g in groupedAtts" :key="g.scene" class="att-group">
          <div class="att-group__title">{{ g.title }}</div>
          <div class="att-list">
            <a
              v-for="a in g.items"
              :key="a.id"
              class="att-file"
              :href="a.url"
              :class="{ 'is-image': isImage(a.mimeType, a.name) }"
              :title="isImage(a.mimeType, a.name) ? '点击查看图片' : '点击下载文件'"
              @click.prevent="onAttachmentClick(a, g.items)"
            >
              <span class="att-file__icon" aria-hidden="true">{{ isImage(a.mimeType, a.name) ? 'IMG' : 'FILE' }}</span>
              <span class="att-file__name">{{ a.name }}</span>
              <span class="att-file__size">{{ fmtSize(a.size) }}</span>
              <span class="att-file__act">{{ isImage(a.mimeType, a.name) ? '查看' : '下载' }}</span>
            </a>
          </div>
        </div>
      </div>

      <!-- 子任务（开发面板） -->
      <div class="moon-card subs-card">
        <div class="subs-head">
          <h3 class="card-title">子任务（{{ detail.subDone }}/{{ detail.subTotal }} 完成）</h3>
          <el-button
            v-if="detail.status === '开发中心' && canManageSubs"
            size="small"
            type="primary"
            @click="openSubDialog"
          >创建子任务</el-button>
        </div>

        <el-table v-if="detail.subTasks.length" :data="detail.subTasks" style="width: 100%">
          <el-table-column label="子任务" min-width="200">
            <template #default="{ row }">
              <div>{{ row.title }}</div>
              <div v-if="row.description" class="sub-text">{{ row.description }}</div>
            </template>
          </el-table-column>
          <el-table-column label="负责人" width="100">
            <template #default="{ row }">{{ row.assigneeName }}</template>
          </el-table-column>
          <el-table-column label="开发分支" width="180">
            <template #default="{ row }">
              <span class="mono">{{ row.branch || '—' }}</span>
              <div class="sub-text">继承主任务</div>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <span :class="SUB_TASK_CLASS[row.status as keyof typeof SUB_TASK_CLASS]">{{ row.status }}</span>
            </template>
          </el-table-column>
          <el-table-column label="完成说明" min-width="180">
            <template #default="{ row }">
              <template v-if="row.doneNote">
                <div>{{ row.doneNote }}</div>
                <div class="sub-text">{{ fmtDateTime(row.doneAt ?? '') }}</div>
                <div v-if="row.attachments.length" class="sub-atts">
                  <a v-for="a in row.attachments" :key="a.id" class="sub-att" :href="a.url" target="_blank" rel="noopener">
                    {{ a.name }}
                  </a>
                </div>
              </template>
              <template v-else>—</template>
            </template>
          </el-table-column>
          <el-table-column v-if="detail.status === '开发中心'" label="操作" width="160" fixed="right">
            <template #default="{ row }">
              <template v-if="canOperateSub(row) && row.status !== '已完成'">
                <el-button v-if="row.status === '待开发' || row.status === '需返工'" size="small" link type="primary" @click="onSubAction(row, 'start')">开工</el-button>
                <el-button size="small" link type="success" @click="onSubAction(row, 'done')">
                  {{ row.status === '需返工' ? '返工完成' : '提交完成' }}
                </el-button>
              </template>
              <span v-else class="sub-text">{{ row.status === '已完成' ? '已完成' : '' }}</span>
            </template>
          </el-table-column>
          <template #empty>尚未创建子任务</template>
        </el-table>
        <div v-else class="subs-empty">
          {{ detail.status === '开发中心' ? (canManageSubs ? '请拆解开发子任务（需指定负责人），全部完成后自动进入待发布' : '开发负责人正在拆解子任务') : '—' }}
        </div>
      </div>

      <!-- 时间线 -->
      <div class="moon-card logs-card">
        <h3 class="card-title">流转时间线</h3>
        <ul v-if="detail.logs.length" class="logs">
          <li v-for="l in detail.logs" :key="l.id" class="log-item">
            <span class="log-item__dot" aria-hidden="true" />
            <div class="log-item__main">
              <div class="log-item__head">
                <span :class="logTypeClass(l.type)">{{ l.type }}</span>
                <span class="log-item__who">{{ l.account }}（{{ l.role }}）</span>
                <span class="log-item__time">{{ fmtDateTime(l.createdAt) }}</span>
              </div>
              <div class="log-item__body">{{ l.content }}</div>
            </div>
          </li>
        </ul>
        <div v-else class="subs-empty">暂无流转记录</div>
      </div>
    </template>

    <div v-else-if="!loading" class="subs-empty">需求不存在或不在您的可见范围</div>

    <!-- 创建子任务弹窗 -->
    <el-dialog v-model="subDialogVisible" title="创建子任务" width="520px" destroy-on-close>
      <el-form label-width="92px">
        <el-form-item label="子任务标题" required>
          <el-input v-model="subForm.title" maxlength="100" show-word-limit placeholder="如：导出中心接口开发" />
        </el-form-item>
        <el-form-item label="任务说明">
          <el-input v-model="subForm.description" type="textarea" :rows="3" maxlength="500" show-word-limit />
        </el-form-item>
        <el-form-item label="负责人" required>
          <el-select v-model="subForm.assigneeId" placeholder="选择开发工程师" style="width: 100%">
            <el-option
              v-for="d in devs"
              :key="d.id"
              :label="`${d.name}（${d.departmentName || d.account}）`"
              :value="d.id"
            />
          </el-select>
          <div class="form-tip">开发分支自动继承主任务：{{ detail?.branch || '—' }}</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="subDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="subSubmitting" @click="onSubCreate">创建</el-button>
      </template>
    </el-dialog>

    <!-- 子任务完成弹窗 -->
    <el-dialog v-model="doneDialogVisible" :title="`提交完成：${doneTarget?.title ?? ''}`" width="520px" destroy-on-close>
      <el-input
        v-model="doneForm.note"
        type="textarea"
        :rows="4"
        maxlength="1000"
        show-word-limit
        placeholder="完成说明：做了什么、自测情况、需要注意的点（至少 5 个字）"
      />
      <div style="margin-top: 12px">
        <AttachmentUpload v-model="doneForm.attachments" :max="9" tip="选填，完成凭证/截图，最多 9 个" />
      </div>
      <template #footer>
        <el-button @click="doneDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="doneSubmitting" @click="onDoneSubmit">提交完成</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.steps-card {
  padding: 18px 18px 12px;
  margin-bottom: 14px;
}

.steps-status {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-top: 4px;
}

.reject-hint {
  font-size: 12px;
  color: var(--color-danger, #c45656);
}

.info-card {
  margin-bottom: 14px;
}

.info-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.info-title {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}

.info-title h2 {
  margin: 0;
  font-size: 17px;
  color: var(--color-text-primary);
}

.mono {
  font-family: var(--font-mono, monospace);
  font-size: 12px;
  color: var(--color-text-secondary);
}

.desc-block {
  margin-top: 10px;
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  font-size: 13.5px;
  line-height: 1.7;
  color: var(--color-text-regular);
  white-space: pre-wrap;
  word-break: break-word;
}

.meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 8px 24px;
  margin-top: 14px;
}

.meta-item {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 13px;
  min-width: 0;
}

.meta-label {
  flex: 0 0 auto;
  color: var(--color-text-secondary);
}

.meta-label::after {
  content: '：';
}

.meta-note {
  display: block;
  font-style: normal;
  font-size: 12px;
  color: var(--color-text-secondary);
}

.design-doc {
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: var(--radius-control);
  border: 1px dashed var(--color-border-divider);
}

.design-doc__title {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--color-text-secondary);
}

.design-doc__body {
  margin-top: 6px;
  font-size: 13px;
  line-height: 1.7;
  color: var(--color-text-regular);
  white-space: pre-wrap;
  word-break: break-word;
}

.card-title {
  margin: 0 0 10px;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.atts-card,
.subs-card,
.logs-card {
  margin-bottom: 14px;
}

.att-group {
  margin-bottom: 10px;
}

.att-group__title {
  font-size: 12.5px;
  color: var(--color-text-secondary);
  margin-bottom: 6px;
}

.att-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.att-file {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  color: var(--color-text-regular);
  text-decoration: none;
  font-size: 12.5px;
}

.att-file:hover {
  background: var(--color-primary-tint);
  color: var(--color-primary);
}

.att-file__icon {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--color-primary);
}

.att-file__size {
  font-size: 11px;
  color: var(--color-text-placeholder);
}

.att-file.is-image .att-file__icon {
  color: #2c7d5e;
}

.att-file__act {
  font-size: 11px;
  color: var(--color-primary);
}

.subs-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.sub-text {
  margin-top: 2px;
  font-size: 11.5px;
  color: var(--color-text-secondary);
}

.sub-atts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
}

.sub-att {
  font-size: 12px;
  color: var(--color-primary);
  text-decoration: none;
}

.sub-att:hover {
  text-decoration: underline;
}

.subs-empty {
  padding: 18px 0;
  text-align: center;
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

.logs {
  margin: 0;
  padding: 0;
  list-style: none;
}

.log-item {
  position: relative;
  display: flex;
  gap: 10px;
  padding: 0 0 16px 18px;
}

.log-item::before {
  content: '';
  position: absolute;
  left: 5px;
  top: 14px;
  bottom: 0;
  width: 1px;
  background: var(--color-border-divider);
}

.log-item:last-child::before {
  display: none;
}

.log-item__dot {
  position: absolute;
  left: 2px;
  top: 6px;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--color-primary);
}

.log-item__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.log-item__who {
  font-size: 12.5px;
  color: var(--color-text-regular);
}

.log-item__time {
  font-size: 11.5px;
  color: var(--color-text-placeholder);
  font-variant-numeric: tabular-nums;
}

.log-item__body {
  margin-top: 4px;
  font-size: 12.8px;
  line-height: 1.6;
  color: var(--color-text-regular);
  word-break: break-word;
}

.form-tip {
  font-size: 11.5px;
  color: var(--color-text-secondary);
  margin-top: 4px;
}
</style>
