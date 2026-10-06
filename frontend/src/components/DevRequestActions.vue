<script setup lang="ts">
/**
 * 需求操作组件：按「角色 + 需求状态」动态渲染操作按钮与弹窗。
 * 列表页与详情页共用；操作成功后 emit('refresh') 让父级重新拉数据。
 * 覆盖动作：评审通过/驳回（驳回即作废）、取消、认领、提交设计、提交开发完成、发布上线。
 */
import { computed, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import {
  cancelDevRequest,
  claimDevRequest,
  designDevRequest,
  devDoneDevRequest,
  fetchDevCandidates,
  releaseDevRequest,
  reviewDevRequest,
  type DevAttachmentPayload,
} from '@/api/devreq'
import { canDesignReq, canReviewReq, isAdmin } from '@/constants/user'
import AttachmentUpload from '@/components/AttachmentUpload.vue'
import type { Attachment, DevCandidate, DevRequest } from '@/types'

const props = defineProps<{ req: DevRequest }>()
const emit = defineEmits<{ (e: 'refresh'): void }>()

const auth = useAuthStore()

const admin = computed(() => isAdmin(auth.user?.roles))
const isClaimer = computed(() => props.req.pmId !== null && props.req.pmId === auth.user?.id)
const isDevLeader = computed(
  () => props.req.devLeaderId !== null && props.req.devLeaderId === auth.user?.id,
)

/* —— 按钮显隐 —— */
const showReview = computed(
  () => props.req.status === '评审池' && canReviewReq(auth.user?.roles),
)
const showClaim = computed(
  () => props.req.status === '需求池' && canDesignReq(auth.user?.roles),
)
const showDesign = computed(
  () =>
    props.req.status === '设计中心' &&
    canDesignReq(auth.user?.roles) &&
    (admin.value || isClaimer.value),
)
const showDevDone = computed(
  () => props.req.status === '开发中心' && (admin.value || isDevLeader.value),
)
const showRelease = computed(
  () => props.req.status === '待发布' && canReviewReq(auth.user?.roles),
)
const showCancel = computed(
  () =>
    ['评审池', '需求池'].includes(props.req.status) &&
    (props.req.submitterId === auth.user?.id || admin.value),
)
const hasActions = computed(
  () =>
    showReview.value ||
    showClaim.value ||
    showDesign.value ||
    showDevDone.value ||
    showRelease.value ||
    showCancel.value,
)

/* —— 简单确认类动作 —— */
const busy = ref(false)

async function onReviewPass(): Promise<void> {
  await ElMessageBox.confirm(`确定通过需求「${props.req.title}」的评审吗？通过后进入需求池。`, '评审通过', {
    confirmButtonText: '通过',
    cancelButtonText: '取消',
    type: 'warning',
  })
  busy.value = true
  try {
    await reviewDevRequest(props.req.id, '通过')
    ElMessage.success('评审通过，需求已进入需求池')
    emit('refresh')
  } finally {
    busy.value = false
  }
}

async function onClaim(): Promise<void> {
  await ElMessageBox.confirm(`认领需求「${props.req.title}」并进入设计中心吗？`, '认领任务', {
    confirmButtonText: '认领',
    cancelButtonText: '取消',
    type: 'info',
  })
  busy.value = true
  try {
    await claimDevRequest(props.req.id)
    ElMessage.success('认领成功，已进入设计中心')
    emit('refresh')
  } finally {
    busy.value = false
  }
}

async function onRelease(): Promise<void> {
  await ElMessageBox.confirm(`确定把「${props.req.title}」发布上线吗？上线后需求进入终态。`, '发布上线', {
    confirmButtonText: '确认发布',
    cancelButtonText: '取消',
    type: 'warning',
  })
  busy.value = true
  try {
    await releaseDevRequest(props.req.id)
    ElMessage.success('已发布，需求正式上线')
    emit('refresh')
  } finally {
    busy.value = false
  }
}

async function onCancel(): Promise<void> {
  await ElMessageBox.confirm('取消后需求将终止流转，确定取消吗？', '取消需求', {
    confirmButtonText: '确定取消',
    cancelButtonText: '再想想',
    type: 'warning',
  })
  busy.value = true
  try {
    await cancelDevRequest(props.req.id)
    ElMessage.success('需求已取消')
    emit('refresh')
  } finally {
    busy.value = false
  }
}

/* —— 弹窗通用 —— */
const dialogVisible = ref(false)
const submitting = ref(false)
/** 当前打开的弹窗：驳回 / 提交设计 / 提交开发完成 */
const dialogKind = ref<'reject' | 'design' | 'devdone'>('reject')
const toPayload = (list: Attachment[]): DevAttachmentPayload[] =>
  list.map((a) => ({ url: a.url, name: a.name, size: a.size, mimeType: a.mimeType }))

/* —— 驳回（评审驳回 = 作废） —— */
const rejectForm = reactive({ comment: '', attachments: [] as Attachment[] })

function openReject(): void {
  rejectForm.comment = ''
  rejectForm.attachments = []
  dialogKind.value = 'reject'
  dialogVisible.value = true
}

/** 弹窗确定按钮统一分发 */
function onDialogSubmit(): void {
  if (dialogKind.value === 'reject') {
    void onRejectSubmit()
  } else if (dialogKind.value === 'design') {
    void onDesignSubmit()
  } else {
    void onDevDoneSubmit()
  }
}

async function onRejectSubmit(): Promise<void> {
  if (rejectForm.comment.trim().length < 5) {
    ElMessage.warning('驳回原因至少 5 个字')
    return
  }
  submitting.value = true
  try {
    await reviewDevRequest(props.req.id, '驳回', {
      comment: rejectForm.comment.trim(),
      attachments: toPayload(rejectForm.attachments),
    })
    ElMessage.success('已驳回，该需求已作废')
    dialogVisible.value = false
    emit('refresh')
  } finally {
    submitting.value = false
  }
}

/* —— 提交开发完成（开发中心 → 待发布） —— */
const devDoneForm = reactive({ doneNote: '', attachments: [] as Attachment[] })

function openDevDone(): void {
  devDoneForm.doneNote = ''
  devDoneForm.attachments = []
  dialogKind.value = 'devdone'
  dialogVisible.value = true
}

async function onDevDoneSubmit(): Promise<void> {
  if (devDoneForm.doneNote.trim().length < 5) {
    ElMessage.warning('完成说明至少 5 个字')
    return
  }
  submitting.value = true
  try {
    await devDoneDevRequest(props.req.id, devDoneForm.doneNote.trim(), toPayload(devDoneForm.attachments))
    ElMessage.success('开发完成已提交，需求进入待发布')
    dialogVisible.value = false
    emit('refresh')
  } finally {
    submitting.value = false
  }
}

/* —— 提交设计（设计中心） —— */
const designForm = reactive({
  designDoc: '',
  branch: '',
  devLeaderId: undefined as number | undefined,
  attachments: [] as Attachment[],
})
const devs = ref<DevCandidate[]>([])

async function openDesign(): Promise<void> {
  designForm.designDoc = ''
  designForm.branch = ''
  designForm.devLeaderId = undefined
  designForm.attachments = []
  dialogKind.value = 'design'
  dialogVisible.value = true
  if (!devs.value.length) {
    try {
      devs.value = await fetchDevCandidates()
    } catch {
      devs.value = []
    }
  }
}

async function onDesignSubmit(): Promise<void> {
  if (designForm.designDoc.trim().length < 10) {
    ElMessage.warning('设计说明至少 10 个字')
    return
  }
  if (!designForm.branch.trim()) {
    ElMessage.warning('请填写开发分支')
    return
  }
  if (!designForm.devLeaderId) {
    ElMessage.warning('请选择开发负责人')
    return
  }
  submitting.value = true
  try {
    await designDevRequest(props.req.id, {
      designDoc: designForm.designDoc.trim(),
      branch: designForm.branch.trim(),
      devLeaderId: designForm.devLeaderId,
      attachments: toPayload(designForm.attachments),
    })
    ElMessage.success('设计已提交，任务进入开发中心')
    dialogVisible.value = false
    emit('refresh')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div v-if="hasActions" class="dev-actions">
    <!-- 评审池 -->
    <template v-if="showReview">
      <el-button size="small" type="primary" :loading="busy" @click="onReviewPass">评审通过</el-button>
      <el-button size="small" type="danger" plain @click="openReject">驳回</el-button>
    </template>

    <!-- 需求池 -->
    <el-button v-if="showClaim" size="small" type="primary" :loading="busy" @click="onClaim">认领设计</el-button>

    <!-- 设计中心 -->
    <el-button v-if="showDesign" size="small" type="primary" @click="openDesign">提交设计</el-button>

    <!-- 开发中心 -->
    <el-button v-if="showDevDone" size="small" type="primary" @click="openDevDone">提交开发完成</el-button>

    <!-- 待发布 -->
    <el-button v-if="showRelease" size="small" type="success" :loading="busy" @click="onRelease">执行发布</el-button>

    <!-- 取消 -->
    <el-button v-if="showCancel" size="small" type="info" plain :loading="busy" @click="onCancel">取消需求</el-button>

    <el-dialog
      v-model="dialogVisible"
      :title="
        dialogKind === 'design' ? '提交设计（设计中心 → 开发中心）'
        : dialogKind === 'devdone' ? '提交开发完成（开发中心 → 待发布）'
        : '评审驳回（作废）'
      "
      width="560px"
      destroy-on-close
    >
      <!-- 驳回表单 -->
      <template v-if="dialogKind === 'reject'">
        <el-alert
          type="warning"
          :closable="false"
          title="评审驳回后该需求将直接作废（终态），提交人如仍需要请重新提交新需求。"
          style="margin-bottom: 12px"
        />
        <el-input
          v-model="rejectForm.comment"
          type="textarea"
          :rows="4"
          maxlength="500"
          show-word-limit
          placeholder="请填写驳回原因，提交人会看到（至少 5 个字）"
        />
        <div class="dialog-atts">
          <AttachmentUpload v-model="rejectForm.attachments" :max="9" tip="选填，驳回说明附件，最多 9 个" />
        </div>
      </template>

      <!-- 设计表单 -->
      <template v-else-if="dialogKind === 'design'">
        <el-form label-width="92px">
          <el-form-item label="设计说明" required>
            <el-input
              v-model="designForm.designDoc"
              type="textarea"
              :rows="5"
              maxlength="2000"
              show-word-limit
              placeholder="功能设计方案要点、交互与边界说明（至少 10 个字）"
            />
          </el-form-item>
          <el-form-item label="设计文档">
            <AttachmentUpload v-model="designForm.attachments" :max="9" tip="选填，原型/设计稿等附件，最多 9 个" />
          </el-form-item>
          <el-form-item label="开发分支" required>
            <el-input v-model="designForm.branch" maxlength="100" placeholder="如 feature/export-center" />
          </el-form-item>
          <el-form-item label="开发负责人" required>
            <el-select v-model="designForm.devLeaderId" placeholder="选择开发工程师" style="width: 100%">
              <el-option
                v-for="d in devs"
                :key="d.id"
                :label="`${d.name}（${d.departmentName || d.account}）`"
                :value="d.id"
              />
            </el-select>
          </el-form-item>
        </el-form>
      </template>

      <!-- 开发完成表单 -->
      <template v-else>
        <el-input
          v-model="devDoneForm.doneNote"
          type="textarea"
          :rows="4"
          maxlength="1000"
          show-word-limit
          placeholder="完成说明：做了什么、自测情况、需要注意的点（至少 5 个字）"
        />
        <div class="dialog-atts">
          <AttachmentUpload v-model="devDoneForm.attachments" :max="9" tip="选填，完成凭证/截图，最多 9 个" />
        </div>
      </template>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onDialogSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.dev-actions {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 6px;
}

.dialog-atts {
  margin-top: 12px;
}
</style>
