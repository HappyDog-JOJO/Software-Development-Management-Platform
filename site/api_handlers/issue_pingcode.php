<?php
declare(strict_types=1);

/**
 * 问题 → PingCode 同步（问题列表「同步到 PingCode」）。
 *
 * 与「开发管理 → 开发中心 → 同步任务」同一套 PingCode 调用与字段契约，
 * 在 PingCode 项目里创建「史诗」工作项。
 *
 * 与需求同步的差异（BUG 修复排班）：
 * - 默认负责人 = BUG 修复排班「当前顺位」的人；描述固定写一行「BUG 处理人：xxx（第 N 顺位）」；
 * - 只有使用当前顺位（未在弹窗手动改负责人）时才推进游标，手动指定负责人时游标不动；
 * - 已同步过（pingcode_task_id 非空）自动跳过，不重复创建。
 */

require_once __DIR__ . '/bugduty.php';

/**
 * 同步弹窗选项：优先级 / 状态 / 项目成员 + BUG 排班当前顺位（开发工程师/管理员）。
 */
function qly_api_issues_pingcode_options(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可同步问题到 PingCode');
    }
    require_once __DIR__ . '/../inc/pingcode.php';
    if (!qly_pingcode_configured()) {
        qly_fail('PingCode 未配置：请在 .env 中补充 PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID');
    }

    $projectRef = qly_pingcode_env('PINGCODE_PROJECT_ID');
    try {
        $projectId = qly_pingcode_resolve_project($projectRef);
        if ($projectId === '') {
            qly_fail('未找到 PingCode 项目「' . $projectRef . '」，请检查 PINGCODE_PROJECT_ID 配置');
        }
        $typeId = qly_pingcode_task_type_id($projectId);
        if ($typeId === '') {
            qly_fail('未在 PingCode 项目「' . $projectRef . '」中找到「史诗」类型');
        }
        $opts = qly_pingcode_task_options($projectId, $typeId);
    } catch (Throwable $e) {
        qly_fail('连接 PingCode 失败：' . $e->getMessage());
    }

    $current = qly_bug_duty_peek();
    $memberId = '';
    if ($current !== null) {
        $memberId = (string) $current['pingcodeMemberId'];
    }

    qly_ok([
        'projectName' => $projectRef,
        'typeName' => '史诗',
        'priorities' => $opts['priorities'],
        'states' => $opts['states'],
        'members' => $opts['members'],
        'bugDuty' => $current === null ? null : [
            'userId' => $current['userId'],
            'userName' => $current['userName'],
            'position' => $current['position'],
            'memberId' => $memberId,
        ],
    ]);
}

/**
 * 搜索 PingCode 标签库（同步弹窗标签远程搜索用；开发工程师/管理员）。
 */
function qly_api_issues_pingcode_tags(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可同步问题到 PingCode');
    }
    require_once __DIR__ . '/../inc/pingcode.php';
    if (!qly_pingcode_configured()) {
        qly_fail('PingCode 未配置：请在 .env 中补充 PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID');
    }
    $keyword = trim((string) qly_input('keyword', ''));
    if ($keyword === '') {
        qly_ok([]);
    }
    $projectRef = qly_pingcode_env('PINGCODE_PROJECT_ID');
    try {
        $projectId = qly_pingcode_resolve_project($projectRef);
        if ($projectId === '') {
            qly_fail('未找到 PingCode 项目「' . $projectRef . '」，请检查 PINGCODE_PROJECT_ID 配置');
        }
        $tags = qly_pingcode_search_tags($keyword, $projectId);
    } catch (Throwable $e) {
        qly_fail('搜索 PingCode 标签失败：' . $e->getMessage());
    }
    qly_ok($tags);
}

/**
 * 把问题同步到 PingCode（开发工程师/管理员，支持批量）。
 * 入参：ids[] 或 id；fields{title,description,priorityId,assigneeId,stateId,startAt,dueAt,tags[]}
 */
