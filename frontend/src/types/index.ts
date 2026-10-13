/** 全局实体类型定义（禁止 any） */

export type Role =
  | '销售员'
  | '售后服务人员'
  | '销售经理'
  | '管理员'
  | '评审委员'
  | '产品经理'
  | '开发工程师'

export type IssueStatus = '待处理' | '处理中' | '已处理' | '未处理' | '已驳回'

export type DictType = 'module' | 'value' | 'handle' | 'category'

export type LogType =
  | '登录'
  | '退出'
  | '注册'
  | '改密'
  | '提交问题'
  | '问题处理'
  | '用户管理'
  | '部门维护'
  | '字典维护'
  | '导出'
  | '提交需求'
  | '需求评审'
  | '需求开发'
  | '需求发布'

/* ==================== 需求管理模块 ==================== */

/** 需求主任务状态 */
export type DevStatus =
  | '评审池'
  | '需求池'
  | '开发中心'
  | '待发布'
  | '已上线'
  | '已驳回'
  | '已取消'

/** 需求优先级（四级） */
export type DevPriority = '紧急' | '高' | '中' | '低'

/** 统一响应体 */
export interface ApiResponse<T> {
  code: number
  data: T
  message: string
  missing?: string[]
}

/** 分页结果 */
export interface PageResult<T> {
  items: T[]
  total: number
  page: number
  pageSize: number
  /** 因权限范围被过滤掉的条数（服务端返回） */
  filteredOut?: number
}

export interface LoginUser {
  id: number
  account: string
  name: string
  phone: string | null
  avatar: string | null
  departmentId: number | null
  departmentName: string | null
  roles: Role[]
  /** 兼容：首个角色作为主角色展示 */
  role: Role | '未分配'
  status: number
  /** 首次登录是否必须修改密码（服务端下发，缺失会导致强制改密失效） */
  mustChangePassword: boolean
  createdAt: string
}

export interface LoginResult {
  token: string
  user: LoginUser
}

export interface User {
  id: number
  account: string
  name: string
  phone: string | null
  avatar: string | null
  departmentId: number | null
  departmentName: string | null
  roles: Role[]
  /** 兼容：首个角色作为主角色展示 */
  role: Role | '未分配'
  status: number
  mustChangePassword: boolean
  createdAt: string
}

export interface Department {
  id: number
  name: string
  leader: string | null
  sort: number
  status: number
  userCount: number
  createdAt: string
}

export interface DictItem {
  id: number
  type: DictType
  typeLabel: string
  name: string
  sort: number
  status: number
  referenceCount: number
  createdAt: string
}

export interface Attachment {
  id: number
  url: string
  name: string
  size: number
  mimeType: string
}

/** 上传返回值 */
export interface UploadResult {
  url: string
  name: string
  size: number
  mimeType: string
}

/** 站内消息通知 */
export interface NotificationItem {
  id: number
  title: string
  content: string
  /** 点击后跳转的前端路由（如 /issues/20），可为空 */
  link: string
  isRead: boolean
  createdAt: string
}

/** 公告 */
export interface Notice {
  id: number
  /** 标题（可空，空则灯牌只展示内容） */
  title: string
  content: string
  /** 1启用 0停用 */
  status: number
  publisherId: number
  publisherName: string
  createdAt: string
  updatedAt: string
}

export interface Issue {
  id: number
  issueNo: string
  submitterId: number
  submitterName: string
  submitterAccount: string
  submitterDepartmentId: number | null
  submitterDepartmentName: string | null
  moduleId: number | null
  moduleName: string | null
  /** 最新问题分类（售后处理时选择；未处理为 null） */
  categoryId: number | null
  categoryName: string | null
  description: string
  status: IssueStatus
  valueId: number | null
  valueName: string | null
  attachmentCount: number
  handlerName: string | null
  handledAt: string | null
  /** 最新一条处理备注（无处理记录时为空字符串） */
  handleRemark: string
  createdAt: string
}

export interface IssueAppend {
  id: number
  content: string
  authorName: string
  authorRole: Role
  createdAt: string
}

export interface IssueLogItem {
  id: number
  account: string
  role: string
  type: string
  content: string
  createdAt: string
}

export interface IssueDetail extends Issue {
  attachments: Attachment[]
  appends: IssueAppend[]
  logs: IssueLogItem[]
}

export interface HandleRecord {
  id: number
  issueId: number
  handleStatusId: number
  handleStatusName: string
  /** 本次处理选择的问题分类 */
  categoryId: number | null
  categoryName: string | null
  remark: string
  valueId: number
  valueName: string
  handlerId: number
  handlerName: string
  handlerRole: Role
  createdAt: string
  attachments: Attachment[]
}

export interface OperationLog {
  id: number
  account: string
  role: string
  type: LogType | string
  target: string
  content: string
  ip: string
  createdAt: string
}

export interface ReportQuery {
  dateStart?: string
  dateEnd?: string
  departmentId?: number
  moduleId?: number
}

export interface ReportOverview {
  total: number
  handled: number
  processing: number
  unhandled: number
  rejected: number
  pending: number
  rated: number
  valuable: number
  veryValuable: number
  fairlyValuable: number
  worthless: number
  valuableRate: number
  unhandledRate: number
  avgHandleHours: number
}

