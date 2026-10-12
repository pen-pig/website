<?php
/**
 * 项目管理后台（管理员 / 干事）
 * - 管理员：创建/删除分支与小项目
 * - 管理员/干事：编辑项目介绍、上传资料、查看作业、评选优秀作业、一键清理非优秀作业（管理员）
 * 作业文件物理清理：仅删除"非优秀"作业，页面前端 confirm 二次确认
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin', 'officer'));
$isAdmin = $user['role'] === 'admin';

// ---------------- GET 动作：评选优秀作业 ----------------
if (isset($_GET['action']) && ($_GET['action'] === 'feature' || $_GET['action'] === 'unfeature')) {
    check_csrf();
    $pid = isset($_GET['project_id']) ? $_GET['project_id'] : '';
    $hwId = isset($_GET['hw_id']) ? $_GET['hw_id'] : '';
    $branches = jdb_collection('projects', 'branches');
    foreach ($branches as $bk => $br) {
        if (empty($br['projects'])) continue;
        foreach ($br['projects'] as $pk => $p) {
            if ($p['id'] === $pid && !empty($p['homework'])) {
                foreach ($branches[$bk]['projects'][$pk]['homework'] as $hk => $h) {
                    if ($h['id'] === $hwId) {
                        $branches[$bk]['projects'][$pk]['homework'][$hk]['featured'] = ($_GET['action'] === 'feature');
                        add_log(($_GET['action'] === 'feature') ? '设为优秀作业' : '取消优秀作业', '项目：' . $p['name'] . ' ｜ 作业：' . $h['orig']);
                    }
                }
                jdb_save_collection('projects', 'branches', $branches);
            }
        }
    }
    redirect('projects.php?view=project&id=' . urlencode($pid));
}

// ---------------- POST 处理 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $branches = jdb_collection('projects', 'branches');

    // 创建分支（管理员）
    if ($action === 'add_branch' && $isAdmin) {
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $desc = trim(isset($_POST['desc']) ? $_POST['desc'] : '');
        if ($name === '') {
            $_SESSION['flash'] = '分支名称不能为空';
        } else {
            $branches[] = array(
                'id' => 'b_' . uniqid(),
                'name' => $name,
                'desc' => $desc,
                'projects' => array()
            );
            jdb_save_collection('projects', 'branches', $branches);
            add_log('创建分支', '分支：' . $name);
            $_SESSION['flash'] = '分支已创建';
        }
    }

    // 创建小项目（管理员）
    if ($action === 'add_project' && $isAdmin) {
        $bid = isset($_POST['branch_id']) ? $_POST['branch_id'] : '';
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $intro = isset($_POST['intro_md']) ? $_POST['intro_md'] : '';
        if ($name === '') {
            $_SESSION['flash'] = '项目名称不能为空';
        } else {
            foreach ($branches as $bk => $br) {
                if ($br['id'] === $bid) {
                    $branches[$bk]['projects'][] = array(
                        'id' => 'p_' . uniqid(),
                        'name' => $name,
                        'intro_md' => $intro,
                        'materials' => array(),
                        'homework' => array(),
                        'status' => 'open'
                    );
                    jdb_save_collection('projects', 'branches', $branches);
                    add_log('创建小项目', '分支：' . $br['name'] . ' ｜ 项目：' . $name);
                    $_SESSION['flash'] = '小项目已创建';
                    break;
                }
            }
        }
    }

    // 删除分支（管理员；连同分支下所有项目的资料/作业物理文件一并删除，防止文件爆量）
    if ($action === 'delete_branch' && $isAdmin) {
        $bid = isset($_POST['branch_id']) ? $_POST['branch_id'] : '';
        $delName = '';
        $delFiles = 0;
        $newBranches = array();
        foreach ($branches as $br) {
            if ($br['id'] !== $bid) {
                $newBranches[] = $br;
            } else {
                $delName = $br['name'];
                // 同步清理该分支下所有项目的资料与作业物理文件
                if (!empty($br['projects'])) {
                    foreach ($br['projects'] as $p) {
                        if (!empty($p['materials'])) {
                            foreach ($p['materials'] as $m) {
                                if (remove_upload_file('materials', isset($m['stored']) ? $m['stored'] : '')) $delFiles++;
                            }
                        }
                        if (!empty($p['homework'])) {
                            foreach ($p['homework'] as $h) {
                                if (remove_upload_file('homework', isset($h['stored']) ? $h['stored'] : '')) $delFiles++;
                            }
                        }
                    }
                }
            }
        }
        jdb_save_collection('projects', 'branches', $newBranches);
        add_log('删除分支', '分支：' . $delName . ' ｜ 同步删除 ' . $delFiles . ' 个文件');
        $_SESSION['flash'] = '分支已删除，其下 ' . $delFiles . ' 个资料/作业文件已同步清理';
    }

    // 删除小项目（管理员；连同项目下资料/作业物理文件一并删除，防止文件爆量）
    if ($action === 'delete_project' && $isAdmin) {
        $pid = isset($_POST['project_id']) ? $_POST['project_id'] : '';
        $delName = '';
        $delFiles = 0;
        foreach ($branches as $bk => $br) {
            $newProjects = array();
            foreach ($br['projects'] as $p) {
                if ($p['id'] !== $pid) {
                    $newProjects[] = $p;
                } else {
                    $delName = $p['name'];
                    // 同步清理该项目的资料与作业物理文件
                    if (!empty($p['materials'])) {
                        foreach ($p['materials'] as $m) {
                            if (remove_upload_file('materials', isset($m['stored']) ? $m['stored'] : '')) $delFiles++;
                        }
                    }
                    if (!empty($p['homework'])) {
                        foreach ($p['homework'] as $h) {
                            if (remove_upload_file('homework', isset($h['stored']) ? $h['stored'] : '')) $delFiles++;
                        }
                    }
                }
            }
            $branches[$bk]['projects'] = $newProjects;
        }
        jdb_save_collection('projects', 'branches', $branches);
        add_log('删除小项目', '项目：' . $delName . ' ｜ 同步删除 ' . $delFiles . ' 个文件');
        $_SESSION['flash'] = '小项目已删除，其下 ' . $delFiles . ' 个资料/作业文件已同步清理';
    }

    // 编辑项目介绍（管理员/干事）
    if ($action === 'update_intro') {
        $pid = isset($_POST['project_id']) ? $_POST['project_id'] : '';
        $intro = isset($_POST['intro_md']) ? $_POST['intro_md'] : '';
        foreach ($branches as $bk => $br) {
            if (empty($br['projects'])) continue;
            foreach ($br['projects'] as $pk => $p) {
                if ($p['id'] === $pid) {
                    $branches[$bk]['projects'][$pk]['intro_md'] = $intro;
                    jdb_save_collection('projects', 'branches', $branches);
                    add_log('编辑项目介绍', '项目：' . $p['name']);
                    $_SESSION['flash'] = '项目介绍已更新';
                    break 2;
                }
            }
        }
    }

    // 切换项目开放/封闭状态（管理员）
    if ($action === 'toggle_project' && $isAdmin) {
        $pid = isset($_POST['project_id']) ? $_POST['project_id'] : '';
        foreach ($branches as $bk => $br) {
            if (empty($br['projects'])) continue;
            foreach ($br['projects'] as $pk => $p) {
                if ($p['id'] === $pid) {
                    $cur = isset($p['status']) ? $p['status'] : 'open';
                    $branches[$bk]['projects'][$pk]['status'] = ($cur === 'open') ? 'closed' : 'open';
                    jdb_save_collection('projects', 'branches', $branches);
                    add_log('切换项目状态', '项目：' . $p['name'] . ' → ' . (($cur === 'open') ? '封闭' : '开放'));
                    $_SESSION['flash'] = ($cur === 'open') ? '小项目已封闭，成员暂不可查看' : '小项目已开放';
                    break 2;
                }
            }
        }
    }

    // 一键清理非优秀作业（管理员；保留"优秀作业展示"）
    if ($action === 'cleanup_homework' && $isAdmin) {
        $pid = isset($_POST['project_id']) ? $_POST['project_id'] : '';
        $removed = 0;
        foreach ($branches as $bk => $br) {
            if (empty($br['projects'])) continue;
            foreach ($br['projects'] as $pk => $p) {
                if ($p['id'] !== $pid) continue;
                $keep = array();
                foreach ($p['homework'] as $h) {
                    if (!empty($h['featured'])) {
                        $keep[] = $h;
                    } else {
                        // 物理删除作业文件（仅非优秀）
                        $abs = DATA_DIR . '/uploads/homework/' . $h['stored'];
                        if (is_file($abs)) { @unlink($abs); }
                        $removed++;
                    }
                }
                $branches[$bk]['projects'][$pk]['homework'] = $keep;
                add_log('清理非优秀作业', '项目：' . $p['name'] . ' ｜ 删除 ' . $removed . ' 份');
                break 2;
            }
        }
        jdb_save_collection('projects', 'branches', $branches);
        $_SESSION['flash'] = '清理完成：删除 ' . $removed . ' 份非优秀作业，优秀作业已保留';
    }

    redirect('projects.php');
}

$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$branches = jdb_collection('projects', 'branches');
$viewProject = isset($_GET['view']) && $_GET['view'] === 'project' ? (isset($_GET['id']) ? $_GET['id'] : '') : '';

page_header('项目管理', 'admin');
if ($flash !== '') flash_toast($flash);

if ($viewProject !== '') {
    // 单个项目详情（作业管理）
    foreach ($branches as $br) {
        foreach ($br['projects'] as $p) {
            if ($p['id'] === $viewProject) {
                echo '<div class="d-flex justify-content-between align-items-center mb-3"><h4>' . e($br['name']) . ' / ' . e($p['name']) . '</h4><a class="btn btn-sm btn-outline-secondary" href="projects.php">返回列表</a></div>';

                // 编辑介绍
                echo '<div class="card mb-4"><div class="card-header"><strong>项目介绍（Markdown）</strong></div><div class="card-body">';
                echo '<form method="post" action="projects.php">' . csrf_field();
                echo '<input type="hidden" name="action" value="update_intro">';
                echo '<input type="hidden" name="project_id" value="' . e($p['id']) . '">';
                echo '<textarea name="intro_md" class="form-control md-input" rows="6">' . e($p['intro_md']) . '</textarea>';
                echo '<button class="btn btn-primary mt-2">保存介绍</button></form></div></div>';

                // 作业列表
                $homework = isset($p['homework']) ? $p['homework'] : array();
                echo '<div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center"><strong>作业（' . count($homework) . '）</strong>';
                if ($isAdmin && count($homework) > 0) {
                    echo '<form method="post" action="projects.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="cleanup_homework"><input type="hidden" name="project_id" value="' . e($p['id']) . '"><button class="btn btn-sm btn-danger" onclick="return confirm(\'确定清理所有非优秀作业？优秀作业会保留。\')">一键清理非优秀作业</button></form>';
                }
                echo '</div><div class="card-body p-0">';
                if (count($homework) === 0) { echo '<div class="p-3 text-muted small">暂无作业</div>'; }
                else {
                    echo '<ul class="list-group list-group-flush">';
                    foreach ($homework as $h) {
                        echo '<li class="list-group-item d-flex justify-content-between align-items-center">';
                        echo '<span><a href="/download.php?type=homework&amp;file=' . urlencode($h['stored']) . '">' . e($h['orig']) . '</a> <span class="text-muted small">by ' . e($h['uploader_name']) . ' ｜ ' . e($h['created_at']) . '</span></span>';
                        if (!empty($h['featured'])) {
                            echo '<a class="btn btn-sm btn-outline-warning" href="projects.php?action=unfeature&amp;project_id=' . urlencode($p['id']) . '&amp;hw_id=' . urlencode($h['id']) . '&amp;token=' . urlencode($_SESSION['csrf_token']) . '">取消优秀</a>';
                        } else {
                            echo '<a class="btn btn-sm btn-outline-success" href="projects.php?action=feature&amp;project_id=' . urlencode($p['id']) . '&amp;hw_id=' . urlencode($h['id']) . '&amp;token=' . urlencode($_SESSION['csrf_token']) . '">设为优秀</a>';
                        }
                        echo '</li>';
                    }
                    echo '</ul>';
                }
                echo '</div></div>';

                // 资料列表
                $materials = isset($p['materials']) ? $p['materials'] : array();
                echo '<div class="card"><div class="card-header"><strong>资料（' . count($materials) . '）</strong></div><div class="card-body p-0">';
                if (count($materials) === 0) { echo '<div class="p-3 text-muted small">暂无资料</div>'; }
                else {
                    echo '<ul class="list-group list-group-flush">';
                    foreach ($materials as $m) {
                        echo '<li class="list-group-item d-flex justify-content-between align-items-center"><span>' . e($m['orig']) . ' <span class="text-muted small">' . round($m['size'] / 1024) . ' KB ｜ ' . e($m['created_at']) . '</span></span><a class="btn btn-sm btn-outline-primary" href="/download.php?type=materials&amp;file=' . urlencode($m['stored']) . '">下载</a></li>';
                    }
                    echo '</ul>';
                }
                echo '</div></div>';
                break 2;
            }
        }
    }
    page_footer();
    exit;
}

// ---------------- 总览：分支 + 小项目 ----------------
echo '<div class="row">';
echo '<div class="col-lg-4">';
if ($isAdmin) {
    echo '<div class="card mb-4"><div class="card-header"><strong>创建分支</strong></div><div class="card-body">';
    echo '<form method="post" action="projects.php">' . csrf_field();
    echo '<input type="hidden" name="action" value="add_branch">';
    echo '<div class="form-group"><label>分支名称 *</label><input type="text" name="name" class="form-control" required></div>';
    echo '<div class="form-group"><label>分支说明</label><input type="text" name="desc" class="form-control"></div>';
    echo '<button class="btn btn-primary btn-block">创建分支</button></form></div></div>';

    echo '<div class="card"><div class="card-header"><strong>创建小项目</strong></div><div class="card-body">';
    echo '<form method="post" action="projects.php">' . csrf_field();
    echo '<input type="hidden" name="action" value="add_project">';
    echo '<div class="form-group"><label>所属分支 *</label><select name="branch_id" class="form-control" required><option value="">请选择</option>';
    foreach ($branches as $br) echo '<option value="' . e($br['id']) . '">' . e($br['name']) . '</option>';
    echo '</select></div>';
    echo '<div class="form-group"><label>项目名称 *</label><input type="text" name="name" class="form-control" required></div>';
    echo '<div class="form-group"><label>项目介绍（Markdown）</label><textarea name="intro_md" class="form-control md-input" rows="4"></textarea></div>';
    echo '<button class="btn btn-primary btn-block">创建小项目</button></form></div></div>';
} else {
    echo '<div class="alert alert-info">仅管理员可创建/删除分支与项目；您可以编辑项目介绍、上传资料与评选优秀作业。</div>';
}
echo '</div>';

echo '<div class="col-lg-8">';
if (count($branches) === 0) { echo '<div class="alert alert-warning">暂无分支</div>'; }
foreach ($branches as $br) {
    echo '<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><strong>' . e($br['name']) . '</strong>';
    if ($isAdmin) {
        echo '<form method="post" action="projects.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="delete_branch"><input type="hidden" name="branch_id" value="' . e($br['id']) . '"><button class="btn btn-sm btn-outline-danger" onclick="return confirm(\'确定删除该分支？项目记录会移除，物理文件保留。\')">删除分支</button></form>';
    }
    echo '</div><div class="card-body">';
    if (!empty($br['desc'])) echo '<p class="text-muted small">' . e($br['desc']) . '</p>';
    if (count($br['projects']) === 0) { echo '<div class="text-muted small">暂无小项目</div>'; }
    foreach ($br['projects'] as $p) {
        $hwCount = count(isset($p['homework']) ? $p['homework'] : array());
        $mtCount = count(isset($p['materials']) ? $p['materials'] : array());
        $pClosed = (isset($p['status']) ? $p['status'] : 'open') === 'closed';
        echo '<div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2">';
        echo '<div><strong>' . e($p['name']) . '</strong> ';
        echo $pClosed ? '<span class="badge badge-secondary">未开放</span>' : '<span class="badge badge-success">开放</span>';
        echo ' <span class="text-muted small">资料 ' . $mtCount . ' ｜ 作业 ' . $hwCount . '</span></div>';
        echo '<div><a class="btn btn-sm btn-outline-primary mr-1" href="projects.php?view=project&amp;id=' . urlencode($p['id']) . '">管理</a>';
        if ($isAdmin) {
            echo '<form method="post" action="projects.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="toggle_project"><input type="hidden" name="project_id" value="' . e($p['id']) . '">';
            echo $pClosed
                ? '<button class="btn btn-sm btn-outline-success" onclick="return confirm(\'确定开放该小项目？开放后成员可查看内容。\')">开放</button>'
                : '<button class="btn btn-sm btn-outline-warning" onclick="return confirm(\'确定封闭该小项目？封闭后成员将无法查看内容。\')">封闭</button>';
            echo '</form>';
            echo '<form method="post" action="projects.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="' . e($p['id']) . '"><button class="btn btn-sm btn-outline-danger" onclick="return confirm(\'确定删除该小项目？\')">删除</button></form>';
        }
        echo '</div></div>';
    }
    echo '</div></div>';
}
echo '</div></div>';

page_footer();
