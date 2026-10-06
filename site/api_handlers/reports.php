<?php
declare(strict_types=1);

/**
 * 报表统计接口。所有统计在「筛选 + 权限范围」后的数据集上计算。
 * 权限范围：销售经理=本部门，售后/管理员=全部，销售员无权访问。
 */

function qly_report_guard(): array
{
    $user = qly_auth_user();
    if (!qly_can_report($user)) {
        qly_forbidden('您没有报表查看权限');
    }
    return $user;
}

/**
 * 问题价值分档（按分值，不再写死字典名称）。
 * 价值字典现为「1分~5分」，为免以后改名/调档再次失效，统一从
 * 字典名称中解析数字作为分值，并按阈值分档：
 * - 分值 >= 4：有价值
 * - 分值 <= 2：无价值
 * - 中间值（如 3 分）：不计入两边
 * 同时兼容旧名称（很有价值 / 比较有价值 / 毫无价值）。
 * 返回 ['valuable'=>id[], 'worthless'=>id[], 'very'=>id[], 'fairly'=>id[]]
 */
function qly_value_buckets(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $rows = qly_all('SELECT id, name FROM `dict_item` WHERE type = :t', [':t' => 'value']);
    $valuableByScore = []; // score => id[]
    $worthless = [];

    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $name = trim((string) $r['name']);

        // 兼容旧的三档名称
        if ($name === '很有价值') {
            $valuableByScore[5][] = $id;
            continue;
        }
        if ($name === '比较有价值') {
            $valuableByScore[4][] = $id;
            continue;
        }
        if ($name === '毫无价值') {
            $worthless[] = $id;
            continue;
        }

        // 数值型（如「5分」「4 分」「5」）：取名称中的第一个数字作为分值
        if (preg_match('/(\d+(?:\.\d+)?)/', $name, $m)) {
            $score = (float) $m[1];
            if ($score >= 4) {
                $valuableByScore[(int) $score][] = $id;
            } elseif ($score <= 2) {
                $worthless[] = $id;
            }
            // 3 分等中间值不计入两侧
        }
    }

    // 有价值内部再分「最高档 / 次高档」（1-5 分制下即 5 分 / 4 分）
    krsort($valuableByScore);
    $scores = array_keys($valuableByScore);
    $valuable = [];
    foreach ($valuableByScore as $ids) {
        $valuable = array_merge($valuable, $ids);
    }
    $very = isset($scores[0]) ? $valuableByScore[$scores[0]] : [];
    $fairly = isset($scores[1]) ? $valuableByScore[$scores[1]] : [];

    $cache = [
        'valuable' => $valuable,
        'worthless' => $worthless,
        'very' => $very,
        'fairly' => $fairly,
    ];
    return $cache;
}

/** 生成 `v.id IN (...)` 条件；列表为空时返回恒假条件 */
function qly_value_in_sql(array $ids, string $alias = 'v'): string
{
    if (!$ids) {
        return ' AND 1=0';
    }
    return ' AND ' . $alias . '.id IN (' . implode(',', array_map('intval', $ids)) . ')';
}

/** 报表筛选条件（时间范围 + 部门 + 模块） */
function qly_report_filter_sql(array $user, string $alias = 'i'): array
{
    $where = ' WHERE 1=1';
    $params = [];
    $dateStart = (string) qly_input('dateStart', '');
    $dateEnd = (string) qly_input('dateEnd', '');
    if ($dateStart !== '') {
        $where .= ' AND ' . $alias . '.created_at >= :ds';
        $params[':ds'] = $dateStart . ' 00:00:00';
    }
    if ($dateEnd !== '') {
        $where .= ' AND ' . $alias . '.created_at <= :de';
        $params[':de'] = $dateEnd . ' 23:59:59';
    }
    $deptId = qly_input_int('departmentId', 0);
    if ($deptId > 0) {
        $where .= ' AND ' . $alias . '.department_id = :did';
        $params[':did'] = $deptId;
    }
    $moduleId = qly_input_int('moduleId', 0);
    if ($moduleId > 0) {
        $where .= ' AND ' . $alias . '.module_id = :mid';
        $params[':mid'] = $moduleId;
    }
    [$scopeWhere, $scopeParams] = qly_scope_where($user, $alias);
    return [$where . $scopeWhere, array_merge($params, $scopeParams)];
}

