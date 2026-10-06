<?php
declare(strict_types=1);

/** 用户管理接口（仅管理员） */

function qly_api_users_list(): void
{
    qly_admin_user();
    $keyword = (string) qly_input('keyword', '');
    $deptId = qly_input_int('departmentId', 0);
    $role = (string) qly_input('role', '');
    $status = qly_input('status', '');
    $page = max(1, qly_input_int('page', 1));
    $pageSize = min(100, max(1, qly_input_int('pageSize', 10)));

    // 列表只展示未删除的账号（删除走逻辑删除：deleted_at 有值 + 账号加 #deletedN 后缀 + 禁用）
    $where = ' WHERE u.deleted_at IS NULL';
    $params = [];
    if ($keyword !== '') {
        // 注意：PDO 原生预处理不允许同名占位符重复使用，需用不同参数名
        $where .= ' AND (u.account LIKE :kw1 OR u.name LIKE :kw2 OR u.phone LIKE :kw3)';
        $kw = '%' . $keyword . '%';
        $params[':kw1'] = $kw;
        $params[':kw2'] = $kw;
        $params[':kw3'] = $kw;
    }
    if ($deptId > 0) {
        $where .= ' AND u.department_id = :did';
        $params[':did'] = $deptId;
    }
    if ($role !== '') {
        // 多角色：按包含匹配
        $where .= ' AND u.roles LIKE :role';
        $params[':role'] = '%' . $role . '%';
    }
    if ($status !== '' && $status !== null) {
        $where .= ' AND u.status = :st';
        $params[':st'] = (int) $status;
    }

    $total = (int) qly_val('SELECT COUNT(*) FROM `user` u' . $where, $params);
    $offset = ($page - 1) * $pageSize;
    $rows = qly_all(
        'SELECT u.*, d.name AS department_name
           FROM `user` u LEFT JOIN `department` d ON d.id = u.department_id'
        . $where . ' ORDER BY u.id ASC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
        $params
    );
    $items = array_map('qly_user_public', $rows);
    qly_ok(['items' => $items, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
}

function qly_api_users_create(): void
{
    $admin = qly_admin_user();
    $account = (string) qly_input('account', '');
    $password = (string) qly_input('password', '');
    $phone = (string) qly_input('phone', '');
    $roleInput = qly_input('roles', qly_input('role', [QLY_ROLE_SALES]));
    $name = (string) qly_input('name', '');
    $avatar = (string) qly_input('avatar', '');
    $deptId = qly_input_int('departmentId', 0);

    $roles = qly_normalize_roles($roleInput);
    if ($account === '') {
        qly_fail('账号不能为空');
    }
    if (!$roles) {
        qly_fail('请至少选择一个角色');
    }
    if ((int) qly_val('SELECT COUNT(*) FROM `user` WHERE account = :a', [':a' => $account]) > 0) {
        qly_fail('该账号已存在');
    }
    if ($password === '') {
        $password = (string) qly_app_config()['default_password'];
    }
    if (mb_strlen($password) < 6) {
        qly_fail('密码至少 6 位');
    }
    if ($phone !== '' && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
        qly_fail('联系电话格式不正确');
    }
    $id = qly_insert('user', [
        'account' => $account,
        'password_hash' => qly_password_hash($password),
        'name' => $name !== '' ? $name : $account,
        'phone' => $phone !== '' ? $phone : null,
        'avatar' => $avatar !== '' ? $avatar : null,
        'department_id' => $deptId > 0 ? $deptId : null,
        'roles' => implode(',', $roles),
        'status' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $row = qly_one('SELECT u.*, d.name AS department_name FROM `user` u LEFT JOIN `department` d ON d.id = u.department_id WHERE u.id = :id', [':id' => $id]);
    qly_log($admin, '用户管理', $account, '新增用户，角色：' . implode('、', $roles));
    qly_ok(qly_user_public($row), '用户已创建');
}

function qly_api_users_update(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `user` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('用户不存在');
    }
    $data = [];
    $before = [];
    // 账号（登录名）可改：需唯一、格式合法，且不能改成已删除账号占用的名字
    $accountInput = qly_input('account');
    if ($accountInput !== null && trim((string) $accountInput) !== '') {
        $newAccount = trim((string) $accountInput);
        if ($newAccount !== (string) $row['account']) {
            if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,20}$/u', $newAccount)) {
                qly_fail('账号需 2-20 位，支持中英文、数字与下划线');
            }
            $dup = (int) qly_val('SELECT COUNT(*) FROM `user` WHERE account = :a AND id <> :id', [':a' => $newAccount, ':id' => $id]);
            if ($dup > 0) {
                qly_fail('该账号已被使用，请更换');
            }
            $data['account'] = $newAccount;
            $before[] = '账号：' . (string) $row['account'] . ' → ' . $newAccount;
        }
    }
    foreach (['name' => '姓名', 'phone' => '联系电话', 'avatar' => '头像'] as $k => $label) {
        $v = qly_input($k);
        if ($v !== null && $v !== '') {
            if ($k === 'phone' && !preg_match('/^1[3-9]\d{9}$/', (string) $v)) {
                qly_fail('联系电话格式不正确');
            }
            $data[$k] = (string) $v;
            $before[] = $label . '：' . (string) ($row[$k] ?? '空') . ' → ' . (string) $v;
        }
    }
    // 所属部门：允许在编辑弹窗中直接调整（0 或空 = 未分配）
    $deptInput = qly_input('departmentId');
    if ($deptInput !== null) {
        $deptId = (int) $deptInput;
        if ($deptId > 0 && !qly_one('SELECT id FROM `department` WHERE id = :id', [':id' => $deptId])) {
            qly_fail('目标部门不存在');
        }
        $oldDept = $row['department_id'] ? (string) qly_val('SELECT name FROM `department` WHERE id = :id', [':id' => (int) $row['department_id']]) : '未分配';
        $newDept = $deptId > 0 ? (string) qly_val('SELECT name FROM `department` WHERE id = :id', [':id' => $deptId]) : '未分配';
        $data['department_id'] = $deptId > 0 ? $deptId : null;
        $before[] = '所属部门：' . $oldDept . ' → ' . $newDept;
    }
    if (!$data) {
        // 无任何改动（常见于重复点保存）：不当作错误，回成功并给出提示，避免弹红字干扰
        $fresh = qly_one('SELECT u.*, d.name AS department_name FROM `user` u LEFT JOIN `department` d ON d.id = u.department_id WHERE u.id = :id', [':id' => $id]);
        qly_ok(qly_user_public($fresh), '未修改任何内容');
    }
    qly_update('user', $id, $data);
    qly_log($admin, '用户管理', (string) ($data['account'] ?? $row['account']), '编辑用户资料（' . implode('；', $before) . '）');
    $fresh = qly_one('SELECT u.*, d.name AS department_name FROM `user` u LEFT JOIN `department` d ON d.id = u.department_id WHERE u.id = :id', [':id' => $id]);
    qly_ok(qly_user_public($fresh), '用户资料已更新');
}

function qly_api_users_department(): void
{
    $admin = qly_admin_user();
    $ids = qly_input('ids');
    $id = qly_input_int('id', 0);
    $targets = [];
    if (is_array($ids)) {
        foreach ($ids as $v) {
            $targets[] = (int) $v;
        }
    } elseif ($id > 0) {
        $targets[] = $id;
    }
    if (!$targets) {
        qly_fail('请选择要分配部门的用户');
    }
    $deptId = qly_input_int('departmentId', 0);
    $deptName = '未分配';
    if ($deptId > 0) {
        $dept = qly_one('SELECT * FROM `department` WHERE id = :id', [':id' => $deptId]);
        if (!$dept) {
            qly_fail('目标部门不存在');
        }
        $deptName = (string) $dept['name'];
    }
    foreach ($targets as $tid) {
        qly_update('user', $tid, ['department_id' => $deptId > 0 ? $deptId : null]);
    }
    qly_log($admin, '用户管理', '共 ' . count($targets) . ' 个用户', '分配部门为：' . $deptName);
    qly_ok(['affected' => count($targets)], '部门分配完成');
}

function qly_api_users_role(): void
{
    $admin = qly_admin_user();
    $ids = qly_input('ids');
    $id = qly_input_int('id', 0);
    $targets = [];
    if (is_array($ids)) {
        foreach ($ids as $v) {
            $targets[] = (int) $v;
        }
    } elseif ($id > 0) {
        $targets[] = $id;
    }
    // 支持一次性设置多个角色（roles 数组）；兼容旧的单角色参数 role
    $roleInput = qly_input('roles', qly_input('role', ''));
    if (!$targets) {
        qly_fail('请选择要调整角色的用户');
    }
    $roles = qly_normalize_roles($roleInput);
    if (!$roles) {
        qly_fail('请至少选择一个角色');
    }
    $adminId = (int) $admin['id'];
    $rolesStr = implode(',', $roles);
    foreach ($targets as $tid) {
        if ($tid === $adminId && !in_array(QLY_ROLE_ADMIN, $roles, true)) {
            qly_fail('不能移除自己的管理员角色');
        }
        qly_update('user', $tid, ['roles' => $rolesStr]);
    }
    qly_log($admin, '用户管理', '共 ' . count($targets) . ' 个用户', '调整角色为：' . implode('、', $roles));
    qly_ok(['affected' => count($targets), 'roles' => $roles], '角色调整完成，重新登录后生效');
}

function qly_api_users_status(): void
{
    $admin = qly_admin_user();
    $ids = qly_input('ids');
    $id = qly_input_int('id', 0);
    $targets = [];
    if (is_array($ids)) {
        foreach ($ids as $v) {
            $targets[] = (int) $v;
        }
    } elseif ($id > 0) {
        $targets[] = $id;
    }
    $status = qly_input_int('status', -1);
    if (!$targets) {
        qly_fail('请选择要操作的用户');
    }
    if (!in_array($status, [0, 1], true)) {
        qly_fail('状态取值不合法');
    }
    $adminId = (int) $admin['id'];
    foreach ($targets as $tid) {
        if ($tid === $adminId && $status === 0) {
            qly_fail('不能禁用自己的账号');
        }
        qly_update('user', $tid, ['status' => $status]);
        // 禁用账号时踢下其全部会话；启用不动会话
        if ($status === 0) {
            qly_revoke_all_tokens($tid);
        }
    }
    qly_log($admin, '用户管理', '共 ' . count($targets) . ' 个用户', $status === 1 ? '启用账号' : '禁用账号');
    qly_ok(['affected' => count($targets)], $status === 1 ? '账号已启用' : '账号已禁用');
}

function qly_api_users_reset_password(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `user` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('用户不存在');
    }
    $pwd = (string) qly_app_config()['default_password'];
    qly_update('user', $id, [
        'password_hash' => qly_password_hash($pwd),
    ]);
    // 重置密码后，该账号所有设备的会话全部失效（安全要求）
    qly_revoke_all_tokens($id);
    qly_log($admin, '用户管理', (string) $row['account'], '重置密码为默认密码');
    qly_ok(['defaultPassword' => $pwd], '密码已重置为默认密码');
}

