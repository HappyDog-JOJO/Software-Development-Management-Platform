<script setup lang="ts">
/**
 * PingCode 同步弹窗（单条 / 批量共用）。
 * 打开时拉取 PingCode「史诗」可选字段（优先级 / 状态 / 项目成员），
 * 提交时把弹窗字段随 sync-task 一并传给后端。
 * - 单条：可改标题、描述（默认带出需求内容）
 * - 批量：标题/描述按各需求自身内容，弹窗只设公共字段
 */
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import {
  fetchPingcodeOptions,
  searchPingcodeTags,
  syncTaskDevRequest,
  type PingcodeOption,
  type PingcodeSyncFields,
} from '@/api/devreq'

const props = defineProps<{
  modelValue: boolean
  ids: number[]
  single?: boolean
  defaultTitle?: string
  defaultDescription?: string
  /** 需求优先级（紧急/高/中/低）→ 自动映射 PingCode 优先级 */
  defaultPriority?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
  (e: 'done'): void
}>()

const visible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const loading = ref(false)
const submitting = ref(false)
const priorities = ref<PingcodeOption[]>([])
const states = ref<PingcodeOption[]>([])
const members = ref<PingcodeOption[]>([])
const projectName = ref('')

const form = ref<PingcodeSyncFields>({
  title: '',
  description: '',
  priorityId: '',
  assigneeId: '',
  stateId: '',
  startAt: '',
  dueAt: '',
  tags: [],
})
/** 标签用自由输入（allow-create），这里存字符串数组；选项按关键词远程搜索 */
const tagList = ref<string[]>([])
/** 标签远程搜索结果 */
const tagOptions = ref<PingcodeOption[]>([])
const tagSearching = ref(false)
let tagSearchTimer: ReturnType<typeof setTimeout> | null = null

/** 远程搜索标签（防抖 300ms；空关键词清空结果，由后端按名建标签） */
function remoteSearchTags(query: string): void {
  const q = (query ?? '').trim()
  if (tagSearchTimer) clearTimeout(tagSearchTimer)
  if (!q) {
    tagOptions.value = []
    tagSearching.value = false
    return
  }
  tagSearching.value = true
  tagSearchTimer = setTimeout(async () => {
    try {
      tagOptions.value = await searchPingcodeTags(q)
    } catch {
      tagOptions.value = []
    } finally {
      tagSearching.value = false
    }
  }, 300)
}

/** 需求优先级 → PingCode 优先级名称 */
const PRIORITY_MAP: Record<string, string> = {
  紧急: '最高',
  高: '较高',
  中: '普通',
  低: '较低',
}

function resetDefaults(): void {
  form.value = {
    title: props.defaultTitle ?? '',
    description: props.defaultDescription ?? '',
    priorityId: '',
    assigneeId: '',
    stateId: '',
    startAt: '',
    dueAt: '',
  }
  tagList.value = []
  tagOptions.value = []
  // 默认优先级按需求优先级映射
  const want = props.defaultPriority ? PRIORITY_MAP[props.defaultPriority] ?? '' : ''
  if (want) {
    const hit = priorities.value.find((p) => p.name === want)
    if (hit) form.value.priorityId = hit.id
  }
  // 默认状态：未开始
  const pending = states.value.find((s) => s.name === '未开始')
  if (pending) form.value.stateId = pending.id
}

async function loadOptions(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchPingcodeOptions()
    priorities.value = res.priorities
    states.value = res.states
    members.value = res.members
    projectName.value = res.projectName
    resetDefaults()
  } catch {
    ElMessage.error('拉取 PingCode 字段失败，请检查配置或稍后重试')
    visible.value = false
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (v) => {
    if (v) void loadOptions()
  },
)

async function onSubmit(): Promise<void> {
  if (props.single && !(form.value.title ?? '').trim()) {
    ElMessage.warning('请填写标题')
    return
  }
  const fields: PingcodeSyncFields = {
    priorityId: form.value.priorityId || undefined,
    assigneeId: form.value.assigneeId || undefined,
    stateId: form.value.stateId || undefined,
    startAt: form.value.startAt || undefined,
    dueAt: form.value.dueAt || undefined,
    tags: tagList.value.length ? tagList.value : undefined,
  }
  if (props.single) {
    fields.title = (form.value.title ?? '').trim()
    if ((form.value.description ?? '').trim()) fields.description = form.value.description
  }
  submitting.value = true
  try {
    const res = await syncTaskDevRequest(props.ids, fields)
    ElMessage.success(`已同步 ${res.affected} 条到 PingCode${res.skipped > 0 ? `，${res.skipped} 条已跳过` : ''}`)
    visible.value = false
    emit('done')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <el-dialog
    v-model="visible"
    :title="single ? '同步任务到 PingCode' : '批量同步任务到 PingCode'"
    width="560px"
    append-to-body
    destroy-on-close
  >
    <el-alert
      type="info"
      :closable="false"
      style="margin-bottom: 14px"
      :title="`将在 PingCode「${projectName || '快工单'}」项目创建「史诗」工作项：共 ${ids.length} 条。${single ? '' : '标题/描述按各需求自身内容。'}`"
    />
    <el-form label-width="96px">
      <template v-if="single">
        <el-form-item label="标题" required>
          <el-input v-model="form.title" maxlength="200" show-word-limit placeholder="PingCode 工作项标题" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="7"
            maxlength="2000"
            show-word-limit
            placeholder="默认带出需求描述与来源信息（含提交人、提交部门、联系电话）"
          />
        </el-form-item>
      </template>
      <el-form-item label="优先级">
        <el-select v-model="form.priorityId" placeholder="选择优先级" clearable :loading="loading" style="width: 100%">
          <el-option v-for="p in priorities" :key="p.id" :label="p.name" :value="p.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="负责人">
        <el-select
          v-model="form.assigneeId"
          placeholder="选择负责人（可留空）"
          clearable
          filterable
          :loading="loading"
          style="width: 100%"
        >
          <el-option v-for="m in members" :key="m.id" :label="m.name" :value="m.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="form.stateId" placeholder="选择状态" clearable :loading="loading" style="width: 100%">
          <el-option v-for="s in states" :key="s.id" :label="s.name" :value="s.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="开始时间">
        <el-date-picker
          v-model="form.startAt"
          type="date"
          value-format="YYYY-MM-DD"
          placeholder="开始时间（可留空）"
          style="width: 100%"
        />
      </el-form-item>
      <el-form-item label="结束时间">
        <el-date-picker
          v-model="form.dueAt"
          type="date"
          value-format="YYYY-MM-DD"
          placeholder="结束时间（可留空）"
          style="width: 100%"
        />
      </el-form-item>
      <el-form-item label="标签">
        <el-select
          v-model="tagList"
          multiple
          filterable
          allow-create
          default-first-option
          remote
          :remote-method="remoteSearchTags"
          :loading="tagSearching"
          :reserve-keyword="false"
          placeholder="输入关键词搜索标签，查不到可回车新建（可留空）"
          style="width: 100%"
        >
          <el-option v-for="t in tagOptions" :key="t.id" :label="t.name" :value="t.name" />
        </el-select>
        <div class="pcsd-tag-tip">已选标签会挂到工作项；不存在的名称会在 PingCode 自动新建。</div>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" :loading="submitting" @click="onSubmit">确定同步</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.pcsd-tag-tip {
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--el-text-color-secondary);
}
</style>
