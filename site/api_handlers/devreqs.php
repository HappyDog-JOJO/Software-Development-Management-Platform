<?php
declare(strict_types=1);

/**
 * 需求管理模块：需求研发流接口。
 * 流程：需求提交 → 评审池（评审委员，驳回即作废）→ 需求池（产品经理认领）→
 *       设计中心（设计文档/分支/开发负责人）→ 开发中心（开发负责人提交开发完成）→
 *       待发布 → 发布上线（已上线，终态）。
 * 可见范围：评审委员/管理员全量；产品经理可见需求池与自己认领的；
 *           开发负责人/提交人可见自己的。
 * 方案见 docs/需求管理模块-需求方案.md。
 */

/** 需求编号：XQ + 年月日时分秒 + 3 位随机 */
function qly_devreq_no(): string
{
    return 'XQ' . date('YmdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
}

/** 主任务允许的优先级（低/中/高/紧急） */
function qly_devreq_priorities(): array
{
    return ['低', '中', '高', '紧急'];
}

/** 主任务状态集合（与前端 DevStatus 常量保持一致） */
function qly_devreq_statuses(): array
{
    return ['评审池', '需求池', '设计中心', '开发中心', '待发布', '已上线', '已驳回', '已取消'];
}

/** 附件落库（scene：submit提交/design设计/reject驳回/done完成/release上线） */
function qly_devreq_save_attachments(int $requestId, string $scene, $attachments, int $uploaderId): int
{
    $n = 0;
    if (!is_array($attachments)) {
        return 0;
    }
    foreach ($attachments as $a) {
        if (!is_array($a) || empty($a['url'])) {
            continue;
        }
        qly_insert('dev_request_attachment', [
            'request_id' => $requestId,
            'scene' => $scene,
            'url' => (string) $a['url'],
            'name' => (string) ($a['name'] ?? ''),
            'size' => (int) ($a['size'] ?? 0),
            'mime_type' => (string) ($a['mime_type'] ?? ($a['mimeType'] ?? '')),
            'uploader_id' => $uploaderId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $n++;
    }
    return $n;
}

/** 附件读出 → 前端结构 */
function qly_devreq_attachments_public(array $rows): array
{
    return array_map(fn(array $a): array => [
        'id' => (int) $a['id'],
        'requestId' => (int) $a['request_id'],
        'scene' => (string) $a['scene'],
        'url' => $a['url'],
        'name' => $a['name'],
        'size' => (int) $a['size'],
        'mimeType' => $a['mime_type'],
        'createdAt' => $a['created_at'],
    ], $rows);
}

/** 主任务行 → 前端结构 */
function qly_devreq_row_public(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'reqNo' => $r['req_no'],
        'title' => $r['title'],
        'description' => $r['description'],
        'moduleId' => $r['module_id'] !== null ? (int) $r['module_id'] : null,
        'moduleName' => $r['module_name'] ?? null,
        'priority' => (string) $r['priority'],
        'status' => (string) $r['status'],
        'submitterId' => (int) $r['submitter_id'],
        'submitterName' => $r['submitter_name'],
        'submitterAccount' => $r['submitter_account'],
        'submitterDepartmentName' => $r['department_name'] ?? null,
        'reviewerName' => $r['reviewer_name'],
        'reviewComment' => $r['review_comment'],
        'reviewedAt' => $r['reviewed_at'],
        'reviewPending' => (int) ($r['review_pending'] ?? 0) === 1,
        'pmId' => $r['pm_id'] !== null ? (int) $r['pm_id'] : null,
        'pmName' => $r['pm_name'],
        'designDoc' => $r['design_doc'],
        'branch' => $r['branch'],
        'devLeaderId' => $r['dev_leader_id'] !== null ? (int) $r['dev_leader_id'] : null,
        'devLeaderName' => $r['dev_leader_name'],
        'designedAt' => $r['designed_at'],
        'devDoneNote' => $r['dev_done_note'] ?? null,
        'devDoneAt' => $r['dev_done_at'] ?? null,
        'inspectorName' => $r['inspector_name'],
        'inspectResult' => $r['inspect_result'],
        'inspectComment' => $r['inspect_comment'],
        'inspectedAt' => $r['inspected_at'],
        'rejectCount' => (int) $r['reject_count'],
        'releasedAt' => $r['released_at'],
        'releasedBy' => $r['released_by'],
        'poolAt' => $r['pool_at'],
        'designAt' => $r['design_at'],
        'battleAt' => $r['battle_at'],
        'inspectAt' => $r['inspect_at'],
        'frontlineAt' => $r['frontline_at'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
    ];
}

/** 列表基础查询（JOIN 部门） */
function qly_devreq_base_select(): string
{
    return ' SELECT i.*, d.name AS department_name';
}

function qly_devreq_base_from(): string
{
    return ' FROM `dev_request` i LEFT JOIN `department` d ON d.id = i.department_id';
}

/** 列表筛选（不含权限范围） */
function qly_devreq_filters(): array
{
    $where = '';
    $params = [];
    $status = (string) qly_input('status', '');
    if ($status !== '') {
        if (!in_array($status, qly_devreq_statuses(), true)) {
            qly_fail('无效的需求状态');
        }
        $where .= ' AND i.status = :st';
        $params[':st'] = $status;
    }
    $priority = (string) qly_input('priority', '');
    if ($priority !== '') {
        $where .= ' AND i.priority = :pr';
        $params[':pr'] = $priority;
    }
    $moduleId = qly_input_int('moduleId', 0);
    if ($moduleId > 0) {
        $where .= ' AND i.module_id = :mid';
        $params[':mid'] = $moduleId;
    }
    if ((string) qly_input('mine', '') === '1') {
        $where .= ' AND i.submitter_id = :mine';
        $params[':mine'] = (int) qly_current_user()['id'];
    }
    $keyword = (string) qly_input('keyword', '');
    if ($keyword !== '') {
        $where .= ' AND (i.req_no LIKE :kw1 OR i.title LIKE :kw2 OR i.description LIKE :kw3)';
        $kw = '%' . $keyword . '%';
        $params[':kw1'] = $kw;
        $params[':kw2'] = $kw;
        $params[':kw3'] = $kw;
    }
    return [$where, $params];
}

function qly_api_devreqs_list(): void
{
    $user = qly_auth_user();
    [$filterWhere, $filterParams] = qly_devreq_filters();
    [$scopeWhere, $scopeParams] = qly_devreq_scope_where($user, 'i');

    $page = max(1, qly_input_int('page', 1));
    $pageSize = min(200, max(1, qly_input_int('pageSize', 10)));
    $offset = ($page - 1) * $pageSize;

    $base = qly_devreq_base_from() . ' WHERE 1=1' . $filterWhere;
    $total = (int) qly_val('SELECT COUNT(*)' . $base . $scopeWhere, array_merge($filterParams, $scopeParams));

    // priority 排序固定为：紧急 > 高 > 中 > 低，同级按提交先后（先到先做）
    if ((string) qly_input('sortField', 'createdAt') === 'priority') {
        $order = "FIELD(i.priority, '紧急', '高', '中', '低')";
        $dir = 'ASC';
    } else {
        $order = 'i.created_at';
        $dir = strtoupper((string) qly_input('sortOrder', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    }

    $rows = qly_all(
        qly_devreq_base_select() . $base . $scopeWhere
        . ' ORDER BY ' . $order . ' ' . $dir . ', i.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
        array_merge($filterParams, $scopeParams)
    );
    qly_ok([
        'items' => array_map('qly_devreq_row_public', $rows),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
    ]);
}

/** 需求详情：主任务 + 需求级附件（按场景）+ 时间线日志 */
function qly_api_devreqs_detail(): void
{
    $user = qly_auth_user();
    $id = qly_input_int('id', 0);
    $row = qly_one(qly_devreq_base_select() . qly_devreq_base_from() . ' WHERE i.id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    if (!qly_devreq_visible($user, $row)) {
        qly_forbidden('该需求不在您的可见范围');
    }
    $data = qly_devreq_row_public($row);

    // 需求级附件（提交/设计/驳回/完成/上线）
    $data['attachments'] = qly_devreq_attachments_public(
        qly_all('SELECT * FROM `dev_request_attachment` WHERE request_id = :rid ORDER BY id ASC', [':rid' => $id])
    );

    // 时间线日志（该需求相关的全部需求流日志）
    $logs = [];
    $types = ['提交需求', '需求评审', '需求设计', '需求开发', '质量审核', '需求发布'];
    $in = [];
    $params = [':t' => '%' . $row['req_no'] . '%'];
    foreach ($types as $k => $t) {
        $in[] = ':lg' . $k;
        $params[':lg' . $k] = $t;
    }
    foreach (qly_all(
        'SELECT * FROM `operation_log` WHERE target LIKE :t AND type IN (' . implode(',', $in) . ') ORDER BY created_at DESC, id DESC LIMIT 100',
        $params
    ) as $l) {
        $logs[] = [
            'id' => (int) $l['id'],
            'account' => $l['account'],
            'role' => $l['role'],
            'type' => $l['type'],
            'content' => $l['content'],
            'createdAt' => $l['created_at'],
        ];
    }
    $data['logs'] = $logs;

    qly_ok($data);
}

/** 提交需求：所有登录用户 → 进入评审池 */
function qly_api_devreqs_create(): void
{
    $user = qly_auth_user();
    $title = (string) qly_input('title', '');
    $description = (string) qly_input('description', '');
    $moduleId = qly_input_int('moduleId', 0);
    $priority = (string) qly_input('priority', '中');
    $attachments = qly_input('attachments', []);

    if (mb_strlen(trim($title)) < 4) {
        qly_fail('需求标题至少 4 个字');
    }
    if (trim($description) === '') {
        qly_fail('请填写需求描述');
    }
    if ($moduleId <= 0) {
        qly_fail('请选择所属模块');
    }
    $module = qly_one('SELECT * FROM `dict_item` WHERE id = :id AND type = :t', [':id' => $moduleId, ':t' => 'module']);
    if (!$module) {
        qly_fail('所属模块不存在');
    }
    if ((int) $module['status'] !== 1) {
        qly_fail('该模块已停用，请选择其他模块');
    }
    if (!in_array($priority, qly_devreq_priorities(), true)) {
        $priority = '中';
    }
    if (is_array($attachments) && count($attachments) > 9) {
        qly_fail('附件最多 9 个');
    }

    $reqNo = qly_devreq_no();
    $now = date('Y-m-d H:i:s');
    $id = qly_insert('dev_request', [
        'req_no' => $reqNo,
        'title' => trim($title),
        'description' => $description,
        'module_id' => $moduleId,
        'module_name' => (string) $module['name'],
        'priority' => $priority,
        'status' => '评审池',
        'submitter_id' => (int) $user['id'],
        'submitter_account' => (string) $user['account'],
        'submitter_name' => $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'],
        'department_id' => $user['department_id'] !== null ? (int) $user['department_id'] : null,
        'created_at' => $now,
    ]);
    qly_devreq_save_attachments($id, 'submit', $attachments, (int) $user['id']);

    qly_log($user, '提交需求', $reqNo, '提交需求：' . trim($title) . '（模块：' . $module['name'] . '，优先级：' . $priority . '，附件 ' . count((array) $attachments) . ' 个）');
    qly_notify(
        qly_notify_user_ids_by_roles([QLY_ROLE_COMMITTEE, QLY_ROLE_ADMIN]),
        '新需求进入评审池',
        '【' . $module['name'] . '】' . trim($title),
        '/dev/reqs/' . $id,
        (int) $user['id']
    );
    qly_ok(['id' => $id, 'reqNo' => $reqNo], '需求已提交，等待评审委员会评审');
}

/** 修改需求（提交人本人改自己的 / 管理员改全部；仅评审池阶段，评审开始后不可改） */
function qly_api_devreqs_update(): void
{
    $user = qly_auth_user();
    $id = qly_input_int('id', 0);
    $title = trim((string) qly_input('title', ''));
    $description = (string) qly_input('description', '');
    $moduleId = qly_input_int('moduleId', 0);
    $priority = (string) qly_input('priority', '中');
    $attachments = qly_input('attachments', []);

    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    $isAdmin = qly_has_role($user, [QLY_ROLE_ADMIN]);
    if (!$isAdmin && (int) $row['submitter_id'] !== (int) $user['id']) {
        qly_forbidden('仅提交人本人可修改需求');
    }
    if ((string) $row['status'] !== '评审池') {
        qly_fail('需求已进入评审，不可修改');
    }
    if (mb_strlen($title) < 4) {
        qly_fail('需求标题至少 4 个字');
    }
    if (trim($description) === '') {
        qly_fail('请填写需求描述');
    }
    if ($moduleId <= 0) {
        qly_fail('请选择所属模块');
    }
    $module = qly_one('SELECT * FROM `dict_item` WHERE id = :id AND type = :t', [':id' => $moduleId, ':t' => 'module']);
    if (!$module) {
        qly_fail('所属模块不存在');
    }
    if ((int) $module['status'] !== 1) {
        qly_fail('该模块已停用，请选择其他模块');
    }
    if (!in_array($priority, qly_devreq_priorities(), true)) {
        $priority = '中';
    }
    if (is_array($attachments) && count($attachments) > 9) {
        qly_fail('附件最多 9 个');
    }

    $now = date('Y-m-d H:i:s');
    qly_update('dev_request', $id, [
        'title' => $title,
        'description' => $description,
        'module_id' => $moduleId,
        'module_name' => (string) $module['name'],
        'priority' => $priority,
        'updated_at' => $now,
    ]);
    // 提交附件整体替换：仅在请求携带 attachments 数组时执行（回填失败时前端不传，保持原附件不动）
    if (is_array($attachments)) {
        qly_exec('DELETE FROM `dev_request_attachment` WHERE request_id = :rid AND scene = :sc', [':rid' => $id, ':sc' => 'submit']);
        qly_devreq_save_attachments($id, 'submit', $attachments, (int) $user['id']);
    }
    qly_log($user, '提交需求', (string) $row['req_no'], '修改需求：' . $title . '（评审前修改）');
    qly_ok(null, '需求已修改');
}

/** 删除需求（提交人本人删自己的 / 管理员删全部；仅评审池阶段）。连同附件记录与落盘文件一起删除 */
function qly_api_devreqs_delete(): void
{
    $user = qly_auth_user();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    $isAdmin = qly_has_role($user, [QLY_ROLE_ADMIN]);
    if (!$isAdmin && (int) $row['submitter_id'] !== (int) $user['id']) {
        qly_forbidden('仅提交人本人可删除需求');
    }
    if ((string) $row['status'] !== '评审池') {
        qly_fail('需求已进入评审，不可删除');
    }

    // 先收集待删除的上传文件路径（数据库删掉后就查不到了）
    $files = [];
    foreach (qly_all('SELECT url FROM `dev_request_attachment` WHERE request_id = :rid', [':rid' => $id]) as $r) {
        $files[] = (string) $r['url'];
    }

    $pdo = qly_db();
    $pdo->beginTransaction();
    try {
        qly_exec('DELETE FROM `dev_request_attachment` WHERE request_id = :rid', [':rid' => $id]);
        qly_exec('DELETE FROM `dev_request` WHERE id = :id', [':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        qly_fail('删除失败，请重试');
    }

    // 落盘文件清理：只删本站 uploads 目录下的文件，失败不影响主流程（与问题删除同规则）
    $uploadRoot = realpath((string) qly_app_config()['upload_dir']);
    if ($uploadRoot !== false) {
        foreach (array_unique($files) as $url) {
            $rel = parse_url($url, PHP_URL_PATH);
            if (!is_string($rel) || strpos($rel, '/uploads/') !== 0) {
                continue;
            }
            $path = realpath($uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, strlen('/uploads/'))));
            // 路径必须落在 uploads 目录内，防止 ../ 穿越
            if ($path !== false && strpos($path, $uploadRoot) === 0 && is_file($path)) {
                @unlink($path);
            }
        }
    }

    qly_log($user, '提交需求', (string) $row['req_no'], '删除需求（评审前）：' . $row['title']);
    qly_ok(null, '需求已删除');
}

/** 评审：评审委员 通过→需求池 / 驳回→直接作废（终态，不可重提） */
function qly_api_devreqs_review(): void
{
    $user = qly_auth_user();
    if (!qly_can_review_req($user)) {
        qly_forbidden('仅评审委员或管理员可评审需求');
    }
    $id = qly_input_int('id', 0);
    $action = (string) qly_input('action', '');
    $comment = trim((string) qly_input('comment', ''));
    $attachments = qly_input('attachments', []);
    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    if ((string) $row['status'] !== '评审池') {
        qly_fail('该需求不在评审池');
    }
    $name = $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    $now = date('Y-m-d H:i:s');

    if ($action === '通过') {
        qly_update('dev_request', $id, [
            'status' => '需求池',
            'review_pending' => 0,
            'reviewer_id' => (int) $user['id'],
            'reviewer_name' => $name,
            'review_comment' => $comment !== '' ? $comment : null,
            'reviewed_at' => $now,
            'pool_at' => $now,
            'updated_at' => $now,
        ]);
        qly_log($user, '需求评审', (string) $row['req_no'], '评审通过：' . $row['title'] . ($comment !== '' ? '，意见：' . mb_substr($comment, 0, 80) : ''));
        qly_notify([(int) $row['submitter_id']], '您的需求已评审通过', '需求 ' . $row['req_no'] . ' 已进入需求池，等待产品经理认领设计', '/dev/reqs/' . $id, (int) $user['id']);
        qly_notify(
            qly_notify_user_ids_by_roles([QLY_ROLE_PM]),
            '需求池有新任务',
            '【' . $row['module_name'] . '】' . $row['title'],
            '/dev/reqs/' . $id,
            (int) $user['id']
        );
        qly_ok(null, '评审通过，需求已进入需求池');
    }

    if ($action === '待定') {
        if (trim($comment) === '') {
            qly_fail('请填写待定备注');
        }
        // 待定：需求暂留评审池并标记，之后仍可评审通过或驳回
        qly_update('dev_request', $id, [
            'review_pending' => 1,
            'reviewer_id' => (int) $user['id'],
            'reviewer_name' => $name,
            'review_comment' => $comment,
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);
        qly_log($user, '需求评审', (string) $row['req_no'], '评审待定：' . $row['title'] . '，备注：' . mb_substr($comment, 0, 80));
        qly_ok(null, '已标记待定，需求保留在评审池');
    }

    if ($action === '驳回') {
        if (trim($comment) === '') {
            qly_fail('请填写驳回原因');
        }
        // 评审驳回 = 直接作废（终态，不可重提；仍需要则重新提交新需求）
        qly_update('dev_request', $id, [
            'status' => '已驳回',
            'review_pending' => 0,
            'reviewer_id' => (int) $user['id'],
            'reviewer_name' => $name,
            'review_comment' => $comment,
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);
        qly_devreq_save_attachments($id, 'reject', $attachments, (int) $user['id']);
        qly_log($user, '需求评审', (string) $row['req_no'], '评审驳回（作废）：' . $row['title'] . '，原因：' . mb_substr($comment, 0, 80));
        qly_notify([(int) $row['submitter_id']], '您的需求未通过评审', '需求 ' . $row['req_no'] . ' 已作废。驳回原因：' . mb_substr($comment, 0, 80), '/dev/reqs/' . $id, (int) $user['id']);
        qly_ok(null, '已驳回，该需求已作废');
    }

    qly_fail('无效的评审操作');
}

/** 产品经理认领：需求池 → 设计中心 */
function qly_api_devreqs_claim(): void
{
    $user = qly_auth_user();
    if (!qly_can_design_req($user)) {
        qly_forbidden('仅产品经理或管理员可认领需求');
    }
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    if ((string) $row['status'] !== '需求池') {
        qly_fail('该需求不在需求池');
    }
    $name = $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    $now = date('Y-m-d H:i:s');
    qly_update('dev_request', $id, [
        'status' => '设计中心',
        'pm_id' => (int) $user['id'],
        'pm_name' => $name,
        'design_at' => $now,
        'updated_at' => $now,
    ]);
    qly_log($user, '需求设计', (string) $row['req_no'], '产品经理认领，进入设计中心设计：' . $row['title']);
    qly_notify([(int) $row['submitter_id']], '您的需求已进入设计阶段', '产品经理 ' . $name . ' 已认领需求 ' . $row['req_no'], '/dev/reqs/' . $id, (int) $user['id']);
    qly_ok(null, '认领成功，已进入设计中心');
}

/** 设计中心完成设计：设计说明 + 开发分支 + 开发负责人 → 开发中心 */
function qly_api_devreqs_design(): void
{
    $user = qly_auth_user();
    if (!qly_can_design_req($user)) {
        qly_forbidden('仅产品经理或管理员可提交设计');
    }
    $id = qly_input_int('id', 0);
    $designDoc = (string) qly_input('designDoc', '');
    $branch = trim((string) qly_input('branch', ''));
    $devLeaderId = qly_input_int('devLeaderId', 0);
    $attachments = qly_input('attachments', []);
    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    if ((string) $row['status'] !== '设计中心') {
        qly_fail('该需求不在设计中心');
    }
    $isAdmin = qly_has_role($user, [QLY_ROLE_ADMIN]);
    if (!$isAdmin && (int) ($row['pm_id'] ?? 0) !== (int) $user['id']) {
        qly_forbidden('仅认领该需求的产品经理可提交设计');
    }
    if (trim($designDoc) === '') {
        qly_fail('请填写设计说明');
    }
    if ($branch === '') {
        qly_fail('请填写开发分支');
    }
    if (!preg_match('/^[A-Za-z0-9_\-\.\/]{1,100}$/', $branch)) {
        qly_fail('开发分支格式不正确（仅限字母数字 _ - . /）');
    }
    if ($devLeaderId <= 0) {
        qly_fail('请选择开发负责人');
    }
    $leader = qly_one('SELECT * FROM `user` WHERE id = :id AND status = 1', [':id' => $devLeaderId]);
    if (!$leader) {
        qly_fail('开发负责人不存在或已禁用');
    }
    if (!qly_has_role($leader, [QLY_ROLE_DEV])) {
        qly_fail('开发负责人必须拥有「开发工程师」角色');
    }
    if (is_array($attachments) && count($attachments) > 9) {
        qly_fail('附件最多 9 个');
    }

    $name = $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    $leaderName = $leader['name'] !== '' ? (string) $leader['name'] : (string) $leader['account'];
    $now = date('Y-m-d H:i:s');
    qly_update('dev_request', $id, [
        'design_doc' => $designDoc,
        'branch' => $branch,
        'dev_leader_id' => $devLeaderId,
        'dev_leader_name' => $leaderName,
        'designed_at' => $now,
        'status' => '开发中心',
        'battle_at' => $now,
        'updated_at' => $now,
    ]);
    qly_devreq_save_attachments($id, 'design', $attachments, (int) $user['id']);
    qly_log($user, '需求设计', (string) $row['req_no'], '设计完成进入开发中心：' . $row['title'] . '，分支 ' . $branch . '，开发负责人 ' . $leaderName);
    qly_notify([$devLeaderId], '新的开发任务', '【' . $row['title'] . '】分支 ' . $branch . '，请在开发中心完成开发后提交', '/dev/reqs/' . $id, (int) $user['id']);
    qly_ok(null, '设计已提交，任务进入开发中心');
}

/** 开发完成提交（开发负责人或管理员；主任务须在开发中心）→ 进入待发布 */
function qly_api_devreqs_devdone(): void
{
    $user = qly_auth_user();
    $id = qly_input_int('id', 0);
    $doneNote = trim((string) qly_input('doneNote', ''));
    $attachments = qly_input('attachments', []);
    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    $isAdmin = qly_has_role($user, [QLY_ROLE_ADMIN]);
    if (!$isAdmin && (int) ($row['dev_leader_id'] ?? 0) !== (int) $user['id']) {
        qly_forbidden('仅开发负责人或管理员可提交开发完成');
    }
    if ((string) $row['status'] !== '开发中心') {
        qly_fail('该需求不在开发中心');
    }
    if (trim($doneNote) === '') {
        qly_fail('请填写完成说明');
    }
    if (is_array($attachments) && count($attachments) > 9) {
        qly_fail('附件最多 9 个');
    }
    $now = date('Y-m-d H:i:s');
    qly_update('dev_request', $id, [
        'dev_done_note' => $doneNote,
        'dev_done_at' => $now,
        'status' => '待发布',
        'frontline_at' => $now,
        'updated_at' => $now,
    ]);
    qly_devreq_save_attachments($id, 'done', $attachments, (int) $user['id']);
    qly_log($user, '需求开发', (string) $row['req_no'], '开发完成提交，进入待发布：' . $row['title'] . '，说明：' . mb_substr($doneNote, 0, 80));
    $publishers = qly_notify_user_ids_by_roles([QLY_ROLE_COMMITTEE, QLY_ROLE_ADMIN]);
    qly_notify($publishers, '需求开发完成，待发布上线', '【' . $row['title'] . '】开发完成，请评审委员发布上线', '/dev/reqs/' . $id, (int) $user['id']);
    qly_ok(null, '开发完成已提交，需求进入待发布');
}

/** 发布上线：评审委员/管理员 → 已上线（终态） */
function qly_api_devreqs_release(): void
{
    $user = qly_auth_user();
    if (!qly_can_review_req($user)) {
        qly_forbidden('仅评审委员或管理员可发布上线');
    }
    $id = qly_input_int('id', 0);
    $comment = trim((string) qly_input('comment', ''));
    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    if ((string) $row['status'] !== '待发布') {
        qly_fail('该需求不在待发布状态');
    }
    $name = $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    $now = date('Y-m-d H:i:s');
    qly_update('dev_request', $id, [
        'status' => '已上线',
        'released_at' => $now,
        'released_by' => $name,
        'updated_at' => $now,
    ]);
    qly_log($user, '需求发布', (string) $row['req_no'], '发布上线：' . $row['title'] . ($comment !== '' ? '，说明：' . mb_substr($comment, 0, 80) : ''));
    $watchIds = array_values(array_unique(array_filter([
        (int) $row['submitter_id'],
        (int) ($row['pm_id'] ?? 0),
        (int) ($row['dev_leader_id'] ?? 0),
    ])));
    qly_notify($watchIds, '您的需求已上线', '【' . $row['title'] . '】已正式发布上线', '/dev/reqs/' . $id, (int) $user['id']);
    qly_ok(null, '已发布，需求已上线');
}

/** 开发工程师候选（设计中心指定开发负责人用） */
function qly_api_devreqs_devs(): void
{
    qly_auth_user();
    $rows = qly_all(
        "SELECT u.id, u.account, u.name, u.roles, d.name AS department_name
           FROM `user` u LEFT JOIN `department` d ON d.id = u.department_id
          WHERE u.status = 1 AND u.deleted_at IS NULL"
    );
    $items = [];
    foreach ($rows as $r) {
        if (qly_has_role($r, [QLY_ROLE_DEV])) {
            $items[] = [
                'id' => (int) $r['id'],
                'account' => $r['account'],
                'name' => $r['name'] !== '' ? (string) $r['name'] : (string) $r['account'],
                'departmentName' => $r['department_name'] ?? null,
            ];
        }
    }
    usort($items, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    qly_ok($items);
}
