<?php
declare(strict_types=1);

/** 数据库访问层：PDO 单例 + 常用查询封装 */

require_once __DIR__ . '/config.php';

function qly_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = qly_db_config();

    // 连接候选顺序（技能规范：线上先试 127.0.0.1，再试 .env 里的 DB_HOST）：
    //   线上站点与数据库常在同一台服务器，走 127.0.0.1 更稳更快，
    //   走公网 IP 会绕出去再回来，偶发建连超时（表现为「数据库连接失败」）。
    // 本地开发时 DB_HOST 是云端地址，127.0.0.1 不通会自动回退，不影响本地联调。
    $candidates = [];
    $candidates[] = preg_replace('/host=[^;]+/', 'host=127.0.0.1', $cfg['dsn'], 1);
    $candidates[] = $cfg['dsn'];
    $candidates = array_values(array_unique($candidates));

    $last = null;
    foreach ($candidates as $dsn) {
        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // 连接超时：公网数据库不在时应尽快报错，而不是把请求挂住
                PDO::ATTR_TIMEOUT => 8,
                // 执行超时：避免慢查询占满服务器
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=30, SESSION max_execution_time=8000",
            ]);
            // 会话时区跟随应用时区，避免 NOW()/CURDATE() 与 PHP 差 8 小时
            $offset = (new DateTime('now', new DateTimeZone(qly_timezone())))->format('P');
            $pdo->exec("SET time_zone = '" . $offset . "'");
            // 标记实际使用的地址（便于诊断）
            $GLOBALS['__qly_db_host_used'] = preg_match('/host=([^;]+)/', $dsn, $m) ? $m[1] : '?';
            return $pdo;
        } catch (PDOException $e) {
            $last = $e;
            $pdo = null;
        }
    }

    // 所有候选都失败才报错；不再把内部错误直接吐给用户
    error_log('[qly_db] 数据库连接失败: ' . ($last ? $last->getMessage() : 'unknown'));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 500,
        'data' => null,
        'message' => '数据库连接失败，请稍后重试',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 执行查询返回全部行 */
function qly_all(string $sql, array $params = []): array
{
    $st = qly_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** 执行查询返回首行 */
function qly_one(string $sql, array $params = []): ?array
{
    $st = qly_db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** 执行查询返回首列 */
function qly_val(string $sql, array $params = [])
{
    $st = qly_db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** 执行写入语句，返回影响行数 */
function qly_exec(string $sql, array $params = []): int
{
    $st = qly_db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** 插入并返回自增 ID */
function qly_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $place = array_map(fn($c) => ':' . $c, $cols);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $place) . ')';
    $st = qly_db()->prepare($sql);
    foreach ($data as $k => $v) {
        $st->bindValue(':' . $k, $v);
    }
    $st->execute();
    return (int) qly_db()->lastInsertId();
}

/** 更新指定主键的记录 */
function qly_update(string $table, int $id, array $data): int
{
    if (!$data) {
        return 0;
    }
    $sets = [];
    foreach (array_keys($data) as $c) {
        $sets[] = '`' . $c . '`=:' . $c;
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE id=:__id';
    $st = qly_db()->prepare($sql);
    foreach ($data as $k => $v) {
        $st->bindValue(':' . $k, $v);
    }
    $st->bindValue(':__id', $id, PDO::PARAM_INT);
    $st->execute();
    return $st->rowCount();
}
