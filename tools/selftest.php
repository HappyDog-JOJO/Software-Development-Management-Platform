<?php
declare(strict_types=1);

/**
 * 本地自测脚本（开发期工具，不进交付包）：直接调用接口处理函数，验证核心链路。
 * 用法：php -d extension=pdo_mysql -d extension=mbstring tools/selftest.php
 */

$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once $root . '/site/inc/auth.php';

/** 捕获 qly_json 的输出（qly_json 会 exit） */
function call_api(string $file, string $fn, array $body, string $method = 'POST', array $query = [], array $headers = []): array
{
    global $root;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $body;
    $_GET = $query;
    $raw = json_encode($body, JSON_UNESCAPED_UNICODE);
    // 用临时流模拟 php://input
    if (!defined('QLY_SELFTEST')) {
        define('QLY_SELFTEST', 1);
    }
    $cmd = PHP_BINARY
        . ' -d extension_dir="' . ini_get('extension_dir') . '"'
        . ' -d extension=pdo_mysql -d extension=mbstring'
        . ' -r ' . escapeshellarg(
            '$root=' . var_export($root, true) . ';'
            . '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
            . '$_SERVER["REMOTE_ADDR"]="127.0.0.1";'
            . 'parse_str(' . var_export(http_build_query($query), true) . ', $_GET);'
            . 'parse_str(' . var_export(http_build_query($body), true) . ', $_POST);'
            . 'require $root . "/site/inc/auth.php";'
            . 'require $root . "/site/api_handlers/' . $file . '";'
            . $fn . '();'
        );
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $decoded = json_decode($text, true);
    return ['code' => $code, 'raw' => $text, 'json' => $decoded];
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] " . $name . PHP_EOL;
    } else {
        $fail++;
        echo "[FAIL] " . $name . ' ' . $extra . PHP_EOL;
    }
}

echo "=== 1. 数据库连通与数据量 ===";
$counts = [
    'user' => (int) qly_val('SELECT COUNT(*) FROM `user`'),
    'department' => (int) qly_val('SELECT COUNT(*) FROM `department`'),
    'dict_item' => (int) qly_val('SELECT COUNT(*) FROM `dict_item`'),
    'issue' => (int) qly_val('SELECT COUNT(*) FROM `issue`'),
    'handle_record' => (int) qly_val('SELECT COUNT(*) FROM `handle_record`'),
    'operation_log' => (int) qly_val('SELECT COUNT(*) FROM `operation_log`'),
];
echo json_encode($counts, JSON_UNESCAPED_UNICODE) . PHP_EOL;
check('用户数 >= 8', $counts['user'] >= 8);
check('部门数 >= 4', $counts['department'] >= 4);
check('问题数 >= 30', $counts['issue'] >= 30);
check('处理记录存在', $counts['handle_record'] > 0);
check('日志数 >= 20', $counts['operation_log'] >= 20);

echo PHP_EOL . "=== 2. 默认管理员与密码哈希 ===";
// 管理员账号/密码不写进仓库：用环境变量提供（未提供时跳过对应断言）
$adminAccount = (string) (getenv('ADMIN_ACCOUNT') ?: '');
$adminPassword = (string) (getenv('ADMIN_PASSWORD') ?: '');
$admin = $adminAccount !== '' ? qly_one('SELECT * FROM `user` WHERE account = :a', [':a' => $adminAccount]) : null;
check('默认管理员存在（设 ADMIN_ACCOUNT 后校验）', $adminAccount === '' || $admin !== null);
check('管理员角色正确', $admin === null || qly_has_role($admin, [QLY_ROLE_ADMIN]));
check('密码以哈希存储（非明文）', $admin === null || str_starts_with((string) $admin['password_hash'], '$2y$'));
check('管理员密码可校验通过（设 ADMIN_PASSWORD 后校验）', $admin === null || $adminPassword === '' || password_verify($adminPassword, (string) $admin['password_hash']));

echo PHP_EOL . "=== 3. 字典预置项 ===";
$valueNames = qly_all("SELECT name FROM `dict_item` WHERE type='value' AND status=1 ORDER BY sort");
$names = array_column($valueNames, 'name');
check('问题价值三项预置且顺序正确', $names === ['很有价值', '比较有价值', '毫无价值'], json_encode($names, JSON_UNESCAPED_UNICODE));
$handleNames = array_column(qly_all("SELECT name FROM `dict_item` WHERE type='handle' AND status=1 ORDER BY sort"), 'name');
check('处理情况预置 未处理/处理中/已处理/已驳回', $handleNames === ['未处理', '处理中', '已处理', '已驳回'], json_encode($handleNames, JSON_UNESCAPED_UNICODE));
$moduleCount = (int) qly_val("SELECT COUNT(*) FROM `dict_item` WHERE type='module' AND status=1");
check('所属模块 >= 6', $moduleCount >= 6);