function qly_api_reports_overview(): void
{
    $user = qly_report_guard();
    [$where, $params] = qly_report_filter_sql($user, 'i');

    $total = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where, $params);
    // 状态口径与「处理情况」字典一一对应：已处理 / 处理中 / 未处理 / 已驳回；待处理 = 尚无处理记录
    $handled = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where . " AND i.status = '已处理'", $params);
    $processing = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where . " AND i.status = '处理中'", $params);
    $unhandled = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where . " AND i.status = '未处理'", $params);
    $rejected = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where . " AND i.status = '已驳回'", $params);
    $pending = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where . " AND i.status = '待处理'", $params);

    $rated = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where . ' AND i.value_id IS NOT NULL', $params);
    $buckets = qly_value_buckets();
    $valuable = (int) qly_val(
        'SELECT COUNT(*) FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id'
        . $where . qly_value_in_sql($buckets['valuable']),
        $params
    );
    $veryValuable = (int) qly_val(
        'SELECT COUNT(*) FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id'
        . $where . qly_value_in_sql($buckets['very']),
        $params
    );
    $fairlyValuable = (int) qly_val(
        'SELECT COUNT(*) FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id'
        . $where . qly_value_in_sql($buckets['fairly']),
        $params
    );
    $worthless = (int) qly_val(
        'SELECT COUNT(*) FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id'
        . $where . qly_value_in_sql($buckets['worthless']),
        $params
    );

    // 平均处理时长（小时）：提交时间 → 首次处理时间（同一问题多次处理取最早一条），仅统计已有处理记录的问题
    $avg = qly_val(
        'SELECT AVG(TIMESTAMPDIFF(MINUTE, i.created_at, (SELECT MIN(h.created_at) FROM `handle_record` h WHERE h.issue_id = i.id))) / 60 FROM `issue` i'
        . $where . ' AND EXISTS (SELECT 1 FROM `handle_record` h WHERE h.issue_id = i.id)',
        $params
    );

    $valuableRate = $rated > 0 ? round($valuable / $rated * 100, 1) : 0.0;
    // 未完成 = 处理中 + 未处理 + 已驳回 + 待处理（即所有非「已处理」）
    $unhandledRate = $total > 0 ? round(($total - $handled) / $total * 100, 1) : 0.0;

    qly_ok([
        'total' => $total,
        'handled' => $handled,
        'processing' => $processing,
        'unhandled' => $unhandled,
        'rejected' => $rejected,
        'pending' => $pending,
        'rated' => $rated,
        'valuable' => $valuable,
        'veryValuable' => $veryValuable,
        'fairlyValuable' => $fairlyValuable,
        'worthless' => $worthless,
        'valuableRate' => $valuableRate,
        'unhandledRate' => $unhandledRate,
        'avgHandleHours' => $avg !== null ? round((float) $avg, 1) : 0.0,
    ]);
}

