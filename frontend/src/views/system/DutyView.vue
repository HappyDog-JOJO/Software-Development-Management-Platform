<script setup lang="ts">
/**
 * 值班排班页（两个 tab）。
 * 1) 售后处理排班：原来的按日期日历排班，一天一人，逻辑与数据（duty_schedule）不变。
 * 2) BUG 修复排班：不排日期，只维护一个人员顺序表（第 1 顺位、第 2 顺位……）；
 *    同步问题到 PingCode 时按当前顺位取 BUG 处理人，每同步一条后顺位往后走一位。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { batchDuty, deleteDuty, fetchDutyMonth, saveDuty } from '@/api/duty'
import { fetchBugDuty, resetBugDuty, saveBugDuty } from '@/api/bugduty'
import { useAuthStore } from '@/stores/auth'
import { canManageDuty, ROLE_ADMIN } from '@/constants/user'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import type { BugDutyItem, DutyCandidate, DutyItem } from '@/types'

const auth = useAuthStore()

const activeTab = ref<'service' | 'bug'>('service')
const loading = ref(false)
const saving = ref(false)

/* ==================== 售后处理排班（原逻辑） ==================== */

const month = ref(currentMonth())
const items = ref<DutyItem[]>([])
const candidates = ref<DutyCandidate[]>([])

const canManage = computed(() => canManageDuty(auth.roles))
const isAdmin = computed(() => !!auth.roles?.includes(ROLE_ADMIN))

/** 当前弹窗对应日期的已有排班（用于“取消当天排班”按钮与标题） */
const currentDayDuty = computed(() => items.value.find((i) => i.dutyDate === form.dutyDate) ?? null)

// 单日编辑弹窗
const dialogVisible = ref(false)
const form = reactive({ dutyDate: '', userId: undefined as number | undefined, remark: '' })

// 批量排班弹窗
const batchVisible = ref(false)
/** 排班区间 [开始日期, 结束日期]，用范围选择器选 */
const batchRange = ref<[string, string] | null>(null)
const batchForm = reactive({
  userIds: [] as number[],
  // 公司单休：默认只跳周日（周六照常排班）
  skipSunday: 1,
  skipSaturday: 0,
})