export interface RankItem {
  name: string
  value: number
  extra: { very: number; fairly: number } | null
}

export interface RankBundle {
  ask: RankItem[]
  valuable: RankItem[]
  worthless: RankItem[]
  module: RankItem[]
  department: RankItem[]
  handle: RankItem[]
}

export interface CrossAnalysis {
  trend: Array<{ date: string; total: number; handled: number; unhandled: number }>
  valueDist: Array<{ name: string; value: number }>
  departments: string[]
  modules: string[]
  matrix: Array<{ department: string; module: string; value: number }>
  profile: Array<{ name: string; total: number; valuable: number; worthless: number; valuableRate: number }>
}

export interface AdviceItem {
  id: string
  level: 'info' | 'warning' | 'error'
  title: string
  conclusion: string
  evidence: string
  action: string
  link: { type: 'issues' | 'reports'; params: Record<string, string | number> }
}

export interface AdviceResult {
  hasData: boolean
  total: number
  advice: AdviceItem[]
}

/** 值得培训学习的问题：高价值问题的优质范例 */

/** 优质范例（高价值问题的优质处理） */
export interface LearningItem {
  id: number
  issueNo: string
  submitterName: string
  submitterDepartmentName: string | null
  moduleName: string | null
  description: string
  status: string
  valueName: string | null
  handlerName: string | null
  handledAt: string | null
  /** 最新一条处理备注（优质处理范例可直接用作培训材料） */
  handleRemark: string
  /** 是否已培训：1是 0否 */
  trainingStatus: number
  createdAt: string
}

export interface LearningResult {
  items: LearningItem[]
  total: number
  trained: number
  untrained: number
  all: number
}

export interface IssueQuery {
  dateStart?: string
  dateEnd?: string
  submitter?: string
  departmentId?: number
  moduleId?: number
  categoryId?: number
  status?: string
  valueId?: number
  /** 处理人筛选："id:姓名" 或姓名 */
  handler?: string
  keyword?: string
  page?: number
  pageSize?: number
  sortField?: 'createdAt' | 'handledAt'
  sortOrder?: 'ASC' | 'DESC'
}

/** 售后值班排班 */
export interface DutyItem {
  id: number
  dutyDate: string
  userId: number
  userName: string
  remark: string
  createdAt: string
  updatedAt: string
}

/** 可值班人员（仅售后服务人员角色） */
export interface DutyCandidate {
  id: number
  account: string
  name: string
  departmentId: number | null
}

export interface DutyMonthResult {
  dateStart: string
  dateEnd: string
  items: DutyItem[]
  candidates: DutyCandidate[]
}

export interface DutyToday {
  date: string
  onDuty: boolean
  userId: number | null
  userName: string | null
  /** 值班人头像地址（未设置时为 null） */
  avatar?: string | null
  /** 值班人联系电话 */
  phone?: string | null
  /** 值班人所属部门 */
  departmentName?: string | null
  remark: string
}

/* ==================== 需求管理模块 ==================== */

/** 需求附件（scene：submit提交/reject驳回/done完成/release上线） */
export interface DevAttachment extends Attachment {
  requestId: number
  scene: 'submit' | 'reject' | 'done' | 'release' | string
  createdAt: string
}

/** 需求主任务（列表/详情共用，详情多出 attachments/logs） */
export interface DevRequest {
  id: number
  reqNo: string
  title: string
  description: string
  moduleId: number | null
  moduleName: string | null
  priority: DevPriority
  status: DevStatus
  submitterId: number
  submitterName: string
  submitterAccount: string
  submitterDepartmentName: string | null
  reviewerName: string | null
  reviewComment: string | null
  reviewedAt: string | null
  /** 评审待定：暂留评审池，之后仍可通过/驳回 */
  reviewPending: boolean
  devDoneNote: string | null
  devDoneAt: string | null
  releasedAt: string | null
  releasedBy: string | null
  /** PingCode 同步状态（同步后记录） */
  pingcodeTaskId: string | null
  pingcodeTaskNo: string | null
  pingcodeTaskUrl: string | null
  pingcodeSyncedAt: string | null
  /** 负责人（同步到 PingCode 时选定的成员） */
  assigneeId: string | null
  assigneeName: string | null
  poolAt: string | null
  designAt: string | null
  battleAt: string | null
  frontlineAt: string | null
  createdAt: string
  updatedAt: string
}

export interface DevReqLogItem {
  id: number
  account: string
  role: string
  type: LogType | string
  content: string
  createdAt: string
}

export interface DevRequestDetail extends DevRequest {
  attachments: DevAttachment[]
  logs: DevReqLogItem[]
}

export interface DevQuery {
  status?: DevStatus | string
  priority?: DevPriority | string
  moduleId?: number
  keyword?: string
  /** 只看我自己提交的 */
  mine?: '1'
  /** 隐藏评审待定的需求（我的需求页开关） */
  hidePending?: '1'
  /** 上线时间范围筛选（发布上线记录页） */
  releasedStart?: string
  releasedEnd?: string
  /** 负责人筛选（PingCode 成员 id） */
  assigneeId?: string
  page?: number
  pageSize?: number
  sortField?: 'createdAt' | 'priority' | 'releasedAt'
  sortOrder?: 'ASC' | 'DESC'
}
