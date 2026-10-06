<?php
declare(strict_types=1);

/**
 * 站内消息通知接口。
 * - pending：拉取「未弹过系统通知」的消息，前端据此调 Notification 弹 Windows 通知；
 *            返回后由前端回执 notified（避免同一条反复弹）。
 * - list：当前用户的通知列表（分页），用于消息中心。
 * - unread：未读数量（角标）。
 * - read：单条标记已读 / read-all：全部标记已读。
 */

require_once __DIR__ . '/../inc/auth.php';

/** 未弹过系统通知的消息（按时间正序，保证弹窗顺序符合发生顺序） */
function qly_api_notify_pending(): void
{
    $user = qly_auth_user();
    $limit = min(20, max(1, qly_input_int('limit', 10)));
    $rows = qly_all(
        'SELECT * FROM `notification`
          WHERE user_id = :u AND notified = 0
          ORDER BY id ASC LIMIT ' . $limit,
        [':u' => (int) $user['id']]
    );
    qly_ok([
        'items' => array_map('qly_notification_public', $rows),
        'unread' => (int) qly_val(
            'SELECT COUNT(*) FROM `notification` WHERE user_id = :u AND is_read = 0',
            [':u' => (int) $user['id']]
        ),
    ]);
}

/** 回执：这些消息已弹出系统通知 */
function qly_api_notify_mark_notified(): void
{
    $user = qly_auth_user();
    $ids = qly_input('ids', []);
    if (!is_array($ids) || !$ids) {
        qly_ok(null, '无需回执');
    }
    $clean = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean[] = $id;
        }
    }
    if (!$clean) {
        qly_ok(null, '无需回执');
    }
    $place = [];
    $params = [':u' => (int) $user['id']];
    foreach ($clean as $i => $id) {
        $place[] = ':i' . $i;
        $params[':i' . $i] = $id;
    }
    qly_exec(
        'UPDATE `notification` SET notified = 1 WHERE user_id = :u AND id IN (' . implode(',', $place) . ')',
        $params
    );
    qly_ok(null, '已回执');
}

/** 当前用户的通知列表 */
function qly_api_notify_list(): void
{
    $user = qly_auth_user();
    $page = max(1, qly_input_int('page', 1));
    $pageSize = min(50, max(1, qly_input_int('pageSize', 15)));
    $offset = ($page - 1) * $pageSize;
    $uid = (int) $user['id'];

    $total = (int) qly_val('SELECT COUNT(*) FROM `notification` WHERE user_id = :u', [':u' => $uid]);
    $rows = qly_all(
        'SELECT * FROM `notification` WHERE user_id = :u ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
        [':u' => $uid]
    );
    qly_ok([
        'items' => array_map('qly_notification_public', $rows),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
    ]);
}

/** 未读数量 + 最近若干条（顶栏铃铛用） */
function qly_api_notify_unread(): void
{
    $user = qly_auth_user();
    $uid = (int) $user['id'];
    $rows = qly_all(
        'SELECT * FROM `notification` WHERE user_id = :u ORDER BY id DESC LIMIT 10',
        [':u' => $uid]
    );
    qly_ok([
        'unread' => (int) qly_val(
            'SELECT COUNT(*) FROM `notification` WHERE user_id = :u AND is_read = 0',
            [':u' => $uid]
        ),
        'items' => array_map('qly_notification_public', $rows),
    ]);
}

/** 标记已读：传 id 标单条；不传 id（或 all=1）标记全部 */
function qly_api_notify_read(): void
{
    $user = qly_auth_user();
    $uid = (int) $user['id'];
    $all = (int) qly_input('all', 0) === 1;
    $id = qly_input_int('id', 0);
    if ($all || $id <= 0) {
        qly_exec('UPDATE `notification` SET is_read = 1 WHERE user_id = :u AND is_read = 0', [':u' => $uid]);
        qly_ok(null, '已全部标记为已读');
    }
    qly_exec('UPDATE `notification` SET is_read = 1 WHERE user_id = :u AND id = :i', [':u' => $uid, ':i' => $id]);
    qly_ok(null, '已标记为已读');
}