function currentMonth(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** 当月按周分组（周一起），便于日历展示 */
const weeks = computed<Array<Array<{ date: string; day: number; inMonth: boolean; duty: DutyItem | null }>>>(() => {
  const [y, m] = month.value.split('-').map(Number)
  const first = new Date(y, m - 1, 1)
  const last = new Date(y, m, 0)
  const dutyMap = new Map(items.value.map((i) => [i.dutyDate, i]))

  const cells: Array<{ date: string; day: number; inMonth: boolean; duty: DutyItem | null }> = []
  // 补足到周一开头
  const startOffset = (first.getDay() + 6) % 7
  for (let i = startOffset; i > 0; i--) {
    const d = new Date(y, m - 1, 1 - i)
    cells.push({ date: fmt(d), day: d.getDate(), inMonth: false, duty: null })
  }
  for (let day = 1; day <= last.getDate(); day++) {
    const d = new Date(y, m - 1, day)
    const key = fmt(d)
    cells.push({ date: key, day, inMonth: true, duty: dutyMap.get(key) ?? null })
  }
  // 补足整周
  while (cells.length % 7 !== 0) {
    const lastCell = cells[cells.length - 1]
    const [yy, mm, dd] = lastCell.date.split('-').map(Number)
    const d = new Date(yy, mm - 1, dd + 1)
    cells.push({ date: fmt(d), day: d.getDate(), inMonth: false, duty: null })
  }
  const out: Array<Array<{ date: string; day: number; inMonth: boolean; duty: DutyItem | null }>> = []
  for (let i = 0; i < cells.length; i += 7) {
    out.push(cells.slice(i, i + 7))
  }
  return out
})

const today = fmt(new Date())

/** 本月每人值班天数（按 user_id 聚合，避免改名后被拆成两张卡片） */
const stats = computed(() => {
  const inMonth = items.value.filter((i) => i.dutyDate.startsWith(month.value))
  // 姓名优先取当前用户列表里的最新姓名（排班表里的姓名是历史冗余）
  const nameOf = new Map(candidates.value.map((c) => [c.id, c.name]))
  const byUser = new Map<number, { name: string; count: number }>()
  inMonth.forEach((i) => {
    const cur = byUser.get(i.userId)
    const name = nameOf.get(i.userId) || i.userName
    if (cur) {
      cur.count += 1
    } else {
      byUser.set(i.userId, { name, count: 1 })
    }
  })
  return {
    total: inMonth.length,
    byUser: [...byUser.values()].sort((a, b) => b.count - a.count || a.name.localeCompare(b.name)),
  }
})

function fmt(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function loadServiceDuty(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchDutyMonth(month.value)
    items.value = res.items
    candidates.value = res.candidates
  } finally {
    loading.value = false
  }
}

function onMonthChange(v: string): void {
  month.value = v
  void loadServiceDuty()
}

function openDay(cell: { date: string; inMonth: boolean; duty: DutyItem | null }): void {
  if (!canManage.value) {
    ElMessage.info('仅管理员可维护排班')
    return
  }
  if (!cell.inMonth) {
    return
  }
  form.dutyDate = cell.date
  form.userId = cell.duty?.userId
  form.remark = cell.duty?.remark ?? ''
  dialogVisible.value = true
}

async function onSave(): Promise<void> {
  if (!form.userId) {
    ElMessage.warning('请选择值班人')
    return
  }
  saving.value = true
  try {
    await saveDuty({ dutyDate: form.dutyDate, userId: form.userId, remark: form.remark })
    ElMessage.success('排班已保存')
    dialogVisible.value = false
    await loadServiceDuty()
  } finally {
    saving.value = false
  }
}

async function onClear(): Promise<void> {
  await ElMessageBox.confirm(`确定清除 ${form.dutyDate} 的排班吗？`, '清除排班', {
    confirmButtonText: '确定清除',
    cancelButtonText: '取消',
    type: 'warning',
  })
  await deleteDuty(form.dutyDate)
  ElMessage.success('该日排班已清除')
  dialogVisible.value = false
  await loadServiceDuty()
}

function openBatch(): void {
  const [y, m] = month.value.split('-').map(Number)
  const first = fmt(new Date(y, m - 1, 1))
  const last = fmt(new Date(y, m, 0))
  // 默认预填当前月份区间，用户可自由调整
  batchRange.value = [first, last]
  batchForm.userIds = candidates.value.map((c) => c.id)
  batchForm.skipSunday = 1
  batchForm.skipSaturday = 0
  batchVisible.value = true
}

/** 区间内实际会排班的天数（扣除被跳过的周六/周日），用于预览 */
const batchPreview = computed(() => {
  const range = batchRange.value
  if (!range || !range[0] || !range[1]) {
    return { total: 0, saved: 0, skipped: 0 }
  }
  const start = new Date(range[0].replace(/-/g, '/'))
  const end = new Date(range[1].replace(/-/g, '/'))
  const total = Math.floor((end.getTime() - start.getTime()) / 86400000) + 1
  let saved = 0
  let skipped = 0
  for (let i = 0; i < total; i++) {
    const d = new Date(start.getTime() + i * 86400000)
    const dow = d.getDay() === 0 ? 7 : d.getDay() // 1=周一 ... 7=周日
    if (batchForm.skipSunday === 1 && dow === 7) {
      skipped++
      continue
    }
    if (batchForm.skipSaturday === 1 && dow === 6) {
      skipped++
      continue
    }
    saved++
  }
  return { total, saved, skipped }
})

async function onBatch(): Promise<void> {
  const range = batchRange.value
  if (!range || !range[0] || !range[1]) {
    ElMessage.warning('请选择排班日期区间')
    return
  }
  if (!batchForm.userIds.length) {
    ElMessage.warning('请选择参与轮值的人员')
    return
  }
  saving.value = true
  try {
    const res = await batchDuty({
      startDate: range[0],
      endDate: range[1],
      userIds: batchForm.userIds,
      skipSunday: batchForm.skipSunday,
      skipSaturday: batchForm.skipSaturday,
    })
    ElMessage.success(`已排好 ${res.saved} 天${res.skipped ? `，跳过 ${res.skipped} 天` : ''}`)
    batchVisible.value = false
    // 排班可能跨月：把日历切到开始日期所在月份，方便立刻看到结果
    const targetMonth = range[0].slice(0, 7)
    if (targetMonth !== month.value) {
      month.value = targetMonth
    }
    await loadServiceDuty()
  } finally {
    saving.value = false
  }
}

/* ==================== BUG 修复排班（顺序轮值） ==================== */

const bugItems = ref<BugDutyItem[]>([])
/** 排班候选人 = PingCode 项目成员本身（不再从系统用户里选） */
const bugMembers = ref<Array<{ id: string; name: string; account?: string }>>([])
const bugMemberError = ref('')
const bugCurrent = ref<{ userId: number; userName: string; position: number } | null>(null)
const bugLoading = ref(false)
/** 顺序编辑区的本地顺序（保存前只在本地变动，点保存才落库），元素是 PingCode 成员 ID */
const bugDraft = ref<string[]>([])
/** 待加入的 PingCode 成员 */
const bugAddId = ref<string | undefined>(undefined)

/** 成员 ID → 成员名 */
const bugMemberName = computed(() => new Map(bugMembers.value.map((m) => [m.id, m.name])))

/** 编辑区里的人员明细（按本地顺序） */
const bugRows = computed(() =>
  bugDraft.value.map((mid, i) => ({
    memberId: mid,
    name: bugMemberName.value.get(mid) || bugItems.value.find((it) => it.pingcodeMemberId === mid)?.userName || mid,
    position: i + 1,
  })),
)

/** 本地顺序是否与已保存顺序不同（用于提示未保存） */
const bugDirty = computed(() => {
  const saved = bugItems.value.map((it) => it.pingcodeMemberId)
  return saved.length !== bugDraft.value.length || saved.some((mid, i) => mid !== bugDraft.value[i])
})

/** 可加入的成员（排除已在顺序里的） */
const bugAddable = computed(() => bugMembers.value.filter((m) => !bugDraft.value.includes(m.id)))

/** 当前顺位展示：优先用服务端返回的 current；顺序为空时给出提示 */
const bugCurrentText = computed(() => {
  if (!bugItems.value.length) {
    return '尚未排班'
  }
  if (!bugCurrent.value) {
    return '尚未排班'
  }
  return `${bugCurrent.value.userName}（第 ${bugCurrent.value.position} 顺位）`
})

async function loadBugDuty(): Promise<void> {
  bugLoading.value = true
  try {
    const res = await fetchBugDuty()
    bugItems.value = res.items
    bugMembers.value = res.members
    bugMemberError.value = res.memberError || ''
    bugCurrent.value = res.current
    bugDraft.value = res.items.map((it) => it.pingcodeMemberId).filter((mid) => mid !== '')
  } catch (e) {
    bugMemberError.value = e instanceof Error ? e.message : '拉取 PingCode 成员失败'
  } finally {
    bugLoading.value = false
  }
}

function onAddBugMember(): void {
  const mid = bugAddId.value
  if (!mid) {
    ElMessage.warning('请选择要加入的 PingCode 成员')
    return
  }
  if (bugDraft.value.includes(mid)) {
    ElMessage.info('该成员已在顺序中')
    return
  }
  bugDraft.value.push(mid)
  bugAddId.value = undefined
}

function moveBugRow(index: number, delta: number): void {
  const next = index + delta
  if (next < 0 || next >= bugDraft.value.length) {
    return
  }
  const list = [...bugDraft.value]
  const [x] = list.splice(index, 1)
  list.splice(next, 0, x)
  bugDraft.value = list
}

function removeBugRow(index: number): void {
  bugDraft.value = bugDraft.value.filter((_, i) => i !== index)
}

async function onSaveBugDuty(): Promise<void> {
  saving.value = true
  try {
    const res = await saveBugDuty(bugDraft.value)
    bugItems.value = res.items
    bugMembers.value = res.members
    bugCurrent.value = res.current
    bugDraft.value = res.items.map((it) => it.pingcodeMemberId).filter((mid) => mid !== '')
    ElMessage.success('BUG 修复排班已保存，轮值已从第 1 顺位重新开始')
  } finally {
    saving.value = false
  }
}

async function onResetBugDuty(): Promise<void> {
  await ElMessageBox.confirm(
    '确定把轮值重置为第 1 顺位吗？下一条同步的问题将由第 1 顺位的人负责。',
    '重置轮值',
    { confirmButtonText: '确定重置', cancelButtonText: '取消', type: 'warning' },
  )
  const res = await resetBugDuty()
  bugCurrent.value = res.current
  ElMessage.success('已重置为第 1 顺位')
}

onMounted(async () => {
  await loadServiceDuty()
  // BUG 排班只对开发工程师/管理员有权限，其他角色（当前页面仅管理员可见）失败也不影响页面
  if (isAdmin.value) {
    await loadBugDuty()
  }
})
</script>

<template>
  <div class="page">
    <el-tabs v-model="activeTab" class="duty-tabs">
      <el-tab-pane label="售后处理排班" name="service">
        <!-- 排班情况（左）+ 月份与批量排班（右）：同一行顶部对齐 -->
        <div class="duty-head">
          <div class="duty-summary">
            <div v-for="s in stats.byUser" :key="s.name" class="duty-summary__item moon-card moon-card--moonlit">
              <span class="duty-summary__label">{{ s.name }}</span>
              <span class="duty-summary__value num-strong">{{ s.count }}<span class="duty-summary__unit">天</span></span>
            </div>
            <div v-if="!stats.byUser.length" class="duty-summary__item moon-card moon-card--moonlit">
              <span class="duty-summary__label">本月值班</span>
              <span class="duty-summary__value num-strong">0<span class="duty-summary__unit">天</span></span>
            </div>
          </div>

          <div class="duty-head__actions">
            <el-date-picker
              :model-value="month"
              type="month"
              size="default"
              placeholder="选择月份"
              value-format="YYYY-MM"
              style="width: 140px"
              @update:model-value="onMonthChange"
            />
            <el-button v-if="canManage" type="primary" plain @click="openBatch">批量排班</el-button>
          </div>
        </div>

        <div v-loading="loading" class="moon-card moon-card--tight">
          <div class="cal-weekhead">
            <span v-for="w in ['一', '二', '三', '四', '五', '六', '日']" :key="w" class="cal-weekhead__cell">{{ w }}</span>
          </div>

          <div class="cal-body">
            <div v-for="(week, wi) in weeks" :key="wi" class="cal-week">
              <div
                v-for="cell in week"
                :key="cell.date"
                class="cal-cell"
                :class="{
                  'is-out': !cell.inMonth,
                  'is-today': cell.date === today,
                  'is-weekend': [6, 7].includes(((new Date(cell.date.replace(/-/g, '/')).getDay() + 6) % 7) + 1),
                  'is-clickable': canManage && cell.inMonth,
                }"
                @click="openDay(cell)"
              >
                <div class="cal-cell__head">
                  <span class="cal-cell__day">{{ cell.day }}</span>
                  <span v-if="cell.date === today" class="pill pill--pink">今日</span>
                </div>
                <div v-if="cell.inMonth" class="cal-cell__duty">
                  <template v-if="cell.duty">
                    <span class="cal-cell__name">{{ cell.duty.userName }}</span>
                    <span v-if="cell.duty.remark" class="cal-cell__remark">{{ cell.duty.remark }}</span>
                  </template>
                  <span v-else class="cal-cell__empty">未排班</span>
                </div>
              </div>
            </div>
          </div>

          <EmptyIllustration
            v-if="!loading && !stats.total"
            :size="120"
            text="本月尚未排班"
            :sub-text="canManage ? '点击「批量排班」可按周快速排满整月' : '仅管理员可维护排班'"
          />
        </div>

        <!-- 规则说明 -->
        <div class="moon-card moon-card--tight duty-rules">
          <div class="section-title">排班规则</div>
          <ul class="duty-rules__list">
            <li>一天一位值班人，值班人从「售后服务人员」角色中选取（启用中的账号）。</li>
            <li>本页仅管理员可见；排班维护权同样归管理员。</li>
            <li v-if="canManage">「批量排班」可自由选择【开始～结束】日期区间，区间内按人员顺序逐日轮换；默认仅跳过周日（单休），双休可再开启「跳过周六」。</li>
            <li>临时换班或休假，{{ canManage ? '直接点击日历上对应日期：重新选取值班人保存即为替换，也可一键「取消当天排班」；' : '请联系管理员调整，' }}可在备注里说明原因。</li>
          </ul>
        </div>
      </el-tab-pane>

      <el-tab-pane label="BUG 修复排班" name="bug">
        <div class="duty-head">
          <div class="bug-current moon-card moon-card--moonlit">
            <div class="bug-current__label">当前顺位（下一个同步的问题由 TA 负责）</div>
            <div class="bug-current__value">{{ bugCurrentText }}</div>
            <div v-if="bugDirty" class="bug-current__dirty">顺序有改动，记得点「保存顺序」</div>
          </div>
          <div class="duty-head__actions">
            <el-button :disabled="!bugItems.length" @click="onResetBugDuty">重置到第 1 顺位</el-button>
            <el-button type="primary" :loading="saving" @click="onSaveBugDuty">保存顺序</el-button>
          </div>
        </div>

        <div v-loading="bugLoading" class="moon-card moon-card--tight">
          <div class="bug-add">
            <span class="bug-add__label">加入成员</span>
            <el-select
              v-model="bugAddId"
              placeholder="从 PingCode 项目成员中选择"
              filterable
              clearable
              :loading="bugLoading"
              :no-data-text="bugMemberError || '没有可选成员'"
              style="width: 300px"
            >
              <el-option
                v-for="m in bugAddable"
                :key="m.id"
                :label="m.account && m.account !== m.name ? `${m.name}（${m.account}）` : m.name"
                :value="m.id"
              />
            </el-select>
            <el-button type="primary" plain @click="onAddBugMember">加入顺序</el-button>
            <span class="bug-add__tip">成员直接取自 PingCode 项目，无需再匹配；按下面顺序依次轮：第 1 顺位 → 第 2 顺位 → … → 回到第 1 顺位</span>
          </div>

          <el-alert
            v-if="bugMemberError"
            :title="bugMemberError"
            type="warning"
            :closable="false"
            show-icon
            class="bug-add__alert"
          />

          <div v-if="bugRows.length" class="bug-list">
            <div v-for="(r, i) in bugRows" :key="r.memberId" class="bug-row" :class="{ 'is-current': i === 0 && !bugDirty && bugCurrent }">
              <span class="bug-row__pos">{{ i + 1 }}</span>
              <span class="bug-row__name">{{ r.name }}</span>
              <span v-if="i === 0 && !bugDirty && bugCurrent" class="pill pill--green">下一个</span>
              <span class="bug-row__spacer" />
              <el-button link type="primary" :disabled="i === 0" @click="moveBugRow(i, -1)">上移</el-button>
              <el-button link type="primary" :disabled="i === bugRows.length - 1" @click="moveBugRow(i, 1)">下移</el-button>
              <el-button link type="danger" @click="removeBugRow(i)">移除</el-button>
            </div>
          </div>

          <EmptyIllustration
            v-if="!bugLoading && !bugRows.length"
            :size="120"
            text="尚未编排 BUG 修复顺序"
            sub-text="在上方从 PingCode 成员中选人并「加入顺序」，保存后即可按顺位自动指派"
          />
        </div>

        <div class="moon-card moon-card--tight duty-rules">
          <div class="section-title">BUG 修复排班规则</div>
          <ul class="duty-rules__list">
            <li>这里只排「顺序」，不排日期：人员按第 1 顺位、第 2 顺位依次排列。</li>
            <li>排班人员直接取自 PingCode 项目成员，选中即生效，不需要再和系统账号做匹配。</li>
            <li>在问题列表把问题同步到 PingCode 时，系统取当前顺位的人作为 BUG 处理人，写进 PingCode 描述并指派给该成员。</li>
            <li>每同步一个问题，顺位往后走一位；排到最后一位后回到第 1 顺位。</li>
            <li>同步弹窗里手动改了负责人时，以手动选择的为准，且不消耗当前顺位。</li>
            <li>「保存顺序」会把轮值重置为第 1 顺位；「重置到第 1 顺位」只重置轮值、不动顺序。</li>
          </ul>
        </div>
      </el-tab-pane>
    </el-tabs>

    <!-- 单日排班：点击日历任意日期打开，可替换值班人或取消当天排班 -->
    <el-dialog
      v-model="dialogVisible"
      :title="currentDayDuty ? '调整当日排班' : '新增排班'"
      width="470px"
      :close-on-click-modal="false"
    >
      <el-form label-width="90px">
        <el-form-item label="值班日期">
          <div class="readonly-field">
            {{ form.dutyDate }}
            <span v-if="currentDayDuty" class="readonly-field__extra">
              当前值班人：{{ currentDayDuty.userName }}
            </span>
            <span v-else class="readonly-field__extra is-empty">当日尚未排班</span>
          </div>
        </el-form-item>
        <el-form-item label="值班人">
          <el-select v-model="form.userId" placeholder="请选择值班人" clearable filterable style="width: 100%">
            <el-option v-for="c in candidates" :key="c.id" :label="`${c.name}（${c.account}）`" :value="c.id" />
          </el-select>
          <div class="form-tip">仅列出具有「售后服务人员」角色且启用中的用户；重新选择并保存即为「替换值班人」。</div>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" maxlength="60" placeholder="选填，如休假、换班说明" />
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="dialog-footer">
          <el-button v-if="currentDayDuty" type="danger" plain @click="onClear">取消当天排班</el-button>
          <span class="dialog-footer__spacer" />
          <el-button @click="dialogVisible = false">关闭</el-button>
          <el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
        </div>
      </template>
    </el-dialog>

    <!-- 批量排班 -->
    <el-dialog v-model="batchVisible" title="批量排班（按人员逐日轮换）" width="540px" :close-on-click-modal="false">
      <el-form label-width="110px">
        <el-form-item label="排班日期">
          <el-date-picker
            v-model="batchRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            :clearable="false"
            style="width: 100%"
          />
          <div class="form-tip">
            支持灵活选择区间（可跨月）；区间内每日一位值班人，按下方人员顺序逐日轮换。
          </div>
        </el-form-item>
        <el-form-item label="轮值人员">
          <el-select v-model="batchForm.userIds" multiple placeholder="请选择人员（按此处顺序轮值）" style="width: 100%">
            <el-option v-for="c in candidates" :key="c.id" :label="`${c.name}（${c.account}）`" :value="c.id" />
          </el-select>
          <div class="form-tip">按选择顺序依次轮值，仅限「售后服务人员」角色。</div>
        </el-form-item>
        <el-form-item label="跳过周日">
          <el-switch v-model="batchForm.skipSunday" :active-value="1" :inactive-value="0" />
          <span class="form-tip" style="margin-left: 8px">公司单休：默认跳过周日，周六照常排班</span>
        </el-form-item>
        <el-form-item label="跳过周六">
          <el-switch v-model="batchForm.skipSaturday" :active-value="1" :inactive-value="0" />
          <span class="form-tip" style="margin-left: 8px">如为双休，可一并打开（与上一项同时开启即等同跳周末）</span>
        </el-form-item>
        <el-form-item v-if="batchPreview.total > 0" label=" ">
          <div class="batch-preview">
            区间共 <b>{{ batchPreview.total }}</b> 天，将排 <b>{{ batchPreview.saved }}</b> 天，跳过 <b>{{ batchPreview.skipped }}</b> 天
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="batchVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="onBatch">开始排班</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.duty-tabs {
  margin-top: -4px;
}

