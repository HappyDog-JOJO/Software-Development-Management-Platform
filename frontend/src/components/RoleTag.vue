<script setup lang="ts">
/** 角色胶囊 Tag（支持多角色，用 + 分隔） */
import { computed } from 'vue'
import { roleClass } from '@/constants/issue'
import type { Role } from '@/types'

const props = defineProps<{ role?: string; roles?: Role[] }>()

const list = computed<string[]>(() => {
  if (props.roles && props.roles.length) {
    return [...props.roles]
  }
  return props.role ? [props.role] : []
})
</script>

<template>
  <span class="role-tags">
    <span v-for="r in list" :key="r" :class="roleClass(r)">{{ r }}</span>
    <span v-if="!list.length" class="pill pill--grey">未分配</span>
  </span>
</template>

<style scoped>
.role-tags {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}
</style>
