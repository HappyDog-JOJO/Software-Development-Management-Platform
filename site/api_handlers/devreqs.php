<?php
declare(strict_types=1);

/**
 * 需求管理模块：需求研发流接口。
 * 流程：需求提交 → 评审池（评审委员，驳回即作废）→ 需求池（开发工程师点「开发」认领）→
 *       开发中心（发布上线）→ 已上线（终态）。
 * （原「设计中心」环节已移除：需求池不再经产品经理认领设计，而是由开发工程师“开发”直接进入开发中心；
 *   开发中心也不再展示设计文档/设计人/负责人字段。）
 * 可见范围：评审委员/管理员全量；开发工程师可见需求池（待开发）与开发中心；提交人可见自己的。
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
    return ['评审池', '需求池', '开发中心', '待发布', '已上线', '已驳回', '已取消'];
}

/** 附件落库（scene：submit提交/reject驳回/done完成/release上线） */
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
        'submitterPhone' => $r['submitter_phone'] ?? null,
        'reviewerName' => $r['reviewer_name'],
        'reviewComment' => $r['review_comment'],
        'reviewedAt' => $r['reviewed_at'],
        'reviewPending' => (int) ($r['review_pending'] ?? 0) === 1,
        'devDoneNote' => $r['dev_done_note'] ?? null,
        'devDoneAt' => $r['dev_done_at'] ?? null,
        'releasedAt' => $r['released_at'],
        'releasedBy' => $r['released_by'],
        'pingcodeTaskId' => $r['pingcode_task_id'] ?? null,
        'pingcodeTaskNo' => $r['pingcode_task_no'] ?? null,
        'pingcodeTaskUrl' => $r['pingcode_task_url'] ?? null,
        'pingcodeSyncedAt' => $r['pingcode_synced_at'] ?? null,
        'assigneeId' => $r['assignee_id'] ?? null,
        'assigneeName' => $r['assignee_name'] ?? null,
        'poolAt' => $r['pool_at'],
        'designAt' => $r['design_at'],
        'battleAt' => $r['battle_at'],
        'frontlineAt' => $r['frontline_at'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
    ];
}

/** 列表基础查询（JOIN 部门 + 提交人电话） */
function qly_devreq_base_select(): string
{
    return ' SELECT i.*, d.name AS department_name, su.phone AS submitter_phone';
}

