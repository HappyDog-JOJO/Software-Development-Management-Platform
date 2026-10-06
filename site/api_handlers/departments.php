<?php
declare(strict_types=1);

/** 部门维护（仅管理员）；删除前做引用校验 */

function qly_dept_reference_counts(int $deptId): array
{
    return [
        '用户' => (int) qly_val('SELECT COUNT(*) FROM `user` WHERE department_id = :id AND deleted_at IS NULL', [':id' => $deptId]),
        '问题' => (int) qly_val('SELECT COUNT(*) FROM `issue` WHERE department_id = :id', [':id' => $deptId]),
    ];
}

function qly_api_departments_list(): void
{
    qly_auth_user();
    $rows = qly_all(
        'SELECT d.*, (SELECT COUNT(*) FROM `user` u WHERE u.department_id = d.id AND u.deleted_at IS NULL) AS user_count
           FROM `department` d ORDER BY d.sort ASC, d.id ASC'
    );
    $items = array_map(function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'leader' => $r['leader'],
            'sort' => (int) $r['sort'],
            'status' => (int) $r['status'],
            'userCount' => (int) $r['user_count'],
            'createdAt' => $r['created_at'],
        ];
    }, $rows);
    qly_ok($items);
}

/**
 * 启用中的部门（公开接口，供注册页选择部门）。
 * 只回 id 与名称，不泄露负责人/人数等管理信息；未登录也可调用。
 */
function qly_api_departments_public(): void
{
    $rows = qly_all('SELECT id, name FROM `department` WHERE status = 1 ORDER BY sort ASC, id ASC');
    qly_ok(array_map(fn(array $r): array => [
        'id' => (int) $r['id'],
        'name' => (string) $r['name'],
    ], $rows));
}

function qly_api_departments_save(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $name = (string) qly_input('name', '');
    $leader = (string) qly_input('leader', '');
    $sort = qly_input_int('sort', 0);
    if ($name === '') {
        qly_fail('部门名称不能为空');
    }
    $dup = qly_val('SELECT COUNT(*) FROM `department` WHERE name = :n AND id <> :id', [':n' => $name, ':id' => $id]);
    if ((int) $dup > 0) {
        qly_fail('部门名称已存在');
    }
    if ($id > 0) {
        $old = qly_one('SELECT * FROM `department` WHERE id = :id', [':id' => $id]);
        if (!$old) {
            qly_fail('部门不存在');
        }
        qly_update('department', $id, ['name' => $name, 'leader' => $leader, 'sort' => $sort]);
        qly_log($admin, '部门维护', $name, '编辑部门：名称 ' . $old['name'] . ' → ' . $name . '；负责人 ' . ($old['leader'] ?: '空') . ' → ' . ($leader !== '' ? $leader : '空'));
    } else {
        $id = qly_insert('department', [
            'name' => $name,
            'leader' => $leader,
            'sort' => $sort,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        qly_log($admin, '部门维护', $name, '新增部门，负责人：' . ($leader !== '' ? $leader : '空'));
    }
    qly_ok(['id' => $id], '部门已保存');
}

function qly_api_departments_status(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $status = qly_input_int('status', -1);
    if (!in_array($status, [0, 1], true)) {
        qly_fail('状态取值不合法');
    }
    $row = qly_one('SELECT * FROM `department` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('部门不存在');
    }
    qly_update('department', $id, ['status' => $status]);
    qly_log($admin, '部门维护', (string) $row['name'], $status === 1 ? '启用部门' : '停用部门');
    qly_ok(null, $status === 1 ? '部门已启用' : '部门已停用');
}

function qly_api_departments_delete(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `department` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('部门不存在');
    }
    $counts = qly_dept_reference_counts($id);
    $parts = [];
    foreach ($counts as $label => $n) {
        if ($n > 0) {
            $parts[] = $label . ' ' . $n . ' 条';
        }
    }
    if ($parts) {
        // 删除前置校验：被引用时禁止物理删除，仅可停用
        qly_fail('该部门已被引用（' . implode('、', $parts) . '），不可删除，请改为停用');
    }
    qly_exec('DELETE FROM `department` WHERE id = :id', [':id' => $id]);
    qly_log($admin, '部门维护', (string) $row['name'], '删除部门');
    qly_ok(null, '部门已删除');
}
