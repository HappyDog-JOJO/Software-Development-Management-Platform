<script setup lang="ts">
/**
 * 应用根组件：承载路由出口 + 全局附件图片预览弹层。
 *
 * 预览弹层是全局单例：挂载在这里（而不是各页面各自维护），
 * 页面/组件只要调用 utils/attachment.ts 的 openAttachment() 即可，
 * 图片一律以**页面内弹窗**打开，不会跳转页面或新开标签页。
 */
import { onBeforeUnmount, ref } from 'vue'
import ImageViewer from '@/components/ImageViewer.vue'
import { registerImageViewer } from '@/utils/attachment'

const viewerRef = ref<InstanceType<typeof ImageViewer> | null>(null)

registerImageViewer({
  open: (list, startIndex) => viewerRef.value?.open(list, startIndex),
  close: () => viewerRef.value?.close(),
})

onBeforeUnmount(() => registerImageViewer(null))
</script>

<template>
  <router-view />
  <ImageViewer ref="viewerRef" />
</template>
