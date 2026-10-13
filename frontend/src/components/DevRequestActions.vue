<script setup lang="ts">
/**
 * 需求操作组件：按「角色 + 需求状态」动态渲染操作按钮与弹窗。
 * 列表页与详情页共用；操作成功后 emit('refresh') 让父级重新拉数据。
 * 覆盖动作：评审通过（备注选填）/ 待定（备注必填，暂留评审池）/ 驳回（备注必填，作废终态）、
 *           开发（需求池 → 开发中心）、发布上线（开发中心，开发工程师/管理员）、
 *           修改/删除（提交人本人或管理员，仅评审池）。
 * link：列表内传 true → 文字按钮（与各列表页操作列一致）；详情页不传 → 实心按钮。
 * 说明：原「设计中心」环节已移除——需求池不再经产品经理「认领设计」，改由开发工程师/管理员
 *       点「开发」直接进入开发中心；因此不再有「提交设计」弹窗。
 */
import { computed, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import { useDictStore } from '@/stores/dict'
import {
  battlePublishDevRequest,
  deleteDevRequest,
  developDevRequest,
  getDevRequest,
  reviewDevRequest,
  updateDevRequest,
  type DevAttachmentPayload,
} from '@/api/devreq'
import { canDevReq, canReviewReq, isAdmin } from '@/constants/user'
import AttachmentUpload from '@/components/AttachmentUpload.vue'
import PingcodeSyncDialog from '@/components/PingcodeSyncDialog.vue'
import type { Attachment, DevRequest } from '@/types'

const props = defineProps<{ req: DevRequest; link?: boolean }>()
const emit = defineEmits<{ (e: 'refresh'): void }>()

const auth = useAuthStore()
const dict = useDictStore()

const admin = computed(() => isAdmin(auth.user?.roles))
const isSubmitter = computed(() => props.req.submitterId === auth.user?.id)

/**
 * 按钮形态两套；颜色统一为系统约定：常规动作 primary、销毁性动作 danger。
 * - 列表内（link=true）：文字按钮，同各列表页操作列的写法；
 * - 详情页（不传 link）：实心按钮，同问题详情页操作区。
 */
const btnPrimary = computed(() =>
  props.link
    ? { link: true, type: 'primary' as const }
    : { size: 'small' as const, type: 'primary' as const },
)
const btnDanger = computed(() =>
  props.link
    ? { link: true, type: 'danger' as const }
    : { size: 'small' as const, type: 'danger' as const, plain: true },
)

/* —— 按钮显隐 —— */
const showReview = computed(
  () => props.req.status === '评审池' && canReviewReq(auth.user?.roles),
)
/** 开发（认领开发）：需求池，开发工程师 / 管理员 —— 点击直接进入开发中心 */
const showDevelop = computed(
  () => props.req.status === '需求池' && canDevReq(auth.user?.roles),
)
/** 开发中心发布上线：开发工程师 或 管理员 */
const showBattlePublish = computed(
  () => props.req.status === '开发中心' && canDevReq(auth.user?.roles),
)
/** 同步任务到 PingCode：与发布上线同权限（开发工程师/管理员），仅开发中心且尚未同步。
 *  同步成功后操作栏不再显示同步相关内容（任务编号与链接由列表「PingCode」列承载）。 */
const showSyncTask = computed(
  () =>
    props.req.status === '开发中心' &&
    canDevReq(auth.user?.roles) &&
    !props.req.pingcodeTaskId,
)
/** 修改/删除：提交人本人（自己的）/ 管理员（全部），仅评审池阶段（评审开始后不可改删） */
const showEdit = computed(
  () => props.req.status === '评审池' && (admin.value || isSubmitter.value),
)
const showDelete = showEdit
const hasActions = computed(
  () =>
    showReview.value ||
    showDevelop.value ||
    showBattlePublish.value ||
    showSyncTask.value ||
    showEdit.value,
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

async function onDevelop(): Promise<void> {
  await ElMessageBox.confirm(
    `确定开发需求「${props.req.title}」吗？确认后需求进入开发中心。`,
    '开发需求',
    { confirmButtonText: '开发', cancelButtonText: '取消', type: 'info' },
  )
  busy.value = true
  try {
    await developDevRequest(props.req.id)
    ElMessage.success('已进入开发中心，开始开发吧')
    emit('refresh')
  } finally {
    busy.value = false
  }
}

async function onDelete(): Promise<void> {
  await ElMessageBox.confirm(
    `确定删除需求「${props.req.title}」吗？附件会一并删除，且无法恢复。`,
    '删除需求',
    { confirmButtonText: '确定删除', cancelButtonText: '再想想', type: 'warning' },
  )
  busy.value = true
  try {
    await deleteDevRequest(props.req.id)
    ElMessage.success('需求已删除')
    emit('refresh')
  } finally {
    busy.value = false
  }
}

/* —— 弹窗通用 —— */
const dialogVisible = ref(false)
const submitting = ref(false)
/** 当前打开的弹窗：修改 / 驳回 */
const dialogKind = ref<'edit' | 'reject'>('reject')
const toPayload = (list: Attachment[]): DevAttachmentPayload[] =>
  list.map((a) => ({ url: a.url, name: a.name, size: a.size, mimeType: a.mimeType }))

/* —— 修改需求（评审前；表单与需求提交页一致，附件回填原提交附件） —— */
/** 说明：不包含优先级（提交后由评审委员/产品经理在列表调整，修改保存不覆盖） */
const editForm = reactive({
  title: '',
  moduleId: undefined as number | undefined,
  description: '',
  attachments: [] as Attachment[],
})
/** 编辑期间模块字典停用了原模块时，仍要能显示原模块名 */
const editModuleFallback = ref('')
/** 原提交附件是否已回填：回填失败时提交不带 attachments，后端保持原附件不动 */
const editAttsLoaded = ref(true)

function openEdit(): void {
  editForm.title = props.req.title
  editForm.moduleId = props.req.moduleId ?? undefined
  editForm.description = props.req.description
  editForm.attachments = []
  editModuleFallback.value = props.req.moduleName || ''
  dialogKind.value = 'edit'
  dialogVisible.value = true
  void dict.load()
  // 回填原提交附件（列表接口不带附件明细，按需拉详情）
  editAttsLoaded.value = false
  void getDevRequest(props.req.id)
    .then((detail) => {
      if (dialogKind.value !== 'edit' || !dialogVisible.value) {
        return
      }
      editForm.attachments = detail.attachments
        .filter((a) => a.scene === 'submit')
        .map((a) => ({ id: a.id, url: a.url, name: a.name, size: a.size, mimeType: a.mimeType }))
      editAttsLoaded.value = true
    })
    .catch(() => {
      // 拉取失败：保持未回填，提交时不带 attachments，原附件不会被误删
      editAttsLoaded.value = false
    })
}

async function onEditSubmit(): Promise<void> {
  if (editForm.title.trim().length < 4) {
    ElMessage.warning('需求标题至少 4 个字')
    return
  }
  if (!editForm.moduleId) {
    ElMessage.warning('请选择所属模块')
    return
  }
  if (!editForm.description.trim()) {
    ElMessage.warning('请填写需求描述')
    return
  }
  submitting.value = true
  try {
    await updateDevRequest(props.req.id, {
      title: editForm.title.trim(),
      description: editForm.description.trim(),
      moduleId: editForm.moduleId,
      // 编辑表单不含优先级：不传该字段，后端保留列表内已调整的值
      // 回填失败时不传 attachments，后端保持原附件不动
      attachments: editAttsLoaded.value ? toPayload(editForm.attachments) : undefined,
    })
    ElMessage.success('需求已修改')
    dialogVisible.value = false
    emit('refresh')
  } finally {
    submitting.value = false
  }
}

/* —— 驳回 / 待定（评审池；备注均必填。驳回=作废终态，待定=暂留评审池可再改判） —— */
const rejectForm = reactive({ comment: '', attachments: [] as Attachment[] })
/** 当前弹窗是评审驳回还是评审待定 */
const rejectMode = ref<'reject' | 'pending'>('reject')

function openReject(mode: 'reject' | 'pending'): void {
  rejectMode.value = mode
  rejectForm.comment = ''
  rejectForm.attachments = []
  dialogKind.value = 'reject'
  dialogVisible.value = true
}

/** 弹窗确定按钮统一分发 */
function onDialogSubmit(): void {
  if (dialogKind.value === 'edit') {
    void onEditSubmit()
  } else {
    void onRejectSubmit()
  }
}

async function onRejectSubmit(): Promise<void> {
  if (!rejectForm.comment.trim()) {
    ElMessage.warning(rejectMode.value === 'pending' ? '请填写待定备注' : '请填写驳回原因')
    return
  }
  submitting.value = true
  try {
    if (rejectMode.value === 'pending') {
      await reviewDevRequest(props.req.id, '待定', { comment: rejectForm.comment.trim() })
      ElMessage.success('已标记待定，需求保留在评审池')
    } else {
      await reviewDevRequest(props.req.id, '驳回', {
        comment: rejectForm.comment.trim(),
        attachments: toPayload(rejectForm.attachments),
      })
      ElMessage.success('已驳回，该需求已作废')
    }
    dialogVisible.value = false
    emit('refresh')
  } finally {
    submitting.value = false
  }
}

/* —— 发布上线（开发中心 → 已上线；开发工程师或管理员） —— */
async function onBattlePublish(): Promise<void> {
  await ElMessageBox.confirm(
    `确定将「${props.req.title}」发布上线吗？发布后立即上线（记录上线时间），不可撤回。`,
    '发布上线',
    { confirmButtonText: '确定发布', cancelButtonText: '取消', type: 'warning' },
  )
  busy.value = true
  try {
    await battlePublishDevRequest([props.req.id])
    ElMessage.success('已发布上线')
    emit('refresh')
  } finally {
    busy.value = false
  }
}

/* —— 同步任务到 PingCode（开发中心；弹窗填写关键字段） —— */
const syncDialogVisible = ref(false)
function onSyncTask(): void {
  syncDialogVisible.value = true
}
</script>

<template>
  <div v-if="hasActions" class="dev-actions" :class="{ 'dev-actions--link': link }">
    <!-- 评审池：通过（备注选填）/ 待定（备注必填，暂留评审池）/ 驳回（备注必填，作废） -->
    <template v-if="showReview">
      <el-button v-bind="btnPrimary" :loading="busy" @click="onReviewPass">评审通过</el-button>
      <el-button v-bind="btnPrimary" @click="openReject('pending')">待定</el-button>
      <el-button v-bind="btnDanger" @click="openReject('reject')">驳回</el-button>
    </template>

    <!-- 需求池：开发（开发工程师/管理员；点击直接进入开发中心） -->
    <el-button v-if="showDevelop" v-bind="btnPrimary" :loading="busy" @click="onDevelop">开发</el-button>

    <!-- 开发中心：发布上线（立即上线，记录上线时间） -->
    <el-button v-if="showBattlePublish" v-bind="btnPrimary" :loading="busy" @click="onBattlePublish">发布上线</el-button>

    <!-- 开发中心：同步任务到 PingCode（仅未同步时显示按钮；同步成功后操作栏不再显示相关内容） -->
    <el-button v-if="showSyncTask" v-bind="btnPrimary" :loading="busy" @click="onSyncTask">同步任务</el-button>
    <!-- 修改 / 删除（评审前；提交人本人或管理员） -->
    <el-button v-if="showEdit" v-bind="btnPrimary" @click="openEdit">修改</el-button>
    <el-button v-if="showDelete" v-bind="btnDanger" :loading="busy" @click="onDelete">删除</el-button>

    <el-dialog
      v-model="dialogVisible"
      :title="
        dialogKind === 'edit' ? '修改需求（评审前）'
        : rejectMode === 'pending' ? '评审待定（暂留评审池）'
        : '评审驳回（作废）'
      "
      width="560px"
      append-to-body
      destroy-on-close
    >
      <!-- 修改表单（与需求提交页一致） -->
      <template v-if="dialogKind === 'edit'">
        <el-form label-width="92px">
          <el-form-item label="需求标题" required>
            <el-input v-model="editForm.title" maxlength="100" show-word-limit placeholder="一句话说清要做什么" />
          </el-form-item>
          <el-form-item label="所属模块" required>
            <el-select v-model="editForm.moduleId" placeholder="选择所属模块" style="width: 100%">
              <el-option
                v-for="it in dict.allOf('module')"
                :key="it.id"
                :label="it.name"
                :value="it.id"
              />
            </el-select>
            <div v-if="editModuleFallback && !editForm.moduleId" class="form-tip">原模块「{{ editModuleFallback }}」已不可选，请重新选择</div>
          </el-form-item>
          <el-form-item label="需求描述" required>
            <el-input
              v-model="editForm.description"
              type="textarea"
              :rows="6"
              maxlength="1000"
              show-word-limit
              placeholder="请描述：业务背景、期望的功能效果、涉及的客户与场景、不做的影响"
            />
          </el-form-item>
          <el-form-item label="附件">
            <AttachmentUpload v-model="editForm.attachments" :max="9" tip="选填，需求材料/截图均可，最多 9 个，单个不超过 50MB" />
          </el-form-item>
        </el-form>
      </template>

      <!-- 驳回 / 待定表单 -->
      <template v-else>
        <el-alert
          type="warning"
          :closable="false"
          :title="rejectMode === 'pending'
            ? '待定的需求将保留在评审池并标记「待定」，之后仍可评审通过或驳回。'
            : '评审驳回后该需求将直接作废（终态），提交人如仍需要请重新提交新需求。'"
          style="margin-bottom: 12px"
        />
        <el-input
          v-model="rejectForm.comment"
          type="textarea"
          :rows="4"
          maxlength="500"
          show-word-limit
          :placeholder="rejectMode === 'pending' ? '请填写待定备注（必填）：为什么暂缓、需要补充什么信息' : '请填写驳回原因，提交人会看到'"
        />
        <div v-if="rejectMode === 'reject'" class="dialog-atts">
          <AttachmentUpload v-model="rejectForm.attachments" :max="9" tip="选填，驳回说明附件，最多 9 个" />
        </div>
      </template>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onDialogSubmit">确定</el-button>
      </template>
    </el-dialog>

    <PingcodeSyncDialog
      v-model="syncDialogVisible"
      :ids="[props.req.id]"
      single
      :default-title="props.req.title"
      :default-description="props.req.description"
      :default-priority="props.req.priority"
      @done="emit('refresh')"
    />
  </div>
</template>

<style scoped>
.dev-actions {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 6px;
}

/* link 模式（列表内）：容器不参与布局，让按钮直接作为操作列的子项排布，
   间距规则与各列表页操作列一致（间距由 .cell--actions 统一控制） */
.dev-actions--link {
  display: contents;
}

.dialog-atts {
  margin-top: 12px;
}
</style>
