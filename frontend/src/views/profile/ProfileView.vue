<script setup lang="ts">
/**
 * 个人中心：查看/修改本人头像、姓名、联系电话；修改密码。
 * 角色与部门只读（需管理员调整）。
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { updateProfile } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import AvatarUpload from '@/components/AvatarUpload.vue'
import RoleTag from '@/components/RoleTag.vue'
import ChangePasswordDialog from '@/views/auth/ChangePasswordDialog.vue'

const auth = useAuthStore()
const saving = ref(false)
const changePwdVisible = ref(false)

const form = reactive({ name: '', phone: '', avatar: '' })

function syncFromUser(): void {
  form.name = auth.user?.name || ''
  form.phone = auth.user?.phone || ''
  form.avatar = auth.user?.avatar || ''
}

async function onSave(): Promise<void> {
  if (form.phone && !/^1[3-9]\d{9}$/.test(form.phone)) {
    ElMessage.error('联系电话格式不正确，应为 11 位手机号')
    return
  }
  saving.value = true
  try {
    const u = await updateProfile({ name: form.name.trim(), phone: form.phone.trim(), avatar: form.avatar })
    auth.applyUser(u)
    ElMessage.success('个人资料已更新')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    const u = await auth.fetchProfile()
    auth.applyUser(u)
  } catch {
    // 拉取失败时沿用本地缓存
  }
  syncFromUser()
})
</script>

<template>
  <div class="page">
    <div class="page__actions page__actions--toolbar">
      <el-button @click="changePwdVisible = true">修改密码</el-button>
    </div>

    <div class="profile-grid">
      <div class="moon-card moon-card--moonlit">
        <div class="section-title">基础资料</div>
        <el-form label-width="90px" label-position="right">
          <el-form-item label="账号">
            <div class="readonly-field">{{ auth.user?.account }}</div>
          </el-form-item>
          <el-form-item label="姓名">
            <el-input v-model="form.name" placeholder="用于展示与统计" maxlength="30" />
          </el-form-item>
          <el-form-item label="联系电话">
            <el-input v-model="form.phone" placeholder="11 位手机号" maxlength="11" />
          </el-form-item>
          <el-form-item label="头像">
            <AvatarUpload v-model="form.avatar" :size="84" :required="false" />
          </el-form-item>
          <el-form-item label=" ">
            <el-button type="primary" :loading="saving" @click="onSave">保存资料</el-button>
          </el-form-item>
        </el-form>
      </div>

      <div class="moon-card moon-card--moonlit">
        <div class="section-title">角色与部门<span class="section-title__hint">只读，如需变更请联系管理员</span></div>
        <div class="readonly-list">
          <div class="readonly-row">
            <span class="readonly-row__label">当前角色</span>
            <RoleTag v-if="auth.user" :roles="auth.user.roles" />
          </div>
          <div class="readonly-row">
            <span class="readonly-row__label">所属部门</span>
            <span class="readonly-row__value">{{ auth.user?.departmentName || '未分配（请联系管理员）' }}</span>
          </div>
          <div class="readonly-row">
            <span class="readonly-row__label">数据可见范围</span>
            <span class="readonly-row__value">
              {{ auth.isManager ? '仅本部门问题与本部门报表' : '全部问题' + (auth.isSales ? '（无报表入口）' : '与全部报表') }}
            </span>
          </div>
          <div class="readonly-row">
            <span class="readonly-row__label">注册时间</span>
            <span class="readonly-row__value">{{ auth.user?.createdAt || '—' }}</span>
          </div>
        </div>
      </div>
    </div>

    <ChangePasswordDialog v-model="changePwdVisible" />
  </div>
</template>

<style scoped>
/* 页头已移除，操作按钮独占一行并收紧顶部间距 */
.profile-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
  gap: 24px;
  align-items: start;
}

.readonly-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.readonly-row {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13.5px;
}

.readonly-row__label {
  width: 110px;
  color: var(--color-text-secondary);
  flex: 0 0 auto;
}

.readonly-row__value {
  color: var(--color-text-primary);
}

@media (max-width: 1000px) {
  .profile-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
