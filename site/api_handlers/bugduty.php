<?php
declare(strict_types=1);

/**
 * BUG 修复排班（顺序轮值）。
 *
 * 与「售后处理排班」(duty_schedule，按日期一天一人) 不同：
 * - 本排班不排日期，只维护一个人员顺序表（第 1 顺位、第 2 顺位……）；
 * - 排班人员**直接取自 PingCode 项目成员**，不再从系统用户里选：
 *   页面上的「加入人员」下拉就是 PingCode 成员清单，选中即确定，无需再做姓名匹配；
 * - 每把一个问题同步到 PingCode，就取「当前顺位」的人作为 BUG 处理人写进描述并指派，
 *   然后把游标移到下一位（依次轮转，到头回到第 1 顺位）；
 * - 同步弹窗里手动指定的负责人不消耗顺位（游标不动）。
 *
 * 游标存在 site_config 表（config_key = bug_duty_cursor，值为下一位的 sort）；
 * 人员顺序存在 bug_duty_order 表（sort 从 1 开始，pingcode_member_id 为 PingCode 成员 ID）。
 *
 * 兼容说明：早期版本按系统用户排班（user_id 非 0）。这类历史行仍可读取，
 * 但新保存一律写 PingCode 成员（user_id = 0），页面上也不再提供系统用户选择。
 */

const QLY_BUG_DUTY_CURSOR_KEY = 'bug_duty_cursor';

/** PingCode 项目成员清单（排班候选人就是这个列表本身） */
function qly_bug_duty_pc_members(bool $forceRefresh = false): array
{
    require_once __DIR__ . '/../inc/pingcode.php';
    if (!qly_pingcode_configured()) {
        qly_fail('PingCode 未配置：请在 .env 中补充 PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID');
    }
    $projectId = qly_pingcode_resolve_project(qly_pingcode_env('PINGCODE_PROJECT_ID'));
    if ($projectId === '') {
        qly_fail('未找到 PingCode 项目，请检查 PINGCODE_PROJECT_ID 配置');
    }
    $typeId = qly_pingcode_task_type_id($projectId);
    if ($typeId === '') {
        qly_fail('未在 PingCode 项目中找到「史诗」类型');
    }
    $opts = qly_pingcode_task_options($projectId, $typeId);
    $members = $opts['members'] ?? [];
    $out = [];
    foreach ($members as $m) {
        $id = (string) ($m['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $out[] = [
            'id' => $id,
            'name' => (string) ($m['name'] ?? ''),
            'account' => (string) ($m['account'] ?? ''),
        ];
    }
    usort($out, static function ($a, $b) {
        return strcmp((string) $a['name'], (string) $b['name']);
    });
    return $out;
}

/**
 * 顺序表（sort 升序）。
 * 只以 PingCode 成员为人员来源：userName 即 PingCode 成员名。
 */
function qly_bug_duty_items(): array
{
    $rows = qly_all(
        'SELECT id, user_id, user_name, sort, pingcode_member_id
           FROM `bug_duty_order`
          ORDER BY sort ASC, id ASC'
    );
    $out = [];
    $i = 0;
    foreach ($rows as $r) {
        $i++;
        $name = (string) $r['user_name'];
        if ($name === '') {
            $name = (string) ($r['pingcode_member_id'] ?? '');
        }
        $out[] = [
            'id' => (int) $r['id'],
            'userId' => (int) $r['user_id'],
            'userName' => $name,
            'sort' => (int) $r['sort'],
            // 实际展示顺位：按当前顺序连续编号（即使库里的 sort 有空洞也不会出现跳号）
            'position' => $i,
            'pingcodeMemberId' => $r['pingcode_member_id'] !== null ? (string) $r['pingcode_member_id'] : '',
            'enabled' => true,
        ];
    }
    return $out;
}

function qly_bug_duty_cursor_get(): int
{
    $v = qly_val('SELECT config_value FROM `site_config` WHERE config_key = :k LIMIT 1', [':k' => QLY_BUG_DUTY_CURSOR_KEY]);
    return $v === null ? 0 : (int) $v;
}

function qly_bug_duty_cursor_set(int $sort, string $operator): void
{
    $now = date('Y-m-d H:i:s');
    $exists = qly_val('SELECT id FROM `site_config` WHERE config_key = :k LIMIT 1', [':k' => QLY_BUG_DUTY_CURSOR_KEY]);
    if ($exists === null) {
        qly_insert('site_config', [
            'config_key' => QLY_BUG_DUTY_CURSOR_KEY,
            'config_value' => (string) $sort,
            'updated_by' => $operator,
            'updated_at' => $now,
        ]);
        return;
    }
    qly_exec(
        'UPDATE `site_config` SET config_value = :v, updated_by = :u, updated_at = :t WHERE config_key = :k',
        [':v' => (string) $sort, ':u' => $operator, ':t' => $now, ':k' => QLY_BUG_DUTY_CURSOR_KEY]
    );
}

/**
 * 查看「当前顺位」（不推进游标）。
 * 返回 null 表示顺序表为空；否则返回 memberId/userName/position/sort/nextSort。
 */
function qly_bug_duty_peek(): ?array
{
    $items = qly_bug_duty_items();
    if (!$items) {
        return null;
    }
    $cursor = qly_bug_duty_cursor_get();
    $idx = 0;
    if ($cursor > 0) {
        $found = -1;
        foreach ($items as $i => $it) {
            if ($it['sort'] >= $cursor) {
                $found = $i;
                break;
            }
        }
        // 游标超过最后一位：回到第 1 位（视为一轮结束）
        $idx = $found >= 0 ? $found : 0;
    }
    $pick = $items[$idx];
    $next = $items[($idx + 1) % count($items)];
    return [
        'userId' => $pick['userId'],
        'userName' => $pick['userName'],
        'position' => $idx + 1,
        'sort' => $pick['sort'],
        'nextSort' => $next['sort'],
        'pingcodeMemberId' => $pick['pingcodeMemberId'],
    ];
}

/** 同步成功后推进游标到下一位 */
function qly_bug_duty_commit(int $nextSort, string $operator): void
{
    qly_bug_duty_cursor_set($nextSort, $operator);
}

/** 读取顺序表 + 当前顺位 + PingCode 成员候选人（管理员/开发工程师可读） */
function qly_api_bugduty_list(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可查看 BUG 修复排班');
    }
    $members = [];
    $memberError = '';
    try {
        $members = qly_bug_duty_pc_members();
    } catch (Throwable $e) {
        $memberError = $e->getMessage();
    }
    qly_ok([
        'items' => qly_bug_duty_items(),
        'current' => qly_bug_duty_peek(),
        'members' => $members,
        'memberError' => $memberError,
    ]);
}

