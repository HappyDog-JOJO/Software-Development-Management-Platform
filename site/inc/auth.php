<?php
declare(strict_types=1);

/** 认证、当前用户、角色与数据范围 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/notify.php';

const QLY_ROLE_SALES = '销售员';
const QLY_ROLE_SERVICE = '售后服务人员';
const QLY_ROLE_MANAGER = '销售经理';
const QLY_ROLE_ADMIN = '管理员';
// 需求管理模块新增角色（与前端 constants/user.ts 的 ROLES 保持一致）
const QLY_ROLE_COMMITTEE = '评审委员';
const QLY_ROLE_PM = '产品经理';
const QLY_ROLE_DEV = '开发工程师';
const QLY_ROLE_INSPECTOR = '质量审核员';

function qly_roles(): array
{
    return [
        QLY_ROLE_SALES, QLY_ROLE_SERVICE, QLY_ROLE_MANAGER, QLY_ROLE_ADMIN,
        QLY_ROLE_COMMITTEE, QLY_ROLE_PM, QLY_ROLE_DEV, QLY_ROLE_INSPECTOR,
    ];
}

/** 解析逗号分隔的角色串为数组（去重、顺序稳定） */
function qly_parse_roles(?string $roles): array
{
    if ($roles === null || $roles === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $roles) as $r) {
        $r = trim($r);
        if ($r !== '' && in_array($r, qly_roles(), true) && !in_array($r, $out, true)) {
            $out[] = $r;
        }
    }
    return $out;
}

/** 归一化角色输入（字符串或数组）为去重后的角色数组 */
function qly_normalize_roles($roles): array
{
    if (is_array($roles)) {
        $out = [];
        foreach ($roles as $r) {
            $r = trim((string) $r);
            if ($r !== '' && in_array($r, qly_roles(), true) && !in_array($r, $out, true)) {
                $out[] = $r;
            }
        }
        return $out;
    }
    return qly_parse_roles(is_string($roles) ? $roles : null);
}

/** 当前用户是否拥有指定角色之一（多角色按并集） */
function qly_has_role(array $user, $roles): bool
{
    $need = qly_normalize_roles($roles);
    if (!$need) {
        return true;
    }
    $has = qly_parse_roles(isset($user['roles']) ? (string) $user['roles'] : null);
    return count(array_intersect($need, $has)) > 0;
}

function qly_password_hash(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

function qly_password_verify(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

/** 解析请求头中的 Bearer Token */
function qly_bearer_token(): string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders() ?: [];
    }
    foreach ($headers as $k => $v) {
        if (strtolower((string) $k) === 'authorization') {
            if (preg_match('/Bearer\s+(.+)/i', (string) $v, $m)) {
                return trim($m[1]);
            }
        }
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.+)/i', (string) $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        return trim($m[1]);
    }
    $posted = qly_input('_token');
    return is_string($posted) ? $posted : '';
}

/** 当前登录用户；未登录返回 null。
 * 会话令牌存在独立的 `user_token` 表（一对多），
 * 因此同一账号可在多台设备/多个浏览器同时登录，互相不顶掉。
 */
function qly_current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    $token = qly_bearer_token();
    if ($token === '') {
        return null;
    }
    $row = qly_one(
        'SELECT u.*, d.name AS department_name
           FROM `user_token` t
           JOIN `user` u ON u.id = t.user_id
           LEFT JOIN `department` d ON d.id = u.department_id
          WHERE t.token = :t AND u.status = 1 LIMIT 1',
        [':t' => $token]
    );
    if (!$row) {
        return null;
    }
    $exp = qly_val('SELECT expire_at FROM `user_token` WHERE token = :t LIMIT 1', [':t' => $token]);
    if (!empty($exp) && strtotime((string) $exp) < time()) {
        return null;
    }
    $user = $row;
    return $user;
}

/** 必须登录 */
function qly_auth_user(): array
{
    $u = qly_current_user();
    if ($u === null) {
        qly_unauthorized();
    }
    return $u;
}

/** 必须是管理员 */
function qly_admin_user(): array
{
    $u = qly_auth_user();
    if (!qly_has_role($u, [QLY_ROLE_ADMIN])) {
        qly_forbidden('仅管理员可执行该操作');
    }
    return $u;
}

/** 判断角色是否可处理售后问题 */
function qly_can_handle(array $user): bool
{
    return qly_has_role($user, [QLY_ROLE_SERVICE, QLY_ROLE_ADMIN]);
}

