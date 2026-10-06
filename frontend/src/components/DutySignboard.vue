<script setup lang="ts">
/**
 * 今日值班灯牌（问题提交页右侧）。
 * - 展示今日值班的售后人员：头像、姓名、联系电话
 * - 灯牌顶部为跑马灯灯带（循环滚动）
 * - 小狗举着灯牌左右晃动（prefers-reduced-motion 时静止）
 * 未排班时给出「暂未排班」提示，不隐藏组件（保持版面稳定）。
 */
import { computed } from 'vue'
import type { DutyToday } from '@/types'
import { useSiteConfigStore } from '@/stores/siteConfig'

const props = defineProps<{
  duty: DutyToday | null
}>()

const siteConfig = useSiteConfigStore()

/** 自定义 logo（管理员在「字典管理 → 灯牌 logo」上传）；未配置则用内置卡通造型 */
const customLogo = computed(() => siteConfig.dutyLogo)

const onDuty = computed(() => props.duty?.onDuty === true)
const name = computed(() => props.duty?.userName || '暂未排班')
const phone = computed(() => props.duty?.phone || '—')
const avatar = computed(() => props.duty?.avatar || '')
const initial = computed(() => (name.value || '?').trim().slice(0, 1) || '?')

/** 跑马灯文案：一段只出现一次（模板里拼接两份实现无缝滚动，
 * 不再在一屏内重复同一句话）*/
const marqueeText = '今日值班 · 售后在线 · 有问题找值班人'
</script>

<template>
  <aside class="duty-sign" aria-label="今日值班售后人员">
    <div class="duty-sign__sway">
      <!-- 灯牌 -->
      <div class="duty-sign__board" :class="{ 'is-off': !onDuty }">
        <div class="duty-sign__bulbs" aria-hidden="true">
          <span v-for="i in 14" :key="i" class="duty-sign__bulb" :style="{ animationDelay: `${(i % 7) * 0.16}s` }" />
        </div>

        <div class="duty-sign__marquee" aria-hidden="true">
          <div class="duty-sign__marquee-track">
            <span v-for="i in 4" :key="i">{{ marqueeText }}<i class="duty-sign__dot">·</i></span>
          </div>
        </div>

        <div class="duty-sign__body">
          <div class="duty-sign__avatar-wrap">
            <el-avatar :size="78" :src="avatar || undefined" class="duty-sign__avatar">
              {{ initial }}
            </el-avatar>
          </div>
          <div class="duty-sign__name">{{ name }}</div>
          <div v-if="onDuty" class="duty-sign__phone">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M6.6 3h2.6l1.6 4-2 1.3a12 12 0 0 0 5.9 5.9l1.3-2 4 1.6v2.6a2 2 0 0 1-2.2 2A16 16 0 0 1 4.6 5.2 2 2 0 0 1 6.6 3Z" />
            </svg>
            <span>{{ phone }}</span>
          </div>
          <div v-else class="duty-sign__hint">今天还没有安排值班人</div>
        </div>
      </div>

      <!-- 灯牌下方：管理员可上传自定义图片替换；未配置则用内置卡通小狗 -->
      <img
        v-if="customLogo"
        class="duty-sign__logo"
        :src="customLogo"
        alt="值班灯牌标志"
      />
      <div v-else class="duty-sign__dog" aria-hidden="true">
        <svg viewBox="0 0 200 172" width="100%" height="100%" role="presentation">
          <!-- 尾巴：上翘的开心尾 + 奶油色尾尖 -->
          <path d="M138 138 C168 142 186 122 182 96" stroke="#e8b87a" stroke-width="14" fill="none" stroke-linecap="round" />
          <path d="M180 104 C181 99 182 95 182 91" stroke="#fff0d6" stroke-width="11" fill="none" stroke-linecap="round" />
          <!-- 后脚（坐姿，脚掌朝前） -->
          <ellipse cx="70" cy="156" rx="18" ry="11" fill="#e8b87a" />
          <ellipse cx="130" cy="156" rx="18" ry="11" fill="#e8b87a" />
          <path d="M62 152 v7 M70 150 v9 M78 152 v7" stroke="#d4a06a" stroke-width="2" stroke-linecap="round" />
          <path d="M122 152 v7 M130 150 v9 M138 152 v7" stroke="#d4a06a" stroke-width="2" stroke-linecap="round" />
          <!-- 手臂：圆润上举（画在头之前 → 被头遮住肩关节） -->
          <path d="M78 120 C60 100 50 62 48 34" stroke="#e8b87a" stroke-width="15" fill="none" stroke-linecap="round" />
          <path d="M122 120 C140 100 150 62 152 34" stroke="#e8b87a" stroke-width="15" fill="none" stroke-linecap="round" />
          <!-- 垂耳：从头顶两侧垂下，盖住肩线（画在头之前） -->
          <path d="M68 52 C48 48 32 64 32 88 C32 108 44 120 58 116 C50 94 54 68 68 52 Z" fill="#d4a06a" />
          <path d="M62 62 C50 60 42 72 42 88 C42 100 50 108 58 104 C52 88 56 70 62 62 Z" fill="#c48a5a" opacity="0.45" />
          <path d="M132 52 C152 48 168 64 168 88 C168 108 156 120 142 116 C150 94 146 68 132 52 Z" fill="#d4a06a" />
          <path d="M138 62 C150 60 158 72 158 88 C158 100 150 108 142 104 C148 88 144 70 138 62 Z" fill="#c48a5a" opacity="0.45" />
          <!-- 身体 -->
          <ellipse cx="100" cy="132" rx="36" ry="26" fill="#e8b87a" />
          <ellipse cx="100" cy="138" rx="22" ry="18" fill="#fff0d6" />
          <!-- 头：圆润大头（chibi 比例） -->
          <ellipse cx="100" cy="78" rx="40" ry="38" fill="#e8b87a" />
          <!-- 眉毛 -->
          <path d="M72 56 q10 -5 18 -1" stroke="#c48a5a" stroke-width="2.4" fill="none" stroke-linecap="round" />
          <path d="M110 55 q10 -4 18 1" stroke="#c48a5a" stroke-width="2.4" fill="none" stroke-linecap="round" />
          <!-- 眼睛（圆亮，双高光） -->
          <ellipse cx="83" cy="72" rx="6.5" ry="7.5" fill="#3d2b1f" />
          <ellipse cx="117" cy="72" rx="6.5" ry="7.5" fill="#3d2b1f" />
          <circle cx="85.5" cy="69.2" r="2.4" fill="#fff" />
          <circle cx="119.5" cy="69.2" r="2.4" fill="#fff" />
          <circle cx="81" cy="75" r="1.1" fill="#fff" opacity="0.75" />
          <circle cx="115" cy="75" r="1.1" fill="#fff" opacity="0.75" />
          <!-- 腮红 -->
          <ellipse cx="64" cy="92" rx="7.5" ry="4.2" fill="#f0a090" opacity="0.5" />
          <ellipse cx="136" cy="92" rx="7.5" ry="4.2" fill="#f0a090" opacity="0.5" />
          <!-- 口鼻区（突出的狗吻） -->
          <ellipse cx="100" cy="96" rx="18" ry="14" fill="#fff0d6" />
          <!-- 鼻子（圆黑鼻） -->
          <ellipse cx="100" cy="90" rx="6.5" ry="5" fill="#3d2b1f" />
          <ellipse cx="98.2" cy="88.5" rx="2" ry="1.2" fill="#fff" opacity="0.35" />
          <!-- w 形笑嘴 -->
          <path d="M100 95 v4.5" stroke="#3d2b1f" stroke-width="2.1" stroke-linecap="round" />
          <path d="M100 99.5 q-7.5 7 -13 0.5" stroke="#3d2b1f" stroke-width="2.3" fill="none" stroke-linecap="round" />
          <path d="M100 99.5 q7.5 7 13 0.5" stroke="#3d2b1f" stroke-width="2.3" fill="none" stroke-linecap="round" />
          <!-- 爪掌（跨住灯牌下沿）+ 爪趾 -->
          <ellipse cx="48" cy="28" rx="12.5" ry="11.5" fill="#fff0d6" />
          <path d="M42 19 v7 M48 17 v8.5 M54 19 v7" stroke="#e0c090" stroke-width="2" stroke-linecap="round" />
          <ellipse cx="152" cy="28" rx="12.5" ry="11.5" fill="#fff0d6" />
          <path d="M146 19 v7 M152 17 v8.5 M158 19 v7" stroke="#e0c090" stroke-width="2" stroke-linecap="round" />
        </svg>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.duty-sign {
  display: flex;
  justify-content: center;
  padding-top: var(--space-2);
}