function qly_api_issues_pingcode_sync(): void
{
    $user = qly_auth_user();
    if (!qly_has_role($user, [QLY_ROLE_DEV, QLY_ROLE_ADMIN])) {
        qly_forbidden('仅开发工程师或管理员可同步问题到 PingCode');
    }
    require_once __DIR__ . '/../inc/pingcode.php';
    if (!qly_pingcode_configured()) {
        qly_fail('PingCode 未配置：请在 .env 中补充 PINGCODE_CLIENT_ID / PINGCODE_CLIENT_SECRET / PINGCODE_PROJECT_ID');
    }

    $ids = qly_input('ids');
    if (is_array($ids) && $ids) {
        $targets = array_values(array_unique(array_map('intval', $ids)));
    } else {
        $one = qly_input_int('id', 0);
        $targets = $one > 0 ? [$one] : [];
    }
    if (!$targets) {
        qly_fail('请选择要同步的问题');
    }

    $fields = qly_input('fields', []);
    if (!is_array($fields)) {
        $fields = [];
    }
    $fTitle = trim((string) ($fields['title'] ?? ''));
    $fDesc = (string) ($fields['description'] ?? '');
    $fPriorityId = trim((string) ($fields['priorityId'] ?? ''));
    $fAssigneeId = trim((string) ($fields['assigneeId'] ?? ''));
    $fStateId = trim((string) ($fields['stateId'] ?? ''));
    $fStart = trim((string) ($fields['startAt'] ?? ''));
    $fDue = trim((string) ($fields['dueAt'] ?? ''));
    $fTags = is_array($fields['tags'] ?? null) ? array_values(array_filter(array_map(static function ($t) {
        return trim((string) $t);
    }, $fields['tags']), static function ($t) {
        return $t !== '';
    })) : [];
    $startTs = $fStart !== '' ? (int) strtotime($fStart . ' 00:00:00') : 0;
    $dueTs = $fDue !== '' ? (int) strtotime($fDue . ' 23:59:59') : 0;
    // rosterMode：auto=按 BUG 排班顺位（默认）；manual=弹窗手动指定负责人（不消耗顺位）
    $rosterMode = trim((string) ($fields['rosterMode'] ?? 'auto'));
    $manualAssignee = $rosterMode === 'manual';

    $projectRef = qly_pingcode_env('PINGCODE_PROJECT_ID');
    try {
        $projectId = qly_pingcode_resolve_project($projectRef);
        if ($projectId === '') {
            qly_fail('未找到 PingCode 项目「' . $projectRef . '」，请检查 PINGCODE_PROJECT_ID 配置');
        }
        $typeId = qly_pingcode_task_type_id($projectId);
    } catch (Throwable $e) {
        qly_fail('连接 PingCode 失败：' . $e->getMessage());
    }
    if ($typeId === '') {
        qly_fail('未在 PingCode 项目「' . $projectRef . '」中找到「史诗」类型，请检查项目的工作项类型配置');
    }

    // 负责人姓名（弹窗选的是 PingCode 成员 id）：同步时一并落库，便于列表展示
    $members = [];
    try {
        $opts = qly_pingcode_task_options($projectId, $typeId);
        $members = $opts['members'] ?? [];
    } catch (Throwable $e) {
        $members = [];
    }
    $fAssigneeName = '';
    foreach ($members as $m) {
        if ((string) ($m['id'] ?? '') === $fAssigneeId) {
            $fAssigneeName = (string) ($m['name'] ?? '');
            break;
        }
    }

    $operator = $user['name'] !== null && $user['name'] !== '' ? (string) $user['name'] : (string) $user['account'];
    $single = count($targets) === 1;
    $now = date('Y-m-d H:i:s');
    $okCount = 0;
    $skipCount = 0;
    $errors = [];
    $lastBugHandler = '';

    foreach ($targets as $tid) {
        if ($tid <= 0) {
            $skipCount++;
            continue;
        }
        $row = qly_one(
            'SELECT i.*, d.name AS department_name, m.name AS module_name
               FROM `issue` i
               LEFT JOIN `department` d ON d.id = i.department_id
               LEFT JOIN `dict_item` m ON m.id = i.module_id
              WHERE i.id = :id',
            [':id' => $tid]
        );
        if (!$row) {
            $skipCount++;
            continue;
        }
        if (!qly_issue_visible($user, $row)) {
            $skipCount++;
            continue;
        }
        if ((string) ($row['pingcode_task_id'] ?? '') !== '') {
            // 已同步过：不重复创建
            $skipCount++;
            continue;
        }

        // BUG 处理人：默认取「当前顺位」；弹窗手动指定负责人时以手动为准，且不消耗顺位
        $useRoster = !$manualAssignee;
        $bugHandlerId = 0;
        $bugHandlerName = '';
        $bugPosition = 0;
        $bugNextSort = 0;
        $rosterMemberId = '';
        if ($useRoster) {
            // 批量时每条都重新取当前顺位（每条同步推进一位），保证「一个问题接一个问题」依次轮
            $peek = qly_bug_duty_peek();
            if ($peek !== null) {
                $bugHandlerId = (int) $peek['userId'];
                $bugHandlerName = (string) $peek['userName'];
                $bugPosition = (int) $peek['position'];
                $bugNextSort = (int) $peek['nextSort'];
                $rosterMemberId = (string) $peek['pingcodeMemberId'];
            }
        } else {
            $bugHandlerName = $fAssigneeName;
        }

        // 标题：单条且弹窗填写时用弹窗值，否则用问题摘要
        if ($single && $fTitle !== '') {
            $title = $fTitle;
        } else {
            $title = '【售后问题】' . mb_substr((string) $row['description'], 0, 60);
        }
        if (mb_strlen($title) > 100) {
            $title = mb_substr($title, 0, 100);
        }

        // 描述：单条且弹窗填写时用弹窗值，否则用「问题描述 + 来源信息」
        if ($single && $fDesc !== '') {
            $desc = $fDesc;
        } else {
            $lines = [(string) $row['description'], ''];
            $lines[] = '—— 由售后服务平台同步 ——';
            $lines[] = '问题编号：' . (string) $row['issue_no'];
            if ((string) ($row['module_name'] ?? '') !== '') {
                $lines[] = '所属模块：' . (string) $row['module_name'];
            }
            if ((string) ($row['category_name'] ?? '') !== '') {
                $lines[] = '问题分类：' . (string) $row['category_name'];
            }
            $lines[] = '提交人：' . (string) ($row['submitter_name'] ?? '');
            $lines[] = '提交部门：' . ((string) ($row['department_name'] ?? '') !== '' ? (string) $row['department_name'] : '未分配部门');
            $lines[] = '提交时间：' . (string) $row['created_at'];
            if ($bugHandlerName !== '') {
                $lines[] = 'BUG 处理人：' . $bugHandlerName . ($bugPosition > 0 ? '（第 ' . $bugPosition . ' 顺位）' : '');
            } elseif ($manualAssignee) {
                $lines[] = 'BUG 处理人：未指定（手动同步未选择负责人）';
            } else {
                $lines[] = 'BUG 处理人：未排班（BUG 修复排班顺序表为空）';
            }
            $desc = implode("\n", $lines);
        }

        $assigneeId = $useRoster ? $rosterMemberId : $fAssigneeId;
        $assigneeName = $useRoster ? $bugHandlerName : $fAssigneeName;
        $extra = [
            'assignee_id' => $assigneeId,
            'state_id' => $fStateId,
            'start_at' => $startTs,
            'end_at' => $dueTs,
        ];

        try {
            [$taskId, $taskNo, $taskUrl] = qly_pingcode_create_task($title, $desc, $projectId, $typeId, $fPriorityId, $extra);
            foreach ($fTags as $tagName) {
                try {
                    $tagId = qly_pingcode_ensure_tag($tagName, $projectId);
                    if ($tagId !== '') {
                        qly_pingcode_attach_tag($taskId, $tagId);
                    }
                } catch (Throwable $te) {
                    $errors[] = (string) $row['issue_no'] . ' 标签「' . $tagName . '」：' . $te->getMessage();
                }
            }
        } catch (Throwable $e) {
            $errors[] = (string) $row['issue_no'] . '：' . $e->getMessage();
            $skipCount++;
            continue;
        }

        qly_update('issue', $tid, [
            'pingcode_task_id' => $taskId,
            'pingcode_task_no' => $taskNo !== '' ? $taskNo : null,
            'pingcode_task_url' => $taskUrl !== '' ? $taskUrl : null,
            'pingcode_synced_at' => $now,
            'pingcode_assignee_id' => $assigneeId !== '' ? $assigneeId : null,
            'pingcode_assignee_name' => $assigneeName !== '' ? $assigneeName : null,
        ]);

        // 消耗顺位：只有使用排班（未手动改负责人）才推进
        if ($useRoster && $bugNextSort > 0) {
            qly_bug_duty_commit($bugNextSort, $operator);
        }
        if ($bugHandlerName !== '') {
            $lastBugHandler = $bugHandlerName;
        }

        qly_log($user, '问题处理', (string) $row['issue_no'],
            '同步到 PingCode：' . $title . ($taskNo !== '' ? '（' . $taskNo . '）' : '')
            . ($bugHandlerName !== '' ? '，BUG 处理人 ' . $bugHandlerName . ($bugPosition > 0 ? '（第 ' . $bugPosition . ' 顺位）' : '') : ''));
        $okCount++;
    }

    if ($okCount === 0) {
        if ($errors) {
            qly_fail('同步失败：' . implode('；', array_slice($errors, 0, 3)));
        }
        qly_fail('没有可同步的问题（需尚未同步过）');
    }
    $msg = '已同步 ' . $okCount . ' 条到 PingCode' . ($skipCount > 0 ? '，另有 ' . $skipCount . ' 条已跳过（已同步/失败）' : '');
    if ($lastBugHandler !== '') {
        $msg .= '；BUG 处理人：' . $lastBugHandler;
    }
    if ($errors) {
        $msg .= '；部分标签未生效：' . implode('；', array_slice($errors, 0, 2));
    }
    qly_ok([
        'affected' => $okCount,
        'skipped' => $skipCount,
        'bugDutyCurrent' => qly_bug_duty_peek(),
    ], $msg);
}