/** 判断角色是否可看报表 */
function qly_can_report(array $user): bool
{
    return qly_has_role($user, [QLY_ROLE_SERVICE, QLY_ROLE_MANAGER, QLY_ROLE_ADMIN]);
}

/** 是否可维护排班（管理员 / 售后服务人员） */
function qly_can_manage_duty(array $user): bool
{
    // 排班配置权仅管理员（页面与接口均限管理员）
    return qly_has_role($user, [QLY_ROLE_ADMIN]);
}

/**
 * 数据可见范围策略（由宽到窄，多角色取最宽）：
 * 1）管理员 / 售后服务人员：全部问题
 * 2）销售经理：本部门提交的问题
 * 3）其余（包含销售员）：仅自己提交的问题
 */
function qly_scope_level(array $user): string
{
    $roles = qly_parse_roles(isset($user['roles']) ? (string) $user['roles'] : null);
    if (count(array_intersect($roles, [QLY_ROLE_ADMIN, QLY_ROLE_SERVICE])) > 0) {
        return 'all';
    }
    if (in_array(QLY_ROLE_MANAGER, $roles, true)) {
        return 'dept';
    }
    return 'self';
}

/**
 * 数据可见范围 SQL 片段（需与 qly_scope_level 保持一致）。
 * 返回值 [whereSql, params]。
 */
function qly_scope_where(array $user, string $alias = 'i'): array
{
    switch (qly_scope_level($user)) {
        case 'all':
            return ['', []];
        case 'dept':
            $deptId = (int) ($user['department_id'] ?? 0);
            if ($deptId <= 0) {
                return [' AND 1=0', []];
            }
            return [' AND ' . $alias . '.department_id = :__scope_dept', [':__scope_dept' => $deptId]];
        default: // self：仅本人提交
            $uid = (int) ($user['id'] ?? 0);
            if ($uid <= 0) {
                return [' AND 1=0', []];
            }
            return [' AND ' . $alias . '.submitter_id = :__scope_uid', [':__scope_uid' => $uid]];
    }
}

/**
 * 单条问题是否在当前用户可见范围内（详情/处理记录/补充说明等逐条校验用）。
 * 与 qly_scope_where 同源，避免列表与详情口径不一致。
 */
function qly_issue_visible(array $user, array $issue): bool
{
    switch (qly_scope_level($user)) {
        case 'all':
            return true;
        case 'dept':
            $deptId = (int) ($user['department_id'] ?? 0);
            return $deptId > 0 && (int) ($issue['department_id'] ?? 0) === $deptId;
        default:
            $uid = (int) ($user['id'] ?? 0);
            return $uid > 0 && (int) ($issue['submitter_id'] ?? 0) === $uid;
    }
}

/** 生成登录 Token（写入多会话表，不顶掉其它已登录设备） */
function qly_issue_token(int $userId): string
{
    $token = bin2hex(random_bytes(24));
    $cfg = qly_app_config();
    $now = date('Y-m-d H:i:s');
    qly_insert('user_token', [
        'user_id' => $userId,
        'token' => $token,
        'expire_at' => date('Y-m-d H:i:s', time() + (int) $cfg['token_ttl']),
        'created_at' => $now,
        'last_used_at' => $now,
    ]);
    qly_update('user', $userId, ['last_login_at' => $now]);
    // 顺手清理该用户已过期的会话，避免表无限增长
    qly_exec('DELETE FROM `user_token` WHERE user_id = :u AND expire_at < :now', [':u' => $userId, ':now' => $now]);
    return $token;
}

/** 注销当前会话（仅失效本设备的 token，其它设备不受影响） */
function qly_revoke_token(string $token): void
{
    if ($token !== '') {
        qly_exec('DELETE FROM `user_token` WHERE token = :t', [':t' => $token]);
    }
}

/** 注销某用户的全部会话（重置密码/禁用/删除时使用） */
function qly_revoke_all_tokens(int $userId): void
{
    qly_exec('DELETE FROM `user_token` WHERE user_id = :u', [':u' => $userId]);
}

/** 取客户端 IP（Mock 环境也可能为空） */
function qly_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string) $_SERVER[$k])[0];
            return trim($ip);
        }
    }
    return '127.0.0.1';
}

/**
 * 写操作日志。日志只读，业务代码只调用本函数。
 */
