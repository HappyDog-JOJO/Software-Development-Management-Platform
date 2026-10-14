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
    if ($ini !== '' && @is_file($ini)) {
        return $ini;
    }
    $ini = (string) ini_get('openssl.cafile');
    if ($ini !== '' && @is_file($ini)) {
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
        // 用 @ 抑制 open_basedir 受限等警告（否则 HTML 警告会污染 JSON 响应）
        if (@is_file($p)) {
            return $p;
        }
    }
    return '';
}

/** 底层 HTTP 请求（curl）；返回 [httpStatus, 解析后的 JSON 数组|null, 原始文本] */
function qly_pingcode_http(string $method, string $path, ?array $body, ?string $token, array $query = []): array
{
    $ch = qly_pingcode_curl_init($method, $path, $body, $token, $query);
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

/** 构造一个已配置好的 curl 句柄（供单请求与并行请求共用） */
function qly_pingcode_curl_init(string $method, string $path, ?array $body, ?string $token, array $query = []): \CurlHandle
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
    return $ch;
}

/**
 * 并行执行多个 PingCode 请求（curl_multi），显著降低「短请求串行」的累计延迟。
 * $reqs: 列表，每项 ['method'=>, 'path'=>, 'body'=>(?array), 'query'=>(array)]。
 * 返回：与 $reqs 同键的「已解包 data」数组；任一失败抛 RuntimeException。
 */
function qly_pingcode_api_multi(array $reqs): array
{
    if (!$reqs) {
        return [];
    }
    $token = qly_pingcode_token();

    $run = static function (array $list, string $tok): array {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($list as $i => $r) {
            $ch = qly_pingcode_curl_init($r['method'] ?? 'GET', $r['path'], $r['body'] ?? null, $tok, $r['query'] ?? []);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $status === CURLM_OK);
        $out = [];
        foreach ($handles as $i => $ch) {
            $text = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $json = json_decode((string) $text, true);
            $out[$i] = ['status' => $code, 'json' => is_array($json) ? $json : null];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    };

    $results = $run($reqs, $token);
    // 401（token 过期）时刷新一次并重跑这些项
    $retry = [];
    foreach ($results as $i => $r) {
        if (($r['status'] ?? 0) === 401) {
            $retry[$i] = $reqs[$i];
        }
    }
    if ($retry) {
        $token = qly_pingcode_token(true);
        foreach ($run($retry, $token) as $i => $r) {
            $results[$i] = $r;
        }
    }
    $out = [];
    foreach ($results as $i => $r) {
        $status = (int) ($r['status'] ?? 0);
        $json = $r['json'];
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            $msg = is_array($json) ? (string) ($json['message'] ?? '') : '';
            throw new RuntimeException('PingCode 接口调用失败（HTTP ' . $status . '）' . ($msg !== '' ? '：' . $msg : ''));
        }
        $data = $json['data'] ?? $json;
        $out[$i] = is_array($data) ? $data : [];
    }
    return $out;
}

/** 通用缓存读写（本地 runtime 目录，避免频繁请求 PingCode 造成的加载延迟） */
function qly_pingcode_cache_get(string $key): ?array
{
    $file = qly_pingcode_cache_file($key);
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || (int) ($data['expires_at'] ?? 0) < time()) {
        return null;
    }
    return is_array($data['value'] ?? null) ? $data['value'] : null;
}

function qly_pingcode_cache_put(string $key, array $value, int $ttl): void
{
    $payload = json_encode(['expires_at' => time() + max(1, $ttl), 'value' => $value], JSON_UNESCAPED_UNICODE);
    @file_put_contents(qly_pingcode_cache_file($key), $payload, LOCK_EX);
}

function qly_pingcode_cache_file(string $key): string
{
    $dir = qly_site_dir() . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'pc_' . preg_replace('/[^A-Za-z0-9_]/', '_', $key) . '.json';
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
    $cacheKey = 'typeid_' . $projectId;
    $cached = qly_pingcode_cache_get($cacheKey);
    if (is_array($cached) && isset($cached['id'])) {
        return (string) $cached['id'];
    }
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
            qly_pingcode_cache_put($cacheKey, ['id' => $id], 3600);
            return $id;
        }
        if ($fallback === '' && mb_strpos($name, '史诗') !== false) {
            $fallback = $id;
        }
    }
    if ($fallback !== '') {
        qly_pingcode_cache_put($cacheKey, ['id' => $fallback], 3600);
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
    $cacheKey = 'proj_' . $projectRef;
    $cached = qly_pingcode_cache_get($cacheKey);
    if (is_array($cached) && isset($cached['id'])) {
        return (string) $cached['id'];
    }
    // 1) 先尝试按 ID 直查（ID 通常为字母数字短标识，中文名不会命中）
    try {
        $proj = qly_pingcode_api('GET', '/v1/project/projects/' . rawurlencode($projectRef));
        $id = (string) ($proj['id'] ?? $proj['_id'] ?? '');
        if ($id !== '') {
            qly_pingcode_cache_put($cacheKey, ['id' => $id], 3600);
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
            qly_pingcode_cache_put($cacheKey, ['id' => $id], 3600);
            return $id;
        }
        if ($fallback === '' && ($name !== '' && mb_strpos($name, $projectRef) !== false)) {
            $fallback = $id;
        }
    }
    if ($fallback !== '') {
        qly_pingcode_cache_put($cacheKey, ['id' => $fallback], 3600);
    }
    return $fallback;
}

