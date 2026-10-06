<script setup lang="ts">
/**
 * 多附件上传组件。
 * - 支持拖拽 + 点击上传，图片缩略图预览，文档显示文件名与大小，可删除
 * - 校验：最多 max 个，单个不超过 maxSizeMb（默认 50MB）
 * - 上传走 api/upload.ts，真实落盘到服务器 uploads/
 */
import { computed, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { uploadFile } from '@/api/log'
import { downloadAttachment, isPreviewableImage, openAttachment } from '@/utils/attachment'
import type { Attachment } from '@/types'
import { fmtSize, isImage } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    modelValue: Attachment[]
    max?: number
    maxSizeMb?: number
    accept?: string
    disabled?: boolean
    tip?: string
  }>(),
  {
    max: 9,
    maxSizeMb: 50,
    accept: 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z',
    disabled: false,
    tip: '',
  },
)

const emit = defineEmits<{ 'update:modelValue': [Attachment[]] }>()

const inputRef = ref<HTMLInputElement | null>(null)
const uploading = ref(false)

const list = computed(() => props.modelValue || [])
const canAdd = computed(() => !props.disabled && list.value.length < props.max)

function pick(): void {
  if (!canAdd.value) {
    ElMessage.warning(`最多上传 ${props.max} 个附件`)
    return
  }
  inputRef.value?.click()
}

async function onChange(e: Event): Promise<void> {
  const target = e.target as HTMLInputElement
  const files = Array.from(target.files || [])
  target.value = ''
  await handleFiles(files)
}

async function handleFiles(files: File[]): Promise<void> {
  if (!files.length) {
    return
  }
  const room = props.max - list.value.length
  if (files.length > room) {
    ElMessage.warning(`最多上传 ${props.max} 个附件，本次仅上传前 ${room} 个`)
  }
  const accepted = files.slice(0, Math.max(0, room))
  const next: Attachment[] = [...list.value]
  uploading.value = true
  try {
    for (const file of accepted) {
      if (file.size > props.maxSizeMb * 1024 * 1024) {
        ElMessage.error(`「${file.name}」超过 ${props.maxSizeMb}MB，未上传`)
        continue
      }
      const res = await uploadFile(file, 'attachment')
      next.push({
        id: Date.now() + Math.floor(Math.random() * 1000),
        url: res.url,
        name: res.name || file.name,
        size: res.size || file.size,
        mimeType: res.mimeType || file.type,
      })
    }
    emit('update:modelValue', next)
  } catch {
    ElMessage.error('附件上传失败，请重试')
  } finally {
    uploading.value = false
  }
}

function remove(index: number): void {
  const next = [...list.value]
  next.splice(index, 1)
  emit('update:modelValue', next)
}

/** 图片可点击预览（同组图片可左右切换）；其他文档仅允许下载 */
function preview(att: Attachment): void {
  void openAttachment(
    att.url,
    att.mimeType,
    att.name,
    list.value.filter((a) => isImage(a.mimeType, a.name)).map((a) => ({ url: a.url, name: a.name })),
  )
}

/** 下载单个附件（文档类必须下载，不允许在线查看） */
function downloadOne(att: Attachment): void {
  void downloadAttachment(att.url, att.name)
}

function onDrop(e: DragEvent): void {
  e.preventDefault()
  if (props.disabled) {
    return
  }
  const files = Array.from(e.dataTransfer?.files || [])
  void handleFiles(files)
}

async function downloadAll(): Promise<void> {
  if (!list.value.length) {
    ElMessage.info('暂无可下载的附件')
    return
  }
  await ElMessageBox.alert(
    `共 ${list.value.length} 个附件，点击确定后逐个下载（浏览器可能询问是否允许多个下载）`,
    '下载附件',
    { confirmButtonText: '确定' },
  )
  for (const a of list.value) {
    await downloadAttachment(a.url, a.name)
  }
}
</script>