echo PHP_EOL . "=== 4. 演示账号角色分布 ===";
$roleDist = [];
foreach (qly_all('SELECT roles FROM `user` WHERE deleted_at IS NULL') as $row) {
    foreach (qly_parse_roles((string) $row['roles']) as $r) {
        $roleDist[$r] = ($roleDist[$r] ?? 0) + 1;
    }
}
foreach ($roleDist as $r => $c) {
    echo '  ' . $r . ' = ' . $c . PHP_EOL;
}
check('四种角色齐备', count($roleDist) === 4);
check('存在多角色用户（并集演示）', (int) qly_val("SELECT COUNT(*) FROM `user` WHERE roles LIKE '%,%' AND deleted_at IS NULL") > 0);

echo PHP_EOL . "=== 5. 问题数据覆盖度 ===";
$statusDist = qly_all('SELECT status, COUNT(*) AS c FROM `issue` GROUP BY status');
foreach ($statusDist as $r) {
    echo '  状态 ' . $r['status'] . ' = ' . $r['c'] . PHP_EOL;
}
check('问题状态至少覆盖 2 种', count($statusDist) >= 2);
$multiHandled = (int) qly_val('SELECT COUNT(*) FROM (SELECT issue_id FROM `handle_record` GROUP BY issue_id HAVING COUNT(*) > 1) t');
check('存在多次处理的问题（验证历史记录）', $multiHandled > 0, 'multiHandled=' . $multiHandled);
$withAttach = (int) qly_val('SELECT COUNT(DISTINCT issue_id) FROM `issue_attachment`');
check('存在带附件的问题', $withAttach > 0);
$deptSpread = (int) qly_val('SELECT COUNT(DISTINCT department_id) FROM `issue`');
check('问题跨部门', $deptSpread >= 3, 'depts=' . $deptSpread);

echo PHP_EOL . "=== 6. 权限范围口径（数据层） ===";
// 销售经理（manager01 属销售一部 id=1）只能看到本部门问题
$manager = qly_one("SELECT u.*, d.name AS department_name FROM `user` u LEFT JOIN `department` d ON d.id=u.department_id WHERE u.account='manager01'");
check('manager01 属于销售一部', $manager && (int) $manager['department_id'] === 1);
$all = (int) qly_val('SELECT COUNT(*) FROM `issue`');
$dept = (int) qly_val('SELECT COUNT(*) FROM `issue` WHERE department_id = 1');
echo '  全部问题=' . $all . '，销售一部问题=' . $dept . PHP_EOL;
check('本部门数据是全量子集', $dept < $all && $dept > 0);
check('权限范围 SQL 片段生效', qly_scope_where($manager, 'i')[0] !== '');

echo PHP_EOL . "=== 7. 报表统计口径 ===";
$total = (int) qly_val('SELECT COUNT(*) FROM `issue`');
$rated = (int) qly_val("SELECT COUNT(*) FROM `issue` WHERE value_id IS NOT NULL");
$valuable = (int) qly_val("SELECT COUNT(*) FROM `issue` i JOIN `dict_item` v ON v.id=i.value_id WHERE v.name IN ('很有价值','比较有价值')");
$very = (int) qly_val("SELECT COUNT(*) FROM `issue` i JOIN `dict_item` v ON v.id=i.value_id WHERE v.name='很有价值'");
$fair = (int) qly_val("SELECT COUNT(*) FROM `issue` i JOIN `dict_item` v ON v.id=i.value_id WHERE v.name='比较有价值'");
echo '  总数=' . $total . ' 已评级=' . $rated . ' 有价值=' . $valuable . ' (很有=' . $very . ' 比较=' . $fair . ')' . PHP_EOL;
check('有价值 = 很有价值 + 比较有价值', $valuable === $very + $fair);
$avg = qly_val('SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, handled_at))/60 FROM `issue` WHERE handled_at IS NOT NULL');
check('平均处理时长可计算', $avg !== null && (float) $avg > 0, 'avg=' . (string) $avg);
$handleRank = qly_all('SELECT handler_name, COUNT(*) c FROM `handle_record` GROUP BY handler_name ORDER BY c DESC');
check('处理量排行有数据', count($handleRank) > 0);

echo PHP_EOL . "=== 8. 引用校验前置条件 ===";
$modRef = (int) qly_val("SELECT COUNT(*) FROM `issue` WHERE module_id = (SELECT id FROM `dict_item` WHERE name='产品质量' LIMIT 1)");
check('模块字典项存在引用（用于验证不可删除）', $modRef > 0, 'refs=' . $modRef);
$deptRef = (int) qly_val('SELECT COUNT(*) FROM `issue` WHERE department_id = 1');
check('部门存在引用（用于验证不可删除）', $deptRef > 0);

echo PHP_EOL . "=== 9. 操作日志覆盖度 ===";
$logTypes = array_column(qly_all('SELECT DISTINCT type FROM `operation_log`'), 'type');
echo '  类型：' . implode('、', $logTypes) . PHP_EOL;
$required = ['登录', '提交问题', '问题处理'];
foreach ($required as $t) {
    check('含日志类型：' . $t, in_array($t, $logTypes, true));
}

echo PHP_EOL . "=================================" . PHP_EOL;
echo '通过 ' . $pass . ' 项，失败 ' . $fail . ' 项' . PHP_EOL;
exit($fail > 0 ? 1 : 0);
