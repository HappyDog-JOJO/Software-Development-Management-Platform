<?php
declare(strict_types=1);

/**
 * 销售分析（输单）接口。
 *
 * 数据范围（与 inc/auth.php 的 qly_lost_scope_where / qly_lost_visible 同源）：
 * - 管理员 / 销售经理：全部
 * - 销售员：仅本人提交（salesman_id = 当前用户）
 * 列表、详情、统计三处共用同一套范围函数，避免「列表看不见、详情打得开」的越权。
 *
 * 统计口径：时间范围一律按「丢单时间 lost_at」，不是提交时间。
 */

/** 模块守卫：只有 销售员 / 销售经理 / 管理员 可访问 */
function qly_lost_guard(): array
{
    $user = qly_auth_user();
    if (!qly_can_view_loss($user)) {
        qly_forbidden('您没有销售分析权限');
    }
    return $user;
}

/** 输单编号：SD + 时间戳 + 3 位随机（与问题编号 WT 同一规则） */
function qly_lost_no(): string
{
    return 'SD' . date('YmdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
}

/**
 * 筛选条件（不含权限范围）。
 * 说明：PDO 原生预处理不允许同名占位符重复，LIKE 多字段匹配要编号（:kw1/:kw2）。
 */
function qly_lost_filters(string $alias = 'l'): array
{
    $where = '';
    $params = [];

    $dateStart = (string) qly_input('dateStart', '');
    $dateEnd = (string) qly_input('dateEnd', '');
    if ($dateStart !== '') {
        $where .= ' AND ' . $alias . '.lost_at >= :ds';
        $params[':ds'] = $dateStart . ' 00:00:00';
    }
    if ($dateEnd !== '') {
        $where .= ' AND ' . $alias . '.lost_at <= :de';
        $params[':de'] = $dateEnd . ' 23:59:59';
    }

    // 维度筛选：字典 ID
    $dictFilters = [
        ':lt' => ['lossTypeId', 'loss_type_id'],
        ':in' => ['industryId', 'industry_id'],
        ':cs' => ['companyScaleId', 'company_scale_id'],
        ':ls' => ['lossStageId', 'loss_stage_id'],
        ':bm' => ['budgetMatchId', 'budget_match_id'],
    ];
    foreach ($dictFilters as $ph => $conf) {
        [$input, $column] = $conf;
        $id = qly_input_int($input, 0);
        if ($id > 0) {
            $where .= ' AND ' . $alias . '.' . $column . ' = ' . $ph;
            $params[$ph] = $id;
        }
    }

    // 是否远程演示 / 是否涉及定制化：'' 表示不限
    $remoteDemo = (string) qly_input('remoteDemo', '');
    if ($remoteDemo === '1' || $remoteDemo === '0') {
        $where .= ' AND ' . $alias . '.remote_demo = :rd';
        $params[':rd'] = (int) $remoteDemo;
    }
    $customized = (string) qly_input('customized', '');
    if ($customized === '1' || $customized === '0') {
        $where .= ' AND ' . $alias . '.customized = :cz';
        $params[':cz'] = (int) $customized;
    }

    // 业务员（姓名/账号模糊）
    $salesman = (string) qly_input('salesman', '');
    if ($salesman !== '') {
        $where .= ' AND (' . $alias . '.salesman_name LIKE :sm1 OR ' . $alias . '.salesman_account LIKE :sm2)';
        $kw = '%' . $salesman . '%';
        $params[':sm1'] = $kw;
        $params[':sm2'] = $kw;
    }

    // 关键字：客户名称 / 客户最终选择 / 输单原因
    $keyword = (string) qly_input('keyword', '');
    if ($keyword !== '') {
        $where .= ' AND (' . $alias . '.customer_name LIKE :kw1'
            . ' OR ' . $alias . '.customer_choice LIKE :kw2'
            . ' OR ' . $alias . '.reason LIKE :kw3)';
        $kw = '%' . $keyword . '%';
        $params[':kw1'] = $kw;
        $params[':kw2'] = $kw;
        $params[':kw3'] = $kw;
    }

    return [$where, $params];
}

/** 筛选 + 权限范围合并成完整 WHERE（含前导 WHERE 1=1） */
function qly_lost_where(array $user, string $alias = 'l'): array
{
    [$filterWhere, $filterParams] = qly_lost_filters($alias);
    [$scopeWhere, $scopeParams] = qly_lost_scope_where($user, $alias);
    return [' WHERE 1=1' . $filterWhere . $scopeWhere, array_merge($filterParams, $scopeParams)];
}

/** 对外输出的输单实体（显式挑字段，camelCase） */
function qly_lost_row_public(array $r): array
{
    $intOrNull = static function ($v): ?int {
        return $v === null ? null : (int) $v;
    };
    return [
        'id' => (int) $r['id'],
        'lostNo' => (string) $r['lost_no'],
        'customerName' => (string) $r['customer_name'],
        'lostAt' => (string) $r['lost_at'],
        'lossTypeId' => $intOrNull($r['loss_type_id'] ?? null),
        'lossTypeName' => (string) ($r['loss_type_name'] ?? ''),
        'industryId' => $intOrNull($r['industry_id'] ?? null),
        'industryName' => (string) ($r['industry_name'] ?? ''),
        'companyScaleId' => $intOrNull($r['company_scale_id'] ?? null),
        'companyScaleName' => (string) ($r['company_scale_name'] ?? ''),
        'lossStageId' => $intOrNull($r['loss_stage_id'] ?? null),
        'lossStageName' => (string) ($r['loss_stage_name'] ?? ''),
        'budgetMatchId' => $intOrNull($r['budget_match_id'] ?? null),
        'budgetMatchName' => (string) ($r['budget_match_name'] ?? ''),
        'remoteDemo' => (int) $r['remote_demo'],
        'customized' => (int) $r['customized'],
        'finalQuote' => $r['final_quote'] !== null ? (float) $r['final_quote'] : null,
        'customerChoice' => (string) ($r['customer_choice'] ?? ''),
        'reason' => (string) $r['reason'],
        'salesmanId' => (int) $r['salesman_id'],
        'salesmanAccount' => (string) $r['salesman_account'],
        'salesmanName' => (string) $r['salesman_name'],
        'departmentId' => $intOrNull($r['department_id'] ?? null),
        'departmentName' => (string) ($r['department_name'] ?? ''),
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => $r['updated_at'] ?? null,
    ];
}

/** 校验字典项：必须存在、类型匹配、启用中；返回名称快照 */
function qly_lost_dict_name(int $id, string $type, string $label): string
{
    if ($id <= 0) {
        qly_fail($label . '不能为空');
    }
    $row = qly_one('SELECT name, type, status FROM `dict_item` WHERE id = :id', [':id' => $id]);
    if (!$row || (string) $row['type'] !== $type) {
        qly_fail($label . '不合法');
    }
    if ((int) $row['status'] !== 1) {
        qly_fail($label . '「' . (string) $row['name'] . '」已停用，请重新选择');
    }
    return (string) $row['name'];
}

/** 是/否 入参归一（兼容 true/false、1/0、'是'/'否'） */
function qly_lost_bool(string $key, string $label): int
{
    $v = qly_input($key, 0);
    if ($v === true || $v === 1 || $v === '1' || $v === '是') {
        return 1;
    }
    if ($v === false || $v === 0 || $v === '0' || $v === '否' || $v === '' || $v === null) {
        return 0;
    }
    qly_fail($label . '取值不合法');
}

/** 校验并组装可落库字段（提交与编辑共用） */
function qly_lost_payload(): array
{
    $customerName = trim((string) qly_input('customerName', ''));
    // 连续空白压成一个空格：避免「某某   公司」这类脏数据进统计口径
    $customerName = (string) preg_replace('/\s+/u', ' ', $customerName);
    if ($customerName === '') {
        qly_fail('客户名称不能为空');
    }
    if (mb_strlen($customerName) > 120) {
        qly_fail('客户名称最多 120 个字符');
    }
    // 客户名称只做页面提示（要求填写真实企业名称），服务端不再拦截：
    // 参考数据里确实出现过「13594398399」「不详」这类填法，但业务上仍需允许补录，硬拦会挡住真实登记。

    // 丢单时间可手选（默认取当前时间，便于补录历史单）
    $lostAtRaw = trim((string) qly_input('lostAt', ''));
    if ($lostAtRaw === '') {
        qly_fail('丢单时间不能为空');
    }
    $ts = strtotime(str_replace('/', '-', $lostAtRaw));
    if ($ts === false) {
        qly_fail('丢单时间格式不正确');
    }
    $lostAt = date('Y-m-d H:i:s', $ts);

    $lossTypeId = qly_input_int('lossTypeId', 0);
    $industryId = qly_input_int('industryId', 0);
    $companyScaleId = qly_input_int('companyScaleId', 0);
    $lossStageId = qly_input_int('lossStageId', 0);
    $budgetMatchId = qly_input_int('budgetMatchId', 0);

    $lossTypeName = qly_lost_dict_name($lossTypeId, 'loss_type', '输单类型');
    $industryName = qly_lost_dict_name($industryId, 'industry', '所属行业');
    $companyScaleName = qly_lost_dict_name($companyScaleId, 'company_scale', '企业规模');
    $lossStageName = qly_lost_dict_name($lossStageId, 'loss_stage', '丢单阶段');
    $budgetMatchName = qly_lost_dict_name($budgetMatchId, 'budget_match', '预算匹配');

    $remoteDemo = qly_lost_bool('remoteDemo', '是否远程演示');
    $customized = qly_lost_bool('customized', '是否涉及定制化');

    // 最终报价：允许留空（0 视为未报价）
    $finalQuote = null;
    $quoteRaw = qly_input('finalQuote', '');
    if ($quoteRaw !== '' && $quoteRaw !== null) {
        if (!is_numeric($quoteRaw)) {
            qly_fail('最终报价必须为数字');
        }
        $finalQuote = round((float) $quoteRaw, 2);
        if ($finalQuote < 0) {
            qly_fail('最终报价不能为负数');
        }
        if ($finalQuote > 9999999999) {
            qly_fail('最终报价超出可录入范围');
        }
    }

    $customerChoice = trim((string) qly_input('customerChoice', ''));
    if (mb_strlen($customerChoice) > 500) {
        qly_fail('客户最终选择最多 500 个字符');
    }

    $reason = trim((string) qly_input('reason', ''));
    if ($reason === '') {
        qly_fail('输单原因描述不能为空');
    }
    if (mb_strlen($reason) > 2000) {
        qly_fail('输单原因描述最多 2000 个字符');
    }

    return [
        'customer_name' => $customerName,
        'lost_at' => $lostAt,
        'loss_type_id' => $lossTypeId,
        'loss_type_name' => $lossTypeName,
        'industry_id' => $industryId,
        'industry_name' => $industryName,
        'company_scale_id' => $companyScaleId,
        'company_scale_name' => $companyScaleName,
        'loss_stage_id' => $lossStageId,
        'loss_stage_name' => $lossStageName,
        'budget_match_id' => $budgetMatchId,
        'budget_match_name' => $budgetMatchName,
        'remote_demo' => $remoteDemo,
        'customized' => $customized,
        'final_quote' => $finalQuote,
        'customer_choice' => $customerChoice === '' ? null : $customerChoice,
        'reason' => $reason,
    ];
}

/* ==================== 台账：列表 / 详情 / 新增 / 编辑 / 删除 ==================== */

function qly_api_salesloss_list(): void
{
    $user = qly_lost_guard();
    [$where, $params] = qly_lost_where($user, 'l');

    $page = max(1, qly_input_int('page', 1));
    $pageSize = min(200, max(1, qly_input_int('pageSize', 10)));
    $offset = ($page - 1) * $pageSize;

    $total = (int) qly_val('SELECT COUNT(*) FROM `lost_order` l' . $where, $params);

    // 权限外条数提示（与问题列表同一套做法）
    $filteredOut = 0;
    [$scopeWhere, $scopeParams] = qly_lost_scope_where($user, 'l');
    if ($scopeWhere !== '') {
        [$filterWhere, $filterParams] = qly_lost_filters('l');
        $allTotal = (int) qly_val('SELECT COUNT(*) FROM `lost_order` l WHERE 1=1' . $filterWhere, $filterParams);
        $filteredOut = max(0, $allTotal - $total);
    }
    unset($scopeParams);

    $sortField = (string) qly_input('sortField', 'lostAt');
    $sortOrder = strtoupper((string) qly_input('sortOrder', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $sortMap = [
        'lostAt' => 'l.lost_at',
        'finalQuote' => 'l.final_quote',
        'createdAt' => 'l.created_at',
    ];
    $column = $sortMap[$sortField] ?? $sortMap['lostAt'];

    $rows = qly_all(
        'SELECT l.* FROM `lost_order` l' . $where
        . ' ORDER BY ' . $column . ' ' . $sortOrder . ', l.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
        $params
    );

    qly_ok([
        'items' => array_map('qly_lost_row_public', $rows),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'filteredOut' => $filteredOut,
    ]);
}

function qly_api_salesloss_detail(): void
{
    $user = qly_lost_guard();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT l.* FROM `lost_order` l WHERE l.id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('输单记录不存在');
    }
    if (!qly_lost_visible($user, $row)) {
        qly_forbidden('该记录不在您的可见范围');
    }
    qly_ok(qly_lost_row_public($row));
}

function qly_api_salesloss_create(): void
{
    $user = qly_lost_guard();
    $data = qly_lost_payload();

    $now = date('Y-m-d H:i:s');
    $salesmanName = (string) ($user['name'] !== null && $user['name'] !== '' ? $user['name'] : $user['account']);

    $data['lost_no'] = qly_lost_no();
    // 业务员固定为登录人（不支持代他人录入），部门取当前部门快照
    $data['salesman_id'] = (int) $user['id'];
    $data['salesman_account'] = (string) $user['account'];
    $data['salesman_name'] = $salesmanName;
    $data['department_id'] = $user['department_id'] !== null ? (int) $user['department_id'] : null;
    $data['department_name'] = $user['department_name'] ?? null;
    $data['created_at'] = $now;

    $id = qly_insert('lost_order', $data);
    qly_log($user, '提交输单', $data['lost_no'] . ' / ' . $data['customer_name'], '输单类型：' . $data['loss_type_name'] . '；丢单阶段：' . $data['loss_stage_name']);
    qly_ok(['id' => $id, 'lostNo' => $data['lost_no']], '输单已登记');
}

function qly_api_salesloss_update(): void
{
    $user = qly_lost_guard();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `lost_order` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('输单记录不存在');
    }
    // 编辑权：本人 或 管理员（销售经理只能改自己的单）
    if (!qly_can_edit_loss($user, $row)) {
        qly_forbidden('只能编辑自己登记的输单');
    }
    $data = qly_lost_payload();
    $data['updated_at'] = date('Y-m-d H:i:s');
    qly_update('lost_order', $id, $data);
    qly_log($user, '输单维护', (string) $row['lost_no'] . ' / ' . $data['customer_name'], '编辑输单记录');
    qly_ok(['id' => $id, 'lostNo' => (string) $row['lost_no']], '输单已更新');
}

function qly_api_salesloss_delete(): void
{
    $user = qly_lost_guard();
    if (!qly_has_role($user, [QLY_ROLE_ADMIN])) {
        qly_forbidden('仅管理员可删除输单');
    }
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `lost_order` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('输单记录不存在');
    }
    qly_exec('DELETE FROM `lost_order` WHERE id = :id', [':id' => $id]);
    qly_log($user, '输单维护', (string) $row['lost_no'] . ' / ' . (string) $row['customer_name'], '删除输单记录');
    qly_ok(null, '输单已删除');
}

