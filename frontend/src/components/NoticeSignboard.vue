<script setup lang="ts">
/**
 * 公告灯牌（问题提交页，位于今日值班灯牌下方）。
 * - 展示最新一条「发布中」的公告：标题 + 内容 + 发布人/时间
 * - 小猫举着牌子左右晃动；内容较长时牌内可滚动
 * - 没有公告时本组件不渲染任何内容（由父级 v-if 控制，模板内也兜底）
 */
import { computed } from 'vue'
import type { Notice } from '@/types'
import { useSiteConfigStore } from '@/stores/siteConfig'

const props = defineProps<{
  notice: Notice | null
}>()

const siteConfig = useSiteConfigStore()

/** 自定义 logo（管理员在「字典管理 → 灯牌 logo」上传）；未配置则用内置卡通造型 */
const customLogo = computed(() => siteConfig.noticeLogo)

const hasNotice = computed(() => !!props.notice)
const title = computed(() => props.notice?.title || '')
const content = computed(() => props.notice?.content || '')
const publisher = computed(() => props.notice?.publisherName || '')
/** 发布时间展示成 YYYY-MM-DD HH:mm */
const publishedAt = computed(() => {
  const v = props.notice?.createdAt || ''
  return v ? v.slice(0, 16) : ''
})
</script>