/* 小狗 + 灯牌整体晃动 */
.duty-sign__sway {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  max-width: 330px;
  animation: duty-sway 3.6s ease-in-out infinite;
  transform-origin: 50% 100%;
}

/* —— 灯牌本体 —— */
.duty-sign__board {
  position: relative;
  z-index: 1;
  width: 100%;
  padding: 14px 14px 20px;
  border-radius: var(--radius-large);
  background: linear-gradient(162deg, var(--color-night) 0%, var(--color-night-deep) 100%);
  border: 3px solid var(--color-primary-soft);
  box-shadow: 0 10px 28px rgba(43, 51, 87, 0.28), 0 0 0 6px rgba(124, 140, 228, 0.12);
}

.duty-sign__board.is-off {
  border-color: rgba(124, 140, 228, 0.4);
  box-shadow: 0 8px 20px rgba(43, 51, 87, 0.2);
}

/* 灯牌边缘的灯泡串 */
.duty-sign__bulbs {
  position: absolute;
  inset: 5px 8px auto 8px;
  display: flex;
  justify-content: space-between;
}

.duty-sign__bulb {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--color-accent-pink);
  box-shadow: 0 0 7px rgba(242, 160, 192, 0.95);
  animation: duty-bulb 1.4s ease-in-out infinite;
}

.duty-sign__board.is-off .duty-sign__bulb {
  animation: none;
  background: rgba(255, 255, 255, 0.28);
  box-shadow: none;
}