/**
 * PingCode 选项清单（创建「史诗」弹窗用）：优先级 / 状态 / 项目成员。
 * 三组数据并行拉取 + 服务端短时缓存（默认 10 分钟），避免每次打开弹窗都等 PingCode。
 */
function qly_pingcode_task_options(string $projectId, string $typeId): array
{
    $cacheKey = 'options_' . $projectId . '_' . $typeId;
    $cached = qly_pingcode_cache_get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    [$p, $s, $m] = array_values(qly_pingcode_api_multi([
        ['method' => 'GET', 'path' => '/v1/project/work_item/priorities', 'query' => ['project_id' => $projectId]],
        ['method' => 'GET', 'path' => '/v1/project/work_item/states', 'query' => ['project_id' => $projectId, 'workitem_type_id' => $typeId]],
        ['method' => 'GET', 'path' => '/v1/project/projects/' . rawurlencode($projectId) . '/members', 'query' => []],
    ]));

    $priorities = [];
    foreach (($p['values'] ?? []) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = (string) ($it['id'] ?? '');
        if ($id !== '') {
            $priorities[] = ['id' => $id, 'name' => (string) ($it['name'] ?? '')];
        }
    }
    $states = [];
    foreach (($s['values'] ?? []) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = (string) ($it['id'] ?? '');
        if ($id !== '') {
            $states[] = ['id' => $id, 'name' => (string) ($it['name'] ?? ''), 'type' => (string) ($it['type'] ?? '')];
        }
    }
    $members = [];
    foreach (($m['values'] ?? []) as $it) {
        $u = $it['user'] ?? null;
        if (!is_array($u)) {
            continue;
        }
        $id = (string) ($u['id'] ?? '');
        if ($id !== '') {
            $members[] = [
                'id' => $id,
                'name' => (string) ($u['display_name'] ?? $u['name'] ?? ''),
                'account' => (string) ($u['name'] ?? ''),
            ];
        }
    }
    $result = ['priorities' => $priorities, 'states' => $states, 'members' => $members];
    qly_pingcode_cache_put($cacheKey, $result, 600);
    return $result;
}

/**
 * 按关键词搜索 PingCode 标签库（供同步弹窗远程搜索用）。
 * 说明：标签库是项目全局的（当前企业约 600+ 个），不宜一次性全量拉取；
 *       接口的 name 参数为前缀/包含匹配，传空串则返回最新一页，故这里空关键词时返回空。
 * 返回 [['id'=>, 'name'=>], ...]。
 */
function qly_pingcode_search_tags(string $keyword, string $projectId): array
{
    $keyword = trim($keyword);
    if ($keyword === '') {
        return [];
    }
    $data = qly_pingcode_api('GET', '/v1/pjm/workitem/tags', null, [
        'project_id' => $projectId,
        'name' => $keyword,
        'page_size' => 100,
    ]);
    $items = $data['values'] ?? $data['value'] ?? [];
    $out = [];
    if (is_array($items)) {
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $name = (string) ($it['name'] ?? '');
            $id = (string) ($it['id'] ?? $it['_id'] ?? '');
            if ($name !== '' && $id !== '') {
                $out[] = ['id' => $id, 'name' => $name];
            }
        }
    }
    return $out;
}

/**
 * 在 PingCode 创建工作项（史诗）；返回 [id, identifier(如 KBC-123，可空), htmlUrl(可空)]
 * $extra 支持透传更多字段：description/priority_id/assignee_id/state_id/start_at/end_at。
 * 说明：start_at/end_at 需传 Unix 时间戳（秒，数字）；html_url 字段在 Open API 响应中可能为 url/html_url；
 *       取到什么用什么，前端兜底拼接。
 */
function qly_pingcode_create_task(string $title, string $description, string $projectId, string $typeId, string $priorityId = '', array $extra = []): array
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
    foreach (['assignee_id', 'state_id'] as $k) {
        if (isset($extra[$k]) && $extra[$k] !== '') {
            $body[$k] = (string) $extra[$k];
        }
    }
    foreach (['start_at', 'end_at'] as $k) {
        if (isset($extra[$k]) && (int) $extra[$k] > 0) {
            $body[$k] = (int) $extra[$k];
        }
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