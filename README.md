# 软件开发管理平台

需求与售后双业务线:「需求提交 → 评审 → 设计 → 开发 → 发布上线」的研发需求闭环,叠加「提交问题 → 售后处理并评级 → 多维统计 → 输出行动建议」的售后闭环。
前端 Vue 3 + TypeScript + Element Plus + Pinia + vue-router + ECharts + xlsx；
后端 PHP 8.2 原生接口 + MySQL，部署走 FTP 上传。

## 目录结构

```text
软件开发管理平台/
├── .env                     # 数据库配置（仅项目根，代码只从这里读）
├── .env.example             # 配置项示例
├── AGENTS.md                # 平台注入的环境与资源说明
├── docs/
│   └── 开发规范.md          # 项目开发规范：前后端约定、数据库/部署红线、检查清单
├── database/
│   └── init.sql             # 建表 + 演示种子数据（开发期持续维护，可重复执行）
├── frontend/                # 前端工程
│   ├── src/
│   │   ├── api/             # 数据访问层（与后端解耦的唯一出口）
│   │   ├── components/      # 通用组件 + charts/ 图表 + illustrations/ 内联 SVG 插画
│   │   ├── config/          # 建议规则阈值、统计口径文案
│   │   ├── constants/       # 角色权限、状态/价值枚举与语义色
│   │   ├── directives/      # v-permission 权限指令
│   │   ├── layouts/         # MainLayout（顶栏 + 夜色侧边栏）
│   │   ├── router/          # 路由与角色守卫、菜单定义
│   │   ├── stores/          # Pinia：auth / dict / issue / report / user / department
│   │   ├── styles/          # theme.css（夜空软萌 token）、animation.css
│   │   ├── types/           # 全局实体类型（禁止 any）
│   │   └── views/           # 页面
│   └── vite.config.ts
├── site/                    # 站点根（FTP 上传内容）
│   ├── index.php            # 入口（前端产物缺失时给出提示）
│   ├── index.html           # 前端构建产物入口（由构建脚本复制）
│   ├── assets/              # 前端构建产物
│   ├── api.php              # 后端统一入口：/api.php?r=module/action（41 条路由）
│   ├── api_handlers/        # 各模块接口实现
│   ├── inc/                 # 配置、数据库、响应、鉴权与数据范围
│   ├── uploads/             # 附件与头像落盘目录
│   └── .htaccess            # 入口优先级、SPA 回退、上传目录禁执行
└── tools/                   # 开发期工具（不进交付包）
    ├── gen_init_sql.php     # 生成 database/init.sql
    ├── run_sql.php          # 执行 init.sql
    ├── reset_data.php       # 清空并重新导入演示数据
    ├── selftest.php         # 数据层自测
    ├── check_seed.php       # 演示账号与初始状态核对
    ├── check_env_guard.php  # .env 缺项必须报错 的验证
    └── assemble.mjs         # 把 frontend/dist 组装进 site/
```

## 本地开发

```bash
# 1. 前端依赖
cd frontend && npm install

# 2. 初始化数据库（会执行 database/init.sql，可重复执行）
php -d extension=pdo_mysql -d extension=mbstring tools/run_sql.php

# 3. 构建前端并组装到站点根
npm run build
node ../tools/assemble.mjs

# 4. 起本地服务（在 site/ 目录下）
php -d extension=pdo_mysql -d extension=mbstring -S 127.0.0.1:8811 -t .
```

访问 `http://127.0.0.1:8811/`。

## 演示账号

| 账号 | 密码 | 角色 | 部门 | 用途 |
| --- | --- | --- | --- | --- |
| 商辂 | 1994 | 管理员 | — | 全量数据、后台维护、日志 |
| sales01 | 123456 | 销售员 | 销售一部 | 可见全部问题，无报表菜单 |
| sales02 | 123456 | 销售员 | 销售二部 | 与 sales01 交叉验证提问排行 |
| service01 | 123456 | 售后服务人员 | 售后部 | 可处理问题，可见全部报表，可维护排班 |
| service02 | 123456 | 售后服务人员 + 销售员 | 售后部 | **多角色演示**：权限并集 |
| manager01 | 123456 | 销售经理 | 销售一部 | 仅见销售一部问题与本部门报表 |

其余演示账号：sales03、sales04（销售员）、manager02（销售二部经理）、
sales05（未分配部门，用于验证提交拦截）、sales06（禁用状态）。

## 角色与多角色规则

- 角色为**多值**（`user.roles` 逗号分隔），权限与数据范围按**并集**计算。
- 数据可见范围：拥有 销售员 / 售后服务人员 / 管理员 任一角色 → 全部数据；
  仅有 销售经理 → 仅本部门（提交部门 = 本部门）。
- 值班人候选：仅「售后服务人员」角色（启用中账号）。

## 值班排班

- 粒度：**一天一位值班人**，按整月以日历展示。
- 可见性：所有登录用户可查看排班与顶栏「今日值班人」；仅管理员与售后服务人员可维护。
- 维护方式：点击日期改单日（可清除）；「批量排班」从起始日期按周轮值（7 天一轮），可跳过周末，
  连续调用即可排满整月。
- 排班变更写入操作日志（类型「排班维护」）。

## 部署

前端构建产物与后端代码都在 `site/` 目录下，通过 FTP 上传到站点根即生效，服务器侧无需构建命令。
数据库建表与种子数据由 `database/init.sql` 提供，上传后用站点可访问的方式执行一次即可。

## 时区与缓存（重要）

- **时区**：应用统一使用 `Asia/Shanghai`（`site/inc/config.php` 里 `date_default_timezone_set()`，
  PDO 连接同时执行 `SET time_zone`）。若服务器时区不同，可用环境变量 `APP_TIMEZONE` 覆盖。
  时区错位会导致「今日值班人」取错日期、时间落库错 8 小时。
- **缓存**：`index.html` 必须禁止缓存（`site/index.php` 的响应头 + `site/.htaccess` 的 `FilesMatch`），
  因为 `assets/*` 带内容 hash 可长期缓存，但入口被缓存会让浏览器一直跑旧的 JS，
  表现为「改了代码没生效」。带 hash 的静态产物设为 `immutable` 长期缓存。