function qly_api_reports_ranks(): void
{
    $user = qly_report_guard();
    [$where, $params] = qly_report_filter_sql($user, 'i');

    // 提问问题排行（按提交人）
    $askRows = qly_all(
        'SELECT i.submitter_name AS name, COUNT(*) AS cnt FROM `issue` i' . $where
        . ' GROUP BY i.submitter_name ORDER BY cnt DESC, name ASC LIMIT 10',
        $params
    );
    // 有价值问题排行（按提交人）
    $buckets = qly_value_buckets();
    $valIn = $buckets['valuable'] ? implode(',', array_map('intval', $buckets['valuable'])) : '0';
    $veryIn = $buckets['very'] ? implode(',', array_map('intval', $buckets['very'])) : '0';
    $fairlyIn = $buckets['fairly'] ? implode(',', array_map('intval', $buckets['fairly'])) : '0';
    $valueRows = qly_all(
        'SELECT i.submitter_name AS name,
                SUM(CASE WHEN v.id IN (' . $valIn . ') THEN 1 ELSE 0 END) AS cnt,
                SUM(CASE WHEN v.id IN (' . $veryIn . ') THEN 1 ELSE 0 END) AS veryCnt,
                SUM(CASE WHEN v.id IN (' . $fairlyIn . ') THEN 1 ELSE 0 END) AS fairlyCnt
           FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id' . $where
        . ' GROUP BY i.submitter_name HAVING cnt > 0 ORDER BY cnt DESC, name ASC LIMIT 10',
        $params
    );
    // 无价值问题排行
    $worthlessRows = qly_all(
        'SELECT i.submitter_name AS name, COUNT(*) AS cnt
           FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id' . $where
        . qly_value_in_sql($buckets['worthless'])
        . ' GROUP BY i.submitter_name HAVING cnt > 0 ORDER BY cnt DESC, name ASC LIMIT 10',
        $params
    );
    // 模块问题排行
    $moduleRows = qly_all(
        'SELECT COALESCE(m.name, \'未指定\') AS name, COUNT(*) AS cnt FROM `issue` i LEFT JOIN `dict_item` m ON m.id = i.module_id'
        . $where . ' GROUP BY m.name ORDER BY cnt DESC, name ASC LIMIT 10',
        $params
    );
    // 部门提问排行
    $deptRows = qly_all(
        'SELECT COALESCE(d.name, \'未分配部门\') AS name, COUNT(*) AS cnt FROM `issue` i LEFT JOIN `department` d ON d.id = i.department_id'
        . $where . ' GROUP BY d.name ORDER BY cnt DESC, name ASC LIMIT 10',
        $params
    );
    // 处理量排行（按问题的处理人统计问题数；处理人 = 第一次处理的人，追加处理不变，合计 = 问题总数）
    $handleRows = qly_all(
        "SELECT COALESCE(NULLIF(i.handler_name, ''), '未指定处理人') AS name, COUNT(*) AS cnt
           FROM `issue` i" . $where
        . ' GROUP BY name ORDER BY cnt DESC, name ASC LIMIT 10',
        $params
    );

    $map = fn(array $rows): array => array_map(fn(array $r): array => [
        'name' => (string) $r['name'],
        'value' => (int) $r['cnt'],
        'extra' => isset($r['veryCnt']) ? ['very' => (int) $r['veryCnt'], 'fairly' => (int) $r['fairlyCnt']] : null,
    ], $rows);

    qly_ok([
        'ask' => $map($askRows),
        'valuable' => $map($valueRows),
        'worthless' => $map($worthlessRows),
        'module' => $map($moduleRows),
        'department' => $map($deptRows),
        'handle' => $map($handleRows),
    ]);
}

