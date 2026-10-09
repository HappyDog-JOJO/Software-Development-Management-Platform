<script setup lang="ts">
/**
 * 需求详情页：环节步骤条 + 需求信息卡 + 流转时间线（样式与布局对齐问题详情页）。
 * - 顶部工具栏：返回列表 + 操作区（DevRequestActions 按角色与状态动态渲染）
 * - 信息卡：头部标识区（编号/标签/时间戳）+ 左（元信息列表）+ 右（描述/设计文档/附件）
 * - 时间线：提交、评审、设计、开发与发布的全部记录（最新在上）
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getDevRequest } from '@/api/devreq'
import { DEV_PRIORITY_TAG, DEV_STAGES, DEV_STATUS_TAG, devStageIndex, type DevTagType } from '@/constants/devreq'
import { logTypeClass } from '@/constants/issue'
import DevRequestActions from '@/components/DevRequestActions.vue'
import RoleTag from '@/components/RoleTag.vue'
import MoonLoader from '@/components/illustrations/MoonLoader.vue'
import { fmtDateTime, fmtSize, isImage } from '@/utils/format'
import { openAttachment } from '@/utils/attachment'
import type { DevAttachment, DevRequestDetail } from '@/types'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const detail = ref<DevRequestDetail | null>(null)

const reqId = computed(() => Number(route.params.id) || 0)

/** 步骤条进度：正常状态按五站序号；已驳回停在评审池；已取消不展示步骤条 */
const stepActive = computed(() => {
  if (!detail.value) return 0
  if (detail.value.status === '已驳回') return 0
  return devStageIndex(detail.value.status)
})
const isCancelled = computed(() => detail.value?.status === '已取消')
/** 评审驳回（作废）：时间线停在评审池，轨道高亮转为红色语义 */
const isRejected = computed(() => detail.value?.status === '已驳回')

/**
 * el-tag 属性助手：把「灰色哨兵」转成 info 类型 + 灰色覆盖类，
 * 其余类型原样传给 el-tag（避免直接在模板里做类型断言）。
 */
function tagProps(t: DevTagType): { type: 'primary' | 'success' | 'warning' | 'danger' | 'info'; class: string } {
  if (t === 'grey') {
    return { type: 'info', class: 'tag--grey' }
  }
  return { type: t, class: '' }
}

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
  done: '完成凭证',
  release: '上线材料',
}

