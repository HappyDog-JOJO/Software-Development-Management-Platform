<?php
declare(strict_types=1);

/**
 * PingCode Open API 适配器（需求「同步任务」用）。
 *
 * 协议（依据 pingcode 官方 Open API 与社区 CLI 的实现确认）：
 * - 网关：https://open.pingcode.com ；认证：Bearer {access_token}
 * - 取 token：GET /v1/auth/token?grant_type=client_credentials&client_id=..&client_secret=..
 *   返回 { access_token, expires_in }；token 缓存在 site/runtime/ 下，过期前复用。
 * - 创建工作项：POST /v1/project/work_items，body: title/project_id/type_id/description 等。
 * - 查项目：GET /v1/project/projects ；查工作项类型：GET /v1/project/work_item/types?project_id=..
 *
 * 凭据只从 .env 读取（PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID），
 * 与非数据库配置同级管理：不进代码、不进交付包。未配置时调用方应给出「未配置」提示。
 *
 * 错误约定：本适配器统一抛 RuntimeException（不直接 qly_fail/exit），
 * 以便批量同步时单条失败可被调用方捕获并继续处理其余条目。
 */

/** PingCode 配置是否已就绪（缺任一即视为未配置；不抛错，供 UI 判断） */
function qly_pingcode_configured(): bool
{
    $env = qly_load_env();
    foreach (['PINGCODE_CLIENT_ID', 'PINGCODE_CLIENT_SECRET', 'PINGCODE_PROJECT_ID'] as $k) {
        if (!isset($env[$k]) || trim((string) $env[$k]) === '') {
            return false;
        }
    }
    return true;
}

function qly_pingcode_env(string $key): string
{
    $env = qly_load_env();
    return isset($env[$key]) ? trim((string) $env[$key]) : '';
}

/** token 缓存文件路径（本地运行时目录，不进包） */
function qly_pingcode_token_cache_file(): string
{
    $dir = qly_site_dir() . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'pingcode_token.json';
}

/** 读取缓存的 token（提前 5 分钟视为过期） */
function qly_pingcode_cached_token(): string
{
    $file = qly_pingcode_token_cache_file();
    if (!is_file($file)) {
        return '';
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return '';
    }
    $token = (string) ($data['access_token'] ?? '');
    $expiresAt = (int) ($data['expires_at'] ?? 0);
    if ($token === '' || $expiresAt - 300 < time()) {
        return '';
    }
    return $token;
}

function qly_pingcode_save_token(string $token, int $expiresIn): void
{
    $data = [
        'access_token' => $token,
        'expires_at' => time() + max(60, $expiresIn),
    ];
    @file_put_contents(qly_pingcode_token_cache_file(), json_encode($data), LOCK_EX);
}

/**
 * 定位可用的 CA 证书文件。
 * 背景：部分部署环境（如 Windows + PHP 便携包）未配置 curl.cainfo/openssl.cafile，
 * 导致 curl 无法验证 HTTPS 证书链（报 self-signed certificate in certificate chain）。
 * 策略：优先 php.ini 已配置的 CA；其次探测常见 CA 文件路径（Git for Windows 自带、
 * 系统 curl 路径等）；都找不到时返回空串（仍保持 SSL 验证开启，由 curl 使用默认行为）。
 */
function qly_pingcode_ca_file(): string
{
    $ini = (string) ini_get('curl.cainfo');
    if ($ini !== '' && is_file($ini)) {
        return $ini;
    }
    $ini = (string) ini_get('openssl.cafile');
    if ($ini !== '' && is_file($ini)) {
        return $ini;
    }
    $candidates = [
        'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/mingw64/ssl/certs/ca-bundle.crt',
        'C:/Windows/System32/curl-ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
    ];
    foreach ($candidates as $p) {
        if (is_file($p)) {
            return $p;
        }
    }
    return '';
}

/** 底层 HTTP 请求（curl）；返回 [httpStatus, 解析后的 JSON 数组|null, 原始文本] */
function qly_pingcode_http(string $method, string $path, ?array $body, ?string $token, array $query = []): array
{
    $url = 'https://open.pingcode.com' . $path;
    if ($query) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('服务器未启用 curl 扩展，无法调用 PingCode');
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
    ];
    $caFile = qly_pingcode_ca_file();
    if ($caFile !== '') {
        $opts[CURLOPT_CAINFO] = $caFile;
    }
    curl_setopt_array($ch, $opts);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $text = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($text === false) {
        throw new RuntimeException('调用 PingCode 失败：' . ($err !== '' ? $err : '网络错误'));
    }
    $json = json_decode((string) $text, true);
    return [$status, is_array($json) ? $json : null, (string) $text];
}

