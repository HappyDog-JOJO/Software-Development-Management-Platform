<script setup lang="ts">
/**
 * 头像上传组件：圆形预览、必填校验（jpg/png，≤2MB）。
 * 上传成功后可「重新上传」。
 */
import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { uploadFile } from '@/api/log'

const props = withDefaults(
  defineProps<{
    modelValue: string
    size?: number
    required?: boolean
    disabled?: boolean
  }>(),
  { size: 96, required: true, disabled: false },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const inputRef = ref<HTMLInputElement | null>(null)
const uploading = ref(false)

const previewUrl = computed(() => props.modelValue)

function pick(): void {
  if (props.disabled) {
    return
  }
  inputRef.value?.click()
}

async function onChange(e: Event): Promise<void> {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (!file) {
    return
  }
  if (!/\.(png|jpe?g)$/i.test(file.name) && !['image/png', 'image/jpeg'].includes(file.type)) {
    ElMessage.error('头像仅支持 jpg / png 格式')
    return
  }
  if (file.size > 2 * 1024 * 1024) {
    ElMessage.error('头像不能超过 2MB')
    return
  }
  uploading.value = true
  try {
    const res = await uploadFile(file, 'avatar')
    emit('update:modelValue', res.url)
    ElMessage.success('头像上传成功')
  } catch {
    ElMessage.error('头像上传失败，请重试')
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div class="avatar-upload">
    <div
      class="avatar-upload__circle"
      :class="{ 'is-empty': !previewUrl }"
      :style="{ width: `${size}px`, height: `${size}px` }"
      @click="pick"
    >
      <img v-if="previewUrl" :src="previewUrl" alt="头像预览" />
      <span v-else class="avatar-upload__placeholder">+</span>
      <div v-if="previewUrl && !disabled" class="avatar-upload__mask">重新上传</div>
    </div>
    <div class="avatar-upload__meta">
      <el-button link type="primary" :disabled="disabled || uploading" @click="pick">
        {{ uploading ? '上传中…' : previewUrl ? '重新上传' : '选择头像' }}
      </el-button>
      <span class="form-tip">支持 jpg / png，不超过 2MB{{ required ? '，此项必填' : '' }}</span>
    </div>
    <input ref="inputRef" class="avatar-upload__input" type="file" accept="image/png,image/jpeg" @change="onChange" />
  </div>
</template>

<style scoped>
.avatar-upload {
  display: flex;
  align-items: center;
  gap: 16px;
}

.avatar-upload__circle {
  position: relative;
  flex: 0 0 auto;
  border-radius: var(--radius-pill);
  overflow: hidden;
  border: 1px dashed var(--color-primary-soft);
  background: var(--color-bg-sub);
  cursor: pointer;
  transition: border-color var(--transition-base);
}

.avatar-upload__circle:hover {
  border-color: var(--color-primary);
}

.avatar-upload__circle img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.avatar-upload__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  font-size: 24px;
  color: var(--color-primary-soft);
}

.avatar-upload__mask {
  position: absolute;
  inset: auto 0 0 0;
  padding: 3px 0;
  text-align: center;
  font-size: 11px;
  color: #fff;
  background: rgba(43, 51, 87, 0.6);
}

.avatar-upload__meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.avatar-upload__input {
  display: none;
}
</style>
