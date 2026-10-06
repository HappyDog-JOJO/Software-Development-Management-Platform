<script setup lang="ts">
/**
 * 登录/注册页左侧夜色品牌场景插画。
 * 氛围语言取自参考图：深夜蓝色床铺、柔和月光、蓬松长毛猫（蓝眼睛）、
 * 半身埋在被子里打哈欠的小角色（樱粉挑染 + 白色小翅膀 + 十字发夹）。
 * 全部内联 SVG；柔光只用径向渐变（且在图内收敛为零），不使用会留下硬边的矩形填充。
 * 不使用 emoji 或文字符号。
 * 绘制顺序：枕头与柔光 → 月亮星芒 → 猫 → 角色 → 被子 → 手/爪 → 床沿。
 */
withDefaults(defineProps<{ width?: number }>(), { width: 400 })
</script>

<template>
  <svg
    class="brand-scene"
    :width="width"
    viewBox="0 0 340 250"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    role="img"
    aria-label="夜色场景插画：月光下的床铺，打哈欠的小角色与蓬松长毛猫"
  >
    <defs>
      <!-- 枕头后的柔光（径向，图内收敛，不留边界） -->
      <radialGradient id="pillowGlow">
        <stop offset="0%" stop-color="#C9D2F7" stop-opacity="0.26" />
        <stop offset="55%" stop-color="#C9D2F7" stop-opacity="0.1" />
        <stop offset="100%" stop-color="#C9D2F7" stop-opacity="0" />
      </radialGradient>
      <!-- 月亮光晕 -->
      <radialGradient id="moonGlow">
        <stop offset="0%" stop-color="#EAF2FF" stop-opacity="0.26" />
        <stop offset="60%" stop-color="#EAF2FF" stop-opacity="0.08" />
        <stop offset="100%" stop-color="#EAF2FF" stop-opacity="0" />
      </radialGradient>
      <!-- 被子：上亮下暗 -->
      <linearGradient id="quilt" gradientUnits="userSpaceOnUse" x1="170" y1="158" x2="170" y2="252">
        <stop offset="0%" stop-color="#AAB6DB" />
        <stop offset="55%" stop-color="#8B98C4" />
        <stop offset="100%" stop-color="#6F7B99" />
      </linearGradient>
      <!-- 床沿地面柔光 -->
      <radialGradient id="floorGlow">
        <stop offset="0%" stop-color="#EAF2FF" stop-opacity="0.13" />
        <stop offset="100%" stop-color="#EAF2FF" stop-opacity="0" />
      </radialGradient>
      <!-- 边缘渐隐：四周柔化，底部多留一点，让插画淡入淡出背景 -->
      <radialGradient id="edgeFade" cx="0.5" cy="0.46" r="0.66">
        <stop offset="0%" stop-color="#FFFFFF" stop-opacity="1" />
        <stop offset="52%" stop-color="#FFFFFF" stop-opacity="1" />
        <stop offset="76%" stop-color="#FFFFFF" stop-opacity="0.75" />
        <stop offset="90%" stop-color="#FFFFFF" stop-opacity="0.3" />
        <stop offset="100%" stop-color="#FFFFFF" stop-opacity="0" />
      </radialGradient>
      <mask id="edgeMask">
        <rect x="0" y="0" width="340" height="250" fill="url(#edgeFade)" />
      </mask>
    </defs>

    <g mask="url(#edgeMask)">

    <!-- ============ 枕头柔光与星芒（透明度 ≤ 15%） ============ -->
    <ellipse cx="126" cy="116" rx="120" ry="98" fill="url(#pillowGlow)" />

    <path d="M44 26c1.1 4.4 2.3 5.6 6.7 6.7-4.4 1.1-5.6 2.3-6.7 6.7-1.1-4.4-2.3-5.6-6.7-6.7 4.4-1.1 5.6-2.3 6.7-6.7Z" fill="#EAF2FF" opacity="0.5" />
    <path d="M172 18c0.9 3.6 1.9 4.6 5.5 5.5-3.6 0.9-4.6 1.9-5.5 5.5-0.9-3.6-1.9-4.6-5.5-5.5 3.6-0.9 4.6-1.9 5.5-5.5Z" fill="#EAF2FF" opacity="0.42" />
    <path d="M60 104c0.8 3 1.5 3.8 4.5 4.5-3 0.8-3.7 1.5-4.5 4.5-0.8-3-1.5-3.7-4.5-4.5 3-0.8 3.7-1.5 4.5-4.5Z" fill="#F2A0C0" opacity="0.42" />
    <circle cx="112" cy="22" r="1.5" fill="#EAF2FF" opacity="0.42" />
    <circle cx="204" cy="58" r="1.2" fill="#EAF2FF" opacity="0.35" />

    <!-- ============ 月亮（右上角） ============ -->
    <circle cx="298" cy="40" r="36" fill="url(#moonGlow)" />
    <path d="M301 21a19 19 0 1 0 12 32 22 22 0 0 1-12-32Z" fill="#EAF2FF" opacity="0.9" />

    <!-- ============ 蓬松长毛猫（蓝眼睛） ============ -->
    <g>
      <!-- 尾巴：卷曲、粗软（末端略带毛束） -->
      <path d="M262 158c23-3 32-19 23-32" stroke="#EFE6DA" stroke-width="13" stroke-linecap="round" />
      <path d="M281 128c4-2 5-6 3-9" stroke="#E6DCCD" stroke-width="4" stroke-linecap="round" />
      <!-- 身体：外轮廓为柔和毛簇（长毛质感） -->
      <path
        d="M225 136c6-2 10-6 12-12 3 5 8 8 14 8-2 5-1 10 3 14-4 3-5 8-3 13-5 1-9 5-10 10-4-3-9-3-13 0-2-5-6-8-11-9 3-4 3-9 0-13 5-2 8-6 8-11Z"
        fill="#EFE6DA"
      />
      <path
        d="M186 148c4-3 6-8 5-13 4 3 10 3 14 0-1 5 2 10 7 12-3 4-3 9 0 13-5 1-9 5-9 10-4-3-9-3-13 0 0-5-4-9-9-11 4-3 6-8 5-11Z"
        fill="#EFE6DA"
      />
      <ellipse cx="225" cy="170" rx="46" ry="33" fill="#EFE6DA" />
      <!-- 身体外轮廓毛簇（长毛感：沿躯干外侧的不规则短弧） -->
      <path
        d="M181 156c-4 1-6 4-5 7 2 0 4-1 5-3-1 3-1 6 1 8 2-2 3-4 3-7-1 3 0 6 2 8 1-2 2-5 1-8 0 3 2 6 4 7 1-2 1-5-1-7"
        stroke="#E6DCCD" stroke-width="3.4" stroke-linecap="round" fill="none"
      />
      <path
        d="M269 156c4 1 6 4 5 7-2 0-4-1-5-3 1 3 1 6-1 8-2-2-3-4-3-7 1 3 0 6-2 8-1-2-2-5-1-8 0 3-2 6-4 7-1-2-1-5 1-7"
        stroke="#E6DCCD" stroke-width="3.4" stroke-linecap="round" fill="none"
      />
      <!-- 胸口长毛 -->
      <path
        d="M202 172c-4 6-4 12 0 17M248 172c4 6 4 12 0 17M214 180c-3 5-3 10 0 14M236 180c3 5 3 10 0 14"
        stroke="#F9F4EB"
        stroke-width="3.2"
        stroke-linecap="round"
      />
      <!-- 尖耳朵（左右对称，带耳内转折） -->
      <path d="M199 104 195 70l22 28-18 6Z" fill="#8E8A99" />
      <path d="M251 104 255 70l-22 28 18 6Z" fill="#8E8A99" />
      <path d="M205 101 203 79l14 18-12 4Z" fill="#CDC6D2" opacity="0.75" />
      <path d="M245 101 247 79l-14 18 12 4Z" fill="#CDC6D2" opacity="0.75" />
      <!-- 头 -->
      <ellipse cx="225" cy="120" rx="33" ry="31" fill="#C9C2CE" />
      <!-- 头侧鬃毛（柔和圆弧，体现长毛） -->
      <path
        d="M196 114c-4 1-7 3-8 6 2 1 4 1 6 0-2 3-3 6-2 9 2-1 4-3 5-5-1 3-1 6 1 8 2-2 3-5 3-8"
        stroke="#C9C2CE"
        stroke-width="7"
        stroke-linecap="round"
        fill="none"
      />
      <path
        d="M254 114c4 1 7 3 8 6-2 1-4 1-6 0 2 3 3 6 2 9-2-1-4-3-5-5 1 3 1 6-1 8-2-2-3-5-3-8"
        stroke="#C9C2CE"
        stroke-width="7"
        stroke-linecap="round"
        fill="none"
      />
      <path d="M195 104c8-7 19-11 30-11s22 4 30 11c-9-5-19-8-30-8s-21 3-30 8Z" fill="#8E8A99" opacity="0.35" />
      <!-- 口鼻与嘴 -->
      <ellipse cx="225" cy="135" rx="18" ry="14" fill="#F7F2E9" />
      <path d="M221 126h8l-4 4.5-4-4.5Z" fill="#C79BA6" />
      <path d="M225 130.5v2.4" stroke="#8E8A99" stroke-width="1.1" stroke-linecap="round" />
      <path d="M220 137c1.7 1.9 3.5 2.7 5 2.7s3.3-0.8 5-2.7" stroke="#8E8A99" stroke-width="1.1" stroke-linecap="round" />
      <!-- 蓝眼睛（含瞳孔与高光） -->
      <ellipse cx="211" cy="118" rx="5" ry="5.6" fill="#7EA6D9" />
      <ellipse cx="239" cy="118" rx="5" ry="5.6" fill="#7EA6D9" />
      <circle cx="211" cy="118" r="2.1" fill="#2B3357" />
      <circle cx="239" cy="118" r="2.1" fill="#2B3357" />
      <circle cx="212.6" cy="116.4" r="0.9" fill="#FFFFFF" opacity="0.9" />
      <circle cx="240.6" cy="116.4" r="0.9" fill="#FFFFFF" opacity="0.9" />
      <!-- 胡须（左右对称） -->
      <path d="M207 128 195 125M207 133 194 133M243 128 255 125M243 133 256 133" stroke="#FFFFFF" stroke-width="1" stroke-linecap="round" opacity="0.55" />
    </g>

    <!-- ============ 小角色（半身，打哈欠） ============ -->
    <g>
      <!-- 头发后层 -->
      <path
        d="M64 96C60 58 82 40 108 40s48 18 44 56c-2 24-8 44-16 60l-8-8-8 10-8-10-8 10-8-10-8 10c-8-16-14-36-16-60Z"
        fill="#2B3357"
      />
      <!-- 肩与身体（连接头部与被子） -->
      <path d="M72 152c0-16 16-26 36-26s36 10 36 26v30H72v-30Z" fill="#2B3357" />
      <!-- 樱粉挑染：先画宽底再压深色发丝，形成自然过渡 -->
      <path d="M74 78C70 100 72 124 80 148" stroke="#F2A0C0" stroke-width="14" stroke-linecap="round" opacity="0.35" />
      <path d="M142 78C146 100 144 124 136 148" stroke="#F2A0C0" stroke-width="14" stroke-linecap="round" opacity="0.28" />
      <path d="M74 78C70 100 72 124 80 148" stroke="#F2A0C0" stroke-width="8" stroke-linecap="round" opacity="0.95" />
      <path d="M142 78C146 100 144 124 136 148" stroke="#F2A0C0" stroke-width="8" stroke-linecap="round" opacity="0.8" />
      <!-- 挑染上的深色发丝（融入头发） -->
      <path d="M77 86c-2 16-1 32 4 48M139 86c2 16 1 32-4 48" stroke="#2B3357" stroke-width="1.6" stroke-linecap="round" opacity="0.45" />
      <!-- 十字发夹（贴合挑染发束） -->
      <g stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" opacity="0.95">
        <path d="M73 92h12M79 86v12" />
        <path d="M131 92h12M137 86v12" />
      </g>
      <!-- 白色小翅膀（三瓣羽尖，紧贴头侧） -->
      <g>
        <path d="M88 74c-3-9-9-16-17-18 2-8 11-12 19-8 7 4 10 13 8 22l-10 4Z" fill="#F8FBFF" />
        <path d="M71 56c8-4 17 0 22 9M80 66c5 3 8 8 8 14" stroke="#C9D2F7" stroke-width="1.3" stroke-linecap="round" />
        <path d="M88 74c0 6 1 11 4 15l-9-2 5-13Z" fill="#F1F5FF" />
        <path d="M128 74c3-9 9-16 17-18-2-8-11-12-19-8-7 4-10 13-8 22l10 4Z" fill="#F8FBFF" />
        <path d="M145 56c-8-4-17 0-22 9M136 66c-5 3-8 8-8 14" stroke="#C9D2F7" stroke-width="1.3" stroke-linecap="round" />
        <path d="M128 74c0 6-1 11-4 15l9-2-5-13Z" fill="#F1F5FF" />
      </g>
      <!-- 脸 -->
      <ellipse cx="108" cy="96" rx="33" ry="35" fill="#F7E2D8" />
      <!-- 刘海 -->
      <path
        d="M76 96C74 62 90 50 108 50s34 12 32 46c-4-12-9-19-15-23-4 8-10 13-17 15-4-5-6-9-6-14-2 5-5 10-9 14-6-4-11-11-14-19-3 5-5 12-5 27Z"
        fill="#2B3357"
      />
      <!-- 刘海内挑染 -->
      <path d="M97 60c-2 10-2 20 1 29" stroke="#F2A0C0" stroke-width="3" stroke-linecap="round" opacity="0.8" />
      <path d="M120 62c2 9 2 18-1 26" stroke="#F2A0C0" stroke-width="2.6" stroke-linecap="round" opacity="0.5" />
      <!-- 闭眼 -->
      <path d="M89 98c3 3.4 8 3.4 11 0" stroke="#2B3357" stroke-width="2.2" stroke-linecap="round" />
      <path d="M116 98c3 3.4 8 3.4 11 0" stroke="#2B3357" stroke-width="2.2" stroke-linecap="round" />
      <!-- 打哈欠的嘴（含口腔暗部与舌） -->
      <ellipse cx="108" cy="118" rx="9" ry="11.5" fill="#8E4657" />
      <path d="M104 124c1.6-1.4 3-2 4-2s2.4 0.6 4 2c-1.4 3.4-2.6 5-4 5s-2.6-1.6-4-5Z" fill="#C4788A" />
      <path d="M100 112c1.9-2.7 4.4-4 8-4s6.1 1.3 8 4c-2.4-1.2-4.9-1.8-8-1.8s-5.6 0.6-8 1.8Z" fill="#FFFFFF" opacity="0.4" />
      <!-- 腮红 -->
      <ellipse cx="87" cy="110" rx="4.5" ry="3" fill="#F2A0C0" opacity="0.5" />
      <ellipse cx="129" cy="110" rx="4.5" ry="3" fill="#F2A0C0" opacity="0.5" />
    </g>

    <!-- ============ 前景被子 ============ -->
    <path
      d="M-10 170C40 160 78 172 126 166 174 160 214 170 262 164 300 159 330 164 350 160L350 260L-10 260Z"
      fill="url(#quilt)"
    />
    <path d="M-10 170C40 160 78 172 126 166 174 160 214 170 262 164 300 159 330 164 350 160" stroke="#C9D2EE" stroke-width="3" stroke-linecap="round" opacity="0.5" />
    <path d="M-10 177C40 167 78 179 126 173 174 167 214 177 262 171 300 166 330 171 350 167" stroke="#6E7A9B" stroke-width="1.3" stroke-linecap="round" opacity="0.3" />
    <path
      d="M48 186c9 12 11 25 6 38M104 192c8 11 10 21 6 31M166 184c8 12 9 23 5 33M230 186c7 11 8 20 4 29M292 182c6 10 7 18 4 26"
      stroke="#6E7A9B"
      stroke-width="1.5"
      stroke-linecap="round"
      opacity="0.26"
    />
    <path d="M-10 226C46 233 104 232 160 224 208 218 278 218 350 211" stroke="#5F6B8A" stroke-width="1.3" stroke-linecap="round" opacity="0.2" />

    <!-- ============ 手与爪子（搭在被沿上，手指清晰） ============ -->
    <g>
      <path d="M96 162c-4 0-7 3-7 8s3 10 8 10 9-4 9-10c0-5-3-8-6-9l-4 1Z" fill="#F7E2D8" />
      <path d="M99 166c-2 2-3 5-3 8M104 165c-1 3-1 6 0 9" stroke="#E0C4B6" stroke-width="1.2" stroke-linecap="round" />
      <path d="M120 162c4 0 7 3 7 8s-3 10-8 10-9-4-9-10c0-5 3-8 6-9l4 1Z" fill="#F7E2D8" />
      <path d="M117 166c2 2 3 5 3 8M112 165c1 3 1 6 0 9" stroke="#E0C4B6" stroke-width="1.2" stroke-linecap="round" />
    </g>
    <g>
      <ellipse cx="210" cy="164" rx="10" ry="6" fill="#FFFFFF" opacity="0.95" />
      <path d="M204 162c2 2 3 4 3 7M210 160c1 2 1 5 1 8M216 162c-2 2-3 4-3 7" stroke="#D6D0DA" stroke-width="1" stroke-linecap="round" />
      <ellipse cx="241" cy="164" rx="10" ry="6" fill="#FFFFFF" opacity="0.95" />
      <path d="M235 162c2 2 3 4 3 7M241 160c1 2 1 5 1 8M247 162c-2 2-3 4-3 7" stroke="#D6D0DA" stroke-width="1" stroke-linecap="round" />
    </g>

    <!-- ============ 床沿柔光 ============ -->
    <ellipse cx="150" cy="236" rx="190" ry="22" fill="url(#floorGlow)" />
    </g>
  </svg>
</template>

<style scoped>
.brand-scene {
  display: block;
  max-width: 100%;
  height: auto;
}
</style>