/** 取 access_token（缓存优先；失败抛业务错） */
function qly_pingcode_token(bool $forceRefresh = false): string
{
    if (!$forceRefresh) {
        $cached = qly_pingcode_cached_token();
        if ($cached !== '') {
            return $cached;
        }
    }
    $clientId = qly_pingcode_env('PINGCODE_CLIENT_ID');
    $clientSecret = qly_pingcode_env('PINGCODE_CLIENT_SECRET');
    [$status, $json] = qly_pingcode_http('GET', '/v1/auth/token', null, null, [
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]);
    $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
    if ($status !== 200 || $token === '') {
        $msg = is_array($json) ? (string) ($json['message'] ?? '') : '';
        throw new RuntimeException('获取 PingCode 授权失败' . ($msg !== '' ? '：' . $msg : '（请检查 PINGCODE_CLIENT_ID / CLIENT_SECRET 配置）'));
    }
    qly_pingcode_save_token($token, (int) ($json['expires_in'] ?? 7200));
    return $token;
}

/** 通用 PingCode API 调用：自动带 token，401 时刷新一次重试；返回 data 数组 */
function qly_pingcode_api(string $method, string $path, ?array $body = null, array $query = []): array
{
    $token = qly_pingcode_token();
    [$status, $json] = qly_pingcode_http($method, $path, $body, $token, $query);
    if ($status === 401) {
        // token 过期：强制刷新重试一次
        $token = qly_pingcode_token(true);
        [$status, $json] = qly_pingcode_http($method, $path, $body, $token, $query);
    }
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        $msg = is_array($json) ? (string) ($json['message'] ?? '') : '';
        throw new RuntimeException('PingCode 接口调用失败（HTTP ' . $status . '）' . ($msg !== '' ? '：' . $msg : ''));
    }
    // 统一解包：{ data: {...} } 或裸对象
    $data = $json['data'] ?? $json;
    return is_array($data) ? $data : [];
}

/** 解析出「史诗」类型 id（需求同步固定创建「史诗」工作项；查不到时返回空串由上层报错） */
function qly_pingcode_task_type_id(string $projectId): string
{
    $data = qly_pingcode_api('GET', '/v1/project/work_item/types', null, ['project_id' => $projectId]);
    $items = $data['values'] ?? $data['value'] ?? $data;
    if (!is_array($items)) {
        return '';
    }
    // 优先精确名「史诗」，其次包含「史诗」
    $fallback = '';
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $name = (string) ($it['name'] ?? $it['display_name'] ?? '');
        $id = (string) ($it['id'] ?? $it['_id'] ?? '');
        if ($id === '') {
            continue;
        }
        if ($name === '史诗') {
            return $id;
        }
        if ($fallback === '' && mb_strpos($name, '史诗') !== false) {
            $fallback = $id;
        }
    }
    return $fallback;
}

/**
 * 解析项目 ID：PINGCODE_PROJECT_ID 可填「项目 ID」或「项目名称」（如 快工单）。
 * 先按 ID 直查（命中返回原值），再拉项目列表按名称精确匹配；都找不到返回空串。
 */
function qly_pingcode_resolve_project(string $projectRef): string
{
    if ($projectRef === '') {
        return '';
    }
    // 1) 先尝试按 ID 直查（ID 通常为字母数字短标识，中文名不会命中）
    try {
        $proj = qly_pingcode_api('GET', '/v1/project/projects/' . rawurlencode($projectRef));
        $id = (string) ($proj['id'] ?? $proj['_id'] ?? '');
        if ($id !== '') {
            return $id;
        }
    } catch (Throwable $e) {
        // 直查失败继续走名称匹配
    }
    // 2) 拉项目列表按名称匹配（先精确，后包含）
    $data = qly_pingcode_api('GET', '/v1/project/projects');
    $items = $data['values'] ?? $data['value'] ?? $data;
    if (!is_array($items)) {
        return '';
    }
    $fallback = '';
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = (string) ($it['id'] ?? $it['_id'] ?? '');
        if ($id === '') {
            continue;
        }
        $name = (string) ($it['name'] ?? $it['display_name'] ?? '');
        $identifier = (string) ($it['identifier'] ?? $it['key'] ?? '');
        if ($name === $projectRef || $identifier === $projectRef) {
            return $id;
        }
        if ($fallback === '' && ($name !== '' && mb_strpos($name, $projectRef) !== false)) {
            $fallback = $id;
        }
    }
    return $fallback;
}