/* 排班情况 + 操作区：左右两端、顶部对齐 */
.duty-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.duty-head__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}

.duty-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.duty-summary__item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 120px;
  padding: 12px 16px;
}

.duty-summary__label {
  font-size: 13px;
  color: var(--color-text-regular);
}

.duty-summary__value {
  font-size: 22px;
  line-height: 1.2;
  color: var(--color-primary);
}

.duty-summary__unit {
  margin-left: 2px;
  font-size: 12px;
  color: var(--color-text-secondary);
}

/* 日历 */
.cal-weekhead {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 8px;
}

.cal-weekhead__cell {
  text-align: center;
  font-size: 12px;
  color: var(--color-text-secondary);
}

.cal-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cal-week {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 8px;
}

.cal-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-height: 78px;
  padding: 8px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  border: 1px solid var(--color-border-divider);
  transition: border-color var(--transition-base), background var(--transition-base);
}

.cal-cell.is-clickable {
  cursor: pointer;
}

.cal-cell.is-clickable:hover {
  border-color: var(--color-primary-soft);
  background: var(--color-primary-tint);
}

.cal-cell.is-out {
  opacity: 0.35;
}

.cal-cell.is-weekend {
  background: #fbfcff;
}

.cal-cell.is-today {
  border-color: var(--color-primary);
  box-shadow: var(--shadow-card);
}

