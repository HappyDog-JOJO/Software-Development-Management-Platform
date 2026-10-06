<?php
declare(strict_types=1);

/**
 * 站内消息通知（配合前端浏览器 Notification 弹 Windows 系统通知）。
 *
 * 触达规则（与业务约定一致）：
 * - 提交问题          → 通知「售后服务人员 + 管理员」（能处理售后的人）
 * - 处理问题 / 追加处理 → 通知问题提出人
 * - 发布公告          → 通知全部启用账号
 *
 * 本身不主动弹系统通知：前端轮询 pending 接口取未弹出的消息并调 Notification，
 * 这样「页面打开着（含最小化/后台标签）」即可收到 Windows 通知。
 *
 * 依赖：qly_normalize_roles / qly_has_role 来自 inc/auth.php（由 inc/auth.php 反向引入本文件）。
 */

require_once __DIR__ . '/db.php';

/** 通知标题/正文长度上限（与表结构一致） */
const QLY_NOTIFY_TITLE_MAX = 100;
const QLY_NOTIFY_CONTENT_MAX = 500;

/** 取所有启用账号的 ID */
function qly_notify_all_user_ids(): array
{
    $rows = qly_all('SELECT id FROM `user` WHERE status = 1');
    return array_map(fn(array $r): int => (int) $r['id'], $rows);
}

/** 取拥有指定角色之一（并集）的启用账号 ID */
function qly_notify_user_ids_by_roles(array $roles): array
{
    $roles = qly_normalize_roles($roles);
    if (!$roles) {
        return [];
    }
    // 角色在库里是逗号分隔串，用 LIKE 先粗筛，再用 qly_has_role 精判，避免 PHP 侧全表遍历出错
    $conds = [];
    $params = [];
    foreach ($roles as $i => $r) {
        $conds[] = 'roles LIKE :r' . $i;
        $params[':r' . $i] = '%' . $r . '%';
    }
    $rows = qly_all(
        'SELECT id, roles FROM `user` WHERE status = 1 AND (' . implode(' OR ', $conds) . ')',
        $params
    );
    $ids = [];
    foreach ($rows as $row) {
        if (qly_has_role($row, $roles)) {
            $ids[] = (int) $row['id'];
        }
    }
    return $ids;
}

/**
 * 批量写入通知。
 * @param int[]  $userIds 接收人 ID 列表
 * @param string $title   标题
 * @param string $content 正文
 * @param string $link    点击后跳转的前端路由（如 /issues/20），可为空
 * @param int    $exclude 排除的操作人（自己做的事不通知自己）
 * @return int 实际写入条数
 */
function qly_notify(array $userIds, string $title, string $content, string $link = '', int $exclude = 0): int
{
    $ids = [];
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid > 0 && $uid !== $exclude && !in_array($uid, $ids, true)) {
            $ids[] = $uid;
        }
    }
    if (!$ids) {
        return 0;
    }
    $title = mb_substr(trim($title), 0, QLY_NOTIFY_TITLE_MAX);
    $content = mb_substr(trim($content), 0, QLY_NOTIFY_CONTENT_MAX);
    $link = mb_substr(trim($link), 0, 200);
    $now = date('Y-m-d H:i:s');

    // 一条 SQL 批量插入，避免单线程服务器下的多次往返。
    // 注意：PDO 原生预处理（ATTR_EMULATE_PREPARES=false）下同名占位符只能出现一次，
    // 因此每行都用独立参数名（:t0/:c0/...），不能复用。
    $values = [];
    $params = [];
    foreach ($ids as $i => $uid) {
        $values[] = "(:u{$i}, :t{$i}, :c{$i}, :l{$i}, 0, 0, :now{$i})";
        $params[":u{$i}"] = $uid;
        $params[":t{$i}"] = $title;
        $params[":c{$i}"] = $content;
        $params[":l{$i}"] = $link;
        $params[":now{$i}"] = $now;
    }

    try {
        qly_exec(
            'INSERT INTO `notification` (`user_id`,`title`,`content`,`link`,`is_read`,`notified`,`created_at`) VALUES '
            . implode(',', $values),
            $params
        );
    } catch (Throwable $e) {
        // 通知失败不能影响业务主流程（例如建表脚本尚未执行）
        error_log('[qly_notify] 写入通知失败: ' . $e->getMessage());
        return 0;
    }
    return count($ids);
}

/** 通知行 → 前端结构 */
function qly_notification_public(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'title' => (string) $r['title'],
        'content' => (string) $r['content'],
        'link' => (string) $r['link'],
        'isRead' => (int) $r['is_read'] === 1,
        'createdAt' => (string) $r['created_at'],
    ];
}
