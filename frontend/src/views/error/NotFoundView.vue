<script setup lang="ts">
/** 404：页面不存在 */
import { useRouter } from 'vue-router'
import ErrorIllustration from '@/components/illustrations/ErrorIllustration.vue'

const router = useRouter()
</script>

<template>
  <div class="error-page">
    <div class="error-card moon-card">
      <ErrorIllustration variant="404" :size="170" />
      <h2 class="error-card__title">404 · 页面不存在</h2>
      <p class="error-card__desc">你访问的页面可能已被移动或删除，请检查地址是否正确，或返回首页继续操作。</p>
      <div class="error-card__actions">
        <el-button type="primary" @click="router.replace('/issues/create')">返回首页</el-button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.error-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100%;
  padding: 32px;
}

.error-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  width: 480px;
  max-width: 100%;
  padding: 36px 32px;
  text-align: center;
}

.error-card__title {
  margin: 6px 0 0;
  font-size: 20px;
  font-weight: 600;
  color: rgba(43, 51, 87, 0.88);
}

.error-card__desc {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.7;
  color: rgba(43, 51, 87, 0.65);
}

.error-card__actions {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}
</style>