/* ==================== 分析：概览 / 维度分布 / 交叉与趋势 / 排行 ==================== */

function qly_api_salesloss_overview(): void
{
    $user = qly_lost_guard();
    [$where, $params] = qly_lost_where($user, 'l');

    $row = qly_one(
        'SELECT COUNT(*) AS total,
                SUM(COALESCE(l.final_quote, 0)) AS quote_total,
                SUM(CASE WHEN l.final_quote > 0 THEN 1 ELSE 0 END) AS quote_cnt,
                AVG(CASE WHEN l.final_quote > 0 THEN l.final_quote END) AS quote_avg,
                SUM(CASE WHEN l.remote_demo = 1 THEN 1 ELSE 0 END) AS remote_demo,
                SUM(CASE WHEN l.customized = 1 THEN 1 ELSE 0 END) AS customized,
                COUNT(DISTINCT l.customer_name) AS customer_cnt,
                COUNT(DISTINCT l.salesman_name) AS salesman_cnt
           FROM `lost_order` l' . $where,
        $params
    ) ?? [];

    $total = (int) ($row['total'] ?? 0);
    $remoteDemo = (int) ($row['remote_demo'] ?? 0);
    $customized = (int) ($row['customized'] ?? 0);

    qly_ok([
        'total' => $total,
        'quoteTotal' => round((float) ($row['quote_total'] ?? 0), 2),
        'quoteCount' => (int) ($row['quote_cnt'] ?? 0),
        'quoteAvg' => round((float) ($row['quote_avg'] ?? 0), 2),
        'remoteDemo' => $remoteDemo,
        'remoteDemoRate' => $total > 0 ? round($remoteDemo / $total * 100, 1) : 0.0,
        'customized' => $customized,
        'customizedRate' => $total > 0 ? round($customized / $total * 100, 1) : 0.0,
        'customerCount' => (int) ($row['customer_cnt'] ?? 0),
        'salesmanCount' => (int) ($row['salesman_cnt'] ?? 0),
    ]);
}