function qly_devreq_base_from(): string
{
    return ' FROM `dev_request` i LEFT JOIN `department` d ON d.id = i.department_id'
        . ' LEFT JOIN `user` su ON su.id = i.submitter_id';
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
    // 隐藏评审待定的需求（我的需求/评审池开关，默认开启）
    if ((string) qly_input('hidePending', '') === '1') {
        $where .= ' AND i.review_pending = 0';
    }
    // 上线时间范围筛选（发布上线记录页）
    $releasedStart = (string) qly_input('releasedStart', '');
    if ($releasedStart !== '') {
        $where .= ' AND i.released_at >= :rls';
        $params[':rls'] = $releasedStart . ' 00:00:00';
    }
    $releasedEnd = (string) qly_input('releasedEnd', '');
    if ($releasedEnd !== '') {
        $where .= ' AND i.released_at <= :rle';
        $params[':rle'] = $releasedEnd . ' 23:59:59';
    }
    $keyword = (string) qly_input('keyword', '');
    if ($keyword !== '') {
        $where .= ' AND (i.req_no LIKE :kw1 OR i.title LIKE :kw2 OR i.description LIKE :kw3)';
        $kw = '%' . $keyword . '%';
        $params[':kw1'] = $kw;
        $params[':kw2'] = $kw;
        $params[':kw3'] = $kw;
    }
    // 负责人筛选（PingCode 成员 id）
    $assigneeId = (string) qly_input('assigneeId', '');
    if ($assigneeId !== '') {
        $where .= ' AND i.assignee_id = :asg';
        $params[':asg'] = $assigneeId;
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
    $sortField = (string) qly_input('sortField', 'createdAt');
    if ($sortField === 'priority') {
        $order = "FIELD(i.priority, '紧急', '高', '中', '低')";
        $dir = 'ASC';
    } elseif ($sortField === 'releasedAt') {
        // 发布上线记录页：按上线时间倒序（最近上线在前）
        $order = 'i.released_at';
        $dir = strtoupper((string) qly_input('sortOrder', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
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

    // 需求级附件（提交/驳回/完成/上线）
    $data['attachments'] = qly_devreq_attachments_public(
        qly_all('SELECT * FROM `dev_request_attachment` WHERE request_id = :rid ORDER BY id ASC', [':rid' => $id])
    );

    // 时间线日志（该需求相关的全部需求流日志）
    $logs = [];
    $types = ['提交需求', '需求评审', '需求开发', '需求发布', '需求撤回'];
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

/** 提交需求：所有登录用户 → 进入评审池（优先级提交时不选，统一默认「中」，后续由评审委员/产品经理在列表调整） */
function qly_api_devreqs_create(): void
{
    $user = qly_auth_user();
    $title = (string) qly_input('title', '');
    $description = (string) qly_input('description', '');
    $moduleId = qly_input_int('moduleId', 0);
    // 提交阶段不选优先级：固定默认「中」（传入值忽略，防止旧前端或接口直调绕过）
    $priority = '中';
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
    // 优先级：修改表单已不含该字段；仅当显式传入合法值时才更新，否则保留列表内已调整的值
    $hasPriority = array_key_exists('priority', (array) qly_body());
    $priority = $hasPriority ? (string) qly_input('priority', '') : '';
    if ($hasPriority && !in_array($priority, qly_devreq_priorities(), true)) {
        qly_fail('无效的优先级');
    }
    if (is_array($attachments) && count($attachments) > 9) {
        qly_fail('附件最多 9 个');
    }

    $now = date('Y-m-d H:i:s');
    $update = [
        'title' => $title,
        'description' => $description,
        'module_id' => $moduleId,
        'module_name' => (string) $module['name'],
        'updated_at' => $now,
    ];
    if ($hasPriority) {
        $update['priority'] = $priority;
    }
    qly_update('dev_request', $id, $update);
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
        qly_notify([(int) $row['submitter_id']], '您的需求已评审通过', '需求 ' . $row['req_no'] . ' 已进入需求池，等待开发工程师认领开发', '/dev/reqs/' . $id, (int) $user['id']);
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

/**
 * 修改需求优先级（评审委员 / 产品经理 / 管理员；各列表行内直接调整）。
 * 说明：提交需求时不选优先级（默认「中」），优先级由上述角色按实际情况调整；
 * 任意环节均可调整（含已上线，用于事后修正登记），操作写入流转日志。
 */
function qly_api_devreqs_priority(): void
{
    $user = qly_auth_user();
    $roles = qly_parse_roles(isset($user['roles']) ? (string) $user['roles'] : null);
    $allowed = [QLY_ROLE_ADMIN, QLY_ROLE_COMMITTEE, QLY_ROLE_PM];
    if (count(array_intersect($roles, $allowed)) === 0) {
        qly_forbidden('仅评审委员、产品经理或管理员可调整优先级');
    }
    $id = qly_input_int('id', 0);
    $priority = (string) qly_input('priority', '');
    if (!in_array($priority, qly_devreq_priorities(), true)) {
        qly_fail('无效的优先级');
    }
    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    if ((string) $row['priority'] === $priority) {
        qly_ok(['priority' => $priority], '优先级未变化');
    }
    $now = date('Y-m-d H:i:s');
    qly_update('dev_request', $id, [
        'priority' => $priority,
        'updated_at' => $now,
    ]);
    qly_log($user, '需求评审', (string) $row['req_no'], '调整优先级：' . $row['title'] . '（' . (string) $row['priority'] . ' → ' . $priority . '）');
    qly_ok(['priority' => $priority], '优先级已调整');
}

/** 开发（认领开发）：需求池 → 开发中心（开发工程师 / 管理员点击「开发」直接进入开发中心） */
function qly_api_devreqs_develop(): void
{
    $user = qly_auth_user();
    if (!qly_can_develop_req($user)) {
        qly_forbidden('仅开发工程师或管理员可开发需求');
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
        'status' => '开发中心',
        'battle_at' => $now,
        'updated_at' => $now,
    ]);
    qly_log($user, '需求开发', (string) $row['req_no'], '开发工程师认领开发，进入开发中心：' . $row['title']);
    qly_notify([(int) $row['submitter_id']], '您的需求已进入开发', '开发工程师 ' . $name . ' 已认领需求 ' . $row['req_no'] . '，正在开发中', '/dev/reqs/' . $id, (int) $user['id']);
    qly_ok(null, '开发任务已创建，需求进入开发中心');
}

/** 开发中心发布上线（开发工程师或管理员，支持批量）→ 直接上线（记录上线时间与操作人） */
function qly_api_devreqs_battle_publish(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可发布上线');
    }
    $ids = qly_input('ids');
    if (is_array($ids) && $ids) {
        $targets = array_values(array_unique(array_map('intval', $ids)));
    } else {
        // 兼容单选场景
        $one = qly_input_int('id', 0);
        $targets = $one > 0 ? [$one] : [];
    }
    if (!$targets) {
        qly_fail('请选择要发布的需求');
    }
    $name = $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    $now = date('Y-m-d H:i:s');
    $okCount = 0;
    $skipCount = 0;
    foreach ($targets as $tid) {
        if ($tid <= 0) {
            $skipCount++;
            continue;
        }
        $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $tid]);
        if (!$row || (string) $row['status'] !== '开发中心') {
            $skipCount++;
            continue;
        }
        qly_update('dev_request', $tid, [
            'status' => '已上线',
            'released_at' => $now,
            'released_by' => $name,
            'updated_at' => $now,
        ]);
        qly_log($user, '需求发布', (string) $row['req_no'], '发布上线：' . $row['title'] . '（开发工程师发布）');
        $watchIds = array_values(array_unique(array_filter([
            (int) $row['submitter_id'],
        ])));
        qly_notify($watchIds, '您的需求已上线', '【' . $row['title'] . '】已正式发布上线', '/dev/reqs/' . $tid, (int) $user['id']);
        $okCount++;
    }
    if ($okCount === 0) {
        qly_fail('没有可发布的需求（需处于开发中心）');
    }
    $msg = '已上线 ' . $okCount . ' 条' . ($skipCount > 0 ? '，另有 ' . $skipCount . ' 条已跳过（状态不符）' : '');
    qly_ok(['affected' => $okCount, 'skipped' => $skipCount], $msg);
}

/**
 * 撤回（仅管理员）：把需求的处理进度退回上一步（正向流程退一步；已驳回/已取消为终态不可撤回）。
 * 流转规则：需求池 → 评审池；开发中心 → 需求池；已上线 → 开发中心。
 * 回退时清理该环节产生的登记（评审结果/进入开发/上线登记），保证状态与字段自洽；
 * 退回评审池时同时清除评审待定标记（等待重新评审）。操作写入流转日志并通知相关人。
 * 若该需求已同步到 PingCode（pingcode_task_id 非空），撤回时一并删除对应 PingCode 工作项；
 * 删除失败则中止撤回（保持内外一致），由用户重试或先手动处理 PingCode。
 */
function qly_api_devreqs_withdraw(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_ADMIN])) {
        qly_forbidden('仅管理员可撤回需求');
    }
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `dev_request` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('需求不存在');
    }
    $status = (string) $row['status'];
    // 正向流程退一步（已驳回/已取消/评审池不在映射内，均不可撤回）
    $map = ['需求池' => '评审池', '开发中心' => '需求池', '已上线' => '开发中心'];
    if (!isset($map[$status])) {
        if ($status === '评审池') {
            qly_fail('需求已在评审池，无法再撤回');
        }
        qly_fail('当前状态（' . $status . '）不可撤回');
    }
    $target = $map[$status];
    $name = $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    $now = date('Y-m-d H:i:s');

    // 已同步到 PingCode 时：先删对应工作项（删失败则中止撤回，保持内外一致）
    $pcTaskId = trim((string) ($row['pingcode_task_id'] ?? ''));
    $pcTaskNo = trim((string) ($row['pingcode_task_no'] ?? ''));
    $pingcodeDeleted = false;
    if ($pcTaskId !== '') {
        require_once __DIR__ . '/../inc/pingcode.php';
        if (!qly_pingcode_configured()) {
            qly_fail('该需求已同步到 PingCode，但 PingCode 未配置（缺少 PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID），无法撤回；请先在 .env 补充配置');
        }
        try {
            qly_pingcode_delete_task($pcTaskId);
            $pingcodeDeleted = true;
        } catch (Throwable $e) {
            qly_fail('撤回中止：删除 PingCode 工作项失败（' . ($pcTaskNo !== '' ? $pcTaskNo . '，' : '') . $e->getMessage() . '）。请先在 PingCode 处理或稍后重试，期间需求状态未变更');
        }
    }

    // 按目标环节回退登记字段，保证状态与字段自洽
    $update = [
        'status' => $target,
        'updated_at' => $now,
    ];
    if ($target === '评审池') {
        // 退回评审池：清除本次评审结果与待定标记，等待重新评审
        $update += [
            'reviewer_id' => null,
            'reviewer_name' => null,
            'review_comment' => null,
            'reviewed_at' => null,
            'review_pending' => 0,
            'pool_at' => null,
        ];
    } elseif ($target === '需求池') {
        // 退回需求池：清除进入开发与上线登记
        $update += [
            'battle_at' => null,
            'released_at' => null,
            'released_by' => null,
        ];
    } else {
        // 退回开发中心：清除上线登记
        $update += [
            'released_at' => null,
            'released_by' => null,
        ];
    }
    // 已删除 PingCode 工作项时，一并清除同步记录与负责人（与「未同步」状态一致）
    if ($pingcodeDeleted) {
        $update += [
            'pingcode_task_id' => null,
            'pingcode_task_no' => null,
            'pingcode_task_url' => null,
            'pingcode_synced_at' => null,
            'assignee_id' => null,
            'assignee_name' => null,
        ];
    }
    qly_update('dev_request', $id, $update);

    $logContent = '撤回需求：' . $row['title'] . '（' . $status . ' → ' . $target . '，操作人：' . $name . '）';
    if ($pingcodeDeleted) {
        $logContent .= '；已删除 PingCode 工作项' . ($pcTaskNo !== '' ? '（' . $pcTaskNo . '）' : '');
    } elseif ($pcTaskId !== '') {
        $logContent .= '；PingCode 工作项未删除（已保留同步记录）';
    }
    qly_log($user, '需求撤回', (string) $row['req_no'], $logContent);
    // 通知提交人（进度被退回）与开发工程师（退回后重新出现在待处理环节）
    $submitterIds = array_values(array_filter([(int) $row['submitter_id']]));
    if ($submitterIds) {
        qly_notify($submitterIds, '需求进度已撤回', '需求 ' . $row['req_no'] . ' 的进度已被管理员撤回：' . $status . ' → ' . $target, '/dev/reqs/' . $id, (int) $user['id']);
    }
    qly_notify(
        qly_notify_user_ids_by_roles([QLY_ROLE_DEV]),
        '需求被撤回到待处理环节',
        '【' . ((string) ($row['module_name'] ?? '') !== '' ? (string) $row['module_name'] : '未分类') . '】' . $row['title'] . '（现处于' . $target . '）',
        '/dev/reqs/' . $id,
        (int) $user['id']
    );
    qly_ok(
        ['status' => $target, 'pingcodeDeleted' => $pingcodeDeleted],
        '已撤回到「' . $target . '」' . ($pingcodeDeleted ? '，并删除了 PingCode 工作项' : '')
    );
}

