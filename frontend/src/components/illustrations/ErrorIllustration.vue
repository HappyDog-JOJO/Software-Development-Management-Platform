<script setup lang="ts">
/**
 * 错误插画（403 / 404 页）：猫咪与月亮，配文由调用方给出。
 */
withDefaults(defineProps<{ variant?: '403' | '404'; size?: number }>(), { variant: '404', size: 160 })
</script>

<template>
  <div class="error-illustration">
    <svg
      :width="size"
      :height="size * 0.7"
      viewBox="0 0 200 140"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      role="img"
      :aria-label="variant === '403' ? '无访问权限' : '页面不存在'"
    >
      <ellipse cx="100" cy="122" rx="70" ry="9" fill="#EDF0FD" />
      <!-- 月亮（半掩） -->
      <path d="M156 22a20 20 0 1 0 14 34 24 24 0 0 1-14-34Z" fill="#C9D2F7" opacity="0.85" />
      <!-- 云朵 -->
      <g opacity="0.35">
        <ellipse cx="46" cy="34" rx="24" ry="11" fill="#EDF0FD" />
        <ellipse cx="64" cy="29" rx="16" ry="9" fill="#EDF0FD" />
      </g>
      <!-- 猫咪（困惑闭眼） -->
      <g transform="translate(58 54)">
        <path d="M2 30c0-15 12-27 28-27s28 12 28 27c0 6-5 11-12 11H14C7 41 2 36 2 30Z" fill="#EDF0FD" />
        <circle cx="22" cy="25" r="15" fill="#FFFFFF" />
        <path d="M9 15 11 4l9 6-11 5Z" fill="#C9D2F7" />
        <path d="M35 15 33 4l-9 6 11 5Z" fill="#C9D2F7" />
        <path d="M16 26c1.6 1.6 4 1.6 5.6 0" stroke="#7C8CE4" stroke-width="1.6" stroke-linecap="round" />
        <path d="M25 26c1.6 1.6 4 1.6 5.6 0" stroke="#7C8CE4" stroke-width="1.6" stroke-linecap="round" />
        <path d="M23 30l1.4 1.2L23 32.4 21.6 31.2 23 30Z" fill="#F2A0C0" />
        <ellipse cx="13" cy="30" rx="2.6" ry="1.7" fill="#F2A0C0" opacity="0.5" />
        <ellipse cx="32" cy="30" rx="2.6" ry="1.7" fill="#F2A0C0" opacity="0.5" />
        <path d="M58 32c8 0 12-5 11-11-1-4-5-3-4 0 0 3 3 3 3 1" stroke="#C9D2F7" stroke-width="2.8" stroke-linecap="round" />
      </g>
      <!-- 星芒 -->
      <path d="M28 82c1 3.6 1.8 4.4 5.4 5.4-3.6 1-4.4 1.8-5.4 5.4-1-3.6-1.8-4.4-5.4-5.4 3.6-1 4.4-1.8 5.4-5.4Z" fill="#A8B4F0" opacity="0.8" />
      <!-- 变体标记 -->
      <text
        v-if="variant === '403'"
        x="100"
        y="136"
        text-anchor="middle"
        font-size="0"
        fill="none"
      >403</text>
    </svg>
  </div>
</template>

<style scoped>
.error-illustration {
  display: flex;
  align-items: center;
  justify-content: center;
}

.error-illustration svg {
  max-width: 100%;
  height: auto;
}
</style>