<template>
  <div class="attachment-upload">
    <div
      class="attachment-upload__drop"
      :class="{ 'is-disabled': disabled, 'is-full': !canAdd }"
      @click="pick"
      @dragover.prevent
      @drop="onDrop"
    >
      <div class="attachment-upload__hint">
        <span class="attachment-upload__plus">+</span>
        <span class="attachment-upload__text">
          点击或拖拽文件到此处上传（{{ list.length }}/{{ max }}，单个不超过 {{ maxSizeMb }}MB）
        </span>
      </div>
      <div class="attachment-upload__rule">图片可点击直接查看；文档类仅支持下载</div>
      <div v-if="tip" class="form-tip">{{ tip }}</div>
      <input
        ref="inputRef"
        class="attachment-upload__input"
        type="file"
        multiple
        :accept="accept"
        :disabled="disabled"
        @change="onChange"
      />
    </div>

    <div v-if="uploading" class="form-tip">正在上传，请稍候…</div>

    <div v-if="list.length" class="attachment-upload__list">
      <div v-for="(att, idx) in list" :key="`${att.url}-${idx}`" class="attachment-item">
        <img
          v-if="isImage(att.mimeType, att.name)"
          class="attachment-item__thumb"
          :src="att.url"
          :alt="att.name"
          :title="`点击查看图片：${att.name}`"
          @click="preview(att)"
        />
        <div v-else class="attachment-item__file" aria-hidden="true">
          <span class="attachment-item__ext">{{ (att.name.split('.').pop() || 'file').toUpperCase() }}</span>
        </div>
        <div class="attachment-item__meta">
          <!-- 图片：可点击预览；其他文档：不提供在线查看，只能下载 -->
          <a
            v-if="isImage(att.mimeType, att.name)"
            class="attachment-item__name cell-ellipsis"
            :title="`点击查看图片：${att.name}`"
            @click.prevent="preview(att)"
          >{{ att.name }}</a>
          <span v-else class="attachment-item__name cell-ellipsis" :title="att.name">{{ att.name }}</span>
          <span class="attachment-item__size">{{ fmtSize(att.size) }}</span>
        </div>
        <button
          v-if="!isImage(att.mimeType, att.name)"
          class="attachment-item__download"
          type="button"
          title="下载文件"
          @click.stop="downloadOne(att)"
        >
          下载
        </button>
        <button v-if="!disabled" class="attachment-item__remove" type="button" title="删除" @click.stop="remove(idx)">
          ×
        </button>
      </div>
    </div>

    <div v-if="list.length" class="attachment-upload__actions">
      <el-button link type="primary" size="small" @click="downloadAll">下载全部附件</el-button>
    </div>
  </div>
</template>

<style scoped>
.attachment-upload {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.attachment-upload__drop {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 16px;
  border: 1px dashed var(--color-primary-soft);
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  cursor: pointer;
  transition: border-color var(--transition-base), background var(--transition-base);
}

.attachment-upload__drop:hover {
  border-color: var(--color-primary);
  background: var(--color-primary-tint);
}

.attachment-upload__drop.is-disabled,
.attachment-upload__drop.is-full {
  cursor: not-allowed;
  opacity: 0.6;
}

.attachment-upload__hint {
  display: flex;
  align-items: center;
  gap: 8px;
}

.attachment-upload__plus {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: var(--radius-pill);
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 16px;
  font-weight: 600;
}

.attachment-upload__text {
  font-size: 13px;
  color: var(--color-text-regular);
}

.attachment-upload__rule {
  font-size: 12px;
  color: var(--color-text-secondary);
}

.attachment-upload__input {
  display: none;
}

.attachment-upload__list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.attachment-item {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 232px;
  padding: 6px 8px;
  border: 1px solid var(--color-border-divider);
  border-radius: var(--radius-control);
  background: var(--color-bg-container);
  transition: box-shadow var(--transition-base);
}

.attachment-item:hover {
  box-shadow: var(--shadow-card);
}

.attachment-item__thumb {
  width: 34px;
  height: 34px;
  border-radius: 6px;
  object-fit: cover;
  flex: 0 0 auto;
  cursor: zoom-in;
  transition: transform var(--transition-base);
}

.attachment-item__thumb:hover {
  transform: scale(1.06);
}

/* 文档下载按钮（文档不允许在线查看，只能下载） */
.attachment-item__download {
  flex: 0 0 auto;
  height: 22px;
  padding: 0 9px;
  border: 1px solid var(--color-primary-soft);
  border-radius: var(--radius-pill);
  background: var(--color-primary-tint);
  color: var(--color-primary);
  font-size: 12px;
  line-height: 20px;
  cursor: pointer;
  transition: background var(--transition-base);
}

.attachment-item__download:hover {
  background: #e2e7fb;
}

.attachment-item__file {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 6px;
  background: var(--color-primary-tint);
  flex: 0 0 auto;
}

.attachment-item__ext {
  font-size: 9px;
  font-weight: 600;
  color: var(--color-primary);
}

.attachment-item__meta {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.attachment-item__name {
  font-size: 12px;
  color: var(--color-text-primary);
  cursor: pointer;
}

.attachment-item__name:hover {
  color: var(--color-primary);
}

.attachment-item__size {
  font-size: 11px;
  color: var(--color-text-placeholder);
}

.attachment-item__remove {
  flex: 0 0 auto;
  width: 18px;
  height: 18px;
  border: none;
  border-radius: var(--radius-pill);
  background: var(--color-danger-tint);
  color: var(--color-danger);
  font-size: 13px;
  line-height: 1;
  cursor: pointer;
}

.attachment-upload__actions {
  display: flex;
  justify-content: flex-end;
}
</style>