/** 按字典名称分组的通用统计（单量 + 报价金额），保证分布顺序稳定 */
function qly_lost_group_stats(array $user, string $nameColumn, string $emptyLabel = '未填写'): array
{
    [$where, $params] = qly_lost_where($user, 'l');
    $col = 'COALESCE(NULLIF(l.' . $nameColumn . ", ''), '" . $emptyLabel . "')";
    $rows = qly_all(
        'SELECT ' . $col . ' AS name,
                COUNT(*) AS cnt,
                SUM(COALESCE(l.final_quote, 0)) AS quote_total
           FROM `lost_order` l' . $where
        . ' GROUP BY ' . $col . ' ORDER BY cnt DESC, name ASC',
        $params
    );
    return array_map(static function (array $r): array {
        return [
            'name' => (string) $r['name'],
            'value' => (int) $r['cnt'],
            'quoteTotal' => round((float) $r['quote_total'], 2),
            'extra' => null,
        ];
    }, $rows);
}

function qly_api_salesloss_dims(): void
{
    $user = qly_lost_guard();
    [$where, $params] = qly_lost_where($user, 'l');

    $boolDist = static function (string $column, string $yesLabel, string $noLabel) use ($where, $params): array {
        $rows = qly_all(
            'SELECT CASE WHEN l.' . $column . ' = 1 THEN :y ELSE :n END AS name, COUNT(*) AS cnt
               FROM `lost_order` l' . $where
            . ' GROUP BY name ORDER BY cnt DESC',
            array_merge($params, [':y' => $yesLabel, ':n' => $noLabel])
        );
        return array_map(static fn(array $r): array => ['name' => (string) $r['name'], 'value' => (int) $r['cnt']], $rows);
    };

    qly_ok([
        'lossTypes' => qly_lost_group_stats($user, 'loss_type_name'),
        'lossStages' => qly_lost_group_stats($user, 'loss_stage_name'),
        'industries' => qly_lost_group_stats($user, 'industry_name'),
        'companyScales' => qly_lost_group_stats($user, 'company_scale_name'),
        'budgetMatches' => qly_lost_group_stats($user, 'budget_match_name'),
        'remoteDemoDist' => $boolDist('remote_demo', '远程演示', '未远程演示'),
        'customizedDist' => $boolDist('customized', '涉及定制化', '不涉及定制化'),
    ]);
}

