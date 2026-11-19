<?php
/**
 * api_cities.php —— 关注城市增删改查（登录用户专属）
 */
if (!defined('IN_PROJECT')) {
    exit('Access Denied');
}

function city_max()
{
    return 30;
}

function city_list($identity)
{
    $rows = db_all(
        'SELECT id, adcode, city_name, sort_order, created_at FROM user_cities WHERE user_id = ? ORDER BY sort_order ASC, id ASC',
        [$identity['user_id']]
    );
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
    }
    unset($row);
    return ['cities' => $rows];
}

function city_add($identity)
{
    $name = trim((string) param('city_name', ''));
    $adcode = trim((string) param('adcode', ''));
    if ($name === '' || text_len($name) > 40) {
        fail(1001, '城市名称无效', 400);
    }
    if ($adcode !== '' && !preg_match('/^\d{6,12}$/', $adcode)) {
        fail(1001, '城市编码格式无效', 400);
    }

    $userCities = db_val('SELECT COUNT(*) FROM user_cities WHERE user_id = ?', [$identity['user_id']], 0);
    if ((int) $userCities >= city_max()) {
        fail(1001, '最多关注 ' . city_max() . ' 个城市', 400);
    }
    if (db_val('SELECT id FROM user_cities WHERE user_id = ? AND city_name = ?', [$identity['user_id'], $name])) {
        fail(1001, '该城市已关注', 409);
    }

    $next = (int) db_val('SELECT MAX(sort_order) FROM user_cities WHERE user_id = ?', [$identity['user_id']], 0) + 1;
    db_exec(
        'INSERT INTO user_cities (user_id, adcode, city_name, sort_order, created_at) VALUES (?, ?, ?, ?, ?)',
        [$identity['user_id'], $adcode, $name, $next, date('Y-m-d H:i:s')]
    );

    return ['id' => (int) db()->lastInsertId(), 'city_name' => $name, 'adcode' => $adcode, 'sort_order' => $next];
}

function city_del($identity)
{
    $id = int_param('id');
    if ($id <= 0) {
        fail(1001, '缺少城市 ID', 400);
    }
    $stmt = db_exec('DELETE FROM user_cities WHERE id = ? AND user_id = ?', [$id, $identity['user_id']]);
    if ($stmt->rowCount() === 0) {
        fail(1001, '关注城市不存在', 404);
    }
    return ['id' => $id];
}

function city_reorder($identity)
{
    $ids = param('ids', []);
    if (is_string($ids)) {
        $decoded = json_decode($ids, true);
        $ids = is_array($decoded) ? $decoded : explode(',', $ids);
    }
    if (!is_array($ids)) {
        fail(1001, '排序参数无效', 400);
    }
    $clean = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean[] = $id;
        }
    }
    $clean = array_slice(array_values(array_unique($clean)), 0, city_max());
    if (empty($clean)) {
        fail(1001, '排序参数无效', 400);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE user_cities SET sort_order = ? WHERE id = ? AND user_id = ?');
        foreach ($clean as $index => $id) {
            $stmt->execute([$index + 1, $id, $identity['user_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ids' => $clean];
}

return [
    'cities_list'    => ['auth' => 'user', 'rate' => true, 'handler' => 'city_list'],
    'cities_add'     => ['auth' => 'user', 'rate' => true, 'handler' => 'city_add'],
    'cities_del'     => ['auth' => 'user', 'rate' => true, 'handler' => 'city_del'],
    'cities_reorder' => ['auth' => 'user', 'rate' => true, 'handler' => 'city_reorder'],
];