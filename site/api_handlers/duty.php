<?php
declare(strict_types=1);

/**
 * 售后值班排班。
 * 规则：一天一个值班人；值班人只能从「售后服务人员」角色中选择；
 * 今日值班人：所有登录用户可读（顶栏标记、问题提交页灯牌——让销售员知道找谁）；
 * 排班表与配置（新增/修改/删除/批量）：仅管理员可读可写（菜单与接口双重限制）。
 */

/** 取某个月份的起止日期，month 形如 2026-09 */
function qly_duty_month_range(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        qly_fail('月份格式应为 YYYY-MM');
    }
    $start = $month . '-01';
    $ts = strtotime($start);
    if ($ts === false) {
        qly_fail('月份不合法');
    }
    return [$start, date('Y-m-t', $ts)];
}

/** 可作为值班人的用户（启用中且包含「售后服务人员」角色） */
function qly_duty_candidates(): array
{
    $rows = qly_all(
        "SELECT id, account, name, phone, roles, department_id
           FROM `user`
          WHERE status = 1 AND deleted_at IS NULL AND roles LIKE :r
          ORDER BY id ASC",
        [':r' => '%' . QLY_ROLE_SERVICE . '%']
    );
    $out = [];
    foreach ($rows as $r) {
        // LIKE 只是初筛，需精确确认角色串里确实包含该角色
        if (!qly_has_role($r, [QLY_ROLE_SERVICE])) {
            continue;
        }
        $out[] = [
            'id' => (int) $r['id'],
            'account' => $r['account'],
            'name' => $r['name'] !== null && $r['name'] !== '' ? $r['name'] : $r['account'],
            'departmentId' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
        ];
    }
    return $out;
}

