<script setup lang="ts">
/** 问题价值胶囊 Tag：很有价值=樱粉底 / 比较有价值=夜蓝底 / 毫无价值=灰蓝底 */
import { computed } from 'vue'
import { valueClass } from '@/constants/issue'

const props = defineProps<{ valueName: string | null | undefined }>()

const cls = computed(() => valueClass(props.valueName))
const text = computed(() => props.valueName || '未评级')
</script>

<template>
  <span :class="cls">{{ text }}</span>
</template>
