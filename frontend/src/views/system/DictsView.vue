<script setup lang="ts">

/**
 * 字典管理页（仅管理员）。
 * 顶部 Tabs 切换四类字典；表格含引用数；引用数 > 0 时禁止删除，仅可停用。
 * 「问题价值等级」至少保留一项启用。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { deleteDictItem, fetchDict, saveDictItem, toggleDictItem } from '@/api/dict'
import { saveLogoConfig, type LogoConfig } from '@/api/config'
import { uploadFile } from '@/api/log'
import { useDictStore } from '@/stores/dict'
import { useSiteConfigStore } from '@/stores/siteConfig'
import { fmtDateTime } from '@/utils/format'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import type { DictItem, DictType } from '@/types'
import { useColumnWidths } from '@/composables/useColumnWidths'

/** 表格列宽记忆（按当前用户隔离，拖动后自动记住） */
const colw = useColumnWidths('system-dicts')

/** 页签：三类字典 + 灯牌 logo（logo 不是字典类型，单独一页签） */
type TabKey = DictType | 'logo'

const tabs: Array<{ key: TabKey; label: string; tip: string }> = [
  { key: 'module', label: '所属模块', tip: '提交问题时选择，停用项不再出现在新单据中，历史数据正常展示。' },
  { key: 'value', label: '问题价值等级', tip: '默认三项必须预置，顺序即价值高低；至少保留一项启用。' },
  { key: 'handle', label: '处理情况', tip: '与问题状态联动：所选处理情况即为问题状态（未处理/处理中/已处理/已驳回）。' },
  { key: 'category', label: '问题分类', tip: '售后处理问题时选择，用于问题归类与列表筛选；停用项不再出现在新的处理表单中，历史数据正常展示。' },
  { key: 'loss_type', label: '输单类型', tip: '销售分析「输单登记」选择，用于统计丢单主要原因；停用项不再出现在新表单中，历史数据正常展示。' },
  { key: 'loss_stage', label: '丢单阶段', tip: '丢单发生在哪个销售环节（线索 → 合同），用于定位流程卡点。' },
  { key: 'industry', label: '所属行业', tip: '客户所属行业，用于分析不同行业的流失情况。' },
  { key: 'company_scale', label: '企业规模', tip: '客户企业规模分档，用于判断大客户与小微客户的丢单差异。' },
  { key: 'budget_match', label: '预算匹配', tip: '报价与客户预算的匹配情况（符合预算 / 报价超预算 / 预算未明确）。' },
  { key: 'logo', label: '灯牌 logo', tip: '配置问题提交页右侧灯牌下方的小动物。非必填：不上传时使用默认造型；上传后全站生效。' },
]

const activeTab = ref<TabKey>('module')
const items = ref<DictItem[]>([])
const loading = ref(false)
const dictStore = useDictStore()

const dialogVisible = ref(false)
const dialogMode = ref<'create' | 'edit'>('create')
const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive({ id: 0, type: 'module' as DictType, name: '', sort: 0 })

const rules: FormRules = {
  name: [{ required: true, message: '请输入字典项名称', trigger: 'blur' }],
}

const currentTip = computed(() => tabs.find((t) => t.key === activeTab.value)?.tip || '')
const list = computed(() => items.value.filter((i) => i.type === activeTab.value).sort((a, b) => a.sort - b.sort || a.id - b.id))
const isLogoTab = computed(() => activeTab.value === 'logo')

/** —— 灯牌 logo 配置 —— */
const siteConfig = useSiteConfigStore()
/** 页面内的本地草稿（保存前的预览） */
const logos = reactive<LogoConfig>({ duty_logo: '', notice_logo: '' })
const logoUploading = reactive({ duty_logo: false, notice_logo: false })
const logoSaving = reactive({ duty_logo: false, notice_logo: false })

const logoRows: Array<{ key: 'duty_logo' | 'notice_logo'; label: string; hint: string }> = [
  { key: 'duty_logo', label: '值班灯牌 logo', hint: '问题提交页右侧「今日值班」灯牌下方' },
  { key: 'notice_logo', label: '公告灯牌 logo', hint: '问题提交页右侧「公告」灯牌下方' },
]

function syncLogosFromStore(): void {
  logos.duty_logo = siteConfig.dutyLogo
  logos.notice_logo = siteConfig.noticeLogo
}

/** 选择图片→上传→仅暂存到草稿（还需点保存才生效） */
async function onLogoPick(key: 'duty_logo' | 'notice_logo', e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) {
    return
  }
  if (!/^image\//.test(file.type)) {
    ElMessage.warning('请选择图片文件')
    return
  }
  if (file.size > 2 * 1024 * 1024) {
    ElMessage.warning('图片不能超过 2MB')
    return
  }
  logoUploading[key] = true
  try {
    const res = await uploadFile(file, 'logo')
    logos[key] = res.url
    ElMessage.success('图片已上传，点「保存」后生效')
  } finally {
    logoUploading[key] = false
  }
}

