<script setup lang="ts">
/**
 * 加载态插画：缓慢旋转的月亮 + 呼吸的星星。
 * 使用位置：页面/区块加载中（数据密集区仅用小尺寸，不遮挡）。
 * 已遵循 prefers-reduced-motion（动效在 animation.css 统一降级）。
 */
withDefaults(defineProps<{ text?: string; size?: number }>(), { text: '加载中', size: 56 })
</script>

<template>
  <div class="moon-loader">
    <svg
      :width="size"
      :height="size"
      viewBox="0 0 60 60"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      role="img"
      aria-label="加载中"
    >
      <!-- 外圈轨道 -->
      <circle cx="30" cy="30" r="24" stroke="#EDF0FD" stroke-width="3" />
      <!-- 旋转的月亮 -->
      <g class="anim-spin" style="transform-origin: 30px 30px">
        <path d="M30 8a22 22 0 1 0 15 38 26 26 0 0 1-15-38Z" fill="#A8B4F0" />
      </g>
      <!-- 呼吸的星星 -->
      <g class="anim-breath">
        <path d="M46 14c0.9 3.4 1.7 4.2 5.1 5.1-3.4 0.9-4.2 1.7-5.1 5.1-0.9-3.4-1.7-4.2-5.1-5.1 3.4-0.9 4.2-1.7 5.1-5.1Z" fill="#F2A0C0" />
        <circle cx="13" cy="45" r="2" fill="#C9D2F7" />
      </g>
    </svg>
    <span v-if="text" class="moon-loader__text">{{ text }}</span>
  </div>
</template>

<style scoped>
.moon-loader {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 24px;
}

.moon-loader__text {
  font-size: 13px;
  color: rgba(43, 51, 87, 0.45);
}
</style>