<template>
  <aside v-if="hasNotice" class="notice-sign" aria-label="公告">
    <div class="notice-sign__sway">
      <!-- 公告牌 -->
      <div class="notice-sign__board">
        <div class="notice-sign__bulbs" aria-hidden="true">
          <span v-for="i in 12" :key="i" class="notice-sign__bulb" :style="{ animationDelay: `${(i % 6) * 0.18}s` }" />
        </div>

        <div class="notice-sign__ribbon" aria-hidden="true">
          <div class="notice-sign__ribbon-track">
            <span v-for="i in 4" :key="i">公告 NOTICE · 请留意最新通知<i class="notice-sign__dot">·</i></span>
          </div>
        </div>

        <div class="notice-sign__body">
          <div v-if="title" class="notice-sign__title">{{ title }}</div>
          <div class="notice-sign__content">{{ content }}</div>
          <div class="notice-sign__meta">
            <span v-if="publisher">{{ publisher }}</span>
            <span v-if="publisher && publishedAt" class="notice-sign__sep">·</span>
            <span v-if="publishedAt">{{ publishedAt }}</span>
          </div>
        </div>
      </div>

      <!-- 灯牌下方：管理员可上传自定义图片替换；未配置则用内置卡通小猫 -->
      <img
        v-if="customLogo"
        class="notice-sign__logo"
        :src="customLogo"
        alt="公告灯牌标志"
      />
      <div v-else class="notice-sign__cat" aria-hidden="true">
        <svg viewBox="0 0 200 172" width="100%" height="100%" role="presentation">
          <!-- 尾巴：上卷的长尾 + 环纹 -->
          <path d="M136 140 C168 148 190 128 184 98 C180 78 168 70 158 74" stroke="#f5c89a" stroke-width="13" fill="none" stroke-linecap="round" />
          <path d="M172 138 q5 3 10 1" stroke="#e0a878" stroke-width="3.5" fill="none" stroke-linecap="round" />
          <path d="M182 124 q4 2 9 0" stroke="#e0a878" stroke-width="3.5" fill="none" stroke-linecap="round" />
          <path d="M186 108 q3 2 8 0" stroke="#e0a878" stroke-width="3.5" fill="none" stroke-linecap="round" />
          <path d="M180 90 q2 3 7 3" stroke="#e0a878" stroke-width="3.5" fill="none" stroke-linecap="round" />
          <!-- 后脚（坐姿，脚掌朝前） -->
          <ellipse cx="70" cy="156" rx="18" ry="11" fill="#f5c89a" />
          <ellipse cx="130" cy="156" rx="18" ry="11" fill="#f5c89a" />
          <path d="M62 152 v7 M70 150 v9 M78 152 v7" stroke="#e0a878" stroke-width="2" stroke-linecap="round" />
          <path d="M122 152 v7 M130 150 v9 M138 152 v7" stroke="#e0a878" stroke-width="2" stroke-linecap="round" />
          <!-- 手臂：圆润上举（画在头之前 → 被头遮住肩关节） -->
          <path d="M78 120 C60 100 50 62 48 34" stroke="#f5c89a" stroke-width="15" fill="none" stroke-linecap="round" />
          <path d="M122 120 C140 100 150 62 152 34" stroke="#f5c89a" stroke-width="15" fill="none" stroke-linecap="round" />
          <!-- 尖三角耳（画在头之前 → 耳根被头盖住，更像猫耳） -->
          <path d="M72 54 L58 16 L92 44 Z" fill="#f5c89a" />
          <path d="M74 48 L66 28 L88 44 Z" fill="#f5a0c0" />
          <path d="M128 54 L142 16 L108 44 Z" fill="#f5c89a" />
          <path d="M126 48 L134 28 L112 44 Z" fill="#f5a0c0" />
          <!-- 身体 -->
          <ellipse cx="100" cy="132" rx="36" ry="26" fill="#f5c89a" />
          <ellipse cx="100" cy="138" rx="22" ry="18" fill="#fff0e0" />
          <!-- 胸前条纹 -->
          <path d="M88 128 q12 4 24 0" stroke="#e0a878" stroke-width="2.5" fill="none" stroke-linecap="round" />
          <path d="M92 138 q8 3 16 0" stroke="#e0a878" stroke-width="2.5" fill="none" stroke-linecap="round" />
          <!-- 头：圆润大头（chibi 比例） -->
          <ellipse cx="100" cy="78" rx="40" ry="38" fill="#f5c89a" />
          <!-- 额头条纹（猫斑） -->
          <path d="M94 48 v10 M100 46 v12 M106 48 v10" stroke="#e0a878" stroke-width="2.2" stroke-linecap="round" />
          <!-- 胡须（清晰的猫须） -->
          <path d="M60 88 q-12 -3 -18 -6 M60 96 q-13 0 -19 2 M61 104 q-10 3 -15 8" stroke="#d4a880" stroke-width="1.8" fill="none" stroke-linecap="round" />
          <path d="M140 88 q12 -3 18 -6 M140 96 q13 0 19 2 M139 104 q10 3 15 8" stroke="#d4a880" stroke-width="1.8" fill="none" stroke-linecap="round" />
          <!-- 口鼻区 -->
          <ellipse cx="100" cy="96" rx="16" ry="13" fill="#fff0e0" />
          <!-- 眼睛（猫瞳：琥珀色眼珠 + 竖瞳 + 高光） -->
          <ellipse cx="83" cy="72" rx="7" ry="8" fill="#3d2b1f" />
          <ellipse cx="117" cy="72" rx="7" ry="8" fill="#3d2b1f" />
          <ellipse cx="83" cy="72" rx="4.5" ry="5.5" fill="#f0c060" opacity="0.85" />
          <ellipse cx="117" cy="72" rx="4.5" ry="5.5" fill="#f0c060" opacity="0.85" />
          <ellipse cx="83" cy="72" rx="1.8" ry="5.2" fill="#3d2b1f" />
          <ellipse cx="117" cy="72" rx="1.8" ry="5.2" fill="#3d2b1f" />
          <circle cx="85.5" cy="68.5" r="2.2" fill="#fff" />
          <circle cx="119.5" cy="68.5" r="2.2" fill="#fff" />
          <!-- 腮红 -->
          <ellipse cx="64" cy="92" rx="7.5" ry="4.2" fill="#f5a0c0" opacity="0.5" />
          <ellipse cx="136" cy="92" rx="7.5" ry="4.2" fill="#f5a0c0" opacity="0.5" />
          <!-- 粉三角鼻 + w 形嘴 -->
          <path d="M100 88 l-5.5 4.5 a2 2 0 0 0 1.3 3.3 h8.4 a2 2 0 0 0 1.3 -3.3 Z" fill="#f57ba0" />
          <path d="M100 96 v4" stroke="#3d2b1f" stroke-width="2" stroke-linecap="round" />
          <path d="M100 100 q-7.5 7.5 -13 0.5" stroke="#3d2b1f" stroke-width="2.2" fill="none" stroke-linecap="round" />
          <path d="M100 100 q7.5 7.5 13 0.5" stroke="#3d2b1f" stroke-width="2.2" fill="none" stroke-linecap="round" />
          <!-- 爪掌（跨住灯牌下沿）+ 粉色肉垫 -->
          <ellipse cx="48" cy="28" rx="12.5" ry="11.5" fill="#fff0e0" />
          <ellipse cx="48" cy="32" rx="5.5" ry="4.2" fill="#f5a0c0" opacity="0.8" />
          <circle cx="41.5" cy="24" r="2.1" fill="#f5a0c0" opacity="0.75" />
          <circle cx="48" cy="21.5" r="2.1" fill="#f5a0c0" opacity="0.75" />
          <circle cx="54.5" cy="24" r="2.1" fill="#f5a0c0" opacity="0.75" />
          <ellipse cx="152" cy="28" rx="12.5" ry="11.5" fill="#fff0e0" />
          <ellipse cx="152" cy="32" rx="5.5" ry="4.2" fill="#f5a0c0" opacity="0.8" />
          <circle cx="145.5" cy="24" r="2.1" fill="#f5a0c0" opacity="0.75" />
          <circle cx="152" cy="21.5" r="2.1" fill="#f5a0c0" opacity="0.75" />
          <circle cx="158.5" cy="24" r="2.1" fill="#f5a0c0" opacity="0.75" />
        </svg>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.notice-sign {
  display: flex;
  justify-content: center;
  padding-top: var(--space-2);
}

