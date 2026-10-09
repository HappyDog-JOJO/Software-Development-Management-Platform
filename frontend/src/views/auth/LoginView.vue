<script setup lang="ts">
/**
 * 登录页：全站最「可爱」的一页。
 * 左侧 55% 夜色品牌面板（深夜卧室插画铺底 + 顶部墨蓝渐隐保证文字可读 + 星芒点缀）；
 * 右侧 45% 月光白登录卡片。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const formRef = ref<FormInstance>()
const loading = ref(false)

const form = reactive({ account: '', password: '' })

const rules: FormRules = {
  account: [{ required: true, message: '请输入真实姓名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

/** 演示账号（开发模式显示） */
const demoAccounts = [
  { account: '商辂', password: '1994', role: '管理员' },
  { account: 'sales01', password: '123456', role: '销售员' },
  { account: 'service01', password: '123456', role: '售后服务人员' },
  { account: 'manager01', password: '123456', role: '销售经理' },
]

const sellingPoints = ['统一需求入口，想法不再散落各处', '评审开发留痕，进度可追溯可审计', '从提交到上线，每一步都有记录']

const showDemo = computed(() => import.meta.env.DEV || import.meta.env.VITE_SHOW_DEMO === '1')

function fillDemo(item: { account: string; password: string }): void {
  form.account = item.account
  form.password = item.password
}

async function onSubmit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  loading.value = true
  try {
    await auth.login(form.account, form.password)
    ElMessage.success('登录成功')
    const redirect = (route.query.redirect as string) || '/issues/create'
    await router.replace(redirect)
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (!auth.isLoggedIn) {
    return
  }
  // 已登录：先向服务端确认登录态是否仍然有效。
  // 若 token 已失效（如后台重置密码/数据重置），此处直接清理本地登录态，
  // 避免带着无效 token 跳首页又被 401 拉回来，造成反复跳转。
  try {
    await auth.fetchProfile()
    await router.replace((route.query.redirect as string) || '/issues/create')
  } catch {
    await auth.logout()
  }
})
</script>

<template>
  <div class="auth-page">
    <!-- 夜色品牌面板 -->
    <section class="auth-brand">
      <div class="auth-brand__art" aria-hidden="true" />
      <div class="auth-brand__scrim" aria-hidden="true" />
      <div class="auth-brand__stars" aria-hidden="true" />
      <div class="auth-brand__moon" aria-hidden="true" />
      <div class="auth-brand__inner">
        <div class="auth-brand__badge">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 2.6 4 7v10l8 4.4 8-4.4V7l-8-4.4Z" fill="#EAF2FF" opacity="0.92" />
            <path d="M12 8.4 7.6 10.8v4.4L12 17.6l4.4-2.4v-4.4L12 8.4Z" fill="#F2A0C0" />
          </svg>
        </div>
        <h1 class="auth-brand__title">软件开发管理平台</h1>
        <p class="auth-brand__subtitle">提交—评审—开发—上线，让需求全流程闭环可追溯</p>
        <ul class="auth-brand__points">
          <li v-for="p in sellingPoints" :key="p">
            <span class="auth-brand__check" aria-hidden="true">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none">
                <path d="m5 13 4.5 4.5L19 7" stroke="#C9D2F7" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            {{ p }}
          </li>
        </ul>
      </div>
    </section>

    <!-- 月光白登录卡片 -->
    <section class="auth-panel">
      <div class="auth-card moon-card anim-fade-in">
        <h2 class="auth-card__title">登录</h2>
        <p class="auth-card__desc">欢迎回来，登录后继续处理售后与需求工作</p>

        <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="onSubmit">
          <el-form-item label="真实姓名" prop="account">
            <el-input v-model="form.account" size="large" placeholder="请输入真实姓名" clearable />
          </el-form-item>
          <el-form-item label="密码" prop="password">
            <el-input
              v-model="form.password"
              size="large"
              type="password"
              show-password
              placeholder="请输入密码"
              @keyup.enter="onSubmit"
            />
          </el-form-item>
          <el-button type="primary" class="auth-card__submit" :loading="loading" @click="onSubmit">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5Zm-3 8V7a3 3 0 1 1 6 0v3H9Z" />
            </svg>
            登 录
          </el-button>
        </el-form>

        <div class="auth-card__foot">
          还没有账号？<router-link class="auth-card__link" to="/register">立即注册</router-link>
        </div>

        <div v-if="showDemo" class="auth-card__demo">
          <div class="auth-card__demo-title">演示账号（点击填充）</div>
          <div class="auth-card__demo-list">
            <button v-for="d in demoAccounts" :key="d.account" type="button" class="demo-chip" @click="fillDemo(d)">
              {{ d.role }} · {{ d.account }}
            </button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.auth-page {
  display: flex;
  height: 100vh;
  overflow: hidden;
}

/* —— 左侧夜色品牌面板 —— */
.auth-brand {
  position: relative;
  flex: 0 0 55%;
  background: #273747;
  overflow: hidden;
}

/* 深夜卧室插画铺底（人物下移，文案在上方留白区） */
.auth-brand__art {
  position: absolute;
  inset: 0;
  background-image: url('@/assets/brand/brand-scene.jpg');
  background-size: 118% auto;
  background-position: 46% 96%;
  background-repeat: no-repeat;
  pointer-events: none;
}