function qly_log(?array $user, string $type, string $target, string $content = ''): void
{
    try {
        $roles = $user ? qly_parse_roles(isset($user['roles']) ? (string) $user['roles'] : null) : [];
        qly_insert('operation_log', [
            'user_id' => $user ? (int) $user['id'] : 0,
            'account' => $user ? (string) $user['account'] : '系统',
            'role' => $roles ? implode('、', $roles) : ($user ? '未分配' : '系统'),
            'type' => $type,
            'target' => mb_substr($target, 0, 200),
            'content' => mb_substr($content, 0, 2000),
            'ip' => qly_client_ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // 日志失败不影响主流程
    }
}

/** 对外输出的用户信息（不含敏感字段） */
function qly_user_public(array $u): array
{
    $roles = qly_parse_roles(isset($u['roles']) ? (string) $u['roles'] : null);
    return [
        'id' => (int) $u['id'],
        'account' => $u['account'],
        'name' => $u['name'] !== null && $u['name'] !== '' ? $u['name'] : $u['account'],
        'phone' => $u['phone'],
        'avatar' => $u['avatar'],
        'departmentId' => $u['department_id'] !== null ? (int) $u['department_id'] : null,
        'departmentName' => $u['department_name'] ?? null,
        'roles' => $roles,
        // 兼容：首个角色作为主角色展示
        'role' => $roles ? $roles[0] : '未分配',
        'status' => (int) $u['status'],
        // 前端登录页依赖此字段判断是否强制改密（缺失会导致首登改密流程失效）
        'mustChangePassword' => (int) ($u['must_change_password'] ?? 0) === 1,
        'createdAt' => $u['created_at'],
    ];
}

/* ==================== 需求管理模块：角色能力与数据范围 ==================== */

/** 评审需求：评审委员 / 管理员（发布上线同权限） */
function qly_can_review_req(array $user): bool
{
    return qly_has_role($user, [QLY_ROLE_COMMITTEE, QLY_ROLE_ADMIN]);
}

/** 认领/设计需求：产品经理 / 管理员 */
function qly_can_design_req(array $user): bool
{
    return qly_has_role($user, [QLY_ROLE_PM, QLY_ROLE_ADMIN]);
}

/** 需求单条可见范围（与 qly_devreq_scope_where 保持同源）：
 * 评审委员/管理员全量；产品经理可见需求池全部 + 自己认领的；
 * 开发工程师可见自己负责的主任务；提交人可见自己的。 */
function qly_devreq_visible(array $user, array $r): bool
{
    $roles = qly_parse_roles(isset($user['roles']) ? (string) $user['roles'] : null);
    if (count(array_intersect($roles, [QLY_ROLE_ADMIN, QLY_ROLE_COMMITTEE])) > 0) {
        return true;
    }
    $uid = (int) ($user['id'] ?? 0);
    if ($uid > 0 && (int) ($r['submitter_id'] ?? 0) === $uid) {
        return true;
    }
    if ($uid > 0 && (int) ($r['pm_id'] ?? 0) === $uid) {
        return true;
    }
    if ($uid > 0 && (int) ($r['dev_leader_id'] ?? 0) === $uid) {
        return true;
    }
    if (in_array(QLY_ROLE_PM, $roles, true) && (string) ($r['status'] ?? '') === '需求池') {
        return true;
    }
    // 已上线的成果对全员可见
    if ((string) ($r['status'] ?? '') === '已上线') {
        return true;
    }
    return false;
}

/** 需求列表可见范围 SQL 片段（需与 qly_devreq_visible 保持一致）。返回 [whereSql, params]。 */
function qly_devreq_scope_where(array $user, string $alias = 'i'): array
{
    $roles = qly_parse_roles(isset($user['roles']) ? (string) $user['roles'] : null);
    if (count(array_intersect($roles, [QLY_ROLE_ADMIN, QLY_ROLE_COMMITTEE])) > 0) {
        return ['', []];
    }
    $uid = (int) ($user['id'] ?? 0);
    $isPm = in_array(QLY_ROLE_PM, $roles, true);
    // PDO 原生预处理（EMULATE_PREPARES=false）不允许同名占位符重复，每个条件独立编号
    $conds = [
        "{$alias}.submitter_id = :__dv_u1",
        "{$alias}.pm_id = :__dv_u2",
        "{$alias}.dev_leader_id = :__dv_u3",
    ];
    if ($isPm) {
        $conds[] = "{$alias}.status = '需求池'";
    }
    // 已上线的成果（已上线）对全员可见
    $conds[] = "{$alias}.status = '已上线'";
    $where = ' AND (' . implode(' OR ', $conds) . ')';
    return [$where, [':__dv_u1' => $uid, ':__dv_u2' => $uid, ':__dv_u3' => $uid]];
}