function qly_api_salesloss_cross(): void
{
    $user = qly_lost_guard();
    [$where, $params] = qly_lost_where($user, 'l');

    // 丢单阶段 × 输单类型 交叉矩阵
    $matrixRows = qly_all(
        "SELECT COALESCE(NULLIF(l.loss_stage_name, ''), '未填写') AS stage,
                COALESCE(NULLIF(l.loss_type_name, ''), '未填写') AS type,
                COUNT(*) AS cnt
           FROM `lost_order` l" . $where
        . ' GROUP BY stage, type ORDER BY cnt DESC',
        $params
    );
    $stages = [];
    $types = [];
    $matrix = [];
    foreach ($matrixRows as $r) {
        $stage = (string) $r['stage'];
        $type = (string) $r['type'];
        if (!in_array($stage, $stages, true)) {
            $stages[] = $stage;
        }
        if (!in_array($type, $types, true)) {
            $types[] = $type;
        }
        $matrix[] = ['lossStage' => $stage, 'lossType' => $type, 'value' => (int) $r['cnt']];
    }

    // 是否远程演示 × 输单类型（同一类型里"演示过仍丢单"的占比很关键）
    $demoRows = qly_all(
        "SELECT COALESCE(NULLIF(l.loss_type_name, ''), '未填写') AS type,
                CASE WHEN l.remote_demo = 1 THEN '远程演示' ELSE '未远程演示' END AS demo,
                COUNT(*) AS cnt
           FROM `lost_order` l" . $where
        . ' GROUP BY type, demo ORDER BY cnt DESC',
        $params
    );
    $demoMatrix = array_map(static fn(array $r): array => [
        'lossType' => (string) $r['type'],
        'demo' => (string) $r['demo'],
        'value' => (int) $r['cnt'],
    ], $demoRows);

    // 丢单趋势（按日）：单量 + 报价金额
    $trendRows = qly_all(
        'SELECT DATE(l.lost_at) AS day, COUNT(*) AS cnt, SUM(COALESCE(l.final_quote, 0)) AS quote_total
           FROM `lost_order` l' . $where
        . ' GROUP BY DATE(l.lost_at) ORDER BY day ASC',
        $params
    );
    $trend = array_map(static fn(array $r): array => [
        'date' => (string) $r['day'],
        'total' => (int) $r['cnt'],
        'quoteTotal' => round((float) $r['quote_total'], 2),
    ], $trendRows);

    qly_ok([
        'lossStages' => $stages,
        'lossTypes' => $types,
        'matrix' => $matrix,
        'demoMatrix' => $demoMatrix,
        'trend' => $trend,
    ]);
}