/**
 * PingCode 同步表单选项（开发中心「同步任务」弹窗用；开发工程师/管理员）。
 * 返回：优先级 / 状态 / 项目成员 三组下拉数据；另附未配置时的明确提示。
 */
function qly_api_devreqs_pingcode_options(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可查看同步选项');
    }
    require_once __DIR__ . '/../inc/pingcode.php';
    if (!qly_pingcode_configured()) {
        qly_fail('PingCode 未配置：请在 .env 中补充 PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID');
    }
    $projectRef = qly_pingcode_env('PINGCODE_PROJECT_ID');
    try {
        $projectId = qly_pingcode_resolve_project($projectRef);
        if ($projectId === '') {
            qly_fail('未找到 PingCode 项目「' . $projectRef . '」，请检查 PINGCODE_PROJECT_ID 配置');
        }
        $typeId = qly_pingcode_task_type_id($projectId);
        if ($typeId === '') {
            qly_fail('未在 PingCode 项目「' . $projectRef . '」中找到「史诗」类型');
        }
        $opts = qly_pingcode_task_options($projectId, $typeId);
    } catch (Throwable $e) {
        qly_fail('连接 PingCode 失败：' . $e->getMessage());
    }
    qly_ok([
        'projectName' => $projectRef,
        'typeName' => '史诗',
        'priorities' => $opts['priorities'],
        'states' => $opts['states'],
        'members' => $opts['members'],
    ]);
}

