<?php
declare(strict_types=1);

/** 操作日志（仅管理员）——只读，无编辑与删除入口 */

function qly_api_logs_list(): void
{
    qly_admin_user();
    $where = ' WHERE 1=1';
    $params = [];
    $account = (string) qly_input('account', '');
    if ($account !== '') {
        // PDO 原生预处理不允许同名占位符重复，分号命名
        $where .= ' AND (account LIKE :a1 OR target LIKE :a2)';
        $acc = '%' . $account . '%';
        $params[':a1'] = $acc;
        $params[':a2'] = $acc;
    }
    $type = (string) qly_input('type', '');
    if ($type !== '') {
        $where .= ' AND type = :t';
        $params[':t'] = $type;
    }
    $dateStart = (string) qly_input('dateStart', '');
    $dateEnd = (string) qly_input('dateEnd', '');
    if ($dateStart !== '') {
        $where .= ' AND created_at >= :ds';
        $params[':ds'] = $dateStart . ' 00:00:00';
    }
    if ($dateEnd !== '') {
        $where .= ' AND created_at <= :de';
        $params[':de'] = $dateEnd . ' 23:59:59';
    }
    $keyword = (string) qly_input('keyword', '');
    if ($keyword !== '') {
        // PDO 原生预处理不允许同名占位符重复，分号命名
        $where .= ' AND (content LIKE :kw1 OR target LIKE :kw2 OR account LIKE :kw3)';
        $kw = '%' . $keyword . '%';
        $params[':kw1'] = $kw;
        $params[':kw2'] = $kw;
        $params[':kw3'] = $kw;
    }
    $page = max(1, qly_input_int('page', 1));
    $pageSize = min(200, max(1, qly_input_int('pageSize', 10)));
    $offset = ($page - 1) * $pageSize;

    $total = (int) qly_val('SELECT COUNT(*) FROM `operation_log`' . $where, $params);
    $rows = qly_all('SELECT * FROM `operation_log`' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset, $params);
    $items = array_map(fn(array $r): array => [
        'id' => (int) $r['id'],
        'account' => $r['account'],
        'role' => $r['role'],
        'type' => $r['type'],
        'target' => $r['target'],
        'content' => $r['content'],
        'ip' => $r['ip'],
        'createdAt' => $r['created_at'],
    ], $rows);
    qly_ok(['items' => $items, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
}
