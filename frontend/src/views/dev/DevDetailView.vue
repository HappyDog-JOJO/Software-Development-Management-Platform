<script setup lang="ts">
/**
 * 需求详情页：六站步骤条 + 全量信息 + 附件 + 时间线。
 * 操作按钮由 DevRequestActions 按角色与状态动态渲染；
 * 开发中心内由开发负责人直接提交开发完成（操作区按钮）。
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getDevRequest } from '@/api/devreq'
import { DEV_PRIORITY_CLASS, DEV_STAGES, DEV_STATUS_CLASS, devStageIndex } from '@/constants/devreq'
import { logTypeClass } from '@/constants/issue'
import DevRequestActions from '@/components/DevRequestActions.vue'
import { fmtDateTime, fmtSize, isImage } from '@/utils/format'
import { openAttachment } from '@/utils/attachment'
import type { DevAttachment, DevRequestDetail } from '@/types'

const route = useRoute()

const loading = ref(false)
const detail = ref<DevRequestDetail | null>(null)

const reqId = computed(() => Number(route.params.id) || 0)

/** 步骤条进度：正常状态按六站序号；已驳回停在评审池；已取消不展示步骤条 */
const stepActive = computed(() => {
  if (!detail.value) return 0
  if (detail.value.status === '已驳回') return 0
  return devStageIndex(detail.value.status)
})
const isCancelled = computed(() => detail.value?.status === '已取消')

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
  <div class="page" v-loading="loading">
    <template v-if="detail">
      <!-- 步骤条 -->
      <div v-if="!isCancelled" class="moon-card steps-card">
        <el-steps :active="stepActive" align-center finish-status="success">
          <el-step v-for="(s, i) in DEV_STAGES" :key="s" :title="s" :description="stepActive === i && detail.status === '已驳回' ? '已作废' : ''" />
        </el-steps>
        <div class="steps-status">
          <span :class="DEV_STATUS_CLASS[detail.status as keyof typeof DEV_STATUS_CLASS]">{{ detail.status }}</span>
          <span v-if="detail.status === '已驳回'" class="reject-hint">需求未通过评审，已作废（如仍需要请重新提交新需求）</span>
        </div>
      </div>
      <el-alert v-else type="info" :closable="false" title="该需求已被提交人取消" style="margin-bottom: 14px" />

      <!-- 基本信息 -->
      <div class="moon-card info-card">
        <div class="info-head">
          <div class="info-title">
            <h2>{{ detail.title }}</h2>
            <span class="mono">{{ detail.reqNo }}</span>
          </div>
          <div class="info-actions">
            <DevRequestActions :req="detail" @refresh="load" />
          </div>
        </div>

        <div class="desc-block">{{ detail.description }}</div>

        <div class="meta-grid">
          <div class="meta-item">
            <span class="meta-label">状态</span>
            <span :class="DEV_STATUS_CLASS[detail.status as keyof typeof DEV_STATUS_CLASS]">{{ detail.status }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">优先级</span>
            <span :class="DEV_PRIORITY_CLASS[detail.priority as keyof typeof DEV_PRIORITY_CLASS]">{{ detail.priority }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">所属模块</span>
            <span>{{ detail.moduleName || '—' }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">提交人</span>
            <span>{{ detail.submitterName }}（{{ detail.submitterDepartmentName || '未分配部门' }}）</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">提交时间</span>
            <span>{{ fmtDateTime(detail.createdAt) }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">评审</span>
            <span>
              {{ detail.reviewerName ? `${detail.reviewerName} · ${fmtDateTime(detail.reviewedAt ?? '')}` : '待评审' }}
              <em v-if="detail.reviewComment" class="meta-note">{{ detail.reviewComment }}</em>
            </span>
          </div>
          <div class="meta-item">
            <span class="meta-label">产品经理</span>
            <span>{{ detail.pmName || '待认领' }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">开发分支</span>
            <span class="mono">{{ detail.branch || '—' }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">开发负责人</span>
            <span>{{ detail.devLeaderName || '—' }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">开发完成</span>
            <span>
              {{ detail.devDoneAt ? fmtDateTime(detail.devDoneAt) : '开发中' }}
            </span>
          </div>
          <div class="meta-item">
            <span class="meta-label">上线</span>
            <span>
              {{ detail.releasedAt ? `${fmtDateTime(detail.releasedAt)}（发布：${detail.releasedBy ?? '—'}）` : '未上线' }}
            </span>
          </div>
        </div>

        <div v-if="detail.designDoc" class="design-doc">
          <div class="design-doc__title">设计说明（{{ detail.pmName }}）</div>
          <div class="design-doc__body">{{ detail.designDoc }}</div>
        </div>

        <div v-if="detail.devDoneNote" class="design-doc">
          <div class="design-doc__title">开发完成说明</div>
          <div class="design-doc__body">{{ detail.devDoneNote }}</div>
        </div>
      </div>

      <!-- 附件 -->
      <div v-if="groupedAtts.length" class="moon-card atts-card">
        <h3 class="card-title">附件</h3>
        <div v-for="g in groupedAtts" :key="g.scene" class="att-group">
          <div class="att-group__title">{{ g.title }}</div>
          <div class="att-list">
            <a
              v-for="a in g.items"
              :key="a.id"
              class="att-file"
              :href="a.url"
              :class="{ 'is-image': isImage(a.mimeType, a.name) }"
              :title="isImage(a.mimeType, a.name) ? '点击查看图片' : '点击下载文件'"
              @click.prevent="onAttachmentClick(a, g.items)"
            >
              <span class="att-file__icon" aria-hidden="true">{{ isImage(a.mimeType, a.name) ? 'IMG' : 'FILE' }}</span>
              <span class="att-file__name">{{ a.name }}</span>
              <span class="att-file__size">{{ fmtSize(a.size) }}</span>
              <span class="att-file__act">{{ isImage(a.mimeType, a.name) ? '查看' : '下载' }}</span>
            </a>
          </div>
        </div>
      </div>


      <!-- 时间线 -->
      <div class="moon-card logs-card">
        <h3 class="card-title">流转时间线</h3>
        <ul v-if="detail.logs.length" class="logs">
          <li v-for="l in detail.logs" :key="l.id" class="log-item">
            <span class="log-item__dot" aria-hidden="true" />
            <div class="log-item__main">
              <div class="log-item__head">
                <span :class="logTypeClass(l.type)">{{ l.type }}</span>
                <span class="log-item__who">{{ l.account }}（{{ l.role }}）</span>
                <span class="log-item__time">{{ fmtDateTime(l.createdAt) }}</span>
              </div>
              <div class="log-item__body">{{ l.content }}</div>
            </div>
          </li>
        </ul>
        <div v-else class="subs-empty">暂无流转记录</div>
      </div>
    </template>

    <div v-else-if="!loading" class="subs-empty">需求不存在或不在您的可见范围</div>
  </div>
</template>

<style scoped>
.steps-card {
  padding: 18px 18px 12px;
  margin-bottom: 14px;
}

.steps-status {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-top: 4px;
}

.reject-hint {
  font-size: 12px;
  color: var(--color-danger, #c45656);
}

.info-card {
  margin-bottom: 14px;
}

.info-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.info-title {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}

.info-title h2 {
  margin: 0;
  font-size: 17px;
  color: var(--color-text-primary);
}

.mono {
  font-family: var(--font-mono, monospace);
  font-size: 12px;
  color: var(--color-text-secondary);
}

.desc-block {
  margin-top: 10px;
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  font-size: 13.5px;
  line-height: 1.7;
  color: var(--color-text-regular);
  white-space: pre-wrap;
  word-break: break-word;
}

.meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 8px 24px;
  margin-top: 14px;
}

.meta-item {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 13px;
  min-width: 0;
}

.meta-label {
  flex: 0 0 auto;
  color: var(--color-text-secondary);
}

.meta-label::after {
  content: '：';
}

.meta-note {
  display: block;
  font-style: normal;
  font-size: 12px;
  color: var(--color-text-secondary);
}

.design-doc {
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: var(--radius-control);
  border: 1px dashed var(--color-border-divider);
}

.design-doc__title {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--color-text-secondary);
}

.design-doc__body {
  margin-top: 6px;
  font-size: 13px;
  line-height: 1.7;
  color: var(--color-text-regular);
  white-space: pre-wrap;
  word-break: break-word;
}

.card-title {
  margin: 0 0 10px;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.atts-card,
.logs-card {
  margin-bottom: 14px;
}

.att-group {
  margin-bottom: 10px;
}

.att-group__title {
  font-size: 12.5px;
  color: var(--color-text-secondary);
  margin-bottom: 6px;
}

.att-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.att-file {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  color: var(--color-text-regular);
  text-decoration: none;
  font-size: 12.5px;
}

.att-file:hover {
  background: var(--color-primary-tint);
  color: var(--color-primary);
}

.att-file__icon {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--color-primary);
}

.att-file__size {
  font-size: 11px;
  color: var(--color-text-placeholder);
}

.att-file.is-image .att-file__icon {
  color: #2c7d5e;
}

.att-file__act {
  font-size: 11px;
  color: var(--color-primary);
}

.subs-empty {
  padding: 18px 0;
  text-align: center;
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

.logs {
  margin: 0;
  padding: 0;
  list-style: none;
}

.log-item {
  position: relative;
  display: flex;
  gap: 10px;
  padding: 0 0 16px 18px;
}

.log-item::before {
  content: '';
  position: absolute;
  left: 5px;
  top: 14px;
  bottom: 0;
  width: 1px;
  background: var(--color-border-divider);
}

.log-item:last-child::before {
  display: none;
}

.log-item__dot {
  position: absolute;
  left: 2px;
  top: 6px;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--color-primary);
}

.log-item__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.log-item__who {
  font-size: 12.5px;
  color: var(--color-text-regular);
}

.log-item__time {
  font-size: 11.5px;
  color: var(--color-text-placeholder);
  font-variant-numeric: tabular-nums;
}

.log-item__body {
  margin-top: 4px;
  font-size: 12.8px;
  line-height: 1.6;
  color: var(--color-text-regular);
  word-break: break-word;
}
</style>