async function onLogoSave(key: 'duty_logo' | 'notice_logo'): Promise<void> {
  logoSaving[key] = true
  try {
    const data = await saveLogoConfig(key, logos[key])
    siteConfig.apply(data)
    syncLogosFromStore()
    ElMessage.success(logos[key] ? 'logo 已更新' : '已恢复默认 logo')
  } finally {
    logoSaving[key] = false
  }
}

async function onLogoReset(key: 'duty_logo' | 'notice_logo'): Promise<void> {
  await ElMessageBox.confirm('确定恢复为默认 logo 吗？自定义图片将被清除。', '恢复默认 logo', {
    confirmButtonText: '确定恢复',
    cancelButtonText: '取消',
    type: 'warning',
  })
  logos[key] = ''
  await onLogoSave(key)
}

async function load(): Promise<void> {
  loading.value = true
  try {
    items.value = await fetchDict()
    // 同步全局字典缓存：本页是字典的唯一维护入口，
    // 不刷新会护其它页面的下拉（提交/处理/筛选）继续用旧选项
    dictStore.applyItems(items.value)
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  if (isLogoTab.value) {
    return
  }
  dialogMode.value = 'create'
  const maxSort = list.value.reduce((m, i) => Math.max(m, i.sort), 0)
  Object.assign(form, { id: 0, type: activeTab.value as DictType, name: '', sort: maxSort + 1 })
  dialogVisible.value = true
  void formRef.value?.clearValidate()
}

function openEdit(row: DictItem): void {
  dialogMode.value = 'edit'
  Object.assign(form, { id: row.id, type: row.type, name: row.name, sort: row.sort })
  dialogVisible.value = true
}

async function submitForm(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }
  submitting.value = true
  try {
    await saveDictItem({ id: form.id || undefined, type: form.type, name: form.name.trim(), sort: form.sort })
    ElMessage.success('字典项已保存')
    dialogVisible.value = false
    await load()
  } finally {
    submitting.value = false
  }
}

