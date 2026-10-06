<script setup lang="ts">
/** 注册成功结果页：柔和对勾插画 + 角色与部门提示 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import SuccessSpark from '@/components/illustrations/SuccessSpark.vue'

const route = useRoute()
const router = useRouter()

const account = computed(() => (route.query.account as string) || '')
const hasDepartment = computed(() => route.query.dept === '1')
</script>

<template>
  <div class="result-page">
    <div class="result-card moon-card anim-fade-in">
      <SuccessSpark :size="96" text="注册成功" />
      <p class="result-card__account" v-if="account">
        姓名 <span class="num-strong">{{ account }}</span> 已创建
      </p>
      <div class="result-card__tips">
        <p>您的角色为「销售员」，注册无需审核，可直接登录。</p>
        <p>已代入您选择的所属部门，提交问题时将自动带出。</p>
      </div>
      <el-button type="primary" class="result-card__btn" @click="router.replace('/login')">去登录</el-button>
    </div>
  </div>
</template>

<style scoped>
.result-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 24px;
  background: var(--color-bg-page);
}

.result-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  width: 440px;
  max-width: 100%;
  padding: 40px 32px;
  text-align: center;
}

.result-card__account {
  margin: 4px 0 0;
  font-size: 14px;
  color: rgba(43, 51, 87, 0.65);
}

.result-card__tips {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 4px 0 8px;
  font-size: 13px;
  color: rgba(43, 51, 87, 0.65);
}

.result-card__tips p {
  margin: 0;
}

.result-card__btn {
  width: 200px;
  height: 40px;
  border-radius: var(--radius-control);
}
</style>
