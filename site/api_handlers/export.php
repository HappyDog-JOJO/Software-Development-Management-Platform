<?php
declare(strict_types=1);

/** 导出动作日志：导出问题列表 / 报表时写操作日志 */

function qly_api_export_log(): void
{
    $user = qly_auth_user();
    $kind = (string) qly_input('kind', 'issues');
    $count = qly_input_int('count', 0);
    $label = $kind === 'report' ? '报表统计' : '问题列表';
    qly_log($user, '导出', $label, '导出' . $label . ' Excel，共 ' . $count . ' 条记录');
    qly_ok(null, '导出动作已记录');
}
