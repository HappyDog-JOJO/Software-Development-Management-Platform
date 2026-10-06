<script setup lang="ts">
/**
 * 修改密码弹窗（个人中心 / 顶栏入口触发，属自愿操作）。
 * - 新密码需满足强度且不得与原密码相同
 */
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { changePassword } from '@/api/auth'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [boolean] }>()

const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({ oldPassword: '', newPassword: '', confirmPassword: '' })

const visible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

/** 密码强度：按长度与字符类型构成 0-3 级 */
const strength = computed(() => {
  const v = form.newPassword
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
  oldPassword: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  newPassword: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    {
      validator: (_r, value: string, cb) => {
        if (!value || value.length < 6) {
          cb(new Error('新密码至少 6 位'))
          return
        }
        if (!/[A-Za-z]/.test(value) || !/\d/.test(value)) {
          cb(new Error('新密码需同时包含字母与数字'))
          return
        }
        cb()
      },
      trigger: 'blur',
    },
  ],
  confirmPassword: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_r, value: string, cb) => {
        if (value !== form.newPassword) {
          cb(new Error('两次输入的密码不一致'))
          return
        }
        cb()
      },
      trigger: 'blur',
    },
  ],
}

watch(visible, (v) => {
  if (v) {
    form.oldPassword = ''
    form.newPassword = ''
    form.confirmPassword = ''
    formRef.value?.clearValidate()
  }
})

async function submit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  if (form.newPassword === form.oldPassword) {
    ElMessage.error('新密码不能与原密码相同')
    return
  }
  submitting.value = true
  try {
    await changePassword(form.oldPassword, form.newPassword)
    ElMessage.success('密码修改成功')
    emit('update:modelValue', false)
  } finally {
    submitting.value = false
  }
}

const title = '修改密码'
</script>

<template>
  <el-dialog
    v-model="visible"
    :title="title"
    width="440px"
    :close-on-click-modal="false"
    align-center
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
      <el-form-item label="原密码" prop="oldPassword">
        <el-input v-model="form.oldPassword" type="password" show-password placeholder="请输入当前密码" />
      </el-form-item>
      <el-form-item label="新密码" prop="newPassword">
        <el-input v-model="form.newPassword" type="password" show-password placeholder="至少 6 位，建议含字母与数字" />
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
      <el-form-item label="确认新密码" prop="confirmPassword">
        <el-input v-model="form.confirmPassword" type="password" show-password placeholder="请再次输入新密码" />
      </el-form-item>
    </el-form>

    <template #footer>
      <div class="dialog-footer">
        <el-button @click="visible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">确认修改</el-button>
      </div>
    </template>
  </el-dialog>
</template>

<style scoped>
.strength {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-top: 6px;
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

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
