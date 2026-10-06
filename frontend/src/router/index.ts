import { createRouter, createWebHashHistory, type RouteRecordRaw } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import {
  ROLE_ADMIN,
  ROLE_COMMITTEE,
  ROLE_DEV,
  ROLE_MANAGER,
  ROLE_PM,
  ROLE_SALES,
  ROLE_SERVICE,
} from '@/constants/user'
import type { DevStatus, Role } from '@/types'

/**
 * 路由与权限：每条路由带 meta.title 与 meta.roles。
 * 全局前置守卫校验登录态 + 角色，未登录跳 /login，无权限跳 /403。
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/auth/LoginView.vue'),
    meta: { title: '登录', public: true },
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('@/views/auth/RegisterView.vue'),
    meta: { title: '注册', public: true },
  },
  {
    path: '/register-success',
    name: 'RegisterSuccess',
    component: () => import('@/views/auth/RegisterSuccessView.vue'),
    meta: { title: '注册成功', public: true },
  },
  {
    path: '/',
    component: () => import('@/layouts/MainLayout.vue'),
    redirect: '/issues/create',
    children: [
      {
        path: 'issues/create',
        name: 'IssueCreate',
        component: () => import('@/views/issues/IssueCreateView.vue'),
        meta: { title: '问题提交', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], icon: 'edit', group: '售后管理' },
      },
      {
        path: 'issues',
        name: 'IssueList',
        component: () => import('@/views/issues/IssueListView.vue'),
        meta: { title: '问题列表', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], icon: 'list', group: '售后管理' },
      },
      {
        path: 'issues/:id',
        name: 'IssueDetail',
        component: () => import('@/views/issues/IssueDetailView.vue'),
        meta: { title: '问题详情', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], hidden: true },
      },
      {
        path: 'duty',
        name: 'Duty',
        component: () => import('@/views/system/DutyView.vue'),
        meta: { title: '值班排班', roles: [ROLE_ADMIN], icon: 'duty', group: '系统管理' },
      },
      {
        path: 'system/notices',
        name: 'SystemNotices',
        component: () => import('@/views/system/NoticeView.vue'),
        meta: { title: '公告管理', roles: [ROLE_ADMIN], icon: 'notice', group: '系统管理' },
      },
      {
        path: 'reports',
        name: 'Reports',
        component: () => import('@/views/reports/ReportsView.vue'),
        meta: { title: '报表统计', roles: [ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], icon: 'chart', group: '售后管理' },
      },

      /* ==================== 需求管理 ==================== */
      {
        path: 'dev/create',
        name: 'DevCreate',
        component: () => import('@/views/dev/DevCreateView.vue'),
        meta: { title: '需求提交', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], icon: 'edit', group: '开发管理' },
      },
      {
        path: 'dev/mine',
        name: 'DevMine',
        component: () => import('@/views/dev/DevListView.vue'),
        props: { mine: true },
        meta: { title: '我的需求', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], icon: 'list', group: '开发管理' },
      },
      {
        path: 'dev/review',
        name: 'DevReviewPool',
        component: () => import('@/views/dev/DevListView.vue'),
        props: { stage: '评审池' },
        meta: { title: '评审池', roles: [ROLE_COMMITTEE, ROLE_ADMIN], icon: 'review', group: '开发管理' },
      },
      {
        path: 'dev/pool',
        name: 'DevPool',
        component: () => import('@/views/dev/DevListView.vue'),
        props: { stage: '需求池' },
        meta: { title: '需求池', roles: [ROLE_PM, ROLE_COMMITTEE, ROLE_ADMIN], icon: 'pool', group: '开发管理' },
      },
      {
        path: 'dev/design',
        name: 'DevDesign',
        component: () => import('@/views/dev/DevListView.vue'),
        props: { stage: '设计中心' as DevStatus },
        meta: { title: '设计中心', roles: [ROLE_PM, ROLE_COMMITTEE, ROLE_ADMIN], icon: 'design', group: '开发管理' },
      },
      {
        path: 'dev/battle',
        name: 'DevBattle',
        component: () => import('@/views/dev/DevListView.vue'),
        props: { stage: '开发中心' as DevStatus },
        meta: {
          title: '开发中心',
          roles: [ROLE_DEV, ROLE_PM, ROLE_COMMITTEE, ROLE_ADMIN],
          icon: 'battle',
          group: '开发管理',
        },
      },
      {
        path: 'dev/release',
        name: 'DevRelease',
        component: () => import('@/views/dev/DevListView.vue'),
        props: { stage: '待发布' as DevStatus },
        meta: { title: '发布上线', roles: [ROLE_COMMITTEE, ROLE_ADMIN], icon: 'release', group: '开发管理' },
      },
      {
        path: 'dev/reqs/:id',
        name: 'DevReqDetail',
        component: () => import('@/views/dev/DevDetailView.vue'),
        meta: { title: '需求详情', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], hidden: true },
      },
      {
        path: 'system/users',
        name: 'SystemUsers',
        component: () => import('@/views/system/UsersView.vue'),
        meta: { title: '用户管理', roles: [ROLE_ADMIN], icon: 'user', group: '系统管理' },
      },
      {
        path: 'system/departments',
        name: 'SystemDepartments',
        component: () => import('@/views/system/DepartmentsView.vue'),
        meta: { title: '部门管理', roles: [ROLE_ADMIN], icon: 'dept', group: '系统管理' },
      },
      {
        path: 'system/dicts',
        name: 'SystemDicts',
        component: () => import('@/views/system/DictsView.vue'),
        meta: { title: '字典管理', roles: [ROLE_ADMIN], icon: 'dict', group: '系统管理' },
      },
      {
        path: 'system/logs',
        name: 'SystemLogs',
        component: () => import('@/views/system/LogsView.vue'),
        meta: { title: '操作日志', roles: [ROLE_ADMIN], icon: 'log', group: '系统管理' },
      },
      {
        path: 'notifications',
        name: 'Notifications',
        component: () => import('@/views/NotificationsView.vue'),
        meta: { title: '消息中心', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], hidden: true },
      },
      {
        path: 'profile',
        name: 'Profile',
        component: () => import('@/views/profile/ProfileView.vue'),
        meta: { title: '个人中心', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN], hidden: true },
      },
      {
        path: '403',
        name: 'Forbidden',
        component: () => import('@/views/error/ForbiddenView.vue'),
        meta: { title: '无访问权限', hidden: true },
      },
      {
        path: '404',
        name: 'NotFound',
        component: () => import('@/views/error/NotFoundView.vue'),
        meta: { title: '页面不存在', hidden: true },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/404',
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

export interface MenuNode {
  path: string
  title: string
  icon?: string
  group?: string
  roles?: Array<Role | string>
}

/** 导出菜单结构（按角色渲染侧边栏） */
export const MENU: MenuNode[] = [
  // 售后管理
  { path: '/issues/create', title: '问题提交', icon: 'edit', group: '售后管理', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN] },
  { path: '/issues', title: '问题列表', icon: 'list', group: '售后管理', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN] },
  { path: '/reports', title: '报表统计', icon: 'chart', group: '售后管理', roles: [ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN] },
  // 开发管理
  { path: '/dev/create', title: '需求提交', icon: 'edit', group: '开发管理', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN] },
  { path: '/dev/mine', title: '我的需求', icon: 'list', group: '开发管理', roles: [ROLE_SALES, ROLE_SERVICE, ROLE_MANAGER, ROLE_ADMIN] },
  { path: '/dev/review', title: '评审池', icon: 'review', group: '开发管理', roles: [ROLE_COMMITTEE, ROLE_ADMIN] },
  { path: '/dev/pool', title: '需求池', icon: 'pool', group: '开发管理', roles: [ROLE_PM, ROLE_COMMITTEE, ROLE_ADMIN] },
  { path: '/dev/design', title: '设计中心', icon: 'design', group: '开发管理', roles: [ROLE_PM, ROLE_COMMITTEE, ROLE_ADMIN] },
  { path: '/dev/battle', title: '开发中心', icon: 'battle', group: '开发管理', roles: [ROLE_DEV, ROLE_PM, ROLE_COMMITTEE, ROLE_ADMIN] },
  { path: '/dev/release', title: '发布上线', icon: 'release', group: '开发管理', roles: [ROLE_COMMITTEE, ROLE_ADMIN] },
  // 系统管理（固定在最下）
  { path: '/duty', title: '值班排班', icon: 'duty', group: '系统管理', roles: [ROLE_ADMIN] },
  { path: '/system/notices', title: '公告管理', icon: 'notice', group: '系统管理', roles: [ROLE_ADMIN] },
  { path: '/system/users', title: '用户管理', icon: 'user', group: '系统管理', roles: [ROLE_ADMIN] },
  { path: '/system/departments', title: '部门管理', icon: 'dept', group: '系统管理', roles: [ROLE_ADMIN] },
  { path: '/system/dicts', title: '字典管理', icon: 'dict', group: '系统管理', roles: [ROLE_ADMIN] },
  { path: '/system/logs', title: '操作日志', icon: 'log', group: '系统管理', roles: [ROLE_ADMIN] },
]

router.beforeEach((to) => {
  const auth = useAuthStore()
  const isPublic = to.meta.public === true
  const isAuthPage = to.name === 'Login' || to.name === 'Register'

  if (!auth.isLoggedIn) {
    // 未登录：仅允许公开页，其余跳登录页
    if (isPublic) {
      return true
    }
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  // 已登录访问登录页：回首页（默认落在问题提交页）。
  // 注意：带 redirect 参数时不回首页，避免与接口 401 的重登录流程互相推动。
  if (isAuthPage) {
    if (to.query && to.query.redirect) {
      return true
    }
    return { path: '/issues/create' }
  }

  const roles = to.meta.roles as Array<Role | string> | undefined
  if (roles && roles.length > 0 && !auth.hasRole(roles)) {
    ElMessage.warning('没有访问该页面的权限')
    return { path: '/403' }
  }
  return true
})

export default router
