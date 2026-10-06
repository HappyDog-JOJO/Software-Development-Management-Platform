<?php
declare(strict_types=1);

/** 统一响应与入参解析：{ code, data, message } */

const QLY_CODE_OK = 0;
const QLY_CODE_BIZ = 1;
const QLY_CODE_UNAUTH = 401;
const QLY_CODE_FORBIDDEN = 403;

function qly_json($data = null, string $message = 'ok', int $code = QLY_CODE_OK, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function qly_ok($data = null, string $message = 'ok'): void
{
    qly_json($data, $message, QLY_CODE_OK, 200);
}

function qly_fail(string $message, int $code = QLY_CODE_BIZ, int $http = 200): void
{
    qly_json(null, $message, $code, $http);
}

function qly_unauthorized(string $message = '请先登录'): void
{
    qly_json(null, $message, QLY_CODE_UNAUTH, 401);
}

function qly_forbidden(string $message = '没有操作权限'): void
{
    qly_json(null, $message, QLY_CODE_FORBIDDEN, 403);
}

/** 读取 JSON 请求体；兼容表单提交 */
function qly_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = file_get_contents('php://input');
    $json = null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }
    $body = is_array($json) ? array_merge($_POST, $json) : $_POST;
    return $body;
}

function qly_input(string $key, $default = null)
{
    $b = qly_body();
    if (array_key_exists($key, $b)) {
        return is_string($b[$key]) ? trim($b[$key]) : $b[$key];
    }
    if (array_key_exists($key, $_GET)) {
        $v = $_GET[$key];
        return is_string($v) ? trim($v) : $v;
    }
    return $default;
}

function qly_input_int(string $key, int $default = 0): int
{
    $v = qly_input($key, $default);
    return is_numeric($v) ? (int) $v : $default;
}

function qly_require(array $rules): array
{
    $out = [];
    foreach ($rules as $field => $label) {
        $v = qly_input($field);
        if ($v === null || $v === '' || $v === []) {
            qly_fail($label . '不能为空');
        }
        $out[$field] = $v;
    }
    return $out;
}