/* 顶部墨蓝渐隐：压住上方留白供白字阅读，人物区完全露出 */
.auth-brand__scrim {
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, rgba(32, 39, 68, 0.95) 0%, rgba(32, 39, 68, 0.82) 18%, rgba(32, 39, 68, 0.46) 33%, rgba(32, 39, 68, 0.1) 48%, transparent 60%);
  pointer-events: none;
}

/* 星芒点缀（透明度 ≤ 15%） */
.auth-brand__stars {
  position: absolute;
  inset: 0;
  background-image: radial-gradient(1.6px 1.6px at 18% 22%, rgba(234, 242, 255, 0.5), transparent),
    radial-gradient(1.6px 1.6px at 72% 16%, rgba(234, 242, 255, 0.4), transparent),
    radial-gradient(1.4px 1.4px at 42% 62%, rgba(242, 160, 192, 0.4), transparent),
    radial-gradient(1.4px 1.4px at 86% 48%, rgba(234, 242, 255, 0.35), transparent),
    radial-gradient(1.2px 1.2px at 28% 82%, rgba(234, 242, 255, 0.3), transparent);
  opacity: 0.15;
  pointer-events: none;
  z-index: 2;
}

.auth-brand__moon {
  position: absolute;
  top: -60px;
  right: -40px;
  width: 260px;
  height: 260px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(234, 242, 255, 0.14), transparent 62%);
  pointer-events: none;
  z-index: 2;
}

.auth-brand__inner {
  position: relative;
  z-index: 3;
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: 46px 12% 32px 13%;
  justify-content: flex-start;
  gap: 12px;
}

.auth-brand__badge {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.14);
  margin-bottom: 6px;
}

.auth-brand__title {
  margin: 0;
  font-size: 30px;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: 0.5px;
}

.auth-brand__subtitle {
  margin: 0;
  font-size: 14.5px;
  color: rgba(255, 255, 255, 0.72);
}

.auth-brand__points {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 10px 0 0;
  padding: 0;
  list-style: none;
}

.auth-brand__points li {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13.5px;
  color: rgba(255, 255, 255, 0.88);
}

.auth-brand__check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: var(--radius-pill);
  background: rgba(234, 242, 255, 0.16);
  flex: 0 0 auto;
}

.auth-brand__art {
  margin-top: 6px;
}

.auth-brand__art {
  margin-top: 6px;
}

/* —— 右侧登录卡片 —— */
.auth-panel {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--color-bg-page);
}

.auth-card {
  position: relative;
  width: 400px;
  max-width: 100%;
  padding: 36px 36px 28px;
  overflow: hidden;
}

/* 卡片顶部极淡月光高光 */
.auth-card::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 4px;
  background: linear-gradient(90deg, var(--color-moonlight), rgba(242, 160, 192, 0.35) 70%, transparent);
}

.auth-card__title {
  margin: 0 0 4px;
  font-size: 23px;
  font-weight: 700;
  color: rgba(43, 51, 87, 0.88);
}

.auth-card__desc {
  margin: 0 0 20px;
  font-size: 13.5px;
  color: rgba(43, 51, 87, 0.65);
}

.auth-card__submit {
  width: 100%;
  height: 44px;
  border-radius: var(--radius-control);
  font-size: 15px;
  letter-spacing: 1px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-top: 4px;
}

.auth-card__foot {
  margin-top: 14px;
  text-align: center;
  font-size: 13px;
  color: rgba(43, 51, 87, 0.65);
}

.auth-card__link {
  color: var(--color-accent-pink);
  font-weight: 600;
}

.auth-card__link:hover {
  color: #d0698f;
}

.auth-card__demo {
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px dashed var(--color-border);
}

.auth-card__demo-title {
  font-size: 12px;
  color: rgba(43, 51, 87, 0.45);
  margin-bottom: 8px;
}

.auth-card__demo-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.demo-chip {
  padding: 4px 10px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-bg-sub);
  color: rgba(43, 51, 87, 0.65);
  font-size: 12px;
  cursor: pointer;
  transition: all var(--transition-base);
}

.demo-chip:hover {
  border-color: var(--color-primary-soft);
  background: var(--color-primary-tint);
  color: var(--color-primary);
}

/* —— 响应式 —— */
@media (max-width: 1100px) {
  .auth-brand__inner {
    padding-left: 10%;
  }
  .auth-brand__title {
    font-size: 26px;
  }
}

/* 窄屏：插画改为顶部横幅，文案恢复常规留白 */
@media (max-width: 900px) {
  .auth-page {
    flex-direction: column;
    height: auto;
    min-height: 100vh;
    overflow: auto;
  }
  .auth-brand {
    flex: 0 0 auto;
    min-height: 232px;
  }
  .auth-brand__art {
    background-position: 68% 74%;
  }
  .auth-brand__scrim {
    background: linear-gradient(178deg, rgba(35, 42, 73, 0.5) 0%, rgba(35, 42, 73, 0.82) 62%, rgba(35, 42, 73, 0.95) 100%);
  }
  .auth-brand__inner {
    padding: 28px 24px 26px;
    height: auto;
    gap: 8px;
    justify-content: flex-end;
  }
  .auth-panel {
    padding: 20px 16px 32px;
  }
}
</style>