function qly_api_users_delete(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `user` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('用户不存在');
    }
    if ((int) $row['id'] === (int) $admin['id']) {
        qly_fail('不能删除自己的账号');
    }
    // 幂等保护：已删除的账号再删不再追加后缀
    // （否则账号名会被反复追加 #deletedN，且首次 #deletedN 仍能被查到）
    if (!empty($row['deleted_at']) || (int) $row['status'] !== 1 || strpos((string) $row['account'], '#deleted') !== false) {
        qly_fail('该用户已被删除，无需重复操作');
    }
    $issueCount = (int) qly_val('SELECT COUNT(*) FROM `issue` WHERE submitter_id = :id', [':id' => $id])
        + (int) qly_val('SELECT COUNT(*) FROM `handle_record` WHERE handler_id = :id', [':id' => $id]);
    if ($issueCount > 0) {
        $refs = [];
        $n1 = (int) qly_val('SELECT COUNT(*) FROM `issue` WHERE submitter_id = :id', [':id' => $id]);
        $n2 = (int) qly_val('SELECT COUNT(*) FROM `handle_record` WHERE handler_id = :id', [':id' => $id]);
        if ($n1 > 0) {
            $refs[] = '提交问题 ' . $n1 . ' 条';
        }
        if ($n2 > 0) {
            $refs[] = '处理记录 ' . $n2 . ' 条';
        }
        qly_fail('该用户存在业务数据（' . implode('、', $refs) . '），无法删除，建议改为禁用');
    }
    // 逻辑删除：标记禁用 + 账号加一次性删除后缀，保留记录可追溯
    qly_update('user', $id, [
        'status' => 0,
        'deleted_at' => date('Y-m-d H:i:s'),
        'account' => $row['account'] . '#deleted' . $id,
    ]);
    // 删除后踢下该账号全部会话
    qly_revoke_all_tokens($id);
    qly_log($admin, '用户管理', (string) $row['account'], '删除用户（逻辑删除）');
    qly_ok(null, '用户已删除');
}

