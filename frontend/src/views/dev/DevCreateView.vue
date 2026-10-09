<script setup lang="ts">
/**
 * 需求提交页（需求管理模块入口）。
 * 提交人/提交部门自动带入且只读；所属模块取启用中的字典；附件最多 9 个。
 * 提交后进入评审池，等待评审委员会评审（评审驳回即作废）。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { createDevRequest, type DevAttachmentPayload } from '@/api/devreq'
import { useAuthStore } from '@/stores/auth'
import { useDictStore } from '@/stores/dict'
import { DEV_PRIORITY_OPTIONS } from '@/constants/devreq'
import AttachmentUpload from '@/components/AttachmentUpload.vue'
import NoticeSignboard from '@/components/NoticeSignboard.vue'
import { fetchLatestNotice } from '@/api/notice'
import type { Attachment, Notice } from '@/types'

const auth = useAuthStore()
const dict = useDictStore()
const router = useRouter()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const latestNotice = ref<Notice | null>(null)

const form = reactive({
  title: '',
  moduleId: undefined as number | undefined,
  priority: '中' as string,
  description: '',
  attachments: [] as Attachment[],
})

const descLength = computed(() => form.description.trim().length)
const noDepartment = computed(() => !auth.user?.departmentId)

const rules: FormRules = {
  title: [
    { required: true, message: '请填写需求标题', trigger: 'blur' },
    {
      validator: (_r, value: string, cb) => {
        if (!value || value.trim().length < 4) {
          cb(new Error('需求标题至少 4 个字，一句话说清要做什么'))
          return
        }
        cb()
      },
      trigger: 'blur',
    },
  ],
  moduleId: [{ required: true, message: '请选择所属模块', trigger: 'change' }],
  priority: [{ required: true, message: '请选择优先级', trigger: 'change' }],
  description: [
    { required: true, message: '请填写需求描述', trigger: 'blur' },
    {
      validator: (_r, value: string, cb) => {
        if (!value || !value.trim()) {
          cb(new Error('请填写需求描述'))
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
  if (!valid || !form.moduleId) {
    return
  }
  await ElMessageBox.confirm('提交后需求将进入评审池，由评审委员会评审，确定提交吗？', '确认提交', {
    confirmButtonText: '确定提交',
    cancelButtonText: '再检查一下',
    type: 'info',
  })
  submitting.value = true
  try {
    const atts: DevAttachmentPayload[] = form.attachments.map((a) => ({
      url: a.url,
      name: a.name,
      size: a.size,
      mimeType: a.mimeType,
    }))
    await createDevRequest({
      title: form.title.trim(),
      description: form.description.trim(),
      moduleId: form.moduleId,
      priority: form.priority,
      attachments: atts,
    })
    ElMessage.success('需求已提交，进入评审池等待评审')
    await router.push('/dev/mine')
  } finally {
    submitting.value = false
  }
}

function onCancel(): void {
  void router.back()
}

onMounted(() => {
  void dict.load()
  void loadNotice()
})

async function loadNotice(): Promise<void> {
  try {
    const res = await fetchLatestNotice()
    latestNotice.value = res.notice
  } catch {
    latestNotice.value = null
  }
}
</script>

<template>
  <div class="page">
    <div v-if="noDepartment" class="scope-notice">
      <span aria-hidden="true">!</span>
      当前账号尚未分配部门，请联系管理员分配部门后再提交需求。
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

          <el-form-item label="需求标题" prop="title">
            <el-input
              v-model="form.title"
              maxlength="100"
              show-word-limit
              placeholder="一句话说清要做什么，如：客户对账单导出增加筛选条件"
            />
          </el-form-item>

          <el-form-item label="所属模块" prop="moduleId">
            <el-select v-model="form.moduleId" placeholder="请选择所属模块" style="width: 320px">
              <el-option v-for="it in dict.enabledOf('module')" :key="it.id" :label="it.name" :value="it.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="优先级" prop="priority">
            <el-radio-group v-model="form.priority">
              <el-radio-button v-for="p in DEV_PRIORITY_OPTIONS" :key="p" :value="p">{{ p }}</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="需求描述" prop="description">
            <el-input
              v-model="form.description"
              type="textarea"
              :rows="6"
              maxlength="1000"
              show-word-limit
              placeholder="请描述：业务背景、期望的功能效果、涉及的客户与场景、不做的影响"
            />
            <div class="form-tip">已输入 {{ descLength }} 字</div>
          </el-form-item>

          <el-form-item label="附件上传">
            <AttachmentUpload v-model="form.attachments" :max="9" tip="选填，需求材料/截图均可，最多 9 个，单个不超过 50MB" />
          </el-form-item>

          <el-form-item label=" ">
            <div class="form-actions">
              <el-button @click="onCancel">取消</el-button>
              <el-button type="primary" :loading="submitting" @click="onSubmit">提交需求</el-button>
            </div>
          </el-form-item>
        </el-form>
      </div>

      <!-- 右侧：公告灯牌（需求提交页不放今日值班牌） -->
      <div class="create-side">
        <NoticeSignboard :notice="latestNotice" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.create-layout {
  display: flex;
  align-items: flex-start;
  gap: var(--space-6);
}

.form-actions {
  display: flex;
  gap: 8px;
}

.create-layout > .moon-card {
  flex: 1 1 auto;
  min-width: 0;
}

.create-side {
  flex: 0 0 330px;
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

@media (max-width: 1180px) {
  .create-layout {
    flex-direction: column;
  }

  .create-side {
    flex: none;
    width: 100%;
  }
}
</style>
