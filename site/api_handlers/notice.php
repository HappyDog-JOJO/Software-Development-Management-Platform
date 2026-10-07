<?php
declare(strict_types=1);

/**
 * 公告。
 * - 读取（最新一条启用中的公告）：所有登录用户可读（问题提交页灯牌展示）
 * - 维护（列表 / 新增 / 编辑 / 启停 / 删除）：仅管理员
 */

/** 公告行 → 前端结构 */
function qly_notice_public(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'title' => (string) $r['title'],
        'content' => (string) $r['content'],
        'status' => (int) $r['status'],
        'publisherId' => (int) $r['publisher_id'],
        'publisherName' => (string) $r['publisher_name'],
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => (string) $r['updated_at'],
    ];
}

/**
 * 最新一条启用中的公告（所有登录用户可读）。
 * 没公告时返回 null，前端据此不展示灯牌。
 */
function qly_api_notice_latest(): void
{
    qly_auth_user();
    $row = qly_one(
        'SELECT * FROM `notice` WHERE status = 1 ORDER BY id DESC LIMIT 1'
    );
    qly_ok([
        'notice' => $row ? qly_notice_public($row) : null,
    ]);
}

/** 公告列表（仅管理员） */
function qly_api_notice_list(): void
{
    qly_admin_user();
    $page = max(1, qly_input_int('page', 1));
    $pageSize = min(200, max(1, qly_input_int('pageSize', 10)));
    $offset = ($page - 1) * $pageSize;

    $keyword = trim((string) qly_input('keyword', ''));
    $where = ' WHERE 1=1';
    $params = [];
    if ($keyword !== '') {
        $where .= ' AND (title LIKE :kw OR content LIKE :kw)';
        $params[':kw'] = '%' . $keyword . '%';
    }

    $total = (int) qly_val('SELECT COUNT(*) FROM `notice`' . $where, $params);
    $rows = qly_all(
        'SELECT * FROM `notice`' . $where . ' ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
        $params
    );

    qly_ok([
        'items' => array_map('qly_notice_public', $rows),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
    ]);
}

/** 新增/编辑公告（仅管理员） */
function qly_api_notice_save(): void
{
    $user = qly_admin_user();
    $id = qly_input_int('id', 0);
    $title = trim((string) qly_input('title', ''));
    $content = trim((string) qly_input('content', ''));
    $status = (int) qly_input('status', 1) === 0 ? 0 : 1;

    if ($content === '') {
        qly_fail('请输入公告内容');
    }
    if (mb_strlen($content) > 2000) {
        qly_fail('公告内容请控制在 2000 字以内');
    }
    if (mb_strlen($title) > 60) {
        qly_fail('公告标题请控制在 60 字以内');
    }

    $now = date('Y-m-d H:i:s');
    if ($id > 0) {
        $row = qly_one('SELECT * FROM `notice` WHERE id = :id', [':id' => $id]);
        if (!$row) {
            qly_fail('公告不存在');
        }
        qly_update('notice', $id, [
            'title' => $title,
            'content' => $content,
            'status' => $status,
            'updated_at' => $now,
        ]);
        qly_log($user, '公告管理', (string) $id, '修改公告：' . ($title !== '' ? $title : mb_substr($content, 0, 30)));
        $fresh = qly_one('SELECT * FROM `notice` WHERE id = :id', [':id' => $id]);
        qly_ok(qly_notice_public($fresh), '公告已更新');
        return;
    }

    $newId = qly_insert('notice', [
        'title' => $title,
        'content' => $content,
        'status' => $status,
        'publisher_id' => (int) $user['id'],
        'publisher_name' => $user['name'] !== '' && $user['name'] !== null ? (string) $user['name'] : (string) $user['account'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    qly_log($user, '公告管理', (string) $newId, '发布公告：' . ($title !== '' ? $title : mb_substr($content, 0, 30)));
    // 新公告通知全部启用账号（发布人自己不用收到）
    if ($status === 1) {
        qly_notify(
            qly_notify_all_user_ids(),
            '新公告：' . ($title !== '' ? $title : '请查看'),
            mb_substr($content, 0, 120),
            '/issues/create',
            (int) $user['id']
        );
    }
    $fresh = qly_one('SELECT * FROM `notice` WHERE id = :id', [':id' => $newId]);
    qly_ok(qly_notice_public($fresh), '公告已发布');
}

/** 启用/停用公告（仅管理员） */
function qly_api_notice_status(): void
{
    $user = qly_admin_user();
    $id = qly_input_int('id', 0);
    $status = (int) qly_input('status', 1) === 0 ? 0 : 1;
    $row = qly_one('SELECT * FROM `notice` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('公告不存在');
    }
    qly_update('notice', $id, ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    qly_log($user, '公告管理', (string) $id, ($status === 1 ? '启用' : '停用') . '公告：'
        . ($row['title'] !== '' ? $row['title'] : mb_substr((string) $row['content'], 0, 30)));
    qly_ok(null, $status === 1 ? '公告已启用' : '公告已停用');
}

/** 删除公告（仅管理员） */
function qly_api_notice_delete(): void
{
    $user = qly_admin_user();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `notice` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('公告不存在');
    }
    qly_exec('DELETE FROM `notice` WHERE id = :id', [':id' => $id]);
    qly_log($user, '公告管理', (string) $id, '删除公告：'
        . ($row['title'] !== '' ? $row['title'] : mb_substr((string) $row['content'], 0, 30)));
    qly_ok(null, '公告已删除');
}
