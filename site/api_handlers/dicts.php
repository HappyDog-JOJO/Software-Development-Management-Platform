<?php
declare(strict_types=1);

/** 字典维护（仅管理员）；四类字典：所属模块 / 问题价值等级 / 处理情况 / 问题分类 */

const QLY_DICT_TYPES = [
    // 售后管理
    'module' => '所属模块',
    'value' => '问题价值等级',
    'handle' => '处理情况',
    'category' => '问题分类',
    // 销售分析（输单）
    'loss_type' => '输单类型',
    'industry' => '所属行业',
    'company_scale' => '企业规模',
    'loss_stage' => '丢单阶段',
    'budget_match' => '预算匹配',
];

function qly_dict_type_label(string $type): string
{
    return QLY_DICT_TYPES[$type] ?? $type;
}

function qly_dict_reference_count(string $type, int $itemId): int
{
    if ($type === 'module') {
        return (int) qly_val('SELECT COUNT(*) FROM `issue` WHERE module_id = :id', [':id' => $itemId]);
    }
    if ($type === 'value') {
        $a = (int) qly_val('SELECT COUNT(*) FROM `issue` WHERE value_id = :id', [':id' => $itemId]);
        $b = (int) qly_val('SELECT COUNT(*) FROM `handle_record` WHERE value_id = :id', [':id' => $itemId]);
        return $a + $b;
    }
    if ($type === 'handle') {
        return (int) qly_val('SELECT COUNT(*) FROM `handle_record` WHERE handle_status_id = :id', [':id' => $itemId]);
    }
    if ($type === 'category') {
        return (int) qly_val('SELECT COUNT(*) FROM `handle_record` WHERE category_id = :id', [':id' => $itemId]);
    }
    // 销售分析（输单）：各维度字典被输单记录引用的条数
    $lossColumns = [
        'loss_type' => 'loss_type_id',
        'industry' => 'industry_id',
        'company_scale' => 'company_scale_id',
        'loss_stage' => 'loss_stage_id',
        'budget_match' => 'budget_match_id',
    ];
    if (isset($lossColumns[$type])) {
        // 防御：lost_order 尚未建表时（新库/未执行迁移）不要让字典页整体 500，引用数按 0 处理
        try {
            return (int) qly_val('SELECT COUNT(*) FROM `lost_order` WHERE ' . $lossColumns[$type] . ' = :id', [':id' => $itemId]);
        } catch (Throwable $e) {
            return 0;
        }
    }
    return 0;
}

function qly_api_dicts_list(): void
{
    qly_auth_user();
    $type = (string) qly_input('type', '');
    $where = '';
    $params = [];
    if ($type !== '') {
        if (!isset(QLY_DICT_TYPES[$type])) {
            qly_fail('字典类型不合法');
        }
        $where = ' WHERE type = :t';
        $params[':t'] = $type;
    }
    $rows = qly_all('SELECT * FROM `dict_item`' . $where . ' ORDER BY type ASC, sort ASC, id ASC', $params);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'typeLabel' => qly_dict_type_label((string) $r['type']),
            'name' => $r['name'],
            'sort' => (int) $r['sort'],
            'status' => (int) $r['status'],
            'referenceCount' => qly_dict_reference_count((string) $r['type'], (int) $r['id']),
            'createdAt' => $r['created_at'],
        ];
    }
    qly_ok($items);
}

function qly_api_dicts_save(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $type = (string) qly_input('type', '');
    $name = (string) qly_input('name', '');
    $sort = qly_input_int('sort', 0);
    if (!isset(QLY_DICT_TYPES[$type])) {
        qly_fail('字典类型不合法');
    }
    if ($name === '') {
        qly_fail('字典项名称不能为空');
    }
    $dup = qly_val('SELECT COUNT(*) FROM `dict_item` WHERE type = :t AND name = :n AND id <> :id', [':t' => $type, ':n' => $name, ':id' => $id]);
    if ((int) $dup > 0) {
        qly_fail('同类型下已存在同名项');
    }
    if ($id > 0) {
        $old = qly_one('SELECT * FROM `dict_item` WHERE id = :id', [':id' => $id]);
        if (!$old) {
            qly_fail('字典项不存在');
        }
        if ((string) $old['type'] !== $type) {
            qly_fail('不支持跨类型修改字典项');
        }
        qly_update('dict_item', $id, ['name' => $name, 'sort' => $sort]);
        qly_log($admin, '字典维护', qly_dict_type_label($type) . ' / ' . $name, '编辑字典项：名称 ' . $old['name'] . ' → ' . $name);
    } else {
        $id = qly_insert('dict_item', [
            'type' => $type,
            'name' => $name,
            'sort' => $sort,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        qly_log($admin, '字典维护', qly_dict_type_label($type) . ' / ' . $name, '新增字典项');
    }
    qly_ok(['id' => $id], '字典项已保存');
}

function qly_api_dicts_status(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $status = qly_input_int('status', -1);
    if (!in_array($status, [0, 1], true)) {
        qly_fail('状态取值不合法');
    }
    $row = qly_one('SELECT * FROM `dict_item` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('字典项不存在');
    }
    // 问题价值等级：至少保留一项启用，避免新单据无处可选
    if ((string) $row['type'] === 'value' && $status === 0) {
        $enabled = (int) qly_val('SELECT COUNT(*) FROM `dict_item` WHERE type = :t AND status = 1 AND id <> :id', [':t' => 'value', ':id' => $id]);
        if ($enabled < 1) {
            qly_fail('问题价值等级至少需保留一项启用');
        }
    }
    qly_update('dict_item', $id, ['status' => $status]);
    qly_log($admin, '字典维护', qly_dict_type_label((string) $row['type']) . ' / ' . $row['name'], $status === 1 ? '启用字典项' : '停用字典项');
    qly_ok(null, $status === 1 ? '字典项已启用' : '字典项已停用');
}

function qly_api_dicts_delete(): void
{
    $admin = qly_admin_user();
    $id = qly_input_int('id', 0);
    $row = qly_one('SELECT * FROM `dict_item` WHERE id = :id', [':id' => $id]);
    if (!$row) {
        qly_fail('字典项不存在');
    }
    $type = (string) $row['type'];
    $refs = qly_dict_reference_count($type, $id);
    if ($refs > 0) {
        qly_fail('该项已被 ' . $refs . ' 条数据引用，仅可停用');
    }
    if ($type === 'value') {
        $enabled = (int) qly_val('SELECT COUNT(*) FROM `dict_item` WHERE type = :t AND status = 1 AND id <> :id', [':t' => 'value', ':id' => $id]);
        if ($enabled < 1) {
            qly_fail('问题价值等级至少需保留一项启用');
        }
    }
    qly_exec('DELETE FROM `dict_item` WHERE id = :id', [':id' => $id]);
    qly_log($admin, '字典维护', qly_dict_type_label($type) . ' / ' . $row['name'], '删除字典项');
    qly_ok(null, '字典项已删除');
}