async function onToggle(row: DictItem): Promise<void> {
  const next = row.status === 1 ? 0 : 1
  await ElMessageBox.confirm(
    next === 1 ? `确定启用「${row.name}」吗？` : `确定停用「${row.name}」吗？停用后不再出现在新单据的下拉中。`,
    next === 1 ? '启用字典项' : '停用字典项',
    { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' },
  )
  await toggleDictItem(row.id, next)
  ElMessage.success(next === 1 ? '字典项已启用' : '字典项已停用')
  await load()
}

async function onDelete(row: DictItem): Promise<void> {
  if (row.referenceCount > 0) {
    ElMessage.warning(`该字典项已被 ${row.referenceCount} 条数据引用，仅可停用`)
    return
  }
  await ElMessageBox.confirm(`确定删除「${row.name}」吗？`, '删除字典项', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
  await deleteDictItem(row.id)
  ElMessage.success('字典项已删除')
  await load()
}

onMounted(async () => {
  await Promise.all([load(), siteConfig.load()])
  syncLogosFromStore()
})
</script>

<template>
  <div class="page">
    <div class="moon-card moon-card--tight">
      <el-tabs v-model="activeTab">
        <el-tab-pane v-for="t in tabs" :key="t.key" :label="t.label" :name="t.key" />
      </el-tabs>
      <div class="form-tip dict-tip">{{ currentTip }}</div>

      <!-- 卡片工具栏：主操作右对齐（与其它列表页一致） -->
      <div v-if="!isLogoTab" class="toolbar toolbar--actions-only">
        <div class="toolbar__actions">
          <el-button type="primary" @click="openCreate">新增字典项</el-button>
        </div>
      </div>

      <!-- 灯牌 logo 配置（非必填：不上传则用默认造型） -->
      <div v-if="isLogoTab" class="logo-panel">
        <div v-for="row in logoRows" :key="row.key" class="logo-card">
          <div class="logo-card__head">
            <span class="logo-card__title">{{ row.label }}</span>
            <span class="logo-card__hint">{{ row.hint }}</span>
          </div>

          <div class="logo-card__body">
            <div class="logo-card__preview" :class="{ 'is-custom': !!logos[row.key] }">
              <img v-if="logos[row.key]" :src="logos[row.key]" :alt="row.label" />
              <div v-else class="logo-card__empty">
                <span class="logo-card__empty-icon">默认</span>
                <span class="logo-card__empty-text">当前使用内置默认造型</span>
              </div>
            </div>

            <div class="logo-card__ops">
              <label class="logo-upload">
                <input
                  class="logo-upload__input"
                  type="file"
                  accept="image/png,image/jpeg,image/gif,image/webp"
                  @change="onLogoPick(row.key, $event)"
                />
                <el-button :loading="logoUploading[row.key]">
                  {{ logos[row.key] ? '重新选择图片' : '上传图片' }}
                </el-button>
              </label>
              <el-button
                type="primary"
                :disabled="logos[row.key] === (row.key === 'duty_logo' ? siteConfig.dutyLogo : siteConfig.noticeLogo)"
                :loading="logoSaving[row.key]"
                @click="onLogoSave(row.key)"
              >保存</el-button>
              <el-button
                v-if="logos[row.key]"
                :loading="logoSaving[row.key]"
                @click="onLogoReset(row.key)"
              >恢复默认</el-button>
            </div>

            <div class="logo-card__tips">
              <div>支持 PNG / JPG / GIF / WebP，单个不超过 2MB，建议正方形透明底图片。</div>
              <div>非必填：不上传或点「恢复默认」即使用当前的默认 logo。</div>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="moon-table">
        <el-table
        @header-dragend="colw.onDragend" border v-loading="loading" :data="list" row-key="id" stripe>
          <template #empty>
            <EmptyIllustration :size="130" text="暂无字典项" sub-text="点击右上角新增字典项" />
          </template>

          <el-table-column prop="name" label="名称" :min-width="colw.bind('name', { minWidth: 168 }).minWidth" />
          <el-table-column prop="sort" label="排序" :width="colw.bind('sort', { width: 94 }).width" align="right" />
          <el-table-column label="状态" :width="colw.bind('状态', { width: 109 }).width" align="center">
            <template #default="{ row }">
              <span :class="row.status === 1 ? 'pill pill--green' : 'pill pill--grey'">
                {{ row.status === 1 ? '启用' : '停用' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="引用数" :width="colw.bind('引用数', { width: 118 }).width" align="right">
            <template #default="{ row }">
              <span :class="row.referenceCount > 0 ? 'num-strong' : 'text-placeholder'">{{ row.referenceCount }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="createdAt" label="创建时间" :width="colw.bind('createdAt', { width: 165 }).width" align="center">
            <template #default="{ row }">{{ fmtDateTime(row.createdAt) }}</template>
          </el-table-column>
          <el-table-column label="操作" :width="colw.bind('操作', { width: 208 }).width" fixed="right" align="center">
            <template #default="{ row }">
                              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="primary" @click="onToggle(row)">
                {{ row.status === 1 ? '停用' : '启用' }}
              </el-button>
              <el-button link type="danger" @click="onDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>

    <el-dialog v-model="dialogVisible" :title="dialogMode === 'create' ? '新增字典项' : '编辑字典项'" width="460px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="字典类型">
          <div class="readonly-field">{{ tabs.find((t) => t.key === form.type)?.label }}</div>
        </el-form-item>
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" maxlength="30" placeholder="请输入名称" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="999" controls-position="right" />
          <div class="form-tip">数值越小越靠前。</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitForm">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
/* 页头已移除，操作按钮独占一行并收紧顶部间距 */
.dict-tip {
  margin: -6px 0 12px;
}

.text-placeholder {
  color: var(--color-text-placeholder);
}

/* —— 灯牌 logo 面板 —— */
.logo-panel {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.logo-card {
  padding: 16px;
  border: 1px solid var(--color-border-divider);
  border-radius: var(--radius-card);
  background: var(--color-bg-sub);
}

.logo-card__head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.logo-card__title {
  font-size: 14.5px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.logo-card__hint {
  font-size: 12.5px;
  color: var(--color-text-secondary);
}

.logo-card__body {
  display: flex;
  align-items: flex-start;
  gap: 18px;
  flex-wrap: wrap;
}

.logo-card__preview {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 132px;
  height: 132px;
  padding: 8px;
  border: 1px dashed var(--color-border-divider);
  border-radius: var(--radius-control);
  background: var(--color-bg-container);
  flex: 0 0 auto;
}

.logo-card__preview.is-custom {
  border-style: solid;
  border-color: var(--color-primary-soft);
}

.logo-card__preview img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.logo-card__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  text-align: center;
}

.logo-card__empty-icon {
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  background: var(--color-bg-sub);
  color: var(--color-text-secondary);
  font-size: 12px;
}

.logo-card__empty-text {
  font-size: 12px;
  line-height: 1.5;
  color: var(--color-text-placeholder);
}

.logo-card__ops {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

/* 隐藏原生 file 控件，用 el-button 作为触发器 */
.logo-upload {
  position: relative;
  display: inline-flex;
}

.logo-upload__input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.logo-card__tips {
  flex: 1 1 240px;
  min-width: 200px;
  font-size: 12px;
  line-height: 1.8;
  color: var(--color-text-secondary);
}
</style>