/* 跑马灯灯带 */
.duty-sign__marquee {
  overflow: hidden;
  margin: 12px 0 14px;
  padding: 6px 0;
  border-radius: var(--radius-pill);
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(242, 160, 192, 0.35);
}

.duty-sign__marquee-track {
  display: inline-flex;
  white-space: nowrap;
  will-change: transform;
  animation: duty-marquee 14s linear infinite;
}

.duty-sign__marquee-track span {
  padding-right: 8px;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 1px;
  color: var(--color-accent-pink);
  text-shadow: 0 0 8px rgba(242, 160, 192, 0.75);
}

/* 相邻文案之间的分隔符 */
.duty-sign__dot {
  margin: 0 8px;
  font-style: normal;
  opacity: 0.75;
}

.duty-sign__board.is-off .duty-sign__marquee-track {
  animation: none;
}

.duty-sign__board.is-off .duty-sign__marquee-track span {
  color: var(--color-text-on-night-sub);
  text-shadow: none;
}

/* 人员信息 */
.duty-sign__body {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.duty-sign__avatar-wrap {
  padding: 4px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.14);
  box-shadow: 0 0 18px rgba(124, 140, 228, 0.55);
}

.duty-sign__avatar {
  background: var(--color-primary);
  color: #fff;
  font-size: 30px;
  font-weight: 700;
}

.duty-sign__name {
  margin-top: 2px;
  font-size: 24px;
  font-weight: 700;
  letter-spacing: 1px;
  color: var(--color-text-on-night);
  text-shadow: 0 0 12px rgba(234, 242, 255, 0.45);
}

.duty-sign__phone {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 14px;
  border-radius: var(--radius-pill);
  background: rgba(255, 255, 255, 0.12);
  color: var(--color-text-on-night);
  font-size: 15px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.duty-sign__hint {
  font-size: 12.5px;
  color: var(--color-text-on-night-sub);
}

/* —— 自定义灯牌 logo（管理员上传的图片）：
 * 与内置小狗同宽同位，保持“举着灯牌”的视觉位置；高度按内容自适应。 */
.duty-sign__logo {
  position: relative;
  z-index: 2;
  display: block;
  width: 200px;
  max-height: 200px;
  margin-top: -23px;
  object-fit: contain;
  filter: drop-shadow(0 6px 14px rgba(43, 51, 87, 0.28));
}

/* —— 小狗：在灯牌下方双手上举。
 * 关键：小狗必须在灯牌之上（z-index 更高），否则爪掌会被牌面盖住、看不出“举/抓住”动作；
 * 上移量按实测调：让爪掌跨在灯牌下沿（一半压在牌面、一半露在下边）＝ 抓住牌边的即视感。 */
.duty-sign__dog {
  position: relative;
  z-index: 2;
  width: 200px;
  margin-top: -23px;
}

/* 晃动：轻微旋转 + 上下浮动，幅度克制。
 * 幅度上限推导：旋转支点在底部中心，摆动时顶部角落会向外扫出 ≈ 牌高 × sin(角度)。
 * 右侧只有内容区 20px 内边距作为余量，故 411×sin(1.8°) + 4 ≈ 17.5px < 20px，
 * 才能保证摆动全程不越出内容区、不出现横向滚动条（此前 2.4°/6px 会超出约 4px）。 */
@keyframes duty-sway {
  0%,
  100% {
    transform: translateX(0) rotate(-1.8deg);
  }
  50% {
    transform: translateX(4px) rotate(1.8deg);
  }
}

/* 跑马灯：位移一半（内容重复两遍）即无缝 */
@keyframes duty-marquee {
  from {
    transform: translateX(0);
  }
  to {
    transform: translateX(-50%);
  }
}

/* 灯泡呼吸 */
@keyframes duty-bulb {
  0%,
  100% {
    opacity: 0.45;
  }
  50% {
    opacity: 1;
  }
}

/* 窄屏：与表单同列，居中显示 */
@media (max-width: 1180px) {
  .duty-sign {
    padding-top: 0;
  }
  .duty-sign__sway {
    max-width: 300px;
  }
}

/* 灯牌动画强制开启：即使系统/浏览器开启「减弱动态效果」也保持运行。
 * 灯牌是本页的核心展示（提示今日值班），产品要求所有人看到一致的动效；
 * 用更高特异性 + !important 覆盖全局 animation.css 里的 * 通配规则。
 * 其余页面过渡动效仍遵循系统设置。 */
@media (prefers-reduced-motion: reduce) {
  .duty-sign__sway {
    animation-name: duty-sway !important;
    animation-duration: 3.6s !important;
    animation-timing-function: ease-in-out !important;
    animation-iteration-count: infinite !important;
    transform-origin: 50% 100%;
  }

  .duty-sign__bulb {
    animation-name: duty-bulb !important;
    animation-duration: 1.4s !important;
    animation-timing-function: ease-in-out !important;
    animation-iteration-count: infinite !important;
  }

  .duty-sign__marquee-track {
    animation-name: duty-marquee !important;
    animation-duration: 14s !important;
    animation-timing-function: linear !important;
    animation-iteration-count: infinite !important;
  }
}
</style>
