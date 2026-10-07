<script setup lang="ts">
/**
 * 售后处理表单（列表与详情页复用）。
 * 字段：处理情况（字典）、问题分类（字典，必选）、处理备注（必填 ≥5 字）、处理附件（多附件）、问题价值（字典，必选）。
 * 追加处理（该问题已有处理记录）时，默认回填最新一条处理记录的内容：处理情况/问题分类/问题价值/处理备注。
 * 提交按钮 loading 防重复提交，成功后关闭并触发 saved 事件刷新列表/时间线。
 */
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { fetchHandleRecords, getIssue, handleIssue, type IssueAttachmentPayload } from '@/api/issue'
import { useDictStore } from '@/stores/dict'
import AttachmentUpload from '@/components/AttachmentUpload.vue'
import { openAttachment } from '@/utils/attachment'
import { fmtSize, isImage } from '@/utils/format'
import type { Attachment, Issue } from '@/types'

const props = defineProps<{
  modelValue: boolean
  issue: Issue | null
}>()

const emit = defineEmits<{
  'update:modelValue': [boolean]
  saved: []
}>()

const dict = useDictStore()
const formRef = ref<FormInstance>()
const submitting = ref(false)
/** 提交人上传的附件（抽屉打开时按需拉取；列表数据只带附件数，不含明细） */
const issueAttachments = ref<Attachment[]>([])
const loadingAttachments = ref(false)
/** 是否已按上次处理记录回填（用于提示文案） */
const prefilledFromLast = ref(false)

const form = reactive({
  handleStatusId: undefined as number | undefined,
  categoryId: undefined as number | undefined,
  remark: '',
  valueId: undefined as number | undefined,
  attachments: [] as Attachment[],
})

