<script setup lang="ts">
/**
 * 消息中心：当前账号收到的全部站内消息（分页）。
 * 点消息标记已读并跳到对应位置；支持一键全部已读。
 * 说明：Windows 系统通知由浏览器弹出，需页面打开着（可最小化）才能收到；
 * 关掉浏览器后消息仍会保存在这里，下次打开可查看。
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { fetchNotifyList, readNotify } from '@/api/notify'
import { fmtDateTime } from '@/utils/format'
import type { NotificationItem } from '@/types'
import EmptyIllustration from '@/components/illustrations/EmptyIllustration.vue'
import MoonLoader from '@/components/illustrations/MoonLoader.vue'

const router = useRouter()

const loading = ref(true)
const items = ref<NotificationItem[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(15)

async function load(): Promise<void> {
  loading.value = true
  try {
    const res = await fetchNotifyList({ page: page.value, pageSize: pageSize.value })
    items.value = res.items
    total.value = res.total
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

async function openItem(item: NotificationItem): Promise<void> {
  if (!item.isRead) {
    try {
      await readNotify({ id: item.id })
      item.isRead = true
    } catch {
      // 标记失败不影响跳转
    }
  }
  if (item.link) {
    await router.push(item.link)
  }
}

async function readAll(): Promise<void> {
  if (!items.value.some((x) => !x.isRead)) {
    ElMessage.info('没有未读消息')
    return
  }
  await ElMessageBox.confirm('确定将全部消息标记为已读吗？', '全部已读', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'info',
  })
  await readNotify({ all: true })
  ElMessage.success('已全部标记为已读')
  await load()
}

function onPageChange(p: number): void {
  page.value = p
  void load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page__actions page__actions--toolbar">
      <el-button @click="readAll">全部标记已读</el-button>
      <el-button @click="load">刷新</el-button>
    </div>

    <div class="moon-card moon-card--moonlit">
      <div class="section-title">
        消息中心
        <span class="section-title__hint">共 {{ total }} 条</span>
      </div>

      <MoonLoader v-if="loading" text="正在加载消息…" />
      <EmptyIllustration v-else-if="!items.length" :size="120" text="暂无消息" sub-text="提交问题、处理结果与公告都会在这里留痕" />

      <ul v-else class="notify-list">
        <li
          v-for="n in items"
          :key="n.id"
          class="notify-item"
          :class="{ 'is-unread': !n.isRead, 'is-clickable': !!n.link }"
          @click="openItem(n)"
        >
          <span class="notify-item__dot" aria-hidden="true" />
          <div class="notify-item__main">
            <div class="notify-item__head">
              <span class="notify-item__title">{{ n.title }}</span>
              <span class="notify-item__time">{{ fmtDateTime(n.createdAt) }}</span>
            </div>
            <div class="notify-item__body">{{ n.content }}</div>
          </div>
          <span v-if="n.link" class="notify-item__go">查看</span>
        </li>
      </ul>

      <div v-if="total > pageSize" class="notify-pager">
        <el-pagination
          layout="prev, pager, next"
          :total="total"
          :page-size="pageSize"
          :current-page="page"
          background
          @current-change="onPageChange"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.page__actions {
  display: flex;
  gap: 8px;
}

.notify-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.notify-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: var(--radius-control);
  background: var(--color-bg-sub);
  transition: background var(--transition-base);
}

.notify-item.is-clickable {
  cursor: pointer;
}

.notify-item.is-clickable:hover {
  background: var(--color-primary-tint);
}

.notify-item__dot {
  width: 8px;
  height: 8px;
  margin-top: 6px;
  border-radius: 50%;
  background: transparent;
  flex: 0 0 auto;
}

.notify-item.is-unread .notify-item__dot {
  background: var(--color-primary);
}

.notify-item__main {
  flex: 1;
  min-width: 0;
}

.notify-item__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.notify-item__title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.notify-item.is-unread .notify-item__title {
  color: var(--color-primary);
}

.notify-item__time {
  font-size: 12px;
  color: var(--color-text-secondary);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.notify-item__body {
  margin-top: 4px;
  font-size: 13px;
  line-height: 1.6;
  color: var(--color-text-regular);
  word-break: break-word;
}

.notify-item__go {
  align-self: center;
  font-size: 12.5px;
  color: var(--color-primary);
  flex: 0 0 auto;
}

.notify-pager {
  display: flex;
  justify-content: flex-end;
  padding-top: 14px;
}
</style>