function qly_api_reports_cross(): void
{
    $user = qly_report_guard();
    [$where, $params] = qly_report_filter_sql($user, 'i');

    // 问题趋势（按日）
    $trendRows = qly_all(
        'SELECT DATE(i.created_at) AS day,
                COUNT(*) AS total,
                SUM(CASE WHEN i.status = \'已处理\' THEN 1 ELSE 0 END) AS handled,
                SUM(CASE WHEN i.status <> \'已处理\' THEN 1 ELSE 0 END) AS unhandled
           FROM `issue` i' . $where . ' GROUP BY DATE(i.created_at) ORDER BY day ASC',
        $params
    );
    $trend = array_map(fn(array $r): array => [
        'date' => (string) $r['day'],
        'total' => (int) $r['total'],
        'handled' => (int) $r['handled'],
        'unhandled' => (int) $r['unhandled'],
    ], $trendRows);

    // 价值分布
    $valueRows = qly_all(
        'SELECT COALESCE(v.name, \'未评级\') AS name, COUNT(*) AS cnt
           FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id' . $where
        . ' GROUP BY v.name ORDER BY cnt DESC',
        $params
    );
    $valueDist = array_map(fn(array $r): array => ['name' => (string) $r['name'], 'value' => (int) $r['cnt']], $valueRows);

    // 部门 × 模块 交叉矩阵
    $matrixRows = qly_all(
        'SELECT COALESCE(d.name, \'未分配部门\') AS dept, COALESCE(m.name, \'未指定\') AS module, COUNT(*) AS cnt
           FROM `issue` i
           LEFT JOIN `department` d ON d.id = i.department_id
           LEFT JOIN `dict_item` m ON m.id = i.module_id' . $where
        . ' GROUP BY d.name, m.name ORDER BY cnt DESC',
        $params
    );
    $departments = [];
    $modules = [];
    $matrix = [];
    foreach ($matrixRows as $r) {
        $dept = (string) $r['dept'];
        $mod = (string) $r['module'];
        if (!in_array($dept, $departments, true)) {
            $departments[] = $dept;
        }
        if (!in_array($mod, $modules, true)) {
            $modules[] = $mod;
        }
        $matrix[] = ['department' => $dept, 'module' => $mod, 'value' => (int) $r['cnt']];
    }

    // 人员画像：提问数 / 有价值占比 / 无价值数
    $buckets = qly_value_buckets();
    $valIn = $buckets['valuable'] ? implode(',', array_map('intval', $buckets['valuable'])) : '0';
    $worIn = $buckets['worthless'] ? implode(',', array_map('intval', $buckets['worthless'])) : '0';
    $profileRows = qly_all(
        'SELECT i.submitter_name AS name,
                COUNT(*) AS total,
                SUM(CASE WHEN v.id IN (' . $valIn . ') THEN 1 ELSE 0 END) AS valuable,
                SUM(CASE WHEN v.id IN (' . $worIn . ') THEN 1 ELSE 0 END) AS worthless
           FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id' . $where
        . ' GROUP BY i.submitter_name ORDER BY total DESC LIMIT 20',
        $params
    );
    $profile = array_map(function (array $r): array {
        $total = (int) $r['total'];
        $valuable = (int) $r['valuable'];
        $worthless = (int) $r['worthless'];
        $rated = $valuable + $worthless;
        return [
            'name' => (string) $r['name'],
            'total' => $total,
            'valuable' => $valuable,
            'worthless' => $worthless,
            'valuableRate' => $rated > 0 ? round($valuable / $rated * 100, 1) : 0.0,
        ];
    }, $profileRows);

    qly_ok([
        'trend' => $trend,
        'valueDist' => $valueDist,
        'departments' => $departments,
        'modules' => $modules,
        'matrix' => $matrix,
        'profile' => $profile,
    ]);
}

/** 从价值名称解析分值（兼容旧名称与「N分」格式）；无法识别返回 null */
function qly_value_score(?string $name): ?float
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }
    if ($name === '很有价值') {
        return 5.0;
    }
    if ($name === '比较有价值') {
        return 4.0;
    }
    if ($name === '毫无价值') {
        return 1.0;
    }
    if (preg_match('/(\d+(?:\.\d+)?)/', $name, $m)) {
        return (float) $m[1];
    }
    return null;
}

/**
 * 值得培训学习的问题：高价值（4-5 分）问题的优质范例。
 * 选取口径：价值分 ≥ 4；**排除 BUG 类型问题**（BUG 属缺陷修复，不作为培训范例）；
 * 已处理的优先，处理备注越详实越靠前（备注可直接作为培训材料）。
 * 排序：价值分降序 → 处理备注长度降序 → 提交时间降序。
 * 另：每条带 training_status（已培训/未培训）。
 */
