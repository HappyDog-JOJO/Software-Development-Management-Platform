<script setup lang="ts">
/**
 * 问题同步到 PingCode 弹窗（仅单条，从问题列表操作栏发起）。
 * 字段与「开发管理 → 同步任务」完全一致：标题 / 描述 / 优先级 / 负责人 / 状态 / 开始时间 / 结束时间 / 标签。
 *
 * BUG 修复排班：
 * - 默认负责人 = 当前顺位的人（打开时拉取一次）；
 * - 手动改负责人后，该次同步不消耗顺位（后端按 rosterMode=manual 判断）；
 * - 未改负责人时按排班自动指派，同步成功后顺位往后走一位。
 */
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import {
  fetchIssuePingcodeOptions,
  searchIssuePingcodeTags,
  syncIssuesToPingcode,
  type IssuePingcodeOptions,
} from '@/api/bugduty'
import type { PingcodeOption, PingcodeSyncFields } from '@/api/devreq'

const props = defineProps<{
  modelValue: boolean
  ids: number[]
  defaultTitle?: string
  defaultDescription?: string
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
const options = ref<IssuePingcodeOptions | null>(null)

const priorities = ref<PingcodeOption[]>([])
const states = ref<PingcodeOption[]>([])
const members = ref<PingcodeOption[]>([])

const form = ref<PingcodeSyncFields>({
  title: '',
  description: '',
  priorityId: '',
  assigneeId: '',
  stateId: '',
  startAt: '',
  dueAt: '',
})

/** 默认负责人（当前顺位对应的 PingCode 成员），用于判断用户是否手动改过 */
const rosterMemberId = ref('')
const rosterLabel = ref('')

/** 标签用自由输入（allow-create），选项按关键词远程搜索 */
const tagList = ref<string[]>([])
const tagOptions = ref<PingcodeOption[]>([])
const tagSearching = ref(false)
let tagSearchTimer: ReturnType<typeof setTimeout> | null = null

/** 远程搜索标签（防抖 300ms） */
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
      tagOptions.value = await searchIssuePingcodeTags(q)
    } catch {
      tagOptions.value = []
    } finally {
      tagSearching.value = false
    }
  }, 300)
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
  // 默认状态：未开始
  const pending = states.value.find((s) => s.name === '未开始')
  if (pending) form.value.stateId = pending.id
  // 默认负责人：BUG 修复排班当前顺位（同时记下默认值，提交时判断是否被手动改过）
  const bd = options.value?.bugDuty
  rosterMemberId.value = ''
  rosterLabel.value = ''
  if (bd && bd.memberId) {
    rosterMemberId.value = bd.memberId
    rosterLabel.value = `${bd.userName}（第 ${bd.position} 顺位）`
    form.value.assigneeId = bd.memberId
  }
}

async function loadOptions(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchIssuePingcodeOptions()
    options.value = res
    priorities.value = res.priorities
    states.value = res.states
    members.value = res.members
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

/** 是否使用了排班顺位（未手动改负责人） */
const useRoster = computed(() => {
  if (!options.value?.bugDuty) {
    return false
  }
  return rosterMemberId.value !== '' && form.value.assigneeId === rosterMemberId.value
})

/** 排班顺位匹配不上 PingCode 成员时的提示 */
const rosterMissing = computed(() => !!options.value?.bugDuty && !rosterMemberId.value)

async function onSubmit(): Promise<void> {
  if (!(form.value.title ?? '').trim()) {
    ElMessage.warning('请填写标题')
    return
  }
  const manual = !!options.value?.bugDuty && !useRoster.value
  const fields: PingcodeSyncFields = {
    title: (form.value.title ?? '').trim(),
    description: (form.value.description ?? '').trim() ? form.value.description : undefined,
    priorityId: form.value.priorityId || undefined,
    // 负责人：默认不传（后端按顺位指派并推进游标）；手动改过（含清空）时标记 manual，后端以手动值为准且不消耗顺位。
    assigneeId: manual ? (form.value.assigneeId || '') : undefined,
    rosterMode: manual ? 'manual' : 'auto',
    stateId: form.value.stateId || undefined,
    startAt: form.value.startAt || undefined,
    dueAt: form.value.dueAt || undefined,
    tags: tagList.value.length ? tagList.value : undefined,
  }
  submitting.value = true
  try {
    const res = await syncIssuesToPingcode(props.ids, fields)
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
    title="同步问题到 PingCode"
    width="560px"
    append-to-body
    destroy-on-close
  >
    <el-alert
      type="info"
      :closable="false"
      style="margin-bottom: 14px"
      :title="`将在 PingCode「${options?.projectName || '快工单'}」项目创建「史诗」工作项：共 ${ids.length} 条。标题/描述按该问题自身内容。`"
    />

    <el-alert
      v-if="options?.bugDuty"
      type="success"
      :closable="false"
      style="margin-bottom: 14px"
      :title="`当前 BUG 修复顺位：${options.bugDuty.userName}（第 ${options.bugDuty.position} 顺位）${useRoster ? '，本次同步后顺位往后走一位' : '，已手动改负责人，本次不消耗顺位'}`"
    />
    <el-alert
      v-else
      type="warning"
      :closable="false"
      style="margin-bottom: 14px"
      title="BUG 修复排班顺序表为空：描述里会标注「未排班」，请先到「值班排班 → BUG 修复排班」编排顺序"
    />

    <el-form v-loading="loading" label-width="96px">
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
          placeholder="默认带出问题描述与来源信息（含问题编号、模块、提交人）"
        />
        <div class="pcsd-tag-tip">留空时后端会追加问题编号、模块、提交人，以及「BUG 处理人（第 N 顺位）」。</div>
      </el-form-item>

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
        <div class="pcsd-tag-tip">
          {{ rosterLabel ? `排班当前：${rosterLabel}；不改则按排班自动轮换，改了则以你选的为准且不占用顺位。` : (rosterMissing ? '当前顺位人员在 PingCode 里没匹配到同名成员，可在上方手动选择。' : '可在「BUG 修复排班」里编排顺序。') }}
        </div>
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