.notice-sign__sway {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  max-width: 330px;
  /* 与值班灯牌的晃动节奏错开，画面不呆板 */
  animation: notice-sway 4.2s ease-in-out infinite;
  transform-origin: 50% 100%;
}

/* 公告牌（暖粉色调，与值班灯牌的深蓝形成区分） */
.notice-sign__board {
  position: relative;
  z-index: 1;
  width: 100%;
  padding: 14px 14px 20px;
  border-radius: var(--radius-large);
  background: linear-gradient(162deg, #fff4f8 0%, #ffe6ef 100%);
  border: 3px solid var(--color-accent-pink);
  box-shadow: 0 10px 26px rgba(196, 120, 152, 0.22), 0 0 0 6px rgba(242, 160, 192, 0.14);
}

.notice-sign__bulbs {
  position: absolute;
  inset: 5px 8px auto 8px;
  display: flex;
  justify-content: space-between;
}

.notice-sign__bulb {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-primary);
  box-shadow: 0 0 7px rgba(124, 140, 228, 0.9);
  animation: notice-bulb 1.6s ease-in-out infinite;
}

/* 顶部公告跑马灯缎带 */
.notice-sign__ribbon {
  overflow: hidden;
  margin: 12px 0 10px;
  padding: 5px 0;
  border-radius: var(--radius-pill);
  background: var(--color-accent-pink);
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.5);
}

.notice-sign__ribbon-track {
  display: inline-flex;
  white-space: nowrap;
  will-change: transform;
  animation: notice-marquee 16s linear infinite;
}

.notice-sign__ribbon-track span {
  padding-right: 6px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 1px;
  color: #fff;
}

.notice-sign__dot {
  margin: 0 8px;
  font-style: normal;
  opacity: 0.8;
}

/* 公告正文 */
.notice-sign__body {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 190px;
  overflow-y: auto;
  padding-right: 2px;
}

.notice-sign__title {
  font-size: 16px;
  font-weight: 700;
  color: #8a3f5e;
  line-height: 1.4;
}

.notice-sign__content {
  font-size: 13.5px;
  line-height: 1.75;
  color: #5c4450;
  white-space: pre-wrap;
  word-break: break-word;
}

.notice-sign__meta {
  margin-top: 2px;
  font-size: 11.5px;
  color: rgba(92, 68, 80, 0.6);
  font-variant-numeric: tabular-nums;
}

.notice-sign__sep {
  margin: 0 5px;
}

/* —— 自定义灯牌 logo（管理员上传的图片）：与内置小猫同宽同位 */
.notice-sign__logo {
  position: relative;
  z-index: 2;
  display: block;
  width: 200px;
  max-height: 200px;
  margin-top: -26px;
  object-fit: contain;
  filter: drop-shadow(0 6px 14px rgba(196, 120, 152, 0.24));
}

/* —— 举牌小猫 —— */
.notice-sign__cat {
  position: relative;
  z-index: 2;
  width: 200px;
  margin-top: -26px;
}

/* 晃动：与值班灯牌同源（旋转支点在底部中心），幅度同样收紧到
 * 不越出右侧 20px 内边距，避免鼠标滚动时出现横向滚动条。 */
@keyframes notice-sway {
  0%,
  100% {
    transform: translateX(0) rotate(1.7deg);
  }
  50% {
    transform: translateX(-4px) rotate(-1.7deg);
  }
}

@keyframes notice-marquee {
  from {
    transform: translateX(0);
  }
  to {
    transform: translateX(-50%);
  }
}

@keyframes notice-bulb {
  0%,
  100% {
    opacity: 0.4;
  }
  50% {
    opacity: 1;
  }
}

@media (max-width: 1180px) {
  .notice-sign {
    padding-top: 0;
  }
  .notice-sign__sway {
    max-width: 300px;
  }
}

/* 灯牌动画强制开启：即使系统开启「减弱动态效果」也保持运行（同值班灯牌） */
@media (prefers-reduced-motion: reduce) {
  .notice-sign__sway {
    animation-name: notice-sway !important;
    animation-duration: 4.2s !important;
    animation-timing-function: ease-in-out !important;
    animation-iteration-count: infinite !important;
    transform-origin: 50% 100%;
  }

  .notice-sign__bulb {
    animation-name: notice-bulb !important;
    animation-duration: 1.6s !important;
    animation-timing-function: ease-in-out !important;
    animation-iteration-count: infinite !important;
  }

  .notice-sign__ribbon-track {
    animation-name: notice-marquee !important;
    animation-duration: 16s !important;
    animation-timing-function: linear !important;
    animation-iteration-count: infinite !important;
  }
}
</style>
