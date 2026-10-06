<?php
declare(strict_types=1);

/** 认证接口：登录 / 注册 / 退出 / 改密 / 资料 */

function qly_api_auth_login(): void
{
    $account = (string) qly_input('account', '');
    $password = (string) qly_input('password', '');
    if ($account === '' || $password === '') {
        qly_fail('账号和密码不能为空');
    }
    $row = qly_one('SELECT u.*, d.name AS department_name FROM `user` u LEFT JOIN `department` d ON d.id = u.department_id WHERE u.account = :a LIMIT 1', [':a' => $account]);
    if (!$row || !qly_password_verify($password, (string) $row['password_hash'])) {
        // 记录失败尝试（不泄露账号是否存在）
        qly_log(null, '登录', $account, '登录失败：账号或密码错误');
        qly_fail('账号或密码错误');
    }
    if ((int) $row['status'] !== 1) {
        qly_fail('该账号已被禁用，请联系管理员');
    }
    $token = qly_issue_token((int) $row['id']);
    $row['token'] = $token;
    qly_log($row, '登录', (string) $row['account'], '登录成功');
    qly_ok(['token' => $token, 'user' => qly_user_public($row)], '登录成功');
}

/**
 * 账号是否已被占用（公开接口，供注册页实时校验）。
 * 只回「是否可用」，不返回任何用户信息；未登录也可调用。
 */
function qly_api_auth_account_available(): void
{
    $account = trim((string) qly_input('account', ''));
    if ($account === '') {
        qly_fail('请输入真实姓名');
    }
    if (mb_strlen($account) < 2 || mb_strlen($account) > 20) {
        qly_fail('姓名需 2-20 位');
    }
    $n = (int) qly_val('SELECT COUNT(*) FROM `user` WHERE account = :a', [':a' => $account]);
    qly_ok(['available' => $n === 0], $n === 0 ? '姓名可用' : '该姓名已被使用');
}

function qly_api_auth_register(): void
{
    // “真实姓名”字段：即登录名（account），同时作为展示姓名（name）
    $name = trim((string) qly_input('name', ''));
    $account = trim((string) qly_input('account', ''));
    if ($account === '') {
        $account = $name; // 兼容只传 name 的提交
    }
    $password = (string) qly_input('password', '');
    $phone = (string) qly_input('phone', '');
    $avatar = (string) qly_input('avatar', ''); // 选填
    $departmentId = qly_input_int('departmentId', 0); // 必填（仅接受启用中的部门）

    if ($account === '' || $password === '' || $phone === '') {
        qly_fail('真实姓名、密码、联系电话均为必填');
    }
    if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,20}$/u', $account)) {
        qly_fail('姓名需 2-20 位，支持中英文、数字与下划线');
    }
    if (mb_strlen($password) < 6) {
        qly_fail('密码至少 6 位');
    }
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        qly_fail('联系电话格式不正确，应为 11 位手机号');
    }
    $exists = qly_val('SELECT COUNT(*) FROM `user` WHERE account = :a', [':a' => $account]);
    if ((int) $exists > 0) {
        qly_fail('该姓名已被使用，请更换');
    }
    // 部门必填：必须是启用中的真实部门（前端只列出启用的，服务端同样强制校验）
    if ($departmentId <= 0) {
        qly_fail('请选择所属部门');
    }
    $dept = qly_one('SELECT * FROM `department` WHERE id = :id', [':id' => $departmentId]);
    if (!$dept) {
        qly_fail('所选部门不存在');
    }
    if ((int) $dept['status'] !== 1) {
        qly_fail('所选部门已停用，请选择其他部门');
    }
    $deptId = (int) $dept['id'];
    $id = qly_insert('user', [
        'account' => $account,
        'password_hash' => qly_password_hash($password),
        'name' => $name !== '' ? $name : $account,
        'phone' => $phone,
        // 头像选填：未上传时存空字符串（与历史数据一致，前端会回退为姓名首字头像）
        'avatar' => $avatar,
        'department_id' => $deptId,
        'roles' => QLY_ROLE_SALES,
        'status' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $row = qly_one('SELECT * FROM `user` WHERE id = :id', [':id' => $id]);
    qly_log($row, '注册', $account, '新用户注册，角色：销售员（部门：' . (string) $dept['name'] . '）');
    qly_ok(qly_user_public($row), '注册成功');
}

function qly_api_auth_logout(): void
{
    $user = qly_current_user();
    if ($user) {
        // 仅注销当前设备的会话，不影响该账号在其它设备上的登录
        qly_revoke_token(qly_bearer_token());
        qly_log($user, '退出', (string) $user['account'], '退出登录');
    }
    qly_ok(null, '已退出登录');
}

function qly_api_auth_change_password(): void
{
    $user = qly_auth_user();
    $old = (string) qly_input('oldPassword', '');
    $new = (string) qly_input('newPassword', '');
    if ($new === '') {
        qly_fail('新密码不能为空');
    }
    if (mb_strlen($new) < 6) {
        qly_fail('新密码至少 6 位');
    }
    if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        qly_fail('新密码需同时包含字母与数字');
    }
    if (!qly_password_verify($old, (string) $user['password_hash'])) {
        qly_fail('原密码不正确');
    }
    if (qly_password_verify($new, (string) $user['password_hash'])) {
        qly_fail('新密码不能与原密码相同');
    }
    qly_update('user', (int) $user['id'], [
        'password_hash' => qly_password_hash($new),
    ]);
    // 改密后踢掉其它设备的会话，保留当前设备（避免把自己登出）
    qly_exec(
        'DELETE FROM `user_token` WHERE user_id = :u AND token <> :t',
        [':u' => (int) $user['id'], ':t' => qly_bearer_token()]
    );
    qly_log($user, '改密', (string) $user['account'], '修改登录密码');
    qly_ok(null, '密码修改成功');
}

function qly_api_auth_profile(): void
{
    $user = qly_auth_user();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        qly_ok(qly_user_public($user));
    }
    $data = [];
    $avatar = qly_input('avatar');
    $phone = qly_input('phone');
    $name = qly_input('name');
    if ($avatar !== null && $avatar !== '') {
        $data['avatar'] = (string) $avatar;
    }
    if ($phone !== null && $phone !== '') {
        if (!preg_match('/^1[3-9]\d{9}$/', (string) $phone)) {
            qly_fail('联系电话格式不正确，应为 11 位手机号');
        }
        $data['phone'] = (string) $phone;
    }
    if ($name !== null && $name !== '') {
        $data['name'] = (string) $name;
    }
    if (!$data) {
        qly_fail('没有需要更新的内容');
    }
    qly_update('user', (int) $user['id'], $data);
    $fresh = qly_one('SELECT u.*, d.name AS department_name FROM `user` u LEFT JOIN `department` d ON d.id = u.department_id WHERE u.id = :id', [':id' => (int) $user['id']]);
    qly_log($user, '用户管理', (string) $user['account'], '更新个人资料：' . implode('、', array_keys($data)));
    qly_ok(qly_user_public($fresh), '资料已更新');
}
