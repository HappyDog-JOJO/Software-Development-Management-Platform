<script setup lang="ts">
/**
 * 空态插画：猫咪 + 蜷缩小角色（手绘线条、圆润蓬松、夜色系）。
 * 使用位置：列表/表格/时间线/报表的空数据态。
 */
withDefaults(
  defineProps<{
    size?: number
    text?: string
    subText?: string
  }>(),
  { size: 140, text: '暂无数据', subText: '' },
)
</script>

<template>
  <div class="empty-illustration">
    <svg
      class="empty-illustration__art anim-float"
      :width="size"
      :height="size * 0.75"
      viewBox="0 0 200 150"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      role="img"
      :aria-label="text"
    >
      <!-- 柔光底 -->
      <ellipse cx="100" cy="128" rx="76" ry="10" fill="#EDF0FD" />

      <!-- 蓬松猫咪 -->
      <g transform="translate(40 58)">
        <path
          d="M2 26c0-14 11-24 25-24s25 10 25 24c0 6-5 10-11 10H13C7 36 2 32 2 26Z"
          fill="#EDF0FD"
        />
        <circle cx="20" cy="22" r="13" fill="#FFFFFF" />
        <path d="M9 13 11 3l8 6-10 4Z" fill="#C9D2F7" />
        <path d="M31 13 29 3l-8 6 10 4Z" fill="#C9D2F7" />
        <path d="M15 23c1.4 1.4 3.6 1.4 5 0" stroke="#7C8CE4" stroke-width="1.5" stroke-linecap="round" />
        <path d="M23 23c1.4 1.4 3.6 1.4 5 0" stroke="#7C8CE4" stroke-width="1.5" stroke-linecap="round" />
        <path d="M21 27l1.4 1.2L21 29.4 19.6 28.2 21 27Z" fill="#F2A0C0" />
        <ellipse cx="12" cy="27" rx="2.4" ry="1.6" fill="#F2A0C0" opacity="0.5" />
        <ellipse cx="30" cy="27" rx="2.4" ry="1.6" fill="#F2A0C0" opacity="0.5" />
        <path d="M52 27c7 0 11-4 10-10-1-4-5-3-4 0 0 2 2 3 3 1" stroke="#C9D2F7" stroke-width="2.6" stroke-linecap="round" />
      </g>

      <!-- 蜷缩小角色 -->
      <g transform="translate(120 74)">
        <ellipse cx="24" cy="30" rx="22" ry="18" fill="#E7EBFC" />
        <circle cx="24" cy="16" r="12" fill="#C9D2F7" />
        <path d="M13 12c2-6 9-9 15-7-4 1-7 3-8 7-2-1-5-1-7 0Z" fill="#F2A0C0" opacity="0.85" />
        <path d="M19 17c1.2 1.2 3.2 1.2 4.4 0" stroke="#2B3357" stroke-width="1.4" stroke-linecap="round" />
        <path d="M26 17c1.2 1.2 3.2 1.2 4.4 0" stroke="#2B3357" stroke-width="1.4" stroke-linecap="round" />
        <ellipse cx="17" cy="21" rx="2.2" ry="1.4" fill="#F2A0C0" opacity="0.5" />
        <ellipse cx="31" cy="21" rx="2.2" ry="1.4" fill="#F2A0C0" opacity="0.5" />
      </g>

      <!-- 小月亮与星芒 -->
      <path d="M170 26a10 10 0 1 0 7 17 12 12 0 0 1-7-17Z" fill="#C9D2F7" />
      <path d="M30 26c1 3.6 1.8 4.4 5.4 5.4-3.6 1-4.4 1.8-5.4 5.4-1-3.6-1.8-4.4-5.4-5.4 3.6-1 4.4-1.8 5.4-5.4Z" fill="#A8B4F0" opacity="0.8" />
      <circle cx="152" cy="18" r="2" fill="#F2A0C0" opacity="0.7" />
    </svg>
    <p class="empty-illustration__text">{{ text }}</p>
    <p v-if="subText" class="empty-illustration__sub">{{ subText }}</p>
  </div>
</template>

<style scoped>
.empty-illustration {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 24px 16px;
}

.empty-illustration__art {
  display: block;
  max-width: 100%;
  height: auto;
}

.empty-illustration__text {
  margin: 4px 0 0;
  font-size: 14px;
  color: rgba(43, 51, 87, 0.45);
}

.empty-illustration__sub {
  margin: 0;
  font-size: 12px;
  color: rgba(43, 51, 87, 0.25);
}
</style>
