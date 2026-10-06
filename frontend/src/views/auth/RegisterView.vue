<script setup lang="ts">
/**
 * 注册页：沿用登录页左右分栏版式（左夜色插画品牌面板 + 右月光白表单卡）。
 * 必填：真实姓名、密码、联系电话、所属部门；头像选填；注册成功角色固定为「销售员」。
 * 真实姓名同时作为登录名（重名会被占用，第二个同名需加后缀区分）。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { checkAccount, register } from '@/api/auth'
import { fetchPublicDepartments } from '@/api/department'
import AvatarUpload from '@/components/AvatarUpload.vue'

const router = useRouter()
const formRef = ref<FormInstance>()
const loading = ref(false)
const checking = ref(false)
const accountTaken = ref(false)
/** 可选部门（仅启用中的；公开接口，无需登录） */
const departments = ref<Array<{ id: number; name: string }>>([])
const deptLoading = ref(false)

const form = reactive({
  account: '',
  password: '',
  phone: '',
  avatar: '',
  departmentId: undefined as number | undefined,
})

/** 姓名唯一性：输入防抖后调用公开的可用性接口 */
let debounceTimer: number | undefined
function onAccountInput(): void {
  accountTaken.value = false
  if (debounceTimer) {
    window.clearTimeout(debounceTimer)
  }
  const value = form.account.trim()
  if (value.length < 2) {
    return
  }
  debounceTimer = window.setTimeout(async () => {
    checking.value = true
    try {
      const res = await checkAccount(value)
      accountTaken.value = res.available === false
    } catch {
      // 接口不可用时不做实时拦截，提交时由服务端最终校验
      accountTaken.value = false
    } finally {
      checking.value = false
    }
  }, 450)
}

const strength = computed(() => {
  const v = form.password
  if (!v) {
    return { level: 0, text: '未输入', color: 'var(--color-border)' }
  }
  let score = 0
  if (v.length >= 6) {
    score++
  }
  if (/[A-Za-z]/.test(v) && /\d/.test(v)) {
    score++
  }
  if (v.length >= 10 || (/[^A-Za-z0-9]/.test(v) && /[A-Za-z]/.test(v))) {
    score++
  }
  if (score <= 1) {
    return { level: 1, text: '强度偏弱', color: 'var(--color-danger)' }
  }
  if (score === 2) {
    return { level: 2, text: '强度中等', color: 'var(--color-warning)' }
  }
  return { level: 3, text: '强度良好', color: 'var(--color-success)' }
})

const rules: FormRules = {
  account: [
    { required: true, message: '请输入真实姓名', trigger: 'blur' },
    { min: 2, max: 20, message: '姓名需 2-20 位', trigger: 'blur' },
    {
      validator: (_r, _v, cb) => {
        if (accountTaken.value) {
          cb(new Error('该姓名已被使用，请更换'))
          return
        }
        cb()
      },
      trigger: 'blur',
    },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, message: '密码至少 6 位', trigger: 'blur' },
  ],
  phone: [
    { required: true, message: '请输入联系电话', trigger: 'blur' },
    { pattern: /^1[3-9]\d{9}$/, message: '请输入正确的 11 位手机号', trigger: 'blur' },
  ],
  departmentId: [{ required: true, message: '请选择所属部门', trigger: 'change' }],
}

async function onSubmit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  // 头像选填：未上传也能提交（列表与详情实回退为姓名首字头像）
  if (!form.departmentId) {
    // 双保险：表单校验已保证必选，此处避免类型为空
    ElMessage.warning('请选择所属部门')
    return
  }
  loading.value = true
  try {
    await register({
      account: form.account.trim(),
      password: form.password,
      phone: form.phone.trim(),
      avatar: form.avatar,
      name: form.account.trim(),
      departmentId: form.departmentId,
    })
    ElMessage.success('注册成功')
    await router.replace({
      path: '/register-success',
      query: { account: form.account.trim(), dept: form.departmentId ? '1' : '0' },
    })
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  formRef.value?.clearValidate()
  void loadDepartments()
})

/** 拉取可选部门（仅启用中的；失败不阻塞注册） */
async function loadDepartments(): Promise<void> {
  deptLoading.value = true
  try {
    departments.value = await fetchPublicDepartments()
  } catch {
    departments.value = []
  } finally {
    deptLoading.value = false
  }
}
</script>

