<script setup lang="ts">
/**
 * 列配置面板（各列表页复用）。
 *
 * 能力：
 * - 勾选决定每个字段是否展示（按当前登录用户各自保存）；
 * - 上移/下移调整列的先后顺序；
 * - 一键恢复默认（全部展示 + 默认顺序）。
 *
 * 交互说明：面板里对所有用户统一的列表生效，不改变数据权限——
 * 隐藏的列只是不显示，不影响筛选、排序与数据范围。
 */
import { computed, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { useColumnConfig } from '@/composables/useColumnConfig'

const props = defineProps<{
  /** 页面用 useColumnConfig(...) 创建的列配置实例 */
  config: ReturnType<typeof useColumnConfig>
  /** 表格名称（用于文案） */
  title: string
}>()

const visible = ref(false)

/** 面板内的即时草稿：改动立即写回（保持所见即所得），关闭即生效 */
const rows = computed(() => props.config.ordered())

const hiddenCount = computed(() => rows.value.filter((r) => !r.visible).length)

function open(): void {
  visible.value = true
}

function onToggle(key: string): void {
  props.config.toggle(key)
}

function onMove(key: string, dir: -1 | 1): void {
  props.config.move(key, dir)
}

async function onReset(): Promise<void> {
  await ElMessageBox.confirm('确定恢复默认列配置吗？将展示全部字段并恢复默认顺序。', '恢复默认', {
    confirmButtonText: '确定恢复',
    cancelButtonText: '取消',
    type: 'warning',
  })
  props.config.reset()
  ElMessage.success('已恢复默认列配置')
}

defineExpose({ open })
</script>

<template>
  <div class="colcfg">
    <el-button class="colcfg__trigger" @click="open">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M4 5h16v2.2H4V5Zm0 5.9h16v2.2H4v-2.2ZM4 16.8h16V19H4v-2.2Z" />
      </svg>
      <span>列配置{{ hiddenCount ? `（已隐藏 ${hiddenCount} 列）` : '' }}</span>
    </el-button>

    <el-dialog v-model="visible" :title="`${title} · 列配置`" width="440px" append-to-body>
      <div class="colcfg__tip">勾选需要展示的字段，用箭头调整顺序。配置按你的账号保存，不影响其他人。</div>

      <ul class="colcfg__list">
        <li v-for="(row, idx) in rows" :key="row.key" class="colcfg__item">
          <el-checkbox :model-value="row.visible" @change="onToggle(row.key)">
            <span class="colcfg__label">{{ row.label }}</span>
          </el-checkbox>

          <div class="colcfg__ops">
            <button
              class="colcfg__btn"
              type="button"
              title="上移"
              :disabled="idx === 0"
              @click="onMove(row.key, -1)"
            >↑</button>
            <button
              class="colcfg__btn"
              type="button"
              title="下移"
              :disabled="idx === rows.length - 1"
              @click="onMove(row.key, 1)"
            >↓</button>
          </div>
        </li>
      </ul>

      <div v-if="!rows.length" class="colcfg__empty">该表格暂无可配置的列</div>

      <template #footer>
        <el-button @click="onReset">恢复默认</el-button>
        <el-button type="primary" @click="visible = false">完成</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.colcfg {
  display: inline-flex;
}

.colcfg__trigger {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.colcfg__tip {
  margin-bottom: 10px;
  font-size: 12.5px;
  line-height: 1.6;
  color: var(--color-text-secondary);
}

.colcfg__list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 360px;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.colcfg__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 6px 8px;
  border-radius: var(--radius-control);
  transition: background var(--transition-base);
}

.colcfg__item:hover {
  background: var(--color-bg-sub);
}

.colcfg__label {
  font-size: 13.5px;
  color: var(--color-text-primary);
}

.colcfg__ops {
  display: flex;
  gap: 4px;
  flex: 0 0 auto;
}

.colcfg__btn {
  width: 26px;
  height: 26px;
  border: 1px solid var(--color-border-divider);
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  color: var(--color-text-regular);
  font-size: 13px;
  line-height: 1;
  cursor: pointer;
  transition: border-color var(--transition-base), color var(--transition-base);
}

.colcfg__btn:hover:not(:disabled) {
  border-color: var(--color-primary-soft);
  color: var(--color-primary);
}

.colcfg__btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.colcfg__empty {
  padding: 18px 0;
  text-align: center;
  font-size: 12.5px;
  color: var(--color-text-secondary);
}
</style>
