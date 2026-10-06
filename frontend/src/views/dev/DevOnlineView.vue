<script setup lang="ts">
/**
 * 上线功能：全员可见的已上线成果展示墙。
 * 按上线时间倒序展示所有已发布的需求，点击卡片进入需求详情。
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchDevWall } from '@/api/devreq'
import { DEV_PRIORITY_CLASS } from '@/constants/devreq'
import { fmtDateTime } from '@/utils/format'
import type { DevRequest } from '@/types'

const router = useRouter()
const loading = ref(false)
const items = ref<DevRequest[]>([])

async function load(): Promise<void> {
  loading.value = true
  try {
    items.value = await fetchDevWall()
  } catch {
    items.value = []
  } finally {
    loading.value = false
  }
}

function goDetail(row: DevRequest): void {
  void router.push(`/dev/reqs/${row.id}`)
}

onMounted(load)
</script>

<template>
  <div class="page" v-loading="loading">
    <div class="wall-banner">
      <span class="wall-banner__name">上线功能</span>
      <span class="wall-banner__desc">每一份需求落地都会在这里展示，共 {{ items.length }} 个功能已上线。</span>
    </div>

    <div v-if="items.length" class="wall-grid">
      <div v-for="row in items" :key="row.id" class="wall-card moon-card" @click="goDetail(row)">
        <div class="wall-card__head">
          <span class="wall-card__no mono">{{ row.reqNo }}</span>
          <span :class="DEV_PRIORITY_CLASS[row.priority as keyof typeof DEV_PRIORITY_CLASS]">{{ row.priority }}</span>
        </div>
        <div class="wall-card__title">{{ row.title }}</div>
        <div class="wall-card__meta">
          <span>模块：{{ row.moduleName || '—' }}</span>
          <span v-if="row.subTotal > 0">子任务：{{ row.subDone }}/{{ row.subTotal }}</span>
        </div>
        <div class="wall-card__desc">{{ row.description }}</div>
        <div class="wall-card__foot">
          <span class="wall-card__people">
            {{ row.submitterName }} 提出 · {{ row.pmName || '—' }} 设计 · {{ row.devLeaderName || '—' }} 开发
          </span>
          <span class="wall-card__time">{{ row.releasedAt ? fmtDateTime(row.releasedAt) : '' }} 上线</span>
        </div>
      </div>
    </div>

    <div v-else-if="!loading" class="wall-empty">还没有已上线的功能，发布后会第一时间出现在这里。</div>
  </div>
</template>

<style scoped>
.wall-banner {
  display: flex;
  align-items: baseline;
  gap: 12px;
  margin-bottom: 14px;
}

.wall-banner__name {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.wall-banner__desc {
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

.wall-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 14px;
}

.wall-card {
  padding: 14px 16px;
  cursor: pointer;
  transition: transform var(--transition-base), box-shadow var(--transition-base);
}

.wall-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-card, 0 6px 18px rgba(31, 35, 65, 0.1));
}

.wall-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.mono {
  font-family: var(--font-mono, monospace);
  font-size: 11.5px;
  color: var(--color-text-placeholder);
}

.wall-card__title {
  margin-top: 8px;
  font-size: 14.5px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.wall-card__meta {
  display: flex;
  gap: 12px;
  margin-top: 6px;
  font-size: 11.5px;
  color: var(--color-text-secondary);
}

.wall-card__desc {
  margin-top: 8px;
  font-size: 12.5px;
  line-height: 1.6;
  color: var(--color-text-regular);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.wall-card__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px dashed var(--color-border-divider);
}

.wall-card__people {
  font-size: 11.5px;
  color: var(--color-text-secondary);
}

.wall-card__time {
  font-size: 11.5px;
  color: #2c7d5e;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.wall-empty {
  padding: 60px 0;
  text-align: center;
  font-size: 13px;
  color: var(--color-text-secondary);
}
</style>