<template>
  <div class="auth-page">
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
        <p class="auth-brand__subtitle">注册后即可提交需求，部门与角色由管理员分配</p>
        <ul class="auth-brand__points">
          <li>必填：真实姓名、密码、联系电话、所属部门</li>
          <li>真实姓名同时作为登录名</li>
          <li>可直接选择所属部门，提交问题时自动带出</li>
        </ul>
      </div>
    </section>

    <section class="auth-panel">
      <div class="auth-card auth-card--wide moon-card anim-fade-in">
        <h2 class="auth-card__title">注册账号</h2>
        <p class="auth-card__desc">真实姓名、密码、联系电话、所属部门为必填；头像选填</p>

        <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
          <el-form-item label="真实姓名" prop="account">
            <el-input
              v-model="form.account"
              size="large"
              placeholder="2-20 位，支持中英文、数字与下划线"
              clearable
              @input="onAccountInput"
            />
            <div v-if="checking" class="form-tip">正在校验姓名是否可用…</div>
            <div v-else-if="accountTaken" class="form-tip is-error">该姓名已被使用，请更换</div>
            <div v-else class="form-tip">真实姓名同时作为登录名，请填写便于识别的姓名。</div>
          </el-form-item>

          <el-form-item label="密码" prop="password">
            <el-input v-model="form.password" size="large" type="password" show-password placeholder="至少 6 位，建议含字母与数字" />
            <div class="strength">
              <div class="strength__bars">
                <span
                  v-for="i in 3"
                  :key="i"
                  class="strength__bar"
                  :style="{ background: i <= strength.level ? strength.color : 'var(--color-border)' }"
                />
              </div>
              <span class="strength__text" :style="{ color: strength.color }">{{ strength.text }}</span>
            </div>
          </el-form-item>

          <el-form-item label="联系电话" prop="phone">
            <el-input v-model="form.phone" size="large" maxlength="11" placeholder="11 位手机号" clearable />
          </el-form-item>

          <el-form-item label="所属部门" prop="departmentId">
            <el-select
              v-model="form.departmentId"
              size="large"
              placeholder="请选择所属部门"
              :loading="deptLoading"
              filterable
              style="width: 100%"
            >
              <el-option v-for="d in departments" :key="d.id" :label="d.name" :value="d.id" />
            </el-select>
            <div class="form-tip">
              {{ departments.length ? '仅列出启用中的部门，必选。' : '系统暂无可选部门，请联系管理员先维护部门。' }}
            </div>
          </el-form-item>

          <el-form-item label="用户头像">
            <AvatarUpload v-model="form.avatar" :size="88" :required="false" />
          </el-form-item>

          <el-button type="primary" class="auth-card__submit" :loading="loading" @click="onSubmit">
            提交注册
          </el-button>
        </el-form>

        <div class="auth-card__foot">
          已有账号？<router-link class="auth-card__link" to="/login">去登录</router-link>
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

.auth-brand__stars {
  position: absolute;
  inset: 0;
  background-image: radial-gradient(1.6px 1.6px at 18% 22%, rgba(234, 242, 255, 0.5), transparent),
    radial-gradient(1.6px 1.6px at 72% 16%, rgba(234, 242, 255, 0.4), transparent),
    radial-gradient(1.4px 1.4px at 42% 62%, rgba(242, 160, 192, 0.4), transparent),
    radial-gradient(1.4px 1.4px at 86% 48%, rgba(234, 242, 255, 0.35), transparent);
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
  padding: 42px 12% 32px 13%;
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
  font-size: 13.5px;
  color: rgba(255, 255, 255, 0.88);
}

.auth-panel {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--color-bg-page);
  overflow-y: auto;
}

.auth-card {
  position: relative;
  width: 400px;
  max-width: 100%;
  padding: 32px 36px 26px;
  overflow: hidden;
}

.auth-card--wide {
  width: 440px;
}

.auth-card::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 4px;
  background: linear-gradient(90deg, var(--color-moonlight), rgba(242, 160, 192, 0.35) 70%, transparent);
}

.auth-card__title {
  margin: 0 0 4px;
  font-size: 22px;
  font-weight: 700;
  color: rgba(43, 51, 87, 0.88);
}

.auth-card__desc {
  margin: 0 0 18px;
  font-size: 13.5px;
  color: rgba(43, 51, 87, 0.65);
}

.auth-card__submit {
  width: 100%;
  height: 44px;
  border-radius: var(--radius-control);
  font-size: 15px;
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

.form-tip.is-error {
  color: var(--color-danger);
}

.strength {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-top: 6px;
  margin-bottom: 6px;
}

.strength__bars {
  display: flex;
  gap: 4px;
  flex: 1;
}

.strength__bar {
  height: 4px;
  flex: 1;
  border-radius: var(--radius-pill);
  transition: background var(--transition-base);
}

.strength__text {
  font-size: 12px;
  white-space: nowrap;
}

@media (max-width: 900px) {
  .auth-page {
    flex-direction: column;
    height: auto;
    min-height: 100vh;
    overflow: auto;
  }
  .auth-brand {
    flex: 0 0 auto;
    min-height: 210px;
  }
  .auth-brand__art {
    background-position: 68% 74%;
  }
  .auth-brand__scrim {
    background: linear-gradient(178deg, rgba(35, 42, 73, 0.5) 0%, rgba(35, 42, 73, 0.82) 62%, rgba(35, 42, 73, 0.95) 100%);
  }
  .auth-brand__inner {
    padding: 24px 24px 20px;
    height: auto;
    gap: 8px;
    justify-content: flex-end;
  }
  .auth-brand__title {
    font-size: 24px;
  }
  .auth-panel {
    padding: 16px 16px 32px;
    align-items: flex-start;
  }
}
</style>
