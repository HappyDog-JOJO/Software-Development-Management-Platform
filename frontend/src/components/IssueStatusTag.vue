<script setup lang="ts">
/** 问题状态胶囊 Tag：统一圆点 + 文字，不靠图标形状传达状态（避免误读） */
import { computed } from 'vue'
import type { IssueStatus } from '@/types'
import { STATUS_CLASS } from '@/constants/issue'

const props = defineProps<{ status: string }>()

const cls = computed(() => STATUS_CLASS[props.status as IssueStatus] ?? 'pill pill--grey')
</script>

<template>
  <span :class="cls">
    <span class="tag-dot" aria-hidden="true" />
    {{ status }}
  </span>
</template>

<style scoped>
.tag-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  flex: 0 0 auto;
}
</style>