const groupedAtts = computed(() => {
  const groups: Array<{ scene: string; title: string; items: DevAttachment[] }> = []
  if (!detail.value) return groups
  const atts = detail.value.attachments
  for (const scene of ['submit', 'design', 'reject', 'done', 'release']) {
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

onMounted(load)
watch(reqId, load)
</script>

<template>
  <div class="page">
    <!-- 顶部工具栏：返回列表 + 操作区（与问题详情页一致） -->
    <div class="page__actions page__actions--toolbar">
      <el-button @click="router.back()">返回列表</el-button>
      <DevRequestActions v-if="detail" :req="detail" @refresh="load" />
    </div>

    <MoonLoader v-if="loading" text="正在加载需求详情…" />

    <template v-else-if="detail">
      <!-- 环节进度：轻量阶段轨道（小圆点 + 细连线，当前环节高亮；替代默认 el-steps 的大图标样式） -->
      <div v-if="!isCancelled" class="moon-card steps-card">
        <div class="stage-rail">
          <template v-for="(s, i) in DEV_STAGES" :key="s">
            <div
              class="stage-rail__stop"
              :class="{
                'is-done': i < stepActive,
                'is-current': i === stepActive && !isRejected,
                'is-rejected': i === stepActive && isRejected,
              }"
            >
              <span class="stage-rail__dot" aria-hidden="true" />
              <span class="stage-rail__name">{{ s }}</span>
            </div>
            <span
              v-if="i < DEV_STAGES.length - 1"
              class="stage-rail__line"
              :class="{ 'is-done': i < stepActive }"
              aria-hidden="true"
            />
          </template>
          <span v-if="isRejected" class="pill pill--red stage-rail__rejected">已驳回（作废）</span>
        </div>
        <div v-if="isRejected" class="stage-rail__hint">需求未通过评审，如仍需要请重新提交新需求</div>
      </div>
      <el-alert v-else type="info" :closable="false" title="该需求已被提交人取消" />

      <!-- 需求信息卡：头部标识区 + 左（元信息）+ 右（描述/设计文档/附件） -->
      <div class="moon-card moon-card--moonlit issue-card">
        <div class="issue-head">
          <div class="issue-head__main">
            <div class="issue-head__titleline">
              <span class="issue-head__title">{{ detail.title }}</span>
              <span class="issue-head__no">{{ detail.reqNo }}</span>
            </div>
            <div class="issue-head__tags">
              <el-tag v-bind="tagProps(DEV_STATUS_TAG[detail.status])" effect="light" size="small">
                {{ detail.status }}
              </el-tag>
              <el-tag v-bind="tagProps(DEV_PRIORITY_TAG[detail.priority])" effect="light" size="small">
                {{ detail.priority }}
              </el-tag>
              <el-tag type="primary" effect="light" size="small">{{ detail.moduleName || '未分类' }}</el-tag>
              <el-tooltip
                v-if="detail.reviewPending"
                :content="`待定备注：${detail.reviewComment || ''}`"
                placement="top"
                effect="light"
                :show-after="200"
              >
                <el-tag type="warning" effect="light" size="small">待定</el-tag>
              </el-tooltip>
            </div>
          </div>
          <div class="issue-head__aside">
            <div class="issue-head__stamp">
              <span class="issue-head__stamp-label">提交时间</span>
              <span class="issue-head__stamp-value">{{ fmtDateTime(detail.createdAt) }}</span>
            </div>
            <div class="issue-head__stamp">
              <span class="issue-head__stamp-label">最近更新</span>
              <span class="issue-head__stamp-value">{{ detail.updatedAt ? fmtDateTime(detail.updatedAt) : '—' }}</span>
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
                <span class="meta-row__label">产品经理</span>
                <span class="meta-row__value">{{ detail.pmName || '待认领' }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">评审人</span>
                <span class="meta-row__value">{{ detail.reviewerName || '待评审' }}</span>
              </div>
              <div v-if="detail.reviewedAt" class="meta-row">
                <span class="meta-row__label">评审时间</span>
                <span class="meta-row__value">{{ fmtDateTime(detail.reviewedAt) }}</span>
              </div>
              <div v-if="detail.reviewComment" class="meta-row">
                <span class="meta-row__label">评审意见</span>
                <span class="meta-row__value">{{ detail.reviewComment }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">开发分支</span>
                <span class="meta-row__value mono">{{ detail.branch || '—' }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">负责人</span>
                <span class="meta-row__value">{{ detail.devLeaderName || '—' }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-row__label">上线时间</span>
                <span class="meta-row__value">{{ detail.releasedAt ? fmtDateTime(detail.releasedAt) : '未上线' }}</span>
              </div>
              <div v-if="detail.releasedBy" class="meta-row">
                <span class="meta-row__label">发布人</span>
                <span class="meta-row__value">{{ detail.releasedBy }}</span>
              </div>
            </div>
          </aside>

          <div class="issue-body__main">
            <div class="info-desc">
              <div class="info-desc__label">需求描述</div>
              <div class="info-desc__body">{{ detail.description }}</div>
            </div>

            <!-- 设计文档（网址，新窗口打开） -->
            <div v-if="detail.designUrl" class="info-attachments">
              <div class="info-attachments__label">设计文档（{{ detail.pmName || '—' }}）</div>
              <div class="attachment-list">
                <a
                  class="attachment-chip"
                  :href="detail.designUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  :title="`打开设计文档：${detail.designUrl}`"
                >
                  <span class="attachment-chip__icon" aria-hidden="true">URL</span>
                  <span class="attachment-chip__name cell-ellipsis">{{ detail.designUrl }}</span>
                  <span class="attachment-chip__act">打开</span>
                </a>
              </div>
            </div>

            <!-- 设计说明 -->
            <div v-if="detail.designDoc" class="info-desc info-desc--spaced">
              <div class="info-desc__label">设计说明（{{ detail.pmName || '—' }}）</div>
              <div class="info-desc__body">{{ detail.designDoc }}</div>
            </div>

            <!-- 附件（按场景分组，chip 样式与问题详情一致） -->
            <div v-for="g in groupedAtts" :key="g.scene" class="info-attachments">
              <div class="info-attachments__label">{{ g.title }}（{{ g.items.length }} 个）</div>
              <div class="attachment-list">
                <a
                  v-for="a in g.items"
                  :key="a.id"
                  class="attachment-chip"
                  :href="a.url"
                  :class="{ 'is-image': isImage(a.mimeType, a.name) }"
                  :title="isImage(a.mimeType, a.name) ? '点击查看图片' : '点击下载文件'"
                  @click.prevent="onAttachmentClick(a, g.items)"
                >
                  <span class="attachment-chip__icon" aria-hidden="true">{{ isImage(a.mimeType, a.name) ? 'IMG' : 'FILE' }}</span>
                  <span class="attachment-chip__name cell-ellipsis">{{ a.name }}</span>
                  <span class="attachment-chip__size">{{ fmtSize(a.size) }}</span>
                  <span class="attachment-chip__act">{{ isImage(a.mimeType, a.name) ? '查看' : '下载' }}</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 流转时间线（最新在上，样式与问题详情的处理记录一致） -->
      <div class="moon-card">
        <div class="section-title">
          流转时间线
          <span class="section-title__hint">提交、评审、设计、开发与发布的全部记录，最新在上</span>
        </div>

        <el-timeline v-if="detail.logs.length" class="record-timeline">
          <el-timeline-item
            v-for="(l, idx) in detail.logs"
            :key="l.id"
            :timestamp="fmtDateTime(l.createdAt)"
            placement="top"
            :hollow="idx !== 0"
          >
            <div class="record" :class="{ 'is-latest': idx === 0 }">
              <div class="record__head">
                <span :class="logTypeClass(l.type)">{{ l.type }}</span>
                <span class="num-strong">{{ l.account }}</span>
                <RoleTag :role="l.role" />
              </div>
              <div class="record__body">{{ l.content }}</div>
            </div>
          </el-timeline-item>
        </el-timeline>
        <div v-else class="subs-empty">暂无流转记录</div>
      </div>
    </template>

    <div v-else-if="!loading" class="subs-empty">需求不存在或不在您的可见范围</div>
  </div>
</template>

<style scoped>
/* 环节进度轨道：小圆点 + 细连线（与页面 pill 体系同风格）
 * 已过的环节实心主色圆点 + 主色连线；当前环节圆点带光晕；未到环节灰色空心点 */
.steps-card {
  padding: 16px 24px;
}

.stage-rail {
  display: flex;
  align-items: center;
  gap: 0;
  flex-wrap: wrap;
  row-gap: 10px;
}

.stage-rail__stop {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}

.stage-rail__dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--color-bg-container);
  border: 2px solid var(--color-border);
  box-sizing: border-box;
  transition: all var(--transition-base);
}

.stage-rail__name {
  font-size: 13.5px;
  color: var(--color-text-secondary);
  white-space: nowrap;
  transition: color var(--transition-base);
}

/* 已走过的环节：实心主色 + 深色文字 */
.stage-rail__stop.is-done .stage-rail__dot {
  background: var(--color-primary);
  border-color: var(--color-primary);
}

.stage-rail__stop.is-done .stage-rail__name {
  color: var(--color-text-regular);
}

/* 当前环节：圆点放大带光晕 + 主色加粗文字 */
.stage-rail__stop.is-current .stage-rail__dot {
  background: var(--color-primary);
  border-color: var(--color-primary);
  box-shadow: 0 0 0 4px var(--color-primary-tint);
}

.stage-rail__stop.is-current .stage-rail__name {
  color: var(--color-primary);
  font-weight: 600;
}

/* 评审驳回：当前环节转红色语义 */
.stage-rail__stop.is-rejected .stage-rail__dot {
  background: var(--color-danger);
  border-color: var(--color-danger);
  box-shadow: 0 0 0 4px var(--color-danger-tint);
}

.stage-rail__stop.is-rejected .stage-rail__name {
  color: var(--color-danger);
  font-weight: 600;
}

.stage-rail__rejected {
  margin-left: 12px;
}

.stage-rail__hint {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px dashed var(--color-border-light);
  font-size: 12px;
  color: var(--color-danger);
}

/* 连线：细线 + 圆角，未走过与已走过两种色 */
.stage-rail__line {
  flex: 1 1 24px;
  min-width: 24px;
  height: 2px;
  margin: 0 10px;
  border-radius: 2px;
  background: var(--color-border-light);
}

.stage-rail__line.is-done {
  background: var(--color-primary-soft);
}

/* 信息卡：头部标识区（与问题详情页一致） */
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

.issue-head__titleline {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  min-width: 0;
}

.issue-head__title {
  font-size: 20px;
  font-weight: 600;
  line-height: 1.3;
  color: var(--color-text-primary);
  word-break: break-word;
}

.issue-head__no {
  font-family: var(--font-mono, monospace);
  font-size: 13px;
  color: var(--color-text-secondary);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.issue-head__tags {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

/* 灰色语义 tag（el-tag 无 grey 类型，用 info 类型 + 本类覆盖为灰蓝色） */
.issue-head__tags :deep(.tag--grey) {
  --el-tag-bg-color: var(--color-greyblue-tint);
  --el-tag-border-color: transparent;
  --el-tag-text-color: #5d688e;
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
  gap: 10px;
  padding: 8px 0;
  font-size: 13.5px;
}

.meta-row + .meta-row {
  border-top: 1px dashed var(--color-border-light);
}

.meta-row__label {
  flex: 0 0 auto;
  width: 4.5em;
  color: var(--color-text-secondary);
}

.meta-row__value {
  flex: 1 1 auto;
  min-width: 0;
  color: var(--color-text-primary);
  text-align: left;
  word-break: break-word;
}

.meta-row__sub {
  margin-left: 6px;
  font-size: 12px;
  color: var(--color-text-placeholder);
}

.mono {
  font-family: var(--font-mono, monospace);
  font-size: 12.5px;
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

/* 描述 / 设计说明块 */
.info-desc__label {
  font-size: 13px;
  color: var(--color-text-secondary);
  margin-bottom: 6px;
}

.info-desc--spaced {
  margin-top: 16px;
}

.info-desc__body {
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  font-size: 14px;
  line-height: 1.7;
  color: var(--color-text-primary);
  white-space: pre-wrap;
  word-break: break-word;
}

/* 设计文档 / 附件分组 */
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
  max-width: 300px;
  padding: 5px 10px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-bg-container);
  color: var(--color-text-regular);
  font-size: 12px;
  cursor: pointer;
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
  max-width: 180px;
}

.attachment-chip__size {
  color: var(--color-text-placeholder);
  flex: 0 0 auto;
}

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

/* 时间线记录块（与问题详情的处理记录一致） */
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
  word-break: break-word;
}

.subs-empty {
  padding: 18px 0;
  text-align: center;
  font-size: 12.5px;
  color: var(--color-text-secondary);
}
</style>