const visible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const rules: FormRules = {
  handleStatusId: [{ required: true, message: '请选择处理情况', trigger: 'change' }],
  categoryId: [{ required: true, message: '请选择问题分类', trigger: 'change' }],
  valueId: [{ required: true, message: '请选择问题价值', trigger: 'change' }],
  remark: [
    { required: true, message: '请填写处理备注', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) => {
        if (!value || !value.trim()) {
          callback(new Error('请填写处理备注'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}

/** 重置表单为空白态（新增处理时的默认状态） */
function resetForm(): void {
  form.handleStatusId = undefined
  form.categoryId = undefined
  form.remark = ''
  form.valueId = undefined
  form.attachments = []
  prefilledFromLast.value = false
  formRef.value?.clearValidate()
}

watch(visible, (v) => {
  if (v) {
    resetForm()
    void dict.load()
    void loadIssueAttachments()
    void loadLastRecord()
  } else {
    issueAttachments.value = []
    prefilledFromLast.value = false
  }
})

/**
 * 追加处理回填：取该问题最新一条处理记录，回填 处理情况/问题分类/问题价值/处理备注。
 * 首次处理（无处理记录）或拉取失败时保持空白。附件不回填，避免重复上传。
 */
async function loadLastRecord(): Promise<void> {
  const id = props.issue?.id
  if (!id) {
    return
  }
  try {
    const records = await fetchHandleRecords(id)
    // 防止抽屉已切换/关闭后写入过期数据
    if (!props.issue || props.issue.id !== id || !visible.value) {
      return
    }
    const last = records[0]
    if (!last) {
      return
    }
    form.handleStatusId = last.handleStatusId || undefined
    form.categoryId = last.categoryId || undefined
    form.valueId = last.valueId || undefined
    form.remark = last.remark || ''
    prefilledFromLast.value = true
    formRef.value?.clearValidate()
  } catch {
    // 拉取失败保持空白表单，不影响正常处理
  }
}

/** 拉取该问题的提交附件（处理人需要先看到客户提交的材料） */
async function loadIssueAttachments(): Promise<void> {
  const id = props.issue?.id
  if (!id) {
    issueAttachments.value = []
    return
  }
  loadingAttachments.value = true
  try {
    const detail = await getIssue(id)
    issueAttachments.value = detail.attachments ?? []
  } catch {
    // 拉取失败不影响处理流程，仅不展示附件
    issueAttachments.value = []
  } finally {
    loadingAttachments.value = false
  }
}

/** 附件点击：图片在当前页面弹窗预览（同组图片可左右切换）；其他文档强制下载 */
function onAttachmentClick(att: Attachment): void {
  void openAttachment(
    att.url,
    att.mimeType,
    att.name,
    issueAttachments.value
      .filter((s) => isImage(s.mimeType, s.name))
      .map((s) => ({ url: s.url, name: s.name })),
  )
}

async function submit(): Promise<void> {
  if (!props.issue) {
    return
  }
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (!form.handleStatusId || !form.valueId || !form.categoryId) {
    return
  }
  submitting.value = true
  try {
    const payloadAtts: IssueAttachmentPayload[] = form.attachments.map((a) => ({
      url: a.url,
      name: a.name,
      size: a.size,
      mimeType: a.mimeType,
    }))
    await handleIssue({
      id: props.issue.id,
      handleStatusId: form.handleStatusId,
      remark: form.remark.trim(),
      valueId: form.valueId,
      categoryId: form.categoryId,
      attachments: payloadAtts,
    })
    ElMessage.success('处理已提交，记录已保存')
    visible.value = false
    emit('saved')
  } finally {
    submitting.value = false
  }
}

function close(): void {
  visible.value = false
}
</script>

<template>
  <el-drawer v-model="visible" title="售后处理" size="520px" :close-on-click-modal="false" destroy-on-close>
    <div v-if="issue" class="handle-dialog">
      <div class="handle-dialog__issue moon-card moon-card--tight">
        <div class="handle-dialog__row">
          <span class="handle-dialog__label">问题编号</span>
          <span class="num-strong">{{ issue.issueNo }}</span>
        </div>
        <div class="handle-dialog__row">
          <span class="handle-dialog__label">提交人</span>
          <span>{{ issue.submitterName }}（{{ issue.submitterDepartmentName || '未分配部门' }}）</span>
        </div>
        <div class="handle-dialog__row">
          <span class="handle-dialog__label">所属模块</span>
          <span>{{ issue.moduleName || '—' }}</span>
        </div>
        <div class="handle-dialog__row">
          <span class="handle-dialog__label">当前分类</span>
          <span>{{ issue.categoryName || '未分类' }}</span>
        </div>
        <div class="handle-dialog__desc">{{ issue.description }}</div>

        <!-- 提交附件：处理前先看客户提交的材料 -->
        <div class="handle-dialog__atts">
          <div class="handle-dialog__atts-label">
            提交附件
            <span v-if="issueAttachments.length" class="handle-dialog__atts-count">{{ issueAttachments.length }} 个</span>
            <span v-if="loadingAttachments" class="handle-dialog__atts-loading">加载中…</span>
          </div>
          <div v-if="issueAttachments.length" class="attachment-list">
            <a
              v-for="att in issueAttachments"
              :key="att.id"
              class="attachment-chip"
              :class="{ 'is-image': isImage(att.mimeType, att.name) }"
              :href="att.url"
              @click.prevent="onAttachmentClick(att)"
            >
              <span class="attachment-chip__icon" aria-hidden="true">{{ isImage(att.mimeType, att.name) ? 'IMG' : 'FILE' }}</span>
              <span class="attachment-chip__name cell-ellipsis">{{ att.name }}</span>
              <span class="attachment-chip__size">{{ fmtSize(att.size) }}</span>
              <span class="attachment-chip__act">{{ isImage(att.mimeType, att.name) ? '查看' : '下载' }}</span>
            </a>
          </div>
          <div v-else-if="!loadingAttachments" class="handle-dialog__atts-empty">该问题没有提交附件</div>
        </div>
      </div>

      <el-form ref="formRef" :model="form" :rules="rules" label-width="88px" label-position="top">
        <el-form-item label="处理情况" prop="handleStatusId">
          <el-select v-model="form.handleStatusId" placeholder="请选择处理情况" style="width: 100%">
            <el-option
              v-for="it in dict.handleStatuses"
              :key="it.id"
              :label="it.name"
              :value="it.id"
            />
          </el-select>
        </el-form-item>

        <el-form-item label="问题分类" prop="categoryId">
          <el-select v-model="form.categoryId" placeholder="请选择问题分类（必选）" style="width: 100%">
            <el-option v-for="it in dict.categories" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
          <div class="form-tip">
            <template v-if="issue.categoryName">当前分类：{{ issue.categoryName }}；重新选择即可调整。</template>
            <template v-else>用于问题归类与筛选统计，请按问题性质选择。</template>
          </div>
        </el-form-item>

        <el-form-item label="问题价值" prop="valueId">
          <el-select v-model="form.valueId" placeholder="请选择问题价值（必选）" style="width: 100%">
            <el-option v-for="it in dict.values" :key="it.id" :label="it.name" :value="it.id" />
          </el-select>
          <div class="form-tip">价值等级用于统计与排行，请按问题实际价值评定。</div>
        </el-form-item>

        <el-form-item label="处理备注" prop="remark">
          <el-input
            v-model="form.remark"
            type="textarea"
            :rows="4"
            maxlength="500"
            show-word-limit
            placeholder="请填写处理过程与结论"
          />
          <div v-if="prefilledFromLast" class="form-tip">已回填上次处理内容，可直接在此基础上补充或修改。</div>
        </el-form-item>

        <el-form-item label="处理附件">
          <AttachmentUpload v-model="form.attachments" :max="9" />
        </el-form-item>
      </el-form>
    </div>

    <template #footer>
      <div class="handle-dialog__footer">
        <el-button @click="close">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">提交处理</el-button>
      </div>
    </template>
  </el-drawer>
</template>

<style scoped>
.handle-dialog {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.handle-dialog__issue {
  display: flex;
  flex-direction: column;
  gap: 6px;
  background: var(--color-bg-sub);
  box-shadow: none;
}

.handle-dialog__row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}

.handle-dialog__label {
  width: 64px;
  color: var(--color-text-secondary);
  flex: 0 0 auto;
}

.handle-dialog__desc {
  margin-top: 4px;
  padding-top: 8px;
  border-top: 1px solid var(--color-border-divider);
  font-size: 13px;
  line-height: 1.6;
  color: var(--color-text-regular);
}

/* 提交附件区 */
.handle-dialog__atts {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--color-border-divider);
}

.handle-dialog__atts-label {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

.handle-dialog__atts-count {
  padding: 1px 7px;
  border-radius: var(--radius-pill);
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 11.5px;
}

.handle-dialog__atts-loading {
  color: var(--color-text-placeholder);
  font-size: 11.5px;
}

.handle-dialog__atts-empty {
  font-size: 12.5px;
  color: var(--color-text-placeholder);
}

.attachment-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.attachment-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 5px 10px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  border: 1px solid var(--color-border-divider);
  font-size: 12.5px;
  color: var(--color-text-regular);
  text-decoration: none;
  transition: border-color var(--transition-base), background var(--transition-base);
}

.attachment-chip:hover {
  border-color: var(--color-primary-soft);
  background: var(--color-primary-tint);
}

.attachment-chip__icon {
  flex: 0 0 auto;
  padding: 1px 5px;
  border-radius: 4px;
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 10px;
  font-weight: 700;
}

.attachment-chip.is-image .attachment-chip__icon {
  background: rgba(242, 160, 192, 0.18);
  color: #c0678f;
}

.attachment-chip__name {
  max-width: 180px;
}

.attachment-chip__size {
  color: var(--color-text-placeholder);
  font-variant-numeric: tabular-nums;
}

.attachment-chip__act {
  color: var(--color-primary);
  font-size: 12px;
}

.handle-dialog__footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