function qly_api_salesloss_ranks(): void
{
    $user = qly_lost_guard();
    [$where, $params] = qly_lost_where($user, 'l');

    $salesmanRows = qly_all(
        'SELECT l.salesman_name AS name, COUNT(*) AS cnt, SUM(COALESCE(l.final_quote, 0)) AS quote_total
           FROM `lost_order` l' . $where
        . ' GROUP BY l.salesman_name ORDER BY cnt DESC, name ASC LIMIT 20',
        $params
    );
    $deptRows = qly_all(
        "SELECT COALESCE(NULLIF(l.department_name, ''), '未分配部门') AS name,
                COUNT(*) AS cnt, SUM(COALESCE(l.final_quote, 0)) AS quote_total
           FROM `lost_order` l" . $where
        . ' GROUP BY name ORDER BY cnt DESC, name ASC LIMIT 20',
        $params
    );
    $customerRows = qly_all(
        'SELECT l.customer_name AS name, COUNT(*) AS cnt, SUM(COALESCE(l.final_quote, 0)) AS quote_total
           FROM `lost_order` l' . $where
        . ' GROUP BY l.customer_name ORDER BY cnt DESC, name ASC LIMIT 10',
        $params
    );
    $map = static fn(array $rows): array => array_map(static fn(array $r): array => [
        'name' => (string) $r['name'],
        'value' => (int) $r['cnt'],
        'quoteTotal' => round((float) $r['quote_total'], 2),
        'extra' => null,
    ], $rows);

    qly_ok([
        'salesmen' => $map($salesmanRows),
        'departments' => $map($deptRows),
        'customers' => $map($customerRows),
    ]);
}
