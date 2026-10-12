<?php
/**
 * 统一上传接口（AJAX / 表单 POST）
 * - action=material   资料上传（管理员/干事，写入对应项目 materials）
 * - action=notice     通知附件上传（管理员/干事，保存到 uploads/notices/ 并写入通知记录）
 * 安全：拒绝可执行脚本扩展名、随机重命名、文件锁写入
 */
require_once __DIR__ . '/../includes/config.php';
require_role(array('admin', 'officer'));

$action = isset($_GET['action']) ? $_GET['action'] : '';
$user = current_user();

// ---------------- 资料上传 ----------------
if ($action === 'material') {
    check_csrf();
    $pid = isset($_POST['project_id']) ? $_POST['project_id'] : '';
    $up = handle_upload('file', 'materials');
    if (!$up['ok']) {
        $_SESSION['flash'] = $up['error'];
        redirect('/index.php?view=project&id=' . urlencode($pid));
    }
    $branches = jdb_collection('projects', 'branches');
    $saved = false;
    foreach ($branches as $bk => $br) {
        if (empty($br['projects'])) continue;
        foreach ($br['projects'] as $pk => $p) {
            if ($p['id'] === $pid) {
                if (!isset($branches[$bk]['projects'][$pk]['materials'])) {
                    $branches[$bk]['projects'][$pk]['materials'] = array();
                }
                $branches[$bk]['projects'][$pk]['materials'][] = array(
                    'stored' => $up['stored_name'],
                    'orig'   => $up['orig_name'],
                    'size'   => $up['size'],
                    'uploaded_by' => $user['id'],
                    'created_at'  => date('Y-m-d H:i:s')
                );
                $saved = true;
                break 2;
            }
        }
    }
    if ($saved) {
        jdb_save_collection('projects', 'branches', $branches);
        add_log('上传资料', '项目：' . $p['name'] . ' ｜ 文件：' . $up['orig_name']);
        $_SESSION['flash'] = '资料上传成功';
    } else {
        $_SESSION['flash'] = '项目不存在';
    }
    redirect('/index.php?view=project&id=' . urlencode($pid));
}

// ---------------- 通知发布（含附件） ----------------
if ($action === 'notice') {
    check_csrf();
    $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
    $contentMd = isset($_POST['content_md']) ? $_POST['content_md'] : '';
    $type = isset($_POST['type']) ? $_POST['type'] : 'public';
    $pinned = isset($_POST['pinned']) ? 1 : 0;
    $editId = isset($_POST['edit_id']) ? $_POST['edit_id'] : '';

    if ($title === '') {
        $_SESSION['flash'] = '通知标题不能为空';
        redirect('/admin/notices.php');
    }
    $notices = jdb_collection('notices', 'notices');

    // 附件（可选，单个）
    $attachments = array();
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = handle_upload('attachment', 'notices');
        if (!$up['ok']) {
            $_SESSION['flash'] = $up['error'];
            redirect('/admin/notices.php');
        }
        $attachments[] = array('stored' => $up['stored_name'], 'orig' => $up['orig_name'], 'size' => $up['size']);
    }

    if ($editId !== '') {
        // 编辑已有通知：保留原附件，追加新附件
        foreach ($notices as $k => $n) {
            if ($n['id'] === $editId) {
                $notices[$k]['title'] = $title;
                $notices[$k]['content_md'] = $contentMd;
                $notices[$k]['type'] = $type;
                $notices[$k]['pinned'] = $pinned ? true : false;
                if (count($attachments) > 0) {
                    $notices[$k]['attachments'] = array_merge(
                        isset($notices[$k]['attachments']) ? $notices[$k]['attachments'] : array(),
                        $attachments
                    );
                }
                $_SESSION['flash'] = '通知已更新';
                break;
            }
        }
    } else {
        $notices[] = array(
            'id'          => 'n_' . uniqid(),
            'type'        => $type,
            'title'       => $title,
            'content_md'  => $contentMd,
            'attachments' => $attachments,
            'pinned'      => $pinned ? true : false,
            'created_by'  => $user['id'],
            'creator_name'=> $user['name'],
            'created_at'  => date('Y-m-d H:i:s'),
            'read_by'     => array()
        );
        $_SESSION['flash'] = '通知已发布';
    }
    jdb_save_collection('notices', 'notices', $notices);
    redirect('/admin/notices.php');
}

$_SESSION['flash'] = '未知上传操作';
redirect('/admin/index.php');
