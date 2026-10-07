<?php
declare(strict_types=1);

/**
 * 问题与售后处理接口。
 * 权限过滤在数据层完成：销售员/售后/管理员可见全部；销售经理仅见提交部门 = 本部门。
 * 列表返回 filteredOut = 因权限被过滤掉的条数。
 */

function qly_issue_no(): string
{
    return 'WT' . date('YmdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
}

/** 构造筛选条件（不含权限范围） */
function qly_issue_filters(): array
{
    $where = '';
    $params = [];

    $dateStart = (string) qly_input('dateStart', '');
    $dateEnd = (string) qly_input('dateEnd', '');
    if ($dateStart !== '') {
        $where .= ' AND i.created_at >= :ds';
        $params[':ds'] = $dateStart . ' 00:00:00';
    }
    if ($dateEnd !== '') {
        $where .= ' AND i.created_at <= :de';
        $params[':de'] = $dateEnd . ' 23:59:59';
    }
    $submitter = (string) qly_input('submitter', '');
    if ($submitter !== '') {
        // PDO 原生预处理不允许同名占位符重复，分号命名
        $where .= ' AND (i.submitter_name LIKE :sub1 OR i.submitter_account LIKE :sub2)';
        $sub = '%' . $submitter . '%';
        $params[':sub1'] = $sub;
        $params[':sub2'] = $sub;
    }
    $deptId = qly_input_int('departmentId', 0);
    if ($deptId > 0) {
        $where .= ' AND i.department_id = :did';
        $params[':did'] = $deptId;
    }
    $moduleId = qly_input_int('moduleId', 0);
    if ($moduleId > 0) {
        $where .= ' AND i.module_id = :mid';
        $params[':mid'] = $moduleId;
    }
    $categoryId = qly_input_int('categoryId', 0);
    if ($categoryId > 0) {
        $where .= ' AND i.category_id = :cid';
        $params[':cid'] = $categoryId;
    }
    $status = (string) qly_input('status', '');
    if ($status !== '') {
        $where .= ' AND i.status = :st';
        $params[':st'] = $status;
    }
    $valueId = qly_input_int('valueId', 0);
    if ($valueId > 0) {
        $where .= ' AND i.value_id = :vid';
        $params[':vid'] = $valueId;
    }
    // 处理人：支持按处理人 id 或姓名筛选（值为 "id:姓名"）
    $handler = trim((string) qly_input('handler', ''));
    if ($handler !== '') {
        if (preg_match('/^(\d+):(.*)$/', $handler, $m) === 1) {
            $where .= ' AND i.handler_id = :hid';
            $params[':hid'] = (int) $m[1];
        } else {
            $where .= ' AND i.handler_name = :hname';
            $params[':hname'] = $handler;
        }
    }
    $keyword = (string) qly_input('keyword', '');
    if ($keyword !== '') {
        // PDO 原生预处理不允许同名占位符重复，分号命名
        $where .= ' AND (i.issue_no LIKE :kw1 OR i.description LIKE :kw2)';
        $kw = '%' . $keyword . '%';
        $params[':kw1'] = $kw;
        $params[':kw2'] = $kw;
    }
    return [$where, $params];
}

function qly_issue_row_public(array $r, int $attachCount = 0): array
{
    return [
        'id' => (int) $r['id'],
        'issueNo' => $r['issue_no'],
        'submitterId' => (int) $r['submitter_id'],
        'submitterName' => $r['submitter_name'],
        'submitterAccount' => $r['submitter_account'],
        'submitterDepartmentId' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
        'submitterDepartmentName' => $r['department_name'],
        'moduleId' => $r['module_id'] !== null ? (int) $r['module_id'] : null,
        'moduleName' => $r['module_name'],
        'categoryId' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
        'categoryName' => $r['category_name'] ?? null,
        'description' => $r['description'],
        'status' => $r['status'],
        'valueId' => $r['value_id'] !== null ? (int) $r['value_id'] : null,
        'valueName' => $r['value_name'],
        'attachmentCount' => $attachCount,
        'handlerName' => $r['handler_name'],
        'handledAt' => $r['handled_at'],
        // 最新一条处理记录的备注（备注存在 handle_record 表；问题可多次追加处理，列表展示最新一条）
        'handleRemark' => (string) ($r['handle_remark'] ?? ''),
        'createdAt' => $r['created_at'],
    ];
}

function qly_api_issues_list(): void
{
    $user = qly_auth_user();
    [$filterWhere, $filterParams] = qly_issue_filters();
    [$scopeWhere, $scopeParams] = qly_scope_where($user, 'i');

    $base = ' FROM `issue` i
        LEFT JOIN `department` d ON d.id = i.department_id
        LEFT JOIN `dict_item` m ON m.id = i.module_id
        LEFT JOIN `dict_item` v ON v.id = i.value_id
        LEFT JOIN `dict_item` c ON c.id = i.category_id
        WHERE 1=1' . $filterWhere;

    $scopeSql = ' FROM `issue` i WHERE 1=1' . $filterWhere . $scopeWhere;
    unset($scopeSql); // 已改为按需统计，不再需要单独拼接

    $page = max(1, qly_input_int('page', 1));
    $pageSize = min(200, max(1, qly_input_int('pageSize', 10)));
    $offset = ($page - 1) * $pageSize;

    // 一次查询同时得到「可见总数」与「全量总数」，避免重复 COUNT 往返
    $total = (int) qly_val('SELECT COUNT(*)' . $base . $scopeWhere, array_merge($filterParams, $scopeParams));
    $filteredOut = 0;
    if ($scopeWhere !== '') {
        // 仅当存在权限范围限制时，才需要额外统计被过滤掉的条数
        $allTotal = (int) qly_val('SELECT COUNT(*)' . $base, $filterParams);
        $filteredOut = max(0, $allTotal - $total);
    }

    $sortField = (string) qly_input('sortField', 'createdAt');
    $sortOrder = strtoupper((string) qly_input('sortOrder', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $column = $sortField === 'handledAt' ? 'i.handled_at' : 'i.created_at';

    $rows = qly_all(
        'SELECT i.*, d.name AS department_name, m.name AS module_name, v.name AS value_name, c.name AS category_name,
                (SELECT COUNT(*) FROM `issue_attachment` a WHERE a.issue_id = i.id) AS attachment_count,
                (SELECT hr.remark FROM `handle_record` hr WHERE hr.issue_id = i.id ORDER BY hr.id DESC LIMIT 1) AS handle_remark'
        . $base . $scopeWhere
        . ' ORDER BY ' . $column . ' ' . $sortOrder . ', i.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
        array_merge($filterParams, $scopeParams)
    );

    // 附件数已在主查询里子查询得出，避免逐行再查一次（N+1 会拖慢公网数据库）
    $items = array_map(fn(array $r): array => qly_issue_row_public($r, (int) ($r['attachment_count'] ?? 0)), $rows);
    qly_ok([
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'filteredOut' => $filteredOut,
    ]);
}

function qly_api_issues_detail(): void
{
    $user = qly_auth_user();
    $id = qly_input_int('id', 0);
    $row = qly_one(
        'SELECT i.*, d.name AS department_name, m.name AS module_name, v.name AS value_name, c.name AS category_name
           FROM `issue` i
           LEFT JOIN `department` d ON d.id = i.department_id
           LEFT JOIN `dict_item` m ON m.id = i.module_id
           LEFT JOIN `dict_item` v ON v.id = i.value_id
           LEFT JOIN `dict_item` c ON c.id = i.category_id
          WHERE i.id = :id',
        [':id' => $id]
    );
    if (!$row) {
        qly_fail('问题不存在');
    }
    // 越权拦截：按可见范围（销售员仅自己提交 / 销售经理本部门 / 售后与管理员全量）
    if (!qly_issue_visible($user, $row)) {
        qly_forbidden('该问题不在您的可见范围');
    }
    $attachments = qly_all('SELECT * FROM `issue_attachment` WHERE issue_id = :id ORDER BY id ASC', [':id' => $id]);
    $attOut = array_map(fn(array $a): array => [
        'id' => (int) $a['id'],
        'url' => $a['url'],
        'name' => $a['name'],
        'size' => (int) $a['size'],
        'mimeType' => $a['mime_type'],
    ], $attachments);

    $imports = qly_all('SELECT * FROM `issue_append` WHERE issue_id = :id ORDER BY created_at ASC', [':id' => $id]);
    $appendOut = array_map(fn(array $a): array => [
        'id' => (int) $a['id'],
        'content' => $a['content'],
        'authorName' => $a['author_name'],
        'authorRole' => $a['author_role'],
        'createdAt' => $a['created_at'],
    ], $imports);

    // 操作日志：所有登录用户可见（不再限管理员）。
    // 仅取与该问题相关的日志（提交 + 处理），避免混入无关记录。
    $logs = [];
    $rows = qly_all(
        'SELECT * FROM `operation_log`
          WHERE target LIKE :t AND type IN (:t1, :t2)
          ORDER BY created_at DESC, id DESC LIMIT 50',
        [':t' => '%' . $row['issue_no'] . '%', ':t1' => '提交问题', ':t2' => '问题处理']
    );
    foreach ($rows as $l) {
        $logs[] = [
            'id' => (int) $l['id'],
            'account' => $l['account'],
            'role' => $l['role'],
            'type' => $l['type'],
            'content' => $l['content'],
            'createdAt' => $l['created_at'],
        ];
    }

    $data = qly_issue_row_public($row, count($attOut));
    $data['attachments'] = $attOut;
    $data['appends'] = $appendOut;
    $data['logs'] = $logs;
    qly_ok($data);
}

function qly_api_issues_handlers(): void
{
    // 处理人下拉数据：取当前可见范围内、已有处理记录的问题处理人（按处理人聚合）
    $user = qly_auth_user();
    [$filterWhere, $filterParams] = qly_issue_filters();
    unset($filterWhere, $filterParams); // 处理人选项不受其它筛选影响，仅按权限范围
    [$scopeWhere, $scopeParams] = qly_scope_where($user, 'i');
    $rows = qly_all(
        "SELECT i.handler_id AS id, i.handler_name AS name, COUNT(*) AS cnt
           FROM `issue` i
          WHERE i.handler_name IS NOT NULL AND i.handler_name <> ''" . $scopeWhere
        . ' GROUP BY i.handler_id, i.handler_name ORDER BY cnt DESC, name ASC',
        $scopeParams
    );
    $items = array_map(fn(array $r): array => [
        'id' => $r['id'] !== null ? (int) $r['id'] : 0,
        'name' => (string) $r['name'],
        'count' => (int) $r['cnt'],
    ], $rows);
    qly_ok($items);
}

/** 管理员在列表直接修改问题状态（不影响处理人/处理时间等其它字段） */
function qly_api_issues_status(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $status = trim((string) qly_input('status', ''));
    $allowed = ['待处理', '处理中', '已处理', '未处理', '已驳回'];
    if (!in_array($status, $allowed, true)) {
        qly_fail('无效的问题状态');
    }
    $row = qly_one('SELECT id, issue_no, status FROM `issue` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('问题不存在');
    }
    if ((string) $row['status'] === $status) {
        qly_ok(null, '状态未变化');
    }
    qly_update('issue', $id, ['status' => $status]);
    qly_log($admin, '问题处理', (string) $row['issue_no'], '修改问题状态：' . $row['status'] . ' → ' . $status);
    qly_ok(null, '状态已更新');
}

function qly_api_issues_create(): void
{
    $user = qly_auth_user();
    $description = (string) qly_input('description', '');
    $moduleId = qly_input_int('moduleId', 0);
    $attachments = qly_input('attachments', []);

    if (trim($description) === '') {
        qly_fail('请填写问题描述');
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
    if (empty($user['department_id'])) {
        qly_fail('请联系管理员分配部门');
    }
    if (is_array($attachments) && count($attachments) > 9) {
        qly_fail('附件最多 9 个');
    }

    $issueNo = qly_issue_no();
    $id = qly_insert('issue', [
        'issue_no' => $issueNo,
        'submitter_id' => (int) $user['id'],
        'submitter_account' => (string) $user['account'],
        'submitter_name' => $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'],
        'department_id' => (int) $user['department_id'],
        'module_id' => $moduleId,
        'category_id' => null,
        'category_name' => null,
        'description' => $description,
        'status' => '待处理',
        'value_id' => null,
        'handler_id' => null,
        'handler_name' => null,
        'handled_at' => null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    if (is_array($attachments)) {
        foreach ($attachments as $a) {
            if (!is_array($a) || empty($a['url'])) {
                continue;
            }
            qly_insert('issue_attachment', [
                'issue_id' => $id,
                'url' => (string) $a['url'],
                'name' => (string) ($a['name'] ?? ''),
                'size' => (int) ($a['size'] ?? 0),
                'mime_type' => (string) ($a['mimeType'] ?? ''),
                'uploader_id' => (int) $user['id'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    qly_log($user, '提交问题', $issueNo, '提交问题：' . mb_substr($description, 0, 100) . '（模块：' . $module['name'] . '，附件 ' . count((array) $attachments) . ' 个）');
    // 通知所有能处理售后的人（售后服务人员 + 管理员）；提交人自己不用收到
    qly_notify(
        qly_notify_user_ids_by_roles([QLY_ROLE_SERVICE, QLY_ROLE_ADMIN]),
        '新售后问题待处理',
        '【' . $module['name'] . '】' . mb_substr($description, 0, 80),
        '/issues/' . $id,
        (int) $user['id']
    );
    qly_ok(['id' => $id, 'issueNo' => $issueNo], '已保存，问题已提交给售后同学');
}

function qly_api_issues_handle(): void
{
    $user = qly_auth_user();
    if (!qly_can_handle($user)) {
        qly_forbidden('仅售后服务人员或管理员可处理问题');
    }
    $id = qly_input_int('id', 0);
    $handleStatusId = qly_input_int('handleStatusId', 0);
    $categoryId = qly_input_int('categoryId', 0);
    $remark = (string) qly_input('remark', '');
    $valueId = qly_input_int('valueId', 0);
    $attachments = qly_input('attachments', []);

    $issue = qly_one('SELECT * FROM `issue` WHERE id = :id', [':id' => $id]);
    if (!$issue) {
        qly_fail('问题不存在');
    }
    if (trim($remark) === '') {
        qly_fail('请填写处理备注');
    }
    if ($categoryId <= 0) {
        qly_fail('请选择问题分类');
    }
    $category = qly_one('SELECT * FROM `dict_item` WHERE id = :id AND type = :t', [':id' => $categoryId, ':t' => 'category']);
    if (!$category) {
        qly_fail('问题分类不存在');
    }
    if ((int) $category['status'] !== 1) {
        qly_fail('该问题分类已停用，请重新选择');
    }
    if ($valueId <= 0) {
        qly_fail('请选择问题价值');
    }
    $value = qly_one('SELECT * FROM `dict_item` WHERE id = :id AND type = :t', [':id' => $valueId, ':t' => 'value']);
    if (!$value) {
        qly_fail('问题价值不存在');
    }
    if ($handleStatusId <= 0) {
        qly_fail('请选择处理情况');
    }
    $hs = qly_one('SELECT * FROM `dict_item` WHERE id = :id AND type = :t', [':id' => $handleStatusId, ':t' => 'handle']);
    if (!$hs) {
        qly_fail('处理情况不存在');
    }

    $recordId = qly_insert('handle_record', [
        'issue_id' => $id,
        'handle_status_id' => $handleStatusId,
        'handle_status_name' => (string) $hs['name'],
        'category_id' => $categoryId,
        'category_name' => (string) $category['name'],
        'remark' => $remark,
        'value_id' => $valueId,
        'value_name' => (string) $value['name'],
        'handler_id' => (int) $user['id'],
        'handler_name' => $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'],
        'handler_role' => implode('、', qly_parse_roles($user['roles'] ?? null)),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    if (is_array($attachments)) {
        foreach ($attachments as $a) {
            if (!is_array($a) || empty($a['url'])) {
                continue;
            }
            qly_insert('handle_attachment', [
                'record_id' => $recordId,
                'issue_id' => $id,
                'url' => (string) $a['url'],
                'name' => (string) ($a['name'] ?? ''),
                'size' => (int) ($a['size'] ?? 0),
                'mime_type' => (string) ($a['mimeType'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
    // 问题状态与所选「处理情况」保持一致（字典为 未处理/处理中/已处理/已驳回）。
    // 注意：早前这里把非「已处理」一律压成「未处理」，导致选「处理中/已驳回」时状态显示错误。
    $status = (string) $hs['name'];
    // 处理人规则：问题的处理人 = 第一次处理的人；后续追加处理不改变处理人。
    $firstHandlerId = $issue['handler_id'] !== null ? (int) $issue['handler_id'] : (int) $user['id'];
    $firstHandlerName = ($issue['handler_name'] !== null && $issue['handler_name'] !== '')
        ? (string) $issue['handler_name']
        : ($user['name'] !== '' ? (string) $user['name'] : (string) $user['account']);
    qly_update('issue', $id, [
        'status' => $status,
        'category_id' => $categoryId,
        'category_name' => (string) $category['name'],
        'value_id' => $valueId,
        'value_name' => (string) $value['name'],
        'handler_id' => $firstHandlerId,
        'handler_name' => $firstHandlerName,
        'handled_at' => date('Y-m-d H:i:s'),
    ]);
    qly_log($user, '问题处理', (string) $issue['issue_no'], '处理问题：处理情况 ' . $hs['name'] . '，问题价值 ' . $value['name'] . '，问题分类 ' . $category['name'] . '，备注：' . mb_substr($remark, 0, 80));
    // 通知问题提出人（处理人自己不用收到自己的处理结果）
    qly_notify(
        [(int) $issue['submitter_id']],
        '您的售后问题已处理',
        '问题 ' . $issue['issue_no'] . ' 处理情况：' . $hs['name'] . '，备注：' . mb_substr($remark, 0, 80),
        '/issues/' . $id,
        (int) $user['id']
    );
    qly_ok(['recordId' => $recordId, 'status' => $status], '处理已提交');
}

function qly_api_issues_handles(): void
{
    $user = qly_auth_user();
    $id = qly_input_int('id', 0);
    $issue = qly_one('SELECT * FROM `issue` WHERE id = :id', [':id' => $id]);
    if (!$issue) {
        qly_fail('问题不存在');
    }
    if (!qly_issue_visible($user, $issue)) {
        qly_forbidden('该问题不在您的可见范围');
    }
    $rows = qly_all('SELECT * FROM `handle_record` WHERE issue_id = :id ORDER BY created_at DESC, id DESC', [':id' => $id]);
    $items = [];
    foreach ($rows as $r) {
        $atts = qly_all('SELECT * FROM `handle_attachment` WHERE record_id = :rid ORDER BY id ASC', [':rid' => (int) $r['id']]);
        $items[] = [
            'id' => (int) $r['id'],
            'issueId' => (int) $r['issue_id'],
            'handleStatusId' => (int) $r['handle_status_id'],
            'handleStatusName' => $r['handle_status_name'],
            'categoryId' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
            'categoryName' => $r['category_name'] ?? null,
            'remark' => $r['remark'],
            'valueId' => (int) $r['value_id'],
            'valueName' => $r['value_name'],
            'handlerId' => (int) $r['handler_id'],
            'handlerName' => $r['handler_name'],
            'handlerRole' => $r['handler_role'],
            'createdAt' => $r['created_at'],
            'attachments' => array_map(fn(array $a): array => [
                'id' => (int) $a['id'],
                'url' => $a['url'],
                'name' => $a['name'],
                'size' => (int) $a['size'],
                'mimeType' => $a['mime_type'],
            ], $atts),
        ];
    }
    qly_ok($items);
}

function qly_api_issues_append(): void
{
    $user = qly_auth_user();
    $id = qly_input_int('id', 0);
    $content = (string) qly_input('content', '');
    if (trim($content) === '') {
        qly_fail('请填写补充说明');
    }
    $issue = qly_one('SELECT * FROM `issue` WHERE id = :id', [':id' => $id]);
    if (!$issue) {
        qly_fail('问题不存在');
    }
    // 越权拦截：按可见范围（销售员仅自己提交的问题）
    if (!qly_issue_visible($user, $issue)) {
        qly_forbidden('该问题不在您的可见范围');
    }
    qly_insert('issue_append', [
        'issue_id' => $id,
        'content' => $content,
        'author_id' => (int) $user['id'],
        'author_name' => $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'],
        'author_role' => implode('、', qly_parse_roles($user['roles'] ?? null)),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    qly_log($user, '提交问题', (string) $issue['issue_no'], '追加补充说明：' . mb_substr($content, 0, 80));
    // 售后/管理员追加说明属于「处理」的后续跟进 → 提醒问题提出人
    if (qly_can_handle($user)) {
        qly_notify(
            [(int) $issue['submitter_id']],
            '您的售后问题有新的处理说明',
            '问题 ' . $issue['issue_no'] . '：' . mb_substr($content, 0, 80),
            '/issues/' . $id,
            (int) $user['id']
        );
    }
    qly_ok(null, '补充说明已提交');
}

/**
 * 删除问题（仅管理员）。
 * 物理删除问题本体及其全部关联数据（附件 / 补充说明 / 处理记录 / 处理附件），
 * 并清理服务器上的上传文件；日志中保留一条删除记录以便追溯。
 */
function qly_api_issues_delete(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $issue = qly_one('SELECT * FROM `issue` WHERE id = :id', [':id' => $id]);
    if (!$issue) {
        qly_fail('问题不存在');
    }

    // 先收集待删除的上传文件路径（数据库删掉后就查不到了）
    $files = [];
    foreach (qly_all('SELECT url FROM `issue_attachment` WHERE issue_id = :i', [':i' => $id]) as $r) {
        $files[] = (string) $r['url'];
    }
    foreach (qly_all('SELECT url FROM `handle_attachment` WHERE issue_id = :i', [':i' => $id]) as $r) {
        $files[] = (string) $r['url'];
    }

    $pdo = qly_db();
    $pdo->beginTransaction();
    try {
        qly_exec('DELETE FROM `handle_attachment` WHERE issue_id = :i', [':i' => $id]);
        qly_exec('DELETE FROM `handle_record` WHERE issue_id = :i', [':i' => $id]);
        qly_exec('DELETE FROM `issue_attachment` WHERE issue_id = :i', [':i' => $id]);
        qly_exec('DELETE FROM `issue_append` WHERE issue_id = :i', [':i' => $id]);
        qly_exec('DELETE FROM `issue` WHERE id = :i', [':i' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        qly_fail('删除失败，请重试');
    }

    // 落盘文件清理：只删本站 uploads 目录下的文件，失败不影响主流程
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

    qly_log($admin, '问题处理', (string) $issue['issue_no'],
        '删除问题（提交人：' . $issue['submitter_name'] . '，状态：' . $issue['status'] . '）');
    qly_ok(null, '问题已删除');
}
