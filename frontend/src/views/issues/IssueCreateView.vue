<script setup lang="ts">
/**
 * 问题提交页。
 * 提交人/提交部门自动带入且只读；所属模块取启用中的字典；附件最多 9 个。
 * 提交前二次确认，成功后提示并跳转问题列表（新记录高亮）。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { createIssue, type IssueAttachmentPayload } from '@/api/issue'
import { fetchDutyToday } from '@/api/duty'
import { useAuthStore } from '@/stores/auth'
import { useDictStore } from '@/stores/dict'
import { useIssueStore } from '@/stores/issue'
import AttachmentUpload from '@/components/AttachmentUpload.vue'
import DutySignboard from '@/components/DutySignboard.vue'
import NoticeSignboard from '@/components/NoticeSignboard.vue'
import { fetchLatestNotice } from '@/api/notice'
import type { Attachment, DutyToday, Notice } from '@/types'

const auth = useAuthStore()
const dict = useDictStore()
const issueStore = useIssueStore()
const router = useRouter()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const dutyToday = ref<DutyToday | null>(null)
/** 最新一条发布中的公告（无则不展示公告灯牌） */
const latestNotice = ref<Notice | null>(null)

const form = reactive({
  moduleId: undefined as number | undefined,
  description: '',
  attachments: [] as Attachment[],
})

const descLength = computed(() => form.description.trim().length)
const noDepartment = computed(() => !auth.user?.departmentId)

const rules: FormRules = {
  moduleId: [{ required: true, message: '请选择所属模块', trigger: 'change' }],
  description: [
    { required: true, message: '请填写问题描述', trigger: 'blur' },
    {
      validator: (_r, value: string, cb) => {
        if (!value || !value.trim()) {
          cb(new Error('请填写问题描述'))
          return
        }
        cb()
      },
      trigger: 'blur',
    },
  ],
}

async function onSubmit(): Promise<void> {
  if (noDepartment.value) {
    ElMessage.error('请联系管理员分配部门')
    return
  }
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (!form.moduleId) {
    return
  }
  await ElMessageBox.confirm('提交后问题将流转给售后同学处理，确定提交吗？', '确认提交', {
    confirmButtonText: '确定提交',
    cancelButtonText: '再检查一下',
    type: 'info',
  })
  submitting.value = true
  try {
    const atts: IssueAttachmentPayload[] = form.attachments.map((a) => ({
      url: a.url,
      name: a.name,
      size: a.size,
      mimeType: a.mimeType,
    }))
    const res = await createIssue({
      description: form.description.trim(),
      moduleId: form.moduleId,
      attachments: atts,
    })
    issueStore.highlightId = res.id
    ElMessage.success('已保存，问题已提交给售后同学')
    await router.push('/issues')
  } finally {
    submitting.value = false
  }
}

function onCancel(): void {
  void router.back()
}

onMounted(() => {
  void dict.load()
  void loadDuty()
  void loadNotice()
})

/** 最新公告（右侧公告灯牌用；失败静默，不影响提交） */
async function loadNotice(): Promise<void> {
  try {
    const res = await fetchLatestNotice()
    latestNotice.value = res.notice
  } catch {
    latestNotice.value = null
  }
}

/** 今日值班人（右侧灯牌展示用；失败时静默，不影响提交功能） */
async function loadDuty(): Promise<void> {
  try {
    dutyToday.value = await fetchDutyToday()
  } catch {
    dutyToday.value = null
  }
}
</script>

<template>
  <div class="page">
    <div v-if="noDepartment" class="scope-notice">
      <span aria-hidden="true">!</span>
      当前账号尚未分配部门，请联系管理员分配部门后再提交问题。
    </div>

    <div class="create-layout">
      <div class="moon-card moon-card--moonlit">
        <el-form ref="formRef" :model="form" :rules="rules" label-width="92px" label-position="right">
          <el-form-item label="提交人">
            <div class="readonly-field">{{ auth.user?.name || auth.user?.account }}</div>
          </el-form-item>

          <el-form-item label="提交部门">
            <div class="readonly-field">
              {{ auth.user?.departmentName || '未分配部门（请联系管理员分配）' }}
            </div>
          </el-form-item>

          <el-form-item label="所属模块" prop="moduleId">
            <el-select v-model="form.moduleId" placeholder="请选择所属模块" style="width: 320px">
              <el-option v-for="it in dict.enabledOf('module')" :key="it.id" :label="it.name" :value="it.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="问题描述" prop="description">
            <el-input
              v-model="form.description"
              type="textarea"
              :rows="6"
              maxlength="1000"
              show-word-limit
              placeholder="请描述：客户遇到的现象、发生时间、已尝试的处理、对客户的影响"
            />
            <div class="form-tip">已输入 {{ descLength }} 字</div>
          </el-form-item>

          <el-form-item label="附件上传">
            <AttachmentUpload v-model="form.attachments" :max="9" tip="选填，图片或文档均可，最多 9 个，单个不超过 50MB" />
          </el-form-item>

          <el-form-item label=" ">
            <div class="form-actions">
              <el-button @click="onCancel">取消</el-button>
              <el-button type="primary" :loading="submitting" @click="onSubmit">提交</el-button>
            </div>
          </el-form-item>
      </el-form>
      </div>

      <!-- 右侧：今日值班灯牌（小狗举牌 + 跑马灯） -->
      <div class="create-side">
        <DutySignboard :duty="dutyToday" />
        <!-- 公告灯牌（小猫举牌）：无公告时不渲染 -->
        <NoticeSignboard :notice="latestNotice" />
      </div>
    </div>
  </div>
</template>

<style scoped>
/* 左右两栏：左表单（主）+ 右值班灯牌；窄屏自动堆叠 */
.create-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 330px;
  gap: var(--space-6);
  align-items: start;
}

.form-actions {
  display: flex;
  gap: 8px;
}

/* 右侧灯牌列：值班在上、公告在下 */
.create-side {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

@media (max-width: 1180px) {
  .create-layout {
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-4);
  }
}
</style>