/**
 * 保存顺序表（整表覆盖）。
 * 入参 memberIds 为按顺位排列的 PingCode 成员 ID 数组（第 1 个即第 1 顺位）。
 * 保存后游标重置到第 1 顺位，避免顺序变动后指向错人。
 */
function qly_api_bugduty_save(): void
{
    $user = qly_admin_user();
    $memberIds = qly_input('memberIds');
    if (!is_array($memberIds)) {
        $memberIds = [];
    }
    $clean = [];
    foreach ($memberIds as $mid) {
        $mid = trim((string) $mid);
        if ($mid !== '' && !in_array($mid, $clean, true)) {
            $clean[] = $mid;
        }
    }

    // 用 PingCode 成员清单校验（成员名以 PingCode 为准，避免两边名字不一致）
    $byId = [];
    if ($clean) {
        $members = qly_bug_duty_pc_members(true);
        foreach ($members as $m) {
            $byId[(string) $m['id']] = (string) $m['name'];
        }
        foreach ($clean as $mid) {
            if (!isset($byId[$mid])) {
                qly_fail('PingCode 成员不存在或已移出项目（' . $mid . '）');
            }
        }
    }

    $pdo = qly_db();
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        qly_exec('DELETE FROM `bug_duty_order`');
        $sort = 0;
        foreach ($clean as $mid) {
            $sort++;
            qly_insert('bug_duty_order', [
                'user_id' => 0,
                'user_name' => $byId[$mid],
                'sort' => $sort,
                'pingcode_member_id' => $mid,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        qly_fail('保存失败，请重试');
    }

    $operator = $user['name'] !== null && $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    qly_bug_duty_cursor_set(1, $operator);
    $names = array_values($byId);
    qly_log($user, '排班维护', 'BUG修复排班', '更新顺序：' . ($names ? implode(' → ', $names) : '（清空）'));

    qly_ok([
        'items' => qly_bug_duty_items(),
        'current' => qly_bug_duty_peek(),
        'members' => qly_bug_duty_pc_members(),
        'memberError' => '',
    ], $names ? '顺序已保存' : '顺序已清空');
}

/** 重置游标到第 1 顺位（管理员） */
function qly_api_bugduty_reset(): void
{
    $user = qly_admin_user();
    $operator = $user['name'] !== null && $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    qly_bug_duty_cursor_set(1, $operator);
    qly_log($user, '排班维护', 'BUG修复排班', '重置轮值：从第 1 顺位重新开始');
    $members = [];
    try {
        $members = qly_bug_duty_pc_members();
    } catch (Throwable $e) {
        // 重置不依赖成员清单，拉取失败也照样返回
    }
    qly_ok([
        'items' => qly_bug_duty_items(),
        'current' => qly_bug_duty_peek(),
        'members' => $members,
    ], '已重置为第 1 顺位');
}

/** PingCode 项目成员清单（排班页下拉用；开发工程师/管理员） */
function qly_api_bugduty_members(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可查看 PingCode 成员');
    }
    try {
        $members = qly_bug_duty_pc_members(true);
    } catch (Throwable $e) {
        qly_fail('拉取 PingCode 成员失败：' . $e->getMessage());
    }
    qly_ok([
        'members' => $members,
        'items' => qly_bug_duty_items(),
        'current' => qly_bug_duty_peek(),
    ]);
}
