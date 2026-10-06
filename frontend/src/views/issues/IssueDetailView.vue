<script setup lang="ts">
/**
 * 问题详情页。
 * 上半部分：问题信息卡 + 附件列表（可预览/下载）
 * 下半部分：处理记录时间线（最新在上）；无记录显示空态
 * 操作区（售后/管理员）：复用 HandleDialog，提交后原地刷新时间线
 * 侧边折叠区：该问题的操作日志（所有登录用户可见，只读）
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteIssue, fetchHandleRecords, getIssue } from '@/api/issue'
import { useAuthStore } from '@/stores/auth'
import { canHandle } from '@/constants/user'
import { fmtDateTime, fmtSize, isImage } from '@/utils/format'
import { openAttachment } from '@/utils/attachment'
import IssueStatusTag from '@/components/IssueStatusTag.vue'
import ValueTag from '@/components/ValueTag.vue'
import RoleTag from '@/components/RoleTag.vue'
import HandleDialog from '@/components/HandleDialog.vue'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import MoonLoader from '@/components/illustrations/MoonLoader.vue'
import type { Attachment, HandleRecord, IssueDetail, Role } from '@/types'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const detail = ref<IssueDetail | null>(null)
const records = ref<HandleRecord[]>([])
const loading = ref(true)
const handleVisible = ref(false)

const issueId = computed(() => Number(route.params.id))

const canOperate = computed(() => canHandle(auth.roles))

/** 操作类型 → 展示标签颜色（复用元素状态色语义） */
const LOG_TYPE_CLASS: Record<string, string> = {
  提交问题: 'pill pill--green',
  问题处理: 'pill pill--yellow',
}

const logTypeClass = (type: string): string => LOG_TYPE_CLASS[type] || 'pill pill--grey'

/**
 * 统一时间线（最新在上）：把「提出人的操作」与「售后处理记录」合并到处理记录里。
 * - 提出人：首次提交（取操作日志的「提交问题」）+ 补充说明（取 issue_append，更可靠）
 * - 售后：处理记录（handleStatusName 为真正的处理情况）
 * 说明：处理接口自身也会写一条「问题处理」日志，与处理记录重复，这里不再重复展示。
 */
type TimelineItem =
  | { kind: 'handle'; id: string; time: string; rec: HandleRecord }
  | { kind: 'submit'; id: string; time: string; account: string; content: string }
  | { kind: 'append'; id: string; time: string; authorName: string; authorRole: Role; content: string }

const timeline = computed<TimelineItem[]>(() => {
  const items: TimelineItem[] = []

  for (const r of records.value) {
    items.push({ kind: 'handle', id: 'h' + r.id, time: r.createdAt, rec: r })
  }

  for (const l of detail.value?.logs ?? []) {
    // 只要首次提交；补充说明走 issue_append，避免重复
    if (l.type !== '提交问题' || l.content.startsWith('追加补充说明')) {
      continue
    }
    items.push({
      kind: 'submit',
      id: 'l' + l.id,
      time: l.createdAt,
      account: l.account,
      content: l.content.replace(/^提交问题：/, ''),
    })
  }

  for (const a of detail.value?.appends ?? []) {
    items.push({ kind: 'append', id: 'a' + a.id, time: a.createdAt, authorName: a.authorName, authorRole: a.authorRole, content: a.content })
  }

  return items.sort((a, b) => (a.time < b.time ? 1 : a.time > b.time ? -1 : 0))
})

/** 最新一条处理记录的 id（用于高亮「当前结论」） */
const latestRecordId = computed(() => {
  let best: HandleRecord | null = null
  for (const r of records.value) {
    if (!best || r.createdAt > best.createdAt || (r.createdAt === best.createdAt && r.id > best.id)) {
      best = r
    }
  }
  return best ? best.id : 0
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const [d, r] = await Promise.all([getIssue(issueId.value), fetchHandleRecords(issueId.value)])
    detail.value = d
    records.value = r
  } catch {
    if (auth.isLoggedIn) {
      ElMessage.error('加载问题详情失败，请返回列表重试')
    }
  } finally {
    loading.value = false
  }
}

function openHandle(): void {
  handleVisible.value = true
}

function onSaved(): void {
  void load()
}

