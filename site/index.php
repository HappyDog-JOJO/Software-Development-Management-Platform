<?php
declare(strict_types=1);

/**
 * 站点入口。
 * - 静态前端产物在 site/index.html（构建后由 tools/assemble.mjs 复制）
 * - 全部业务接口走 /api.php?r=module/action
 * - 其余路径回落到前端单页应用
 *
 * 关键：index.html 必须禁止缓存。
 * 构建产物 assets/*.js 带内容 hash 可长期缓存，但 index.html 一旦被缓存，
 * 浏览器会一直加载旧的 JS 入口，表现为“改了代码没生效”“登录后不跳转”等问题。
 */

$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri = rtrim($uri, '/');

// 上传文件由 Web 服务器直接返回，不经过本入口
if (str_starts_with($uri, '/uploads/')) {
    http_response_code(404);
    exit;
}

// 禁止 HTML 入口被缓存（含各类中间代理）
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$indexHtml = __DIR__ . '/index.html';
if (is_file($indexHtml)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($indexHtml);
    exit;
}

http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>应用未完成构建</title></head>';
echo '<body style="font-family:system-ui;padding:40px;color:#2b3357">';
echo '<h2>前端产物缺失</h2>';
echo '<p>请在开发环境执行前端构建并把 <code>index.html</code> 与 <code>assets/</code> 上传到站点根目录。</p>';
echo '</body></html>';
