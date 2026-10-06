<script setup lang="ts">
/**
 * 成功对勾插画：柔和弹出（scale 0.9 → 1，200ms，弹性幅度极小）。
 * 使用位置：注册成功结果页、提交成功反馈。
 */
withDefaults(defineProps<{ size?: number; text?: string; subText?: string }>(), {
  size: 84,
  text: '',
  subText: '',
})
</script>

<template>
  <div class="success-spark">
    <svg
      class="anim-pop"
      :width="size"
      :height="size"
      viewBox="0 0 80 80"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      role="img"
      aria-label="操作成功"
    >
      <circle cx="40" cy="40" r="34" fill="#EAF8F2" />
      <circle cx="40" cy="40" r="26" fill="#5FC9A0" opacity="0.16" />
      <path
        d="M27 41.5 36 50l17-19"
        stroke="#5FC9A0"
        stroke-width="5"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
      <path d="M68 16c1 3.8 1.8 4.6 5.6 5.6-3.8 1-4.6 1.8-5.6 5.6-1-3.8-1.8-4.6-5.6-5.6 3.8-1 4.6-1.8 5.6-5.6Z" fill="#F2A0C0" />
      <circle cx="10" cy="24" r="2.4" fill="#A8B4F0" />
    </svg>
    <p v-if="text" class="success-spark__text">{{ text }}</p>
    <p v-if="subText" class="success-spark__sub">{{ subText }}</p>
  </div>
</template>

<style scoped>
.success-spark {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.success-spark__text {
  margin: 4px 0 0;
  font-size: 18px;
  font-weight: 600;
  color: rgba(43, 51, 87, 0.88);
}

.success-spark__sub {
  margin: 0;
  font-size: 13px;
  color: rgba(43, 51, 87, 0.65);
  text-align: center;
}
</style>