/** 按日期范围列出排班（仅管理员：排班页仅管理员可见） */
function qly_api_duty_list(): void
{
    qly_admin_user();
    $dateStart = (string) qly_input('dateStart', '');
    $dateEnd = (string) qly_input('dateEnd', '');
    $month = (string) qly_input('month', '');

    if ($month !== '') {
        [$dateStart, $dateEnd] = qly_duty_month_range($month);
    }
    if ($dateStart === '' || $dateEnd === '') {
        [$dateStart, $dateEnd] = qly_duty_month_range(date('Y-m'));
    }
    if (strtotime($dateStart) === false || strtotime($dateEnd) === false || $dateStart > $dateEnd) {
        qly_fail('日期范围不合法');
    }

    $rows = qly_all(
        'SELECT id, duty_date, user_id, user_name, remark, created_at, updated_at
           FROM `duty_schedule`
          WHERE duty_date BETWEEN :s AND :e
          ORDER BY duty_date ASC',
        [':s' => $dateStart, ':e' => $dateEnd]
    );

    $items = array_map(fn(array $r): array => [
        'id' => (int) $r['id'],
        'dutyDate' => $r['duty_date'],
        'userId' => (int) $r['user_id'],
        'userName' => $r['user_name'],
        'remark' => $r['remark'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
    ], $rows);

    qly_ok([
        'dateStart' => $dateStart,
        'dateEnd' => $dateEnd,
        'items' => $items,
        'candidates' => qly_duty_candidates(),
    ]);
}

/** 值班人展示信息：姓名/头像/电话（头像与电话取自 user 表，排班表只存姓名冗余） */
function qly_duty_today_payload(?array $row): array
{
    if ($row === null) {
        return [
            'onDuty' => false,
            'userId' => null,
            'userName' => null,
            'avatar' => null,
            'phone' => null,
            'departmentName' => null,
            'remark' => '',
        ];
    }
    return [
        'onDuty' => true,
        'userId' => (int) $row['user_id'],
        'userName' => $row['user_name'],
        'avatar' => $row['avatar'] ?? null,
        'phone' => $row['phone'] ?? null,
        'departmentName' => $row['department_name'] ?? null,
        'remark' => (string) $row['remark'],
    ];
}

/** 今日值班人（所有登录用户可见；未排班返回 onDuty=false） */
function qly_api_duty_today(): void
{
    qly_auth_user();
    $today = date('Y-m-d');
    $row = qly_one(
        'SELECT ds.duty_date, ds.user_id, ds.user_name, ds.remark, u.avatar, u.phone, d.name AS department_name
           FROM `duty_schedule` ds
           LEFT JOIN `user` u ON u.id = ds.user_id
           LEFT JOIN `department` d ON d.id = u.department_id
          WHERE ds.duty_date = :d LIMIT 1',
        [':d' => $today]
    );
    qly_ok(array_merge(['date' => $today], qly_duty_today_payload($row)));
}

/** 保存单日排班（管理员 / 售后服务人员） */
function qly_api_duty_save(): void
{
    $user = qly_auth_user();
    if (!qly_can_manage_duty($user)) {
        qly_forbidden('仅管理员可维护排班');
    }

    $dutyDate = (string) qly_input('dutyDate', '');
    $userId = qly_input_int('userId', 0);
    $remark = (string) qly_input('remark', '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dutyDate) || strtotime($dutyDate) === false) {
        qly_fail('请选择值班日期');
    }
    if ($userId <= 0) {
        qly_fail('请选择值班人');
    }

    $target = qly_one('SELECT * FROM `user` WHERE id = :id', [':id' => $userId]);
    if (!$target) {
        qly_fail('值班人不存在');
    }
    // 值班人必须是「售后服务人员」角色
    if (!qly_has_role($target, [QLY_ROLE_SERVICE])) {
        qly_fail('值班人必须具有「售后服务人员」角色');
    }
    if ((int) $target['status'] !== 1) {
        qly_fail('该用户已被禁用，不能作为值班人');
    }

    $userName = $target['name'] !== null && $target['name'] !== '' ? (string) $target['name'] : (string) $target['account'];
    $exists = qly_one('SELECT * FROM `duty_schedule` WHERE duty_date = :d LIMIT 1', [':d' => $dutyDate]);
    $now = date('Y-m-d H:i:s');

    if ($exists) {
        qly_update('duty_schedule', (int) $exists['id'], [
            'user_id' => $userId,
            'user_name' => $userName,
            'remark' => $remark,
            'updated_at' => $now,
        ]);
        qly_log($user, '排班维护', $dutyDate, '修改排班：' . $exists['user_name'] . ' → ' . $userName
            . ($remark !== '' ? '（备注：' . $remark . '）' : ''));
    } else {
        qly_insert('duty_schedule', [
            'duty_date' => $dutyDate,
            'user_id' => $userId,
            'user_name' => $userName,
            'remark' => $remark,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        qly_log($user, '排班维护', $dutyDate, '新增排班：' . $userName
            . ($remark !== '' ? '（备注：' . $remark . '）' : ''));
    }

    qly_ok(['dutyDate' => $dutyDate, 'userId' => $userId, 'userName' => $userName], '排班已保存');
}

/** 清除某日排班（管理员 / 售后服务人员） */
function qly_api_duty_delete(): void
{
    $user = qly_auth_user();
    if (!qly_can_manage_duty($user)) {
        qly_forbidden('仅管理员可维护排班');
    }
    $dutyDate = (string) qly_input('dutyDate', '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dutyDate)) {
        qly_fail('日期不合法');
    }
    $row = qly_one('SELECT * FROM `duty_schedule` WHERE duty_date = :d LIMIT 1', [':d' => $dutyDate]);
    if (!$row) {
        qly_fail('该日期暂无排班');
    }
    qly_exec('DELETE FROM `duty_schedule` WHERE id = :id', [':id' => (int) $row['id']]);
    qly_log($user, '排班维护', $dutyDate, '清除排班：原值班人 ' . $row['user_name']);
    qly_ok(null, '该日排班已清除');
}

/**
 * 批量排班：在指定【起止日期】区间内逐日轮换值班人。
 * - 轮换方式：按人员列表顺序**逐日轮换**（每天一人），跨整个区间连续循环。
 * - 跳过：可只跳「周日」（公司单休），或跳过周六+周日。
 * - 已存在的排班会被覆盖为新轮值结果。
 */
function qly_api_duty_batch(): void
{
    $user = qly_auth_user();
    if (!qly_can_manage_duty($user)) {
        qly_forbidden('仅管理员可维护排班');
    }

    $startDate = (string) qly_input('startDate', '');
    $endDate = (string) qly_input('endDate', '');
    $userIds = qly_input('userIds');
    // skipWeekend 兼容旧参数：1=跳周六周日；skipSunday 优先，默认跳周日（单休）
    $skipSunday = qly_input('skipSunday');
    if ($skipSunday === null) {
        $skipSunday = (int) qly_input('skipWeekend', 1) === 1 ? 1 : 0;
    }
    $skipSunday = (int) $skipSunday === 1;
    $skipSaturday = (int) qly_input('skipSaturday', 0) === 1;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || strtotime($startDate) === false) {
        qly_fail('请选择开始日期');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) || strtotime($endDate) === false) {
        qly_fail('请选择结束日期');
    }
    if ($startDate > $endDate) {
        qly_fail('开始日期不能晚于结束日期');
    }
    if (!is_array($userIds) || !$userIds) {
        qly_fail('请选择参与轮值的人员');
    }
    // 区间上限：防止误操作一次写过多数据（一年足够）
    $spanDays = (int) floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
    if ($spanDays > 366) {
        qly_fail('排班区间过长，请控制在一年以内');
    }

    // 校验全部人员具备售后角色且启用中
    $names = [];
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        $t = qly_one('SELECT * FROM `user` WHERE id = :id', [':id' => $uid]);
        if (!$t) {
            qly_fail('人员不存在（ID ' . $uid . '）');
        }
        if (!qly_has_role($t, [QLY_ROLE_SERVICE])) {
            qly_fail('「' . ($t['name'] ?: $t['account']) . '」不具备售后服务人员角色，不能参与轮值');
        }
        if ((int) $t['status'] !== 1) {
            qly_fail('「' . ($t['name'] ?: $t['account']) . '」已被禁用，不能参与轮值');
        }
        $names[] = ['id' => $uid, 'name' => $t['name'] !== null && $t['name'] !== '' ? (string) $t['name'] : (string) $t['account']];
    }

    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    $now = date('Y-m-d H:i:s');
    $seq = 0;      // 轮值指针（只对实际排班日递增，保证逐日连续轮换）
    $saved = 0;
    $skipped = 0;

    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $dateStr = date('Y-m-d', $ts);
        $dow = (int) date('N', $ts); // 1=周一 ... 7=周日
        if ($skipSunday && $dow === 7) {
            $skipped++;
            continue;
        }
        if ($skipSaturday && $dow === 6) {
            $skipped++;
            continue;
        }
        $pick = $names[$seq % count($names)];
        $seq++;

        $exists = qly_one('SELECT * FROM `duty_schedule` WHERE duty_date = :d LIMIT 1', [':d' => $dateStr]);
        if ($exists) {
            qly_update('duty_schedule', (int) $exists['id'], [
                'user_id' => $pick['id'],
                'user_name' => $pick['name'],
                'updated_at' => $now,
            ]);
        } else {
            qly_insert('duty_schedule', [
                'duty_date' => $dateStr,
                'user_id' => $pick['id'],
                'user_name' => $pick['name'],
                'remark' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $saved++;
    }

    $namesStr = implode('、', array_map(fn($n) => $n['name'], $names));
    qly_log($user, '排班维护', $startDate . ' ~ ' . $endDate,
        '批量排班：' . $namesStr . ' 逐日轮值，共 ' . $saved . ' 天'
        . ($skipped > 0 ? '（跳过 ' . $skipped . ' 天，规则：' . ($skipSunday ? '仅跳周日' : '') . ($skipSaturday ? '跳过周六日' : '') . '）' : ''));

    qly_ok(['saved' => $saved, 'skipped' => $skipped, 'startDate' => $startDate, 'endDate' => $endDate],
        '已排好 ' . $saved . ' 天');
}