/**
 * 搜索 PingCode 标签库（同步弹窗「标签」远程搜索用；开发工程师/管理员）。
 * GET 参数：keyword（必填，空则返回空数组，避免拉全量）
 * 返回：[['id'=>, 'name'=>], ...]
 */
function qly_api_devreqs_pingcode_tags(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可搜索标签');
    }
    require_once __DIR__ . '/../inc/pingcode.php';
    if (!qly_pingcode_configured()) {
        qly_fail('PingCode 未配置：请在 .env 中补充 PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID');
    }
    $keyword = (string) ($_GET['keyword'] ?? $_GET['name'] ?? '');
    $projectRef = qly_pingcode_env('PINGCODE_PROJECT_ID');
    try {
        $projectId = qly_pingcode_resolve_project($projectRef);
        if ($projectId === '') {
            qly_fail('未找到 PingCode 项目「' . $projectRef . '」，请检查 PINGCODE_PROJECT_ID 配置');
        }
        $tags = qly_pingcode_search_tags($keyword, $projectId);
    } catch (Throwable $e) {
        qly_fail('搜索 PingCode 标签失败：' . $e->getMessage());
    }
    qly_ok($tags);
}

/**
 * 同步任务到 PingCode（开发中心操作；开发工程师/管理员，支持批量）。
 * 在 PingCode「快工单」项目创建「史诗」类型工作项。支持弹窗传入的关键字段：
 * - 单条：fields.title / fields.description（可改），其余同批量
 * - 批量/单条公共：fields.priorityId / assigneeId / stateId / startAt / dueAt / tags[]
 * 标题缺省取值需求标题；描述缺省用「需求描述 + 来源信息（编号/模块）」。
 * 成功后记录任务 ID/编号/链接，重复同步自动跳过（已同步的需求不重复创建）。
 * 字段契约（实测 2026-10-13）：priority_id/assignee_id/state_id 直传；start_at/end_at 传 Unix 秒时间戳；
 *   标签需先 ensure_tag 再 attach_tag。
 */
