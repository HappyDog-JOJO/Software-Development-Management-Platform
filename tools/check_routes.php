<?php
declare(strict_types=1);

/**
 * 开发期脚本：校验 api.php 路由表中的每个路由都能找到实现文件与处理函数。
 * 避免出现「路由指向不存在的实现」这类 500。
 * 用法：php -d extension=pdo_mysql -d extension=mbstring tools/check_routes.php
 */

$root = dirname(__DIR__);
$apiFile = $root . '/site/api.php';
$src = file_get_contents($apiFile);
if (!preg_match('/\$routes = \[(.*?)\n\];/s', $src, $m)) {
    fwrite(STDERR, "未能解析路由表\n");
    exit(1);
}
preg_match_all("/'([^']+)' => \[\[([^\]]+)\], '([^']+)', '([^']+)'\]/", $m[1], $rows, PREG_SET_ORDER);

$files = [];
$fail = 0;
echo '路由数：' . count($rows) . PHP_EOL;

foreach ($rows as $r) {
    [$all, $route, $methods, $file, $fn] = $r;
    $path = $root . '/site/api_handlers/' . $file;
    if (!is_file($path)) {
        echo '[FAIL] ' . $route . ' 实现文件缺失：' . $file . PHP_EOL;
        $fail++;
        continue;
    }
    if (!isset($files[$file])) {
        $files[$file] = file_get_contents($path);
    }
    if (!str_contains($files[$file], 'function ' . $fn . '(')) {
        echo '[FAIL] ' . $route . ' 处理函数缺失：' . $fn . ' @ ' . $file . PHP_EOL;
        $fail++;
        continue;
    }
    echo '[ OK ] ' . str_pad($route, 26) . ' -> ' . $file . '::' . $fn . PHP_EOL;
}

// 反向检查：api_handlers 目录里的处理函数是否都被路由引用
$usedFns = array_map(fn($r) => $r[4], $rows);
foreach (glob($root . '/site/api_handlers/*.php') as $f) {
    $content = file_get_contents($f);
    preg_match_all('/function (qly_api_\w+)\(/', $content, $fm);
    foreach ($fm[1] as $fn) {
        if (!in_array($fn, $usedFns, true)) {
            echo '[WARN] 未挂路由的处理函数：' . $fn . ' @ ' . basename($f) . PHP_EOL;
        }
    }
}

echo PHP_EOL . ($fail === 0 ? '全部路由实现完整' : ('存在 ' . $fail . ' 个问题')) . PHP_EOL;
exit($fail > 0 ? 1 : 0);
