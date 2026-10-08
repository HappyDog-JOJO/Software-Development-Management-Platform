<?php
declare(strict_types=1);

/**
 * 后端统一入口。
 * 路由形式：/api.php?r=<module>/<action>
 * 统一响应：{ code, data, message }；code!==0 为业务错误。
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/inc/auth.php';

// 上传限制兜底：upload_max_filesize / post_max_size 属 PHP_INI_PERDIR，
// 运行时 ini_set 通常无效，真正生效的是目录级 .user.ini（见 site/.user.ini）；
// 这里仅作兼容尝试。超限的友善提示在 upload.php 内根据 CONTENT_LENGTH 判断。
@ini_set('upload_max_filesize', '64M');
@ini_set('post_max_size', '64M');

$route = (string) qly_input('r', '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// 路由表：route => [允许的方法, 处理文件, 处理函数]
$routes = [
    'auth/login' => [['POST'], 'auth.php', 'qly_api_auth_login'],
    'auth/register' => [['POST'], 'auth.php', 'qly_api_auth_register'],
    'auth/account-available' => [['GET'], 'auth.php', 'qly_api_auth_account_available'],
    'auth/logout' => [['POST'], 'auth.php', 'qly_api_auth_logout'],
    'auth/change-password' => [['POST'], 'auth.php', 'qly_api_auth_change_password'],
    'auth/profile' => [['GET', 'POST', 'PUT'], 'auth.php', 'qly_api_auth_profile'],

    'users' => [['GET'], 'users.php', 'qly_api_users_list'],
    'users/create' => [['POST'], 'users.php', 'qly_api_users_create'],
    'users/update' => [['POST', 'PUT'], 'users.php', 'qly_api_users_update'],
    'users/department' => [['POST', 'PUT'], 'users.php', 'qly_api_users_department'],
    'users/role' => [['POST', 'PUT'], 'users.php', 'qly_api_users_role'],
    'users/status' => [['POST', 'PUT'], 'users.php', 'qly_api_users_status'],
    'users/reset-password' => [['POST'], 'users.php', 'qly_api_users_reset_password'],
    'users/delete' => [['POST', 'DELETE'], 'users.php', 'qly_api_users_delete'],
    'users/batch' => [['POST'], 'users.php', 'qly_api_users_batch'],

    'departments' => [['GET'], 'departments.php', 'qly_api_departments_list'],
    'departments/public' => [['GET'], 'departments.php', 'qly_api_departments_public'],    'departments/save' => [['POST'], 'departments.php', 'qly_api_departments_save'],
    'departments/status' => [['POST', 'PUT'], 'departments.php', 'qly_api_departments_status'],
    'departments/delete' => [['POST', 'DELETE'], 'departments.php', 'qly_api_departments_delete'],

    'dicts' => [['GET'], 'dicts.php', 'qly_api_dicts_list'],
    'bootstrap' => [['GET'], 'bootstrap.php', 'qly_api_bootstrap'],
    'dicts/save' => [['POST'], 'dicts.php', 'qly_api_dicts_save'],
    'dicts/status' => [['POST', 'PUT'], 'dicts.php', 'qly_api_dicts_status'],
    'dicts/delete' => [['POST', 'DELETE'], 'dicts.php', 'qly_api_dicts_delete'],

    'issues' => [['GET'], 'issues.php', 'qly_api_issues_list'],
    'issues/detail' => [['GET'], 'issues.php', 'qly_api_issues_detail'],
    'issues/create' => [['POST'], 'issues.php', 'qly_api_issues_create'],
    'issues/handle' => [['POST'], 'issues.php', 'qly_api_issues_handle'],
    'issues/handles' => [['GET'], 'issues.php', 'qly_api_issues_handles'],
    'issues/handlers' => [['GET'], 'issues.php', 'qly_api_issues_handlers'],
    'issues/append' => [['POST'], 'issues.php', 'qly_api_issues_append'],
    'issues/delete' => [['POST', 'DELETE'], 'issues.php', 'qly_api_issues_delete'],
    'issues/status' => [['POST', 'PUT'], 'issues.php', 'qly_api_issues_status'],

    'upload' => [['POST'], 'upload.php', 'qly_api_upload'],

    'duty/list' => [['GET'], 'duty.php', 'qly_api_duty_list'],
    'duty/today' => [['GET'], 'duty.php', 'qly_api_duty_today'],
    'duty/save' => [['POST', 'PUT'], 'duty.php', 'qly_api_duty_save'],
    'duty/delete' => [['POST', 'DELETE'], 'duty.php', 'qly_api_duty_delete'],
    'duty/batch' => [['POST'], 'duty.php', 'qly_api_duty_batch'],

    // 公告：读取最新一条对全员开放（提交页灯牌）；维护仅管理员
    'notice/latest' => [['GET'], 'notice.php', 'qly_api_notice_latest'],
    'notice/list' => [['GET'], 'notice.php', 'qly_api_notice_list'],
    'notice/save' => [['POST', 'PUT'], 'notice.php', 'qly_api_notice_save'],
    'notice/status' => [['POST', 'PUT'], 'notice.php', 'qly_api_notice_status'],
    'notice/delete' => [['POST', 'DELETE'], 'notice.php', 'qly_api_notice_delete'],

    // 站内消息通知（前端轮询 pending 弹 Windows 系统通知）
    'notify/pending' => [['GET'], 'notify.php', 'qly_api_notify_pending'],
    'notify/notified' => [['POST'], 'notify.php', 'qly_api_notify_mark_notified'],
    'notify/list' => [['GET'], 'notify.php', 'qly_api_notify_list'],
    'notify/unread' => [['GET'], 'notify.php', 'qly_api_notify_unread'],
    'notify/read' => [['POST', 'PUT'], 'notify.php', 'qly_api_notify_read'],

    // 站点级配置（灯牌 logo）：读所有登录用户，写仅管理员
    'config/logos' => [['GET'], 'config.php', 'qly_api_config_logos'],
    'config/logo/save' => [['POST', 'PUT'], 'config.php', 'qly_api_config_save_logo'],

    'logs' => [['GET'], 'logs.php', 'qly_api_logs_list'],
    'export/log' => [['POST'], 'export.php', 'qly_api_export_log'],
    'reports/overview' => [['GET'], 'reports.php', 'qly_api_reports_overview'],
    'reports/ranks' => [['GET'], 'reports.php', 'qly_api_reports_ranks'],
    'reports/cross' => [['GET'], 'reports.php', 'qly_api_reports_cross'],
    'reports/advice' => [['GET'], 'reports.php', 'qly_api_reports_advice'],
    'reports/learning' => [['GET'], 'reports.php', 'qly_api_reports_learning'],
    'reports/training' => [['POST', 'PUT'], 'reports.php', 'qly_api_reports_training'],

    // 需求管理模块（方案见 docs/需求管理模块-需求方案.md）
    'devreqs' => [['GET'], 'devreqs.php', 'qly_api_devreqs_list'],
    'devreqs/detail' => [['GET'], 'devreqs.php', 'qly_api_devreqs_detail'],
    'devreqs/create' => [['POST'], 'devreqs.php', 'qly_api_devreqs_create'],
    'devreqs/update' => [['POST'], 'devreqs.php', 'qly_api_devreqs_update'],
    'devreqs/delete' => [['POST'], 'devreqs.php', 'qly_api_devreqs_delete'],
    'devreqs/review' => [['POST'], 'devreqs.php', 'qly_api_devreqs_review'],
    'devreqs/claim' => [['POST'], 'devreqs.php', 'qly_api_devreqs_claim'],
    'devreqs/design' => [['POST'], 'devreqs.php', 'qly_api_devreqs_design'],
    'devreqs/battle-publish' => [['POST'], 'devreqs.php', 'qly_api_devreqs_battle_publish'],
    'devreqs/devs' => [['GET'], 'devreqs.php', 'qly_api_devreqs_devs'],
    'devreqs/pms' => [['GET'], 'devreqs.php', 'qly_api_devreqs_pms'],
];

if (!isset($routes[$route])) {
    qly_json(null, '接口不存在：' . $route, 404, 404);
}
[$methods, $file, $fn] = $routes[$route];
if (!in_array($method, $methods, true)) {
    qly_json(null, '请求方法不被支持：' . $method, 405, 405);
}

require_once __DIR__ . '/api_handlers/' . $file;
if (!function_exists($fn)) {
    qly_json(null, '接口实现缺失：' . $route, 500, 500);
}
try {
    $fn();
} catch (Throwable $e) {
    qly_json(null, '服务异常：' . $e->getMessage(), 500, 500);
}
