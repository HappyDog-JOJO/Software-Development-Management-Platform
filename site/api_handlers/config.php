<?php
declare(strict_types=1);

/**
 * 站点级配置（键值对）。
 * 目前用于「灯牌 logo」：值班灯牌 / 公告灯牌下方的小动物可换成自定义图片。
 *
 * 设计要点：
 * - 配置是站点级（全局生效），任何登录用户都要能读到（提交页要展示灯牌）。
 * - 读取接口对所有登录用户开放；写入仅管理员。
 * - 未配置（空值）时前端回退到内置卡通造型，因此「不传图片 = 用当前默认 logo」。
 */

/** 允许的配置键 */
const QLY_SITE_CONFIG_KEYS = ['duty_logo', 'notice_logo'];

/** 读取单个配置（不存在返回空串） */
function qly_site_config_get(string $key): string
{
    $v = qly_val('SELECT config_value FROM `site_config` WHERE config_key = :k LIMIT 1', [':k' => $key]);
    return $v === null ? '' : (string) $v;
}

/** 读取全部灯牌配置 */
function qly_site_config_all(): array
{
    $out = [];
    foreach (QLY_SITE_CONFIG_KEYS as $k) {
        $out[$k] = '';
    }
    $rows = qly_all(
        'SELECT config_key, config_value FROM `site_config` WHERE config_key IN (:a, :b)',
        [':a' => QLY_SITE_CONFIG_KEYS[0], ':b' => QLY_SITE_CONFIG_KEYS[1]]
    );
    foreach ($rows as $r) {
        $out[(string) $r['config_key']] = (string) $r['config_value'];
    }
    return $out;
}

/** 写入单个配置（有则更新，无则插入） */
function qly_site_config_set(string $key, string $value, string $operator): void
{
    $now = date('Y-m-d H:i:s');
    $exists = qly_val('SELECT id FROM `site_config` WHERE config_key = :k LIMIT 1', [':k' => $key]);
    if ($exists === null) {
        qly_insert('site_config', [
            'config_key' => $key,
            'config_value' => $value,
            'updated_by' => $operator,
            'updated_at' => $now,
        ]);
        return;
    }
    qly_exec(
        'UPDATE `site_config` SET config_value = :v, updated_by = :u, updated_at = :t WHERE config_key = :k',
        [':v' => $value, ':u' => $operator, ':t' => $now, ':k' => $key]
    );
}

function qly_site_config_label(string $key): string
{
    return $key === 'duty_logo' ? '值班灯牌 logo' : '公告灯牌 logo';
}

/** 读取灯牌 logo 配置（所有登录用户可读：提交页要展示灯牌） */
function qly_api_config_logos(): void
{
    qly_auth_user();
    qly_ok(qly_site_config_all());
}

/**
 * 保存灯牌 logo 配置（仅管理员）。
 * 传空字符串表示「清除自定义，回退默认 logo」。
 * 只接受本站 uploads 下的图片地址，避免被写入任意外链。
 */
function qly_api_config_save_logo(): void
{
    $admin = qly_admin_user();
    $key = (string) qly_input('key', '');
    $url = trim((string) qly_input('url', ''));

    if (!in_array($key, QLY_SITE_CONFIG_KEYS, true)) {
        qly_fail('配置项不合法');
    }
    if ($url !== '') {
        // 只允许本站 uploads 下的路径，且必须是图片扩展名
        if (strpos($url, '/uploads/') !== 0) {
            qly_fail('logo 地址必须来自本地上传目录');
        }
        if (!preg_match('/\.(png|jpe?g|gif|webp|bmp|svg)$/i', $url)) {
            qly_fail('logo 仅支持图片格式');
        }
    }

    $old = qly_site_config_get($key);
    qly_site_config_set($key, $url, (string) ($admin['name'] !== '' && $admin['name'] !== null ? $admin['name'] : $admin['account']));

    // 换图后旧文件若不再被使用则清理（失败不影响主流程）
    if ($old !== '' && $old !== $url) {
        qly_site_config_remove_file($old);
    }

    qly_log($admin, '字典维护', qly_site_config_label($key), $url === '' ? '清除自定义 logo（回退默认）' : '更新灯牌 logo：' . $url);
    qly_ok(qly_site_config_all(), $url === '' ? '已恢复默认 logo' : 'logo 已更新');
}

/** 删除本站 uploads 下的文件（仅限 uploads 目录，防目录穿越） */
function qly_site_config_remove_file(string $url): void
{
    $uploadRoot = realpath((string) qly_app_config()['upload_dir']);
    if ($uploadRoot === false) {
        return;
    }
    $rel = parse_url($url, PHP_URL_PATH);
    if (!is_string($rel) || strpos($rel, '/uploads/') !== 0) {
        return;
    }
    $path = realpath($uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, strlen('/uploads/'))));
    if ($path !== false && strpos($path, $uploadRoot) === 0 && is_file($path)) {
        @unlink($path);
    }
}