/**
 * 在 PingCode 创建工作项（史诗）；返回 [id, identifier(如 KG-123，可空), htmlUrl(可空)]
 * 说明：html_url 字段在 Open API 响应中可能为 url/html_url；取到什么用什么，前端兜底拼接。
 */
function qly_pingcode_create_task(string $title, string $description, string $projectId, string $typeId, string $priorityId = ''): array
{
    $body = [
        'title' => $title,
        'project_id' => $projectId,
        'type_id' => $typeId,
        'description' => $description,
    ];
    if ($priorityId !== '') {
        $body['priority_id'] = $priorityId;
    }
    $data = qly_pingcode_api('POST', '/v1/project/work_items', $body);
    $id = (string) ($data['id'] ?? $data['_id'] ?? '');
    if ($id === '') {
        throw new RuntimeException('PingCode 创建工作项未返回工作项 ID');
    }
    $identifier = (string) ($data['identifier'] ?? $data['short_id'] ?? '');
    // 优先 html_url（形如 https://kgd.pingcode.com/pjm/workitems/{short_id} 的人类可访问地址）；
    // url 字段是 API 地址（open.pingcode.com/v1/...）不可直接点开，仅在 html_url 缺失时兜底。
    $htmlUrl = (string) ($data['html_url'] ?? '');
    if ($htmlUrl === '' && $id !== '') {
        // 创建响应未带 html_url 时，按 ID 查一次详情补齐
        try {
            $detail = qly_pingcode_api('GET', '/v1/project/work_items/' . rawurlencode($id));
            $htmlUrl = (string) ($detail['html_url'] ?? '');
        } catch (Throwable $e) {
            $htmlUrl = '';
        }
    }
    return [$id, $identifier, $htmlUrl];
}

/**
 * 按名称查标签 id（全局标签库，按项目维度检索）；找不到返回空串。
 * 说明：分支名通常不是已有标签，由调用方决定是否创建。
 */
function qly_pingcode_find_tag(string $name, string $projectId): string
{
    $data = qly_pingcode_api('GET', '/v1/pjm/workitem/tags', null, [
        'project_id' => $projectId,
        'name' => $name,
    ]);
    $items = $data['values'] ?? $data['value'] ?? $data;
    if (!is_array($items)) {
        return '';
    }
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        if ((string) ($it['name'] ?? '') === $name) {
            return (string) ($it['id'] ?? '');
        }
    }
    return '';
}

/** 创建标签（同名已存在时返回已有 id）；返回标签 id，失败抛异常 */
function qly_pingcode_ensure_tag(string $name, string $projectId): string
{
    $existing = qly_pingcode_find_tag($name, $projectId);
    if ($existing !== '') {
        return $existing;
    }
    $data = qly_pingcode_api('POST', '/v1/pjm/workitem_tags', ['name' => $name]);
    $id = (string) ($data['id'] ?? '');
    if ($id === '') {
        // 并发场景：创建失败时再查一次
        $again = qly_pingcode_find_tag($name, $projectId);
        if ($again !== '') {
            return $again;
        }
        throw new RuntimeException('创建标签「' . $name . '」未返回标签 ID');
    }
    return $id;
}

/**
 * 给工作项挂标签（幂等：已挂则忽略）；失败抛异常由调用方决定是否降级。
 * 用途：同步时把「开发分支」作为标签写入，便于 PingCode 侧按分支筛选。
 */
function qly_pingcode_attach_tag(string $workItemId, string $tagId): void
{
    try {
        qly_pingcode_api('POST', '/v1/pjm/workitems/' . rawurlencode($workItemId) . '/tags', ['tag_id' => $tagId]);
    } catch (Throwable $e) {
        // 已挂该标签时接口可能报错：视为成功（幂等）
        if (mb_strpos($e->getMessage(), '已存在') !== false || mb_strpos($e->getMessage(), 'exist') !== false) {
            return;
        }
        throw $e;
    }
}