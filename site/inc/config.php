<?php
declare(strict_types=1);

/**
 * 配置加载。
 * 规则：数据库配置只从 .env 读取；缺少配置项或值为空时直接报错，禁止任何默认回退。
 * 兼容两种部署形态：
 *   1) 本地开发：站点根 site/ 为项目根的子目录，.env 在项目根或站点根；
 *   2) 线上/打包：站点根即应用根，.env 在站点根。
 * 本地覆盖文件 config/db.local.php 存在时优先生效（不上传、不进包）。
 */

// 统一时区：服务器与数据库均为东八区，避免“今日值班”“时间落库”错 8 小时。
// 通过环境变量 APP_TIMEZONE 可覆盖，默认 Asia/Shanghai。
date_default_timezone_set(qly_timezone());

function qly_timezone(): string
{
    $tz = getenv('APP_TIMEZONE');
    if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        return $tz;
    }
    return 'Asia/Shanghai';
}

function qly_fatal_config(string $message, array $missing = []): void
{
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $out = ['code' => 500, 'data' => null, 'message' => $message];
    if ($missing) {
        $out['missing'] = array_values($missing);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function qly_site_dir(): string
{
    return dirname(__DIR__);
}

function qly_load_env(): array
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $siteDir = qly_site_dir();
    $rootDir = dirname($siteDir);
    $candidates = [$siteDir . DIRECTORY_SEPARATOR . '.env', $rootDir . DIRECTORY_SEPARATOR . '.env'];
    $envFile = null;
    foreach ($candidates as $p) {
        // 加 @：线上 open_basedir 可能不包含上一级目录，is_file 会抛警告并暴露服务器路径。
        if (@is_file($p)) {
            $envFile = $p;
            break;
        }
    }
    if ($envFile === null) {
        qly_fatal_config('数据库配置缺失：未找到 .env 文件');
    }
    $data = [];
    // 加 @：同上，避免线上 open_basedir 下 file() 抛警告并暴露服务器路径。
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        qly_fatal_config('数据库配置缺失：.env 读取失败');
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if (strlen($val) >= 2) {
            $q = $val[0];
            if (($q === '"' || $q === "'") && substr($val, -1) === $q) {
                $val = substr($val, 1, -1);
            }
        }
        $data[$key] = $val;
    }

    // 本地数据库覆盖（仅本地存在）
    $localFile = $rootDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.local.php';
    // 加 @：线上 open_basedir 不包含上一级目录时，is_file 会抛警告并暴露服务器路径。
    if (@is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $data = array_merge($data, $local);
        }
    }
    $loaded = $data;
    return $loaded;
}

function qly_env(string $key, bool $required = true): string
{
    $env = qly_load_env();
    $val = isset($env[$key]) ? trim((string) $env[$key]) : '';
    if ($val === '' && $required) {
        qly_fatal_config('数据库配置缺失：' . $key . ' 未配置或为空', [$key]);
    }
    return $val;
}

/** 数据库连接参数：全部来自 .env，无默认值 */
function qly_db_config(): array
{
    $required = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
    $missing = [];
    $env = qly_load_env();
    foreach ($required as $k) {
        if (!isset($env[$k]) || trim((string) $env[$k]) === '') {
            $missing[] = $k;
        }
    }
    $driver = isset($env['DB_CONNECTION']) ? trim((string) $env['DB_CONNECTION']) : '';
    if ($driver !== 'mysql') {
        $missing[] = 'DB_CONNECTION';
    }
    if ($missing) {
        qly_fatal_config('数据库配置缺失：' . implode('、', $missing) . ' 未配置或为空', $missing);
    }
    return [
        'dsn' => 'mysql:host=' . trim($env['DB_HOST']) . ';port=' . trim($env['DB_PORT'])
            . ';dbname=' . trim($env['DB_DATABASE']) . ';charset=utf8mb4',
        'user' => trim($env['DB_USERNAME']),
        'pass' => trim($env['DB_PASSWORD']),
    ];
}

/** 应用级常量 */
function qly_app_config(): array
{
    $siteDir = qly_site_dir();
    return [
        'upload_dir' => $siteDir . DIRECTORY_SEPARATOR . 'uploads',
        'upload_url' => '/uploads',
        // 单个附件上限 50MB（同时需保证 PHP 的 upload_max_filesize / post_max_size 足够大）
        'max_upload_size' => 50 * 1024 * 1024,
        'avatar_max_size' => 2 * 1024 * 1024,
        'token_ttl' => 7 * 24 * 3600,
        'default_password' => '123456',
        // 管理员凭据不写进代码：真实值见部署环境，账号由 database/init.sql 初始化
        'admin_account' => '',
        'admin_password' => '',
    ];
}
