<?php
/**
 * 项目与资料接口（AJAX）
 * - GET action=list    分支 + 小项目列表（需登录）
 * - GET action=view&id=项目ID  项目详情（需登录）
 */
require_once __DIR__ . '/../includes/config.php';
require_login();

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'list') {
    $branches = jdb_collection('projects', 'branches');
    $out = array();
    foreach ($branches as $br) {
        $ps = array();
        foreach ($br['projects'] as $p) {
            $ps[] = array(
                'id' => $p['id'],
                'name' => $p['name'],
                'materials' => count(isset($p['materials']) ? $p['materials'] : array()),
                'homework' => count(isset($p['homework']) ? $p['homework'] : array())
            );
        }
        $out[] = array(
            'id' => $br['id'],
            'name' => $br['name'],
            'desc' => isset($br['desc']) ? $br['desc'] : '',
            'projects' => $ps
        );
    }
    json_response(array('ok' => true, 'branches' => $out));
}

if ($action === 'view') {
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    $branches = jdb_collection('projects', 'branches');
    foreach ($branches as $br) {
        foreach ($br['projects'] as $p) {
            if ($p['id'] === $id) {
                json_response(array('ok' => true, 'project' => $p, 'branch_name' => $br['name']));
            }
        }
    }
    json_response(array('ok' => false, 'error' => '项目不存在'), 404);
}

json_response(array('ok' => false, 'error' => '未知操作'), 400);