function qly_api_reports_learning(): void
{
    $user = qly_report_guard();
    [$where, $params] = qly_report_filter_sql($user, 'i');
    $buckets = qly_value_buckets();
    $valIn = $buckets['valuable'] ? implode(',', array_map('intval', $buckets['valuable'])) : '0';

    // 排除 BUG 类型问题（按问题分类名称模糊匹配）
    $whereNoBug = $where . " AND (c.name IS NULL OR c.name NOT LIKE '%BUG%')";

    // 全量计数（不受「培训状态」筛选影响）
    $counts = qly_one(
        'SELECT SUM(i.training_status = 1) AS t, SUM(i.training_status = 0) AS u, COUNT(*) AS c
           FROM `issue` i
           LEFT JOIN `dict_item` c ON c.id = i.category_id
           LEFT JOIN `dict_item` v ON v.id = i.value_id'
        . $whereNoBug . qly_value_in_sql($buckets['valuable']),
        $params
    );

    // 「培训状态」筛选：0=未培训 1=已培训 空=全部
    $trainingFilter = trim((string) qly_input('trainingStatus', ''));
    $whereTraining = '';
    $paramsItems = $params;
    if ($trainingFilter === '0' || $trainingFilter === '1') {
        $whereTraining = ' AND i.training_status = :ts';
        $paramsItems[':ts'] = (int) $trainingFilter;
    }

    // —— 优质范例（高价值问题，不含 BUG）——
    $rows = qly_all(
        'SELECT i.id, i.issue_no, i.submitter_name, i.description, i.status,
                i.handler_name, i.handled_at, i.created_at,
                d.name AS department_name, m.name AS module_name, v.name AS value_name,
                c.name AS category_name, i.training_status,
                (SELECT hr.remark FROM `handle_record` hr WHERE hr.issue_id = i.id ORDER BY hr.id DESC LIMIT 1) AS handle_remark
           FROM `issue` i
           LEFT JOIN `department` d ON d.id = i.department_id
           LEFT JOIN `dict_item` m ON m.id = i.module_id
           LEFT JOIN `dict_item` v ON v.id = i.value_id
           LEFT JOIN `dict_item` c ON c.id = i.category_id'
        . $whereNoBug . $whereTraining . qly_value_in_sql($buckets['valuable'])
        . " ORDER BY i.created_at DESC LIMIT 200",
        $paramsItems
    );

    $examples = array_map(function (array $r): array {
        $remark = (string) ($r['handle_remark'] ?? '');
        return [
            'id' => (int) $r['id'],
            'issueNo' => (string) $r['issue_no'],
            'submitterName' => (string) $r['submitter_name'],
            'submitterDepartmentName' => $r['department_name'] !== null ? (string) $r['department_name'] : null,
            'moduleName' => $r['module_name'] !== null ? (string) $r['module_name'] : null,
            'categoryName' => $r['category_name'] !== null ? (string) $r['category_name'] : null,
            'description' => (string) $r['description'],
            'status' => (string) $r['status'],
            'valueName' => $r['value_name'] !== null ? (string) $r['value_name'] : null,
            'handlerName' => $r['handler_name'] !== null ? (string) $r['handler_name'] : null,
            'handledAt' => $r['handled_at'] !== null ? (string) $r['handled_at'] : null,
            'handleRemark' => $remark,
            'trainingStatus' => (int) ($r['training_status'] ?? 0),
            'createdAt' => (string) $r['created_at'],
        ];
    }, $rows);

    // 排序：分值降序 → 处理备注长度降序 → 提交时间降序
    usort($examples, function (array $a, array $b): int {
        $sa = (float) (qly_value_score($a['valueName']) ?? 0);
        $sb = (float) (qly_value_score($b['valueName']) ?? 0);
        if ($sa !== $sb) {
            return $sb <=> $sa;
        }
        $la = mb_strlen($a['handleRemark']);
        $lb = mb_strlen($b['handleRemark']);
        if ($la !== $lb) {
            return $lb <=> $la;
        }
        return strcmp($b['createdAt'], $a['createdAt']);
    });

    qly_ok([
        'items' => array_slice($examples, 0, 20),
        'total' => count($examples),
        'trained' => (int) ($counts['t'] ?? 0),
        'untrained' => (int) ($counts['u'] ?? 0),
        'all' => (int) ($counts['c'] ?? 0),
    ]);
}

/** 标记/取消标记「已培训」（仅售后人员/管理员） */
function qly_api_reports_training(): void
{
    $user = qly_auth_user();
    if (!qly_can_handle($user)) {
        qly_forbidden('仅售后服务人员或管理员可标记培训状态');
    }
    $id = qly_input_int('id', 0);
    $status = qly_input_int('status', 0) === 1 ? 1 : 0;
    $row = qly_one('SELECT id, issue_no FROM `issue` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('问题不存在');
    }
    qly_update('issue', $id, ['training_status' => $status]);
    qly_log($user, '问题处理', (string) $row['issue_no'], $status === 1 ? '标记为已培训' : '取消已培训标记');
    qly_ok(null, $status === 1 ? '已标记为已培训' : '已取消培训标记');
}