.cal-cell__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
}

.cal-cell__day {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-primary);
  font-variant-numeric: tabular-nums;
}

.cal-cell__duty {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.cal-cell__name {
  font-size: 13px;
  color: var(--color-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cal-cell__remark {
  font-size: 11px;
  color: var(--color-text-placeholder);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cal-cell__empty {
  font-size: 12px;
  color: var(--color-text-placeholder);
}

.dialog-footer {
  display: flex;
  align-items: center;
  gap: 8px;
}

.dialog-footer__spacer {
  flex: 1;
}

.duty-rules__list {
  margin: 0;
  padding-left: 18px;
  font-size: 13px;
  line-height: 1.9;
  color: var(--color-text-regular);
}

.batch-preview {
  padding: 8px 12px;
  border-radius: var(--radius-control);
  background: var(--color-primary-tint);
  font-size: 13px;
  color: var(--color-text-regular);
}

.batch-preview b {
  color: var(--color-primary);
  font-variant-numeric: tabular-nums;
}

.readonly-field__extra {
  margin-left: 10px;
  font-size: 12px;
  color: var(--color-text-secondary);
}

.readonly-field__extra.is-empty {
  color: var(--color-text-placeholder);
}

/* BUG 修复排班 */
.bug-current {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 14px 18px;
  min-width: 280px;
}

.bug-current__label {
  font-size: 12px;
  color: var(--color-text-secondary);
}

.bug-current__value {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-primary);
}

.bug-current__dirty {
  font-size: 12px;
  color: var(--color-warning, #b8860b);
}

.bug-add {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding-bottom: 12px;
  border-bottom: 1px dashed var(--color-border-divider);
  margin-bottom: 12px;
}

.bug-add__label {
  font-size: 13px;
  color: var(--color-text-regular);
}

.bug-add__tip {
  font-size: 12px;
  color: var(--color-text-placeholder);
}

.bug-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.bug-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  border: 1px solid var(--color-border-divider);
}

.bug-row.is-current {
  border-color: var(--color-success, #2c7d5e);
  background: var(--color-success-tint, #eef8f3);
}

.bug-row__pos {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  font-size: 12px;
  font-weight: 600;
  color: var(--color-primary);
  background: var(--color-primary-tint);
  flex: 0 0 auto;
}

.bug-row__name {
  font-size: 14px;
  color: var(--color-text-primary);
  font-weight: 500;
}

.bug-row__warn {
  font-size: 12px;
  color: var(--color-warning, #b8860b);
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.bug-row__pc {
  font-size: 12px;
  color: var(--color-text-placeholder, #9aa0a6);
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.pc-pick__row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.pc-pick__label {
  color: var(--color-text-secondary, #6b7280);
  font-size: 13px;
}

.pc-pick__name {
  font-weight: 600;
}

.pc-pick__alert {
  margin-bottom: 10px;
}

.pc-pick__tip {
  margin-top: 8px;
  font-size: 12px;
  color: var(--color-text-placeholder, #9aa0a6);
  line-height: 1.6;
}

.bug-row__spacer {
  flex: 1;
}

@media (max-width: 900px) {
  .cal-cell {
    min-height: 64px;
    padding: 6px;
  }
  .cal-cell__name {
    font-size: 12px;
  }
}
</style>