function qly_api_users_batch(): void
{
    $admin = qly_admin_user();
    $ids = qly_input('ids');
    $action = (string) qly_input('action', '');
    $value = qly_input('value');
    if (!is_array($ids) || !$ids) {
        qly_fail('请选择要操作的用户');
    }
    $targets = array_map('intval', $ids);
    $adminId = (int) $admin['id'];
    switch ($action) {
        case 'department':
            $deptId = (int) $value;
            $deptName = '未分配';
            if ($deptId > 0) {
                $dept = qly_one('SELECT * FROM `department` WHERE id = :id', [':id' => $deptId]);
                if (!$dept) {
                    qly_fail('目标部门不存在');
                }
                $deptName = (string) $dept['name'];
            }
            foreach ($targets as $tid) {
                qly_update('user', $tid, ['department_id' => $deptId > 0 ? $deptId : null]);
            }
            qly_log($admin, '用户管理', '共 ' . count($targets) . ' 个用户', '批量分配部门：' . $deptName);
            break;
        case 'role':
            $roles = qly_normalize_roles($value);
            if (!$roles) {
                qly_fail('请至少选择一个角色');
            }
            $rolesStr = implode(',', $roles);
            foreach ($targets as $tid) {
                if ($tid === $adminId && !in_array(QLY_ROLE_ADMIN, $roles, true)) {
                    qly_fail('不能移除自己的管理员角色');
                }
                qly_update('user', $tid, ['roles' => $rolesStr]);
            }
            qly_log($admin, '用户管理', '共 ' . count($targets) . ' 个用户', '批量调整角色：' . implode('、', $roles));
            break;
        case 'status':
            $status = (int) $value;
            foreach ($targets as $tid) {
                if ($tid === $adminId && $status === 0) {
                    qly_fail('不能禁用自己的账号');
                }
                qly_update('user', $tid, ['status' => $status]);
            }
            qly_log($admin, '用户管理', '共 ' . count($targets) . ' 个用户', $status === 1 ? '批量启用账号' : '批量禁用账号');
            break;
        default:
            qly_fail('不支持的批量操作');
    }
    qly_ok(['affected' => count($targets)], '批量操作完成');
}