function qly_api_reports_advice(): void
{
    $user = qly_report_guard();
    [$where, $params] = qly_report_filter_sql($user, 'i');

    $total = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where, $params);
    $advice = [];
    if ($total > 0) {
        // 规则 1：某模块问题占比 ≥ 20%
        $rows = qly_all(
            'SELECT COALESCE(m.name, \'未指定\') AS name, COUNT(*) AS cnt, m.id AS mid
               FROM `issue` i LEFT JOIN `dict_item` m ON m.id = i.module_id' . $where
            . ' GROUP BY m.name, m.id ORDER BY cnt DESC',
            $params
        );
        foreach ($rows as $r) {
            $ratio = round((int) $r['cnt'] / $total * 100, 1);
            if ($ratio >= 20) {
                $advice[] = [
                    'id' => 'module-' . (string) ($r['mid'] ?? 0),
                    'level' => 'warning',
                    'title' => '模块「' . $r['name'] . '」问题集中',
                    'conclusion' => '模块「' . $r['name'] . '」问题占比 ' . $ratio . '%，高于 20% 阈值',
                    'evidence' => '该模块 ' . (int) $r['cnt'] . ' 条 / 全部 ' . $total . ' 条',
                    'action' => '建议产品与售后对该模块做专项整改，定位共性问题后形成整改清单',
                    'link' => ['type' => 'issues', 'params' => ['moduleId' => (int) ($r['mid'] ?? 0)]],
                ];
            }
        }

        // 规则 2：某销售无价值占比 ≥ 50% 且提问数 ≥ 5
        $buckets = qly_value_buckets();
        $worIn = $buckets['worthless'] ? implode(',', array_map('intval', $buckets['worthless'])) : '0';
        $rows = qly_all(
            'SELECT i.submitter_name AS name, COUNT(*) AS total,
                    SUM(CASE WHEN v.id IN (' . $worIn . ') THEN 1 ELSE 0 END) AS worthless
               FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id' . $where
            . ' GROUP BY i.submitter_name HAVING total >= 5',
            $params
        );
        foreach ($rows as $r) {
            $t = (int) $r['total'];
            $w = (int) $r['worthless'];
            $ratio = $t > 0 ? round($w / $t * 100, 1) : 0;
            if ($ratio >= 50) {
                $advice[] = [
                    'id' => 'sales-' . (string) $r['name'],
                    'level' => 'warning',
                    'title' => '销售「' . $r['name'] . '」提问质量偏低',
                    'conclusion' => '无价值问题占比 ' . $ratio . '%，提问数 ' . $t . ' 条',
                        'evidence' => '低分（1-2 分）问题 ' . $w . ' 条 / 共 ' . $t . ' 条',
                    'action' => '建议开展提问规范培训，明确问题描述要素与提交前自检清单',
                    'link' => ['type' => 'issues', 'params' => ['submitter' => (string) $r['name']]],
                ];
            }
        }

        // 规则 3：某部门人均提交量 ≥ 全局人均 × 2
        $globalAvg = (float) qly_val(
            'SELECT COUNT(*) / NULLIF((SELECT COUNT(*) FROM `user` u WHERE u.department_id IS NOT NULL AND u.deleted_at IS NULL), 0) FROM `issue` i' . $where,
            $params
        );
        if ($globalAvg > 0) {
            $rows = qly_all(
                'SELECT COALESCE(d.name, \'未分配部门\') AS name, COUNT(*) AS cnt, d.id AS did,
                        (SELECT COUNT(*) FROM `user` u WHERE u.department_id = d.id AND u.deleted_at IS NULL) AS users
                   FROM `issue` i LEFT JOIN `department` d ON d.id = i.department_id' . $where
                . ' GROUP BY d.name, d.id',
                $params
            );
            foreach ($rows as $r) {
                $users = (int) $r['users'];
                if ($users <= 0) {
                    continue;
                }
                $per = (int) $r['cnt'] / $users;
                if ($per >= $globalAvg * 2) {
                    $advice[] = [
                        'id' => 'dept-' . (string) ($r['did'] ?? 0),
                        'level' => 'info',
                        'title' => '部门「' . $r['name'] . '」问题异常集中',
                        'conclusion' => '该部门人均提交 ' . round($per, 1) . ' 条，达到全局人均 ' . round($globalAvg, 1) . ' 条的 2 倍以上',
                        'evidence' => '该部门 ' . (int) $r['cnt'] . ' 条 / ' . $users . ' 人',
                        'action' => '建议排查是否为共性问题或流程缺陷，必要时推动流程优化',
                        'link' => ['type' => 'issues', 'params' => ['departmentId' => (int) ($r['did'] ?? 0)]],
                    ];
                }
            }
        }

        // 规则 4：未处理占比 ≥ 30%
        $pending = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where . " AND i.status <> '已处理'", $params);
        $pendingRatio = $total > 0 ? round($pending / $total * 100, 1) : 0;
        if ($pendingRatio >= 30) {
            $advice[] = [
                'id' => 'backlog',
                'level' => 'error',
                'title' => '售后处理存在积压',
                'conclusion' => '未处理（含待处理）占比 ' . $pendingRatio . '%，超过 30% 阈值',
                'evidence' => '未完成 ' . $pending . ' 条 / 共 ' . $total . ' 条',
                'action' => '建议评估售后人力配置与响应流程，设置分级响应与超时提醒',
                'link' => ['type' => 'issues', 'params' => ['status' => '待处理']],
            ];
        }

        // 规则 5：有价值问题占比 < 20%
        $rated = (int) qly_val('SELECT COUNT(*) FROM `issue` i' . $where . ' AND i.value_id IS NOT NULL', $params);
        $buckets5 = qly_value_buckets();
        $valuable = (int) qly_val(
            'SELECT COUNT(*) FROM `issue` i LEFT JOIN `dict_item` v ON v.id = i.value_id' . $where
            . qly_value_in_sql($buckets5['valuable']),
            $params
        );
        if ($rated > 0) {
            $rate = round($valuable / $rated * 100, 1);
            if ($rate < 20) {
                $advice[] = [
                    'id' => 'quality',
                    'level' => 'warning',
                    'title' => '整体提问质量偏低',
                    'conclusion' => '有价值问题占比 ' . $rate . '%，低于 20% 阈值',
                    'evidence' => '有价值（4-5 分） ' . $valuable . ' 条 / 已评级 ' . $rated . ' 条',
                    'action' => '建议统一提问模板与引导话术，提交前增加关键信息校验',
                    'link' => ['type' => 'reports', 'params' => []],
                ];
            }
        }

        // 规则 6：平均处理时长 > 48 小时（提交 → 首次处理）
        $avgHours = qly_val(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, i.created_at, (SELECT MIN(h.created_at) FROM `handle_record` h WHERE h.issue_id = i.id))) / 60 FROM `issue` i'
            . $where . ' AND EXISTS (SELECT 1 FROM `handle_record` h WHERE h.issue_id = i.id)',
            $params
        );
        if ($avgHours !== null && (float) $avgHours > 48) {
            $advice[] = [
                'id' => 'sla',
                'level' => 'warning',
                'title' => '平均处理时长偏长',
                'conclusion' => '平均处理时长 ' . round((float) $avgHours, 1) . ' 小时，超过 48 小时阈值',
                'evidence' => '已处理问题的提交至首次处理时间差均值',
                'action' => '建议设置超时提醒与分级响应机制，缩短首次响应时间',
                'link' => ['type' => 'reports', 'params' => []],
            ];
        }

        // 规则 7：某处理人处理的问题数占全局 ≥ 40%（处理人 = 第一次处理的人，合计 = 问题总数）
        $handleTotal = (int) qly_val("SELECT COUNT(*) FROM `issue` i" . $where . " AND i.handler_name IS NOT NULL AND i.handler_name <> ''", $params);
        if ($handleTotal > 0) {
            $rows = qly_all(
                "SELECT i.handler_name AS name, COUNT(*) AS cnt FROM `issue` i" . $where
                . " AND i.handler_name IS NOT NULL AND i.handler_name <> ''"
                . ' GROUP BY i.handler_name ORDER BY cnt DESC',
                $params
            );
            foreach ($rows as $r) {
                $ratio = round((int) $r['cnt'] / $handleTotal * 100, 1);
                if ($ratio >= 40) {
                    $advice[] = [
                        'id' => 'handler-' . (string) $r['name'],
                        'level' => 'info',
                        'title' => '处理量过度集中',
                        'conclusion' => '处理人「' . $r['name'] . '」处理的问题数占全局 ' . $ratio . '%',
                        'evidence' => '处理 ' . (int) $r['cnt'] . ' 个问题 / 全局 ' . $handleTotal . ' 个',
                        'action' => '建议分担处理任务并设置备份人，避免单点依赖',
                        'link' => ['type' => 'reports', 'params' => []],
                    ];
                }
            }
        }
    }
    qly_ok(['hasData' => $total > 0, 'total' => $total, 'advice' => $advice]);
}
