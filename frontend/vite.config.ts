import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// Vite 配置：构建产物输出到 frontend/dist，再由构建脚本组装进站点根目录 site/
//
// 重要：使用 inlineDynamicImports 打成单个 JS 文件。
// 原因：本地预览与部分共享主机用的是 PHP 内置服务器，而它在 Windows 上是单线程的，
// 拆成几十个 chunk 会让浏览器并发请求排队串行处理，表现为「页面/登录一直转圈」。
// 单一入口 + 单一 CSS 可把首屏请求数从 60+ 降到 2~3 个。
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    chunkSizeWarningLimit: 4000,
    cssCodeSplit: false,
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
        entryFileNames: 'assets/app.[hash].js',
        assetFileNames: 'assets/app.[hash][extname]',
      },
    },
  },
  server: {
    port: 5199,
    proxy: {
      // 本地开发：接口转发到本地 PHP 服务
      '/api': {
        target: 'http://127.0.0.1:8811',
        changeOrigin: true,
      },
    },
  },
})
