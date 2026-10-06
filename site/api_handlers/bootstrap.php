<?php
declare(strict_types=1);

/**
 * 合并接口：一次请求返回登录后首屏所需的全部基础数据。
 * 目的：Windows 下 PHP 内置服务器单线程，每个请求都要新建数据库连接（约 400ms），
 * 并发 4 个接口会被串行排队。合并为 1 个请求可显著缩短首屏与登录耗时。
 *
 * 返回：字典列表 + 部门列表 + 今日值班人（+ 可选的当前用户）。
 */

// 复用字典类型文案与值班人展示构造（qly_duty_today_payload）
require_once __DIR__ . '/dicts.php';
require_once __DIR__ . '/duty.php';

function qly_api_bootstrap(): void
{
    $user = qly_auth_user();

    // 字典（含停用项，前端自行分组）
    $dictItems = [];
    foreach (qly_all('SELECT * FROM `dict_item` ORDER BY type ASC, sort ASC, id ASC') as $r) {
        $dictItems[] = [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'typeLabel' => qly_dict_type_label((string) $r['type']),
            'name' => $r['name'],
            'sort' => (int) $r['sort'],
            'status' => (int) $r['status'],
            'referenceCount' => 0, // 首屏不需要，避免逐项计数拖慢
            'createdAt' => $r['created_at'],
        ];
    }

    // 部门
    $departments = [];
    foreach (qly_all('SELECT d.*, (SELECT COUNT(*) FROM `user` u WHERE u.department_id = d.id AND u.deleted_at IS NULL) AS user_count FROM `department` d ORDER BY d.sort ASC, d.id ASC') as $r) {
        $departments[] = [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'leader' => $r['leader'],
            'sort' => (int) $r['sort'],
            'status' => (int) $r['status'],
            'userCount' => (int) $r['user_count'],
            'createdAt' => $r['created_at'],
        ];
    }

    // 今日值班人（含头像与电话，供首页灯牌展示）
    $today = date('Y-m-d');
    $duty = qly_one(
        'SELECT ds.duty_date, ds.user_id, ds.user_name, ds.remark, u.avatar, u.phone, d.name AS department_name
           FROM `duty_schedule` ds
           LEFT JOIN `user` u ON u.id = ds.user_id
           LEFT JOIN `department` d ON d.id = u.department_id
          WHERE ds.duty_date = :d LIMIT 1',
        [':d' => $today]
    );

    qly_ok([
        'user' => qly_user_public($user),
        'dicts' => $dictItems,
        'departments' => $departments,
        'dutyToday' => array_merge(['date' => $today], qly_duty_today_payload($duty)),
    ]);
}