/** 删除问题（仅管理员）：成功后回到列表 */
async function onDelete(): Promise<void> {
  if (!detail.value) {
    return
  }
  await ElMessageBox.confirm(
    `确定删除问题「${detail.value.issueNo}」吗？该问题的处理记录、补充说明与附件会一并删除，且无法恢复。`,
    '删除问题',
    { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'warning' },
  )
  await deleteIssue(detail.value.id)
  ElMessage.success('问题已删除')
  await router.replace('/issues')
}

/** 附件点击：图片在当前页面弹窗预览（同组图片可左右切换）；其他文档强制下载（不允许在线查看） */
function onAttachmentClick(att: Attachment, siblings: Attachment[]): void {
  void openAttachment(
    att.url,
    att.mimeType,
    att.name,
    siblings.filter((s) => isImage(s.mimeType, s.name)).map((s) => ({ url: s.url, name: s.name })),
  )
}

onMounted(load)

/** 同一路由组件在详情间切换时不会重新挂载，须监听 id 变化重新加载（否则会残留上一条详情） */
watch(issueId, (val, old) => {
  if (val && val !== old) {
    void load()
  }
})
</script>

<template>
  <div class="page">
    <div class="page__actions page__actions--toolbar">
      <el-button @click="router.back()">返回列表</el-button>
      <el-button v-if="canOperate && detail" type="primary" @click="openHandle">
        {{ ['待处理', '未处理', '已驳回'].includes(detail.status) ? '处理该问题' : '追加处理' }}
      </el-button>
      <el-button v-if="auth.isAdmin && detail" type="danger" plain @click="onDelete">删除该问题</el-button>
    </div>

    <MoonLoader v-if="loading" text="正在加载问题详情…" />

    <template v-else-if="detail">
      <!-- 问题信息卡：头部标识区 + 左（描述/附件）+ 右（元信息列表） -->
      <div class="moon-card moon-card--moonlit issue-card">
        <div class="issue-head">
          <div class="issue-head__main">
            <div class="issue-head__no">{{ detail.issueNo }}</div>
            <div class="issue-head__tags">
              <IssueStatusTag :status="detail.status" />
              <ValueTag :value-name="detail.valueName" />
              <span v-if="detail.categoryName" class="pill pill--primary">{{ detail.categoryName }}</span>
              <span v-else class="pill pill--grey">未分类</span>
            </div>
          </div>
          <div class="issue-head__aside">
            <div class="issue-head__stamp">
              <span class="issue-head__stamp-label">提交时间</span>
              <span class="issue-head__stamp-value">{{ fmtDateTime(detail.createdAt) }}</span>
            </div>
            <div class="issue-head__stamp">
              <span class="issue-head__stamp-label">最新处理</span>
              <span class="issue-head__stamp-value">{{ detail.handledAt ? fmtDateTime(detail.handledAt) : '暂无处理' }}</span>
            </div>
          </div>
        </div>

        <div class="issue-body">
          <aside class="issue-body__side">
            <div class="meta-list">
              <div class="meta-row">
                <span class="meta-row__label">提交人</span>
                <span class="meta-row__value">{{ detail.submitterName }}<span v-if="detail.submitterAccount && detail.submitterAccount !== detail.submitterName" class="meta-row__sub">{{ detail.submitterAccount }}</span></span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">提交部门</span>
                <span class="meta-row__value">{{ detail.submitterDepartmentName || '未分配部门' }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">所属模块</span>
                <span class="meta-row__value">{{ detail.moduleName || '—' }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">问题分类</span>
                <span class="meta-row__value">{{ detail.categoryName || '未分类' }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">处理人</span>
                <span class="meta-row__value">{{ detail.handlerName || '暂无处理人' }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">最新处理时间</span>
                <span class="meta-row__value">{{ detail.handledAt ? fmtDateTime(detail.handledAt) : '—' }}</span>
              </div>
            </div>
          </aside>

          <div class="issue-body__main">
            <div class="info-desc">
              <div class="info-desc__label">问题描述</div>
              <div class="info-desc__body">{{ detail.description }}</div>
            </div>

            <div v-if="detail.attachments.length" class="info-attachments">
              <div class="info-attachments__label">提交附件（{{ detail.attachments.length }} 个）</div>
              <div class="attachment-list">
                <!-- 图片点击可查看；其他文档不允许在线查看，只能下载 -->
                <a
                  v-for="att in detail.attachments"
                  :key="att.id"
                  class="attachment-chip"
                  :href="att.url"
                  :class="{ 'is-image': isImage(att.mimeType, att.name) }"
                  :title="isImage(att.mimeType, att.name) ? '点击查看图片' : '点击下载文件'"
                  @click.prevent="onAttachmentClick(att, detail.attachments)"
                >
                  <span class="attachment-chip__icon" aria-hidden="true">{{ isImage(att.mimeType, att.name) ? 'IMG' : 'FILE' }}</span>
                  <span class="attachment-chip__name cell-ellipsis">{{ att.name }}</span>
                  <span class="attachment-chip__size">{{ fmtSize(att.size) }}</span>
                  <span class="attachment-chip__act">{{ isImage(att.mimeType, att.name) ? '查看' : '下载' }}</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 处理记录时间线（最新在上；合并售后处理记录与提出人的提交/补充操作） -->
      <div class="moon-card">
        <div class="section-title">
          处理记录
          <span class="section-title__hint">同一问题可多次处理，最新一条为当前结论</span>
        </div>

        <EmptyIllustration
          v-if="!timeline.length"
          :size="130"
          text="暂无处理记录"
          sub-text="售后同学处理后会在这里留下处理过程与结论"
        />

        <el-timeline v-else class="record-timeline">
          <el-timeline-item
            v-for="(item, idx) in timeline"
            :key="item.id"
            :timestamp="fmtDateTime(item.time)"
            placement="top"
            :hollow="idx !== 0"
          >
            <!-- 提出人：首次提交 -->
            <div v-if="item.kind === 'submit'" class="record record--submitter">
              <div class="record__head">
                <span class="num-strong">{{ item.account }}</span>
                <span class="pill pill--grey">提出人</span>
                <span class="pill pill--green">提交问题</span>
              </div>
              <div class="record__body">{{ item.content }}</div>
            </div>

            <!-- 提出人：补充说明 -->
            <div v-else-if="item.kind === 'append'" class="record record--submitter">
              <div class="record__head">
                <span class="num-strong">{{ item.authorName }}</span>
                <RoleTag :role="item.authorRole" />
                <span class="pill pill--pink">补充说明</span>
              </div>
              <div class="record__body">{{ item.content }}</div>
            </div>

            <!-- 售后处理记录 -->
            <div v-else class="record" :class="{ 'is-latest': item.rec.id === latestRecordId && idx === 0 }">
              <div class="record__head">
                <span class="num-strong">{{ item.rec.handlerName }}</span>
                <RoleTag :role="item.rec.handlerRole" />
                <span v-if="item.rec.id === latestRecordId && idx === 0" class="pill pill--pink">当前结论</span>
                <span class="pill pill--primary">处理情况：{{ item.rec.handleStatusName }}</span>
                <span v-if="item.rec.categoryName" class="pill pill--primary">{{ item.rec.categoryName }}</span>
                <ValueTag :value-name="item.rec.valueName" />
              </div>
              <div class="record__body">{{ item.rec.remark }}</div>
              <div v-if="item.rec.attachments.length" class="attachment-list">
                <a
                  v-for="att in item.rec.attachments"
                  :key="att.id"
                  class="attachment-chip"
                  :href="att.url"
                  :class="{ 'is-image': isImage(att.mimeType, att.name) }"
                  :title="isImage(att.mimeType, att.name) ? '点击查看图片' : '点击下载文件'"
                  @click.prevent="onAttachmentClick(att, item.rec.attachments)"
                >
                  <span class="attachment-chip__icon" aria-hidden="true">{{ isImage(att.mimeType, att.name) ? 'IMG' : 'FILE' }}</span>
                  <span class="attachment-chip__name cell-ellipsis">{{ att.name }}</span>
                  <span class="attachment-chip__size">{{ fmtSize(att.size) }}</span>
                  <span class="attachment-chip__act">{{ isImage(att.mimeType, att.name) ? '查看' : '下载' }}</span>
                </a>
              </div>
            </div>
          </el-timeline-item>
        </el-timeline>
      </div>

      <HandleDialog v-model="handleVisible" :issue="detail" @saved="onSaved" />
    </template>
  </div>
</template>

<style scoped>
.page__actions {
  display: flex;
  gap: 8px;
}

/* 页头已移除，操作按钮独占一行并收紧顶部间距 */
/* 信息卡：头部标识区 */
.issue-card {
  padding: 0;
  overflow: hidden;
}

.issue-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px;
  background: linear-gradient(135deg, var(--color-moonlight) 0%, var(--color-bg-container) 78%);
  border-bottom: 1px solid var(--color-border-divider);
}

.issue-head__main {
  min-width: 0;
}

.issue-head__no {
  font-size: 20px;
  font-weight: 600;
  letter-spacing: 0.5px;
  color: var(--color-text-primary);
  font-variant-numeric: tabular-nums;
  line-height: 1.3;
}

.issue-head__tags {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.issue-head__aside {
  display: flex;
  gap: 24px;
  flex: 0 0 auto;
}

.issue-head__stamp {
  display: flex;
  flex-direction: column;
  gap: 4px;
  text-align: right;
}

.issue-head__stamp-label {
  font-size: 12px;
  color: var(--color-text-placeholder);
}

.issue-head__stamp-value {
  font-size: 13px;
  color: var(--color-text-regular);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

/* 信息卡：主体两栏 */
.issue-body {
  display: flex;
  align-items: stretch;
  gap: 24px;
  padding: 20px 24px;
}

.issue-body__main {
  flex: 1 1 auto;
  min-width: 0;
}

.issue-body__side {
  flex: 0 0 264px;
  border-right: 1px solid var(--color-border-divider);
  padding-right: 24px;
}

.meta-list {
  display: flex;
  flex-direction: column;
}

.meta-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 0;
  font-size: 13.5px;
}

.meta-row + .meta-row {
  border-top: 1px dashed var(--color-border-light);
}

.meta-row__label {
  flex: 0 0 auto;
  color: var(--color-text-secondary);
}

.meta-row__value {
  color: var(--color-text-primary);
  text-align: right;
  word-break: break-all;
}

.meta-row__sub {
  margin-left: 6px;
  font-size: 12px;
  color: var(--color-text-placeholder);
}

/* 窄屏：侧栏折到下方，去掉分隔线 */
@media (max-width: 1180px) {
  .issue-body {
    flex-direction: column;
  }

  .issue-body__side {
    flex: 1 1 auto;
    border-right: none;
    border-bottom: 1px solid var(--color-border-divider);
    padding-right: 0;
    padding-bottom: 16px;
  }
}

.info-desc {
  margin-top: 0;
}

.info-desc__label {
  font-size: 13px;
  color: var(--color-text-secondary);
  margin-bottom: 6px;
}

.info-desc__body {
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  font-size: 14px;
  line-height: 1.7;
  color: var(--color-text-primary);
  white-space: pre-wrap;
}

.info-attachments {
  margin-top: 16px;
}

.info-attachments__label {
  font-size: 13px;
  color: var(--color-text-secondary);
  margin-bottom: 8px;
}

.attachment-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}

.attachment-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 260px;
  padding: 5px 10px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-bg-container);
  color: var(--color-text-regular);
  font-size: 12px;
  transition: all var(--transition-base);
}

.attachment-chip:hover {
  border-color: var(--color-primary-soft);
  background: var(--color-primary-tint);
  color: var(--color-primary);
}

.attachment-chip__icon {
  font-size: 9px;
  font-weight: 700;
  color: var(--color-primary);
  flex: 0 0 auto;
}

.attachment-chip__name {
  max-width: 150px;
}

.attachment-chip {
  cursor: pointer;
}

/* 操作标记：图片显示「查看」、文档显示「下载」 */
.attachment-chip__act {
  flex: 0 0 auto;
  padding: 0 7px;
  border-radius: var(--radius-pill);
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 11px;
  line-height: 17px;
}

.attachment-chip.is-image .attachment-chip__act {
  background: var(--color-accent-tint);
  color: #c4638b;
}

.attachment-chip__size {
  color: var(--color-text-placeholder);
  flex: 0 0 auto;
}

/* 补充说明已并入处理记录时间线，单独样式已移除 */

/* 处理记录 */
.record {
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  border: 1px solid transparent;
}

.record.is-latest {
  background: var(--color-primary-tint);
  border-color: rgba(124, 140, 228, 0.24);
}

/* 提出人操作（提交 / 补充说明）：与售后处理记录区分，用左线+虚底 */
.record--submitter {
  background: var(--color-bg-sub);
  border-left: 3px solid var(--color-accent-pink);
}

/* 日志折叠区已移除，相关样式不再需要 */
.record__head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 13px;
}

.record__body {
  margin-top: 8px;
  font-size: 13.5px;
  line-height: 1.7;
  color: var(--color-text-primary);
  white-space: pre-wrap;
}
</style>