function qly_api_devreqs_sync_task(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可同步任务');
    }
    require_once __DIR__ . '/../inc/pingcode.php';

    if (!qly_pingcode_configured()) {
        qly_fail('PingCode 未配置：请在 .env 中补充 PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID');
    }
    $ids = qly_input('ids');
    if (is_array($ids) && $ids) {
        $targets = array_values(array_unique(array_map('intval', $ids)));
    } else {
        $one = qly_input_int('id', 0);
        $targets = $one > 0 ? [$one] : [];
    }
    if (!$targets) {
        qly_fail('请选择要同步的需求');
    }
    // 弹窗字段（可选）
    $fields = qly_input('fields', []);
    if (!is_array($fields)) {
        $fields = [];
    }
    $fTitle = trim((string) ($fields['title'] ?? ''));
    $fDesc = (string) ($fields['description'] ?? '');
    $fPriorityId = trim((string) ($fields['priorityId'] ?? ''));
    $fAssigneeId = trim((string) ($fields['assigneeId'] ?? ''));
    $fStateId = trim((string) ($fields['stateId'] ?? ''));
    $fStart = trim((string) ($fields['startAt'] ?? ''));
    $fDue = trim((string) ($fields['dueAt'] ?? ''));
    $fTags = is_array($fields['tags'] ?? null) ? array_values(array_filter(array_map(static function ($t) {
        return trim((string) $t);
    }, $fields['tags']), static function ($t) {
        return $t !== '';
    })) : [];
    $startTs = $fStart !== '' ? (int) strtotime($fStart . ' 00:00:00') : 0;
    $dueTs = $fDue !== '' ? (int) strtotime($fDue . ' 23:59:59') : 0;

    // 项目引用：支持填项目 ID 或项目名称（如「快工单」），自动解析为 ID
    $projectRef = qly_pingcode_env('PINGCODE_PROJECT_ID');
    try {
        $projectId = qly_pingcode_resolve_project($projectRef);
        if ($projectId === '') {
            qly_fail('未找到 PingCode 项目「' . $projectRef . '」，请检查 PINGCODE_PROJECT_ID 配置（可填项目 ID 或项目名称）');
        }
        $typeId = qly_pingcode_task_type_id($projectId);
    } catch (Throwable $e) {
        qly_fail('连接 PingCode 失败：' . $e->getMessage());
    }
    if ($typeId === '') {
        qly_fail('未在 PingCode 项目「' . $projectRef . '」中找到「史诗」类型，请检查项目的工作项类型配置');
    }
    // 负责人姓名（弹窗选的是 PingCode 成员 id）：同步时一并落库，便于列表展示
    $fAssigneeName = '';
    if ($fAssigneeId !== '') {
        try {
            $opts = qly_pingcode_task_options($projectId, $typeId);
            foreach (($opts['members'] ?? []) as $m) {
                if ((string) ($m['id'] ?? '') === $fAssigneeId) {
                    $fAssigneeName = (string) ($m['name'] ?? '');
                    break;
                }
            }
        } catch (Throwable $e) {
            $fAssigneeName = '';
        }
    }
    $single = count($targets) === 1;
    $now = date('Y-m-d H:i:s');
    $okCount = 0;
    $skipCount = 0;
    $errors = [];
    foreach ($targets as $tid) {
        if ($tid <= 0) {
            $skipCount++;
            continue;
        }
        $row = qly_one(qly_devreq_base_select() . qly_devreq_base_from() . ' WHERE i.id = :id', [':id' => $tid]);
        if (!$row || (string) $row['status'] !== '开发中心') {
            $skipCount++;
            continue;
        }
        if ((string) ($row['pingcode_task_id'] ?? '') !== '') {
            // 已同步过：不重复创建
            $skipCount++;
            continue;
        }
        // 标题：单条且弹窗填写时用弹窗值，否则用需求标题
        $title = ($single && $fTitle !== '') ? $fTitle : (string) $row['title'];
        // 描述：单条且弹窗填写时用弹窗值，否则用「需求描述 + 来源信息」
        if ($single && $fDesc !== '') {
            $desc = $fDesc;
        } else {
            $lines = [(string) $row['description']];
            $lines[] = '';
            $lines[] = '—— 由软件开发管理平台同步 ——';
            $lines[] = '需求编号：' . (string) $row['req_no'];
            if ((string) ($row['module_name'] ?? '') !== '') {
                $lines[] = '所属模块：' . (string) $row['module_name'];
            }
            $lines[] = '提交人：' . (string) ($row['submitter_name'] ?? '');
            $lines[] = '提交部门：' . ((string) ($row['department_name'] ?? '') !== '' ? (string) $row['department_name'] : '未分配部门');
            $lines[] = '联系电话：' . ((string) ($row['submitter_phone'] ?? '') !== '' ? (string) $row['submitter_phone'] : '未填写');
            $desc = implode("\n", $lines);
        }
        $extra = [
            'assignee_id' => $fAssigneeId,
            'state_id' => $fStateId,
            'start_at' => $startTs,
            'end_at' => $dueTs,
        ];
        try {
            [$taskId, $taskNo, $taskUrl] = qly_pingcode_create_task($title, $desc, $projectId, $typeId, $fPriorityId, $extra);
            // 标签：逐个建/挂（失败不阻塞主体，记入错误提示）
            foreach ($fTags as $tagName) {
                try {
                    $tagId = qly_pingcode_ensure_tag($tagName, $projectId);
                    if ($tagId !== '') {
                        qly_pingcode_attach_tag($taskId, $tagId);
                    }
                } catch (Throwable $te) {
                    $errors[] = (string) $row['req_no'] . ' 标签「' . $tagName . '」：' . $te->getMessage();
                }
            }
        } catch (Throwable $e) {
            $errors[] = (string) $row['req_no'] . '：' . $e->getMessage();
            $skipCount++;
            continue;
        }
        qly_update('dev_request', $tid, [
            'pingcode_task_id' => $taskId,
            'pingcode_task_no' => $taskNo !== '' ? $taskNo : null,
            'pingcode_task_url' => $taskUrl !== '' ? $taskUrl : null,
            'pingcode_synced_at' => $now,
            'assignee_id' => $fAssigneeId !== '' ? $fAssigneeId : null,
            'assignee_name' => $fAssigneeName !== '' ? $fAssigneeName : null,
            'updated_at' => $now,
        ]);
        qly_log($user, '需求开发', (string) $row['req_no'], '同步到 PingCode：' . $title . ($taskNo !== '' ? '（' . $taskNo . '）' : ''));
        $okCount++;
    }
    if ($okCount === 0) {
        if ($errors) {
            qly_fail('同步失败：' . implode('；', array_slice($errors, 0, 3)));
        }
        qly_fail('没有可同步的需求（需处于开发中心且尚未同步）');
    }
    $msg = '已同步 ' . $okCount . ' 条到 PingCode' . ($skipCount > 0 ? '，另有 ' . $skipCount . ' 条已跳过（状态不符/已同步）' : '');
    if ($errors) {
        $msg .= '；部分标签未生效：' . implode('；', array_slice($errors, 0, 2));
    }
    qly_ok(['affected' => $okCount, 'skipped' => $skipCount], $msg);
}
