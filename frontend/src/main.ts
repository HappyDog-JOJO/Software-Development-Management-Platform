import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import 'element-plus/dist/index.css'

import App from './App.vue'
import router from './router'
import { setupPermissionDirective } from './directives/permission'

import './styles/theme.css'
import './styles/animation.css'

const app = createApp(App)

// 全局图标：Element Plus 图标作为辅助（业务图标优先 @icon-park/vue-next）
for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component)
}

app.use(createPinia())
app.use(router)
app.use(ElementPlus, { locale: zhCn })
setupPermissionDirective(app)

app.mount('#app')
