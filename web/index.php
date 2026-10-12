<?php
/**
 * 入口页面：根据登录状态与 view 参数分流
 *  - 游客首页：对外公告 + 社团介绍
 *  - 成员首页：内部通知 + 快捷入口
 *  - view=notices&type=public  对外公告列表/详情
 *  - view=notices&type=internal 内部通知（活动/上课，登录可见）
 *  - view=projects / project   项目与资料（分支→小项目→项目页）
 *  - view=tools / tool         插件式工具（iframe 嵌入）
 *  - view=surveys / survey     问卷（创建/参与/结果/导出）
 *  - view=profile              个人中心（改密码）
 */
require_once __DIR__ . '/includes/config.php';

$view = isset($_GET['view']) ? $_GET['view'] : '';
$user = is_logged_in() ? current_user() : null;

// 会话闪现消息
$flash = '';
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/**
 * 解析问卷题目文本：每行 类型|标题|选项1,选项2（选项仅单选/多选需要）
 */
function parse_questions($text) {
    $questions = array();
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode('|', $line);
        $type = isset($parts[0]) ? trim($parts[0]) : 'radio';
        $title = isset($parts[1]) ? trim($parts[1]) : '';
        $options = array();
        if (isset($parts[2]) && trim($parts[2]) !== '') {
            foreach (explode(',', $parts[2]) as $op) {
                $op = trim($op);
                if ($op !== '') $options[] = $op;
            }
        }
        if ($title === '') continue;
        if (!in_array($type, array('radio', 'checkbox', 'text'))) $type = 'radio';
        $questions[] = array(
            'id' => 'q_' . uniqid(),
            'type' => $type,
            'title' => $title,
            'options' => $options
        );
    }
    return $questions;
}

// ---------------- POST 动作处理 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // 头像上传
    if ($action === 'save_avatar') {
        require_login();
        $av = handle_avatar_upload('avatar');
        if ($av === null) {
            $_SESSION['flash'] = '头像上传失败（仅 jpg/png/gif/webp，≤2MB）';
        } else {
            $users = jdb_collection('users', 'users');
            foreach ($users as $k => $u) {
                if ($u['id'] === $user['id']) {
                    if (!empty($u['avatar'])) {
                        $oldPath = UPLOAD_DIR . '/avatars/' . basename($u['avatar']);
                        if (is_file($oldPath)) @unlink($oldPath);
                    }
                    $users[$k]['avatar'] = $av;
                }
            }
            jdb_save_collection('users', 'users', $users);
            $_SESSION['flash'] = '头像已更新';
            log_action('头像', '更新了头像');
        }
        redirect('index.php?view=profile');
    }

    // 个性签名
    if ($action === 'save_signature') {
        require_login();
        $sig = trim(isset($_POST['signature']) ? $_POST['signature'] : '');
        if (mb_strlen($sig) > 60) {
            $_SESSION['flash'] = '签名最多 60 字';
        } else {
            $users = jdb_collection('users', 'users');
            foreach ($users as $k => $u) {
                if ($u['id'] === $user['id']) {
                    $users[$k]['signature'] = $sig;
                }
            }
            jdb_save_collection('users', 'users', $users);
            $_SESSION['flash'] = '签名已更新';
        }
        redirect('index.php?view=profile');
    }

    // 修改密码
    if ($action === 'change_password') {
        require_login();
        $old = isset($_POST['old_password']) ? $_POST['old_password'] : '';
        $new = isset($_POST['new_password']) ? $_POST['new_password'] : '';
        if (!password_verify($old, $user['password'])) {
            $_SESSION['flash'] = '旧密码不正确';
        } elseif (strlen($new) < 6) {
            $_SESSION['flash'] = '新密码至少 6 位';
        } else {
            $users = jdb_collection('users', 'users');
            foreach ($users as $k => $u) {
                if ($u['id'] === $user['id']) {
                    $users[$k]['password'] = password_hash($new, PASSWORD_BCRYPT);
                }
            }
            jdb_save_collection('users', 'users', $users);
            $_SESSION['flash'] = '密码修改成功';
        }
        redirect('index.php?view=profile');
    }

    // 成员上传作业
    if ($action === 'upload_homework') {
        require_login();
        $pid = isset($_POST['project_id']) ? $_POST['project_id'] : '';
        $up = handle_upload('file', 'homework');
        if (!$up['ok']) {
            $_SESSION['flash'] = $up['error'];
        } else {
            $branches = jdb_collection('projects', 'branches');
            $saved = false;
            $denied = false;
            $isStaff = in_array($user['role'], array('admin', 'officer'));
            foreach ($branches as $bk => $br) {
                if (empty($br['projects'])) continue;
                foreach ($br['projects'] as $pk => $p) {
                    if ($p['id'] === $pid) {
                        // 封闭项目：普通成员禁止上传（防止提前交/看内容）
                        if (!$isStaff && (isset($p['status']) ? $p['status'] : 'open') === 'closed') {
                            $denied = true;
                            break 2;
                        }
                        if (!isset($branches[$bk]['projects'][$pk]['homework'])) {
                            $branches[$bk]['projects'][$pk]['homework'] = array();
                        }
                        $branches[$bk]['projects'][$pk]['homework'][] = array(
                            'id' => 'hw_' . uniqid(),
                            'stored' => $up['stored_name'],
                            'orig' => $up['orig_name'],
                            'size' => $up['size'],
                            'uploaded_by' => $user['id'],
                            'uploader_name' => $user['name'],
                            'created_at' => date('Y-m-d H:i:s'),
                            'featured' => false
                        );
                        $saved = true;
                        break 2;
                    }
                }
            }
            if ($denied) {
                $_SESSION['flash'] = '该项目暂未开放，暂不能上传作业';
            } elseif ($saved) {
                jdb_save_collection('projects', 'branches', $branches);
                $_SESSION['flash'] = '作业上传成功';
            } else {
                $_SESSION['flash'] = '项目不存在';
            }
        }
        redirect('index.php?view=project&id=' . urlencode($pid));
    }

    // 创建问卷（任何有账号的人都可以）
    if ($action === 'create_survey') {
        require_login();
        $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
        $desc = trim(isset($_POST['description']) ? $_POST['description'] : '');
        $anonymous = isset($_POST['anonymous']) ? 1 : 0;
        $nameField = isset($_POST['name_field']) ? 1 : 0;
        $deadline = isset($_POST['deadline']) ? trim($_POST['deadline']) : '';
        $qText = isset($_POST['questions']) ? $_POST['questions'] : '';
        $questions = parse_questions($qText);
        if ($title === '' || count($questions) === 0) {
            $_SESSION['flash'] = '请填写问卷标题和至少一道题目';
        } else {
            $surveys = jdb_collection('surveys', 'surveys');
            $surveys[] = array(
                'id' => 's_' . uniqid(),
                'title' => $title,
                'description' => $desc,
                'anonymous' => $anonymous,
                'name_field' => $nameField,
                'questions' => $questions,
                'deadline' => $deadline,
                'status' => 'open',
                'created_by' => $user['id'],
                'creator_name' => $user['name'],
                'created_at' => date('Y-m-d H:i:s'),
                'votes' => array()
            );
            jdb_save_collection('surveys', 'surveys', $surveys);
            $_SESSION['flash'] = '问卷创建成功';
        }
        redirect('index.php?view=surveys');
    }

    // 问卷操作：删除 / 复制 / 关闭重开（创建者或管理员）
    if ($action === 'survey_op') {
        require_login();
        $sid = isset($_POST['survey_id']) ? $_POST['survey_id'] : '';
        $op = isset($_POST['op']) ? $_POST['op'] : '';
        $surveys = jdb_collection('surveys', 'surveys');
        foreach ($surveys as $k => $sv) {
            if ($sv['id'] === $sid && ($sv['created_by'] === $user['id'] || $user['role'] === 'admin')) {
                if ($op === 'delete') {
                    unset($surveys[$k]);
                    $_SESSION['flash'] = '问卷已删除';
                } elseif ($op === 'copy') {
                    $copy = $sv;
                    $copy['id'] = 's_' . uniqid();
                    $copy['title'] = $sv['title'] . '（副本）';
                    $copy['votes'] = array();
                    $copy['status'] = 'open';
                    $copy['created_by'] = $user['id'];
                    $copy['creator_name'] = $user['name'];
                    $copy['created_at'] = date('Y-m-d H:i:s');
                    $surveys[] = $copy;
                    $_SESSION['flash'] = '问卷已复制';
                } elseif ($op === 'toggle') {
                    $surveys[$k]['status'] = ($sv['status'] === 'open') ? 'closed' : 'open';
                    $_SESSION['flash'] = $surveys[$k]['status'] === 'open' ? '问卷已重新开放' : '问卷已关闭';
                }
                break;
            }
        }
        $surveys = array_values($surveys);
        jdb_save_collection('surveys', 'surveys', $surveys);
        redirect('index.php?view=surveys');
    }

    // 问卷投票
    if ($action === 'vote') {
        require_login();
        $sid = isset($_POST['survey_id']) ? $_POST['survey_id'] : '';
        $surveys = jdb_collection('surveys', 'surveys');
        foreach ($surveys as $k => $sv) {
            if ($sv['id'] !== $sid) continue;
            // 截止时间与状态校验
            if ($sv['status'] !== 'open') { $_SESSION['flash'] = '问卷已关闭'; break; }
            if ($sv['deadline'] !== '' && strtotime($sv['deadline']) < time()) { $_SESSION['flash'] = '问卷已过截止时间'; break; }
            // 已投过则拒绝（允许创建者重复投？统一拦截）
            foreach ($sv['votes'] as $v) {
                if (!$sv['anonymous'] && isset($v['user_id']) && $v['user_id'] === $user['id']) {
                    $_SESSION['flash'] = '您已参与过该问卷'; break 2;
                }
            }
            // 组装答案
            $answers = array();
            foreach ($sv['questions'] as $q) {
                $val = isset($_POST['q_' . $q['id']]) ? $_POST['q_' . $q['id']] : '';
                $answers[$q['id']] = $val;
            }
            $name = '';
            if ($sv['name_field']) {
                $name = trim(isset($_POST['respondent_name']) ? $_POST['respondent_name'] : '');
            }
            $surveys[$k]['votes'][] = array(
                'user_id' => $sv['anonymous'] ? null : $user['id'],
                'name' => $name,
                'answers' => $answers,
                'created_at' => date('Y-m-d H:i:s')
            );
            jdb_save_collection('surveys', 'surveys', $surveys);
            $_SESSION['flash'] = '投票成功，感谢参与';
            break;
        }
        redirect('index.php?view=survey&id=' . urlencode($sid));
    }

    // 问卷导出 CSV（不落盘，直接下载）
    if ($action === 'export_csv') {
        require_login();
        $sid = isset($_POST['survey_id']) ? $_POST['survey_id'] : '';
        $surveys = jdb_collection('surveys', 'surveys');
        $sv = jdb_find_by_id($surveys, $sid);
        if (!$sv) { die('问卷不存在'); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="survey_' . $sv['id'] . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM，Excel 直接打开不乱码
        $head = array('序号', '提交时间');
        if ($sv['name_field']) $head[] = '姓名';
        foreach ($sv['questions'] as $q) $head[] = $q['title'];
        fputcsv($out, $head);
        $i = 1;
        foreach ($sv['votes'] as $v) {
            $row = array($i, $v['created_at']);
            if ($sv['name_field']) $row[] = $v['name'];
            foreach ($sv['questions'] as $q) {
                $val = isset($v['answers'][$q['id']]) ? $v['answers'][$q['id']] : '';
                if (is_array($val)) $val = implode(' / ', $val);
                $row[] = $val;
            }
            fputcsv($out, $row);
            $i++;
        }
        fclose($out);
        exit;
    }

    // 保存个人主题（主色 + 背景图）
    if ($action === 'save_theme') {
        require_login();
        $users = jdb_collection('users', 'users');
        foreach ($users as $k => $u) {
            if ($u['id'] === $user['id']) {
                if (!isset($users[$k]['theme']) || !is_array($users[$k]['theme'])) $users[$k]['theme'] = array();
                $main = trim(isset($_POST['main']) ? $_POST['main'] : '');
                if ($main !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $main)) {
                    $users[$k]['theme']['main'] = $main;
                }
                if (isset($_FILES['bg']) && $_FILES['bg']['error'] === UPLOAD_ERR_OK) {
                    $url = handle_theme_bg_upload('bg', 'user_' . $user['id']);
                    if ($url) {
                        if (!empty($users[$k]['theme']['bg'])) remove_theme_bg($users[$k]['theme']['bg']);
                        $users[$k]['theme']['bg'] = $url;
                    } else {
                        $_SESSION['flash'] = '背景图上传失败（仅支持 jpg/png/gif/webp，≤5MB）';
                    }
                }
                break;
            }
        }
        jdb_save_collection('users', 'users', $users);
        $_SESSION['flash'] = '个人主题已保存';
        redirect('index.php?view=profile');
    }

    // 恢复默认主题（清除个人 theme）
    if ($action === 'reset_theme') {
        require_login();
        $users = jdb_collection('users', 'users');
        foreach ($users as $k => $u) {
            if ($u['id'] === $user['id']) {
                if (!empty($users[$k]['theme']['bg'])) remove_theme_bg($users[$k]['theme']['bg']);
                unset($users[$k]['theme']);
                break;
            }
        }
        jdb_save_collection('users', 'users', $users);
        $_SESSION['flash'] = '已恢复为站点默认主题';
        redirect('index.php?view=profile');
    }
}

// ---------------- 视图渲染 ----------------
$s = $GLOBALS['SETTINGS'];
$color = isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8';
$orgName = isset($s['org_name']) ? $s['org_name'] : '社团';

// 根据 view 分流
if ($view === 'notices') {
    $type = isset($_GET['type']) && $_GET['type'] === 'internal' ? 'internal' : 'public';
    if ($type === 'internal') {
        require_login();
        $title = '内部通知';
        page_header($title, 'internal');
        $notices = jdb_collection('notices', 'notices');
        $list = array();
        foreach ($notices as $n) {
            if ($n['type'] === 'internal_activity' || $n['type'] === 'internal_class' || $n['type'] === 'internal') {
                $list[] = $n;
            }
        }
        render_notice_list($list, true);
        page_footer();
        exit;
    } else {
        $title = '对外公告';
        page_header($title, 'public');
        $notices = jdb_collection('notices', 'notices');
        $list = array();
        foreach ($notices as $n) {
            if ($n['type'] === 'public') $list[] = $n;
        }
        render_notice_list($list, is_logged_in());
        page_footer();
        exit;
    }
}

if ($view === 'notice') {
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    $notices = jdb_collection('notices', 'notices');
    $n = jdb_find_by_id($notices, $id);
    if (!$n) { page_header('通知不存在'); echo '<div class="alert alert-warning">通知不存在</div>'; page_footer(); exit; }
    if ($n['type'] !== 'public') { require_login(); } // 内部通知需登录
    // 标记已读
    if (is_logged_in() && $n['type'] !== 'public') {
        $user = current_user();
        foreach ($notices as $k => $nn) {
            if ($nn['id'] === $id && !in_array($user['id'], $nn['read_by'])) {
                $notices[$k]['read_by'][] = $user['id'];
                jdb_save_collection('notices', 'notices', $notices);
            }
        }
    }
    page_header($n['title']);
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="index.php">首页</a></li><li class="breadcrumb-item"><a href="index.php?view=notices&amp;type=' . ($n['type'] === 'public' ? 'public' : 'internal') . '">' . ($n['type'] === 'public' ? '对外公告' : '内部通知') . '</a></li><li class="breadcrumb-item active">详情</li></ol></nav>';
    echo '<div class="card"><div class="card-body">';
    echo '<h4>' . e($n['title']) . '</h4>';
    echo '<div class="text-muted small mb-3">发布人：' . e(isset($n['creator_name']) ? $n['creator_name'] : '') . ' ｜ 时间：' . e($n['created_at']) . '</div>';
    echo '<div class="markdown-body">' . markdown_parse($n['content_md']) . '</div>';
    if (!empty($n['attachments'])) {
        echo '<div class="mt-3"><strong>附件：</strong>';
        foreach ($n['attachments'] as $a) {
            $aext = strtolower(pathinfo($a['stored'], PATHINFO_EXTENSION));
            $aprev = in_array($aext, array('pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'));
            echo '<span class="mr-2 mb-1 d-inline-block"><a class="btn btn-sm btn-outline-primary" href="/download.php?type=notices&amp;file=' . urlencode($a['stored']) . '">' . e($a['orig']) . '（' . round($a['size'] / 1024) . ' KB）</a>';
            if ($aprev) {
                echo ' <a class="btn btn-sm btn-primary" href="/preview.php?type=notices&amp;file=' . urlencode($a['stored']) . '">预览</a></span>';
            } else {
                echo '</span>';
            }
        }
        echo '</div>';
    }
    echo '</div></div>';
    page_footer();
    exit;
}

if ($view === 'projects') {
    require_login();
    page_header('项目与资料', 'projects');
    $branches = jdb_collection('projects', 'branches');
    echo '<div class="card mb-4"><div class="card-body py-3"><h4 class="mb-0">项目与资料</h4></div></div>';
    if (count($branches) === 0) {
        echo '<div class="alert alert-info">暂无项目，请等待管理员创建。</div>';
    } else {
        foreach ($branches as $br) {
            echo '<div class="card mb-4"><div class="card-header"><span class="badge badge-primary mr-1">分支</span><strong>' . e($br['name']) . '</strong>';
            if (!empty($br['desc'])) echo '<span class="text-muted small ml-2">' . e($br['desc']) . '</span>';
            echo '</div><div class="card-body">';
            $ps = isset($br['projects']) ? $br['projects'] : array();
            if (count($ps) === 0) {
                echo '<div class="text-muted small">该分支下暂无小项目</div>';
            } else {
                $isStaff = in_array($user['role'], array('admin', 'officer'));
                echo '<div class="row">';
                foreach ($ps as $p) {
                    $pClosed = (isset($p['status']) ? $p['status'] : 'open') === 'closed';
                    echo '<div class="col-md-4 mb-3"><div class="proj-tile">';
                    echo '<div class="d-flex align-items-center mb-2"><span class="project-ico">' . e(mb_substr($p['name'], 0, 1)) . '</span><h6 class="mb-0">' . e($p['name']) . '</h6></div>';
                    if ($pClosed && !$isStaff) {
                        echo '<div class="proj-stats"><span class="badge badge-secondary">暂未开放</span></div>';
                        echo '<a class="btn btn-sm btn-outline-secondary mt-2 disabled" href="javascript:void(0)">暂未开放</a>';
                    } else {
                        if ($pClosed) {
                            echo '<div class="proj-stats"><span class="badge badge-secondary mr-1">暂未开放</span><span>资料 ' . count(isset($p['materials']) ? $p['materials'] : array()) . ' 份</span><span>作业 ' . count(isset($p['homework']) ? $p['homework'] : array()) . ' 份</span></div>';
                        } else {
                            echo '<div class="proj-stats"><span>资料 ' . count(isset($p['materials']) ? $p['materials'] : array()) . ' 份</span><span>作业 ' . count(isset($p['homework']) ? $p['homework'] : array()) . ' 份</span></div>';
                        }
                        echo '<a class="btn btn-sm btn-outline-primary mt-2" href="index.php?view=project&amp;id=' . urlencode($p['id']) . '">进入项目 →</a>';
                    }
                    echo '</div></div>';
                }
                echo '</div>';
            }
            echo '</div></div>';
        }
    }
    page_footer();
    exit;
}

if ($view === 'project') {
    require_login();
    $pid = isset($_GET['id']) ? $_GET['id'] : '';
    $branches = jdb_collection('projects', 'branches');
    $project = null;
    $branchName = '';
    foreach ($branches as $br) {
        if (empty($br['projects'])) continue;
        foreach ($br['projects'] as $p) {
            if ($p['id'] === $pid) { $project = $p; $branchName = $br['name']; break 2; }
        }
    }
    if (!$project) {
        page_header('项目不存在');
        echo '<div class="alert alert-warning">项目不存在</div>';
        page_footer();
        exit;
    }
    page_header($project['name'], 'projects');
    $isStaff = in_array($user['role'], array('admin', 'officer'));
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="index.php">首页</a></li><li class="breadcrumb-item"><a href="index.php?view=projects">项目与资料</a></li><li class="breadcrumb-item active">' . e($project['name']) . '</li></ol></nav>';

    // 封闭项目：普通成员不可查看（防止提前看内容）
    if (!$isStaff && (isset($project['status']) ? $project['status'] : 'open') === 'closed') {
        echo '<div class="alert alert-warning"><h6>该项目暂未开放</h6><div class="small">管理员尚未开放该项目，开放后可查看资料与作业。</div></div>';
        page_footer();
        exit;
    }

    // 优秀作业展示（置顶显示）
    $featured = array();
    $homework = isset($project['homework']) ? $project['homework'] : array();
    foreach ($homework as $h) {
        if (!empty($h['featured'])) $featured[] = $h;
    }
    if (count($featured) > 0) {
        echo '<div class="alert alert-success"><h6>优秀作业展示</h6>';
        foreach ($featured as $h) {
            echo '<div class="mb-1"><a href="/download.php?type=homework&amp;file=' . urlencode($h['stored']) . '">' . e($h['orig']) . '</a> <span class="text-muted small">by ' . e($h['uploader_name']) . ' ｜ ' . e($h['created_at']) . '</span></div>';
        }
        echo '</div>';
    }

    echo '<div class="card mb-4"><div class="card-header"><strong>项目介绍</strong></div><div class="card-body markdown-body">' . markdown_parse($project['intro_md']) . '</div></div>';

    // 资料下载
    echo '<div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center"><strong>资料下载</strong>';
    if ($isStaff) {
        echo '<form method="post" action="/api/upload.php?action=material" enctype="multipart/form-data" class="form-inline">' . csrf_field();
        echo '<input type="hidden" name="project_id" value="' . e($pid) . '">';
        echo '<input type="file" name="file" class="form-control-file form-control-sm mr-2" required>';
        echo '<button class="btn btn-sm btn-success">上传资料</button></form>';
    }
    echo '</div><div class="card-body">';
    $materials = isset($project['materials']) ? $project['materials'] : array();
    if (count($materials) === 0) { echo '<div class="text-muted small">暂无资料</div>'; }
    else {
        echo '<ul class="list-group list-group-flush">';
        foreach ($materials as $m) {
            $mext = strtolower(pathinfo($m['stored'], PATHINFO_EXTENSION));
            $previewable = in_array($mext, array('pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'));
            echo '<li class="list-group-item d-flex justify-content-between align-items-center"><span>' . e($m['orig']) . '<span class="text-muted small ml-2">' . round($m['size'] / 1024) . ' KB ｜ ' . e($m['created_at']) . '</span></span>';
            echo '<span class="d-flex align-items-center">';
            if ($previewable) {
                echo '<a class="btn btn-sm btn-primary mr-2" href="/preview.php?type=materials&amp;file=' . urlencode($m['stored']) . '" target="_blank">预览</a>';
            }
            echo '<a class="btn btn-sm btn-outline-primary" href="/download.php?type=materials&amp;file=' . urlencode($m['stored']) . '">下载</a></span></li>';
        }
        echo '</ul>';
    }
    echo '</div></div>';

    // 作业上传（成员）+ 作业查看（管理员/干事）
    echo '<div class="card mb-4"><div class="card-header"><strong>作业</strong></div><div class="card-body">';
    echo '<form method="post" action="index.php" enctype="multipart/form-data" class="mb-3">' . csrf_field();
    echo '<input type="hidden" name="action" value="upload_homework">';
    echo '<input type="hidden" name="project_id" value="' . e($pid) . '">';
    echo '<div class="form-row"><div class="col-md-8"><input type="file" name="file" class="form-control" required></div><div class="col-md-4"><button class="btn btn-primary btn-block">上传作业</button></div></div>';
    echo '</form>';
    if ($isStaff) {
        echo '<div class="text-muted small mb-2">全部作业（仅管理员/干事可见）：</div>';
        if (count($homework) === 0) { echo '<div class="text-muted small">暂无作业</div>'; }
        else {
            echo '<ul class="list-group">';
            foreach ($homework as $h) {
                $btn = !empty($h['featured'])
                    ? '<span class="badge badge-success">已展示</span> <a class="btn btn-sm btn-outline-warning ml-1" href="/admin/projects.php?action=unfeature&amp;project_id=' . urlencode($pid) . '&amp;hw_id=' . urlencode($h['id']) . '&amp;token=' . urlencode(csrf_token()) . '">取消展示</a>'
                    : '<a class="btn btn-sm btn-outline-success ml-2" href="/admin/projects.php?action=feature&amp;project_id=' . urlencode($pid) . '&amp;hw_id=' . urlencode($h['id']) . '&amp;token=' . urlencode(csrf_token()) . '">设为优秀</a>';
                echo '<li class="list-group-item d-flex justify-content-between align-items-center"><span><a href="/download.php?type=homework&amp;file=' . urlencode($h['stored']) . '">' . e($h['orig']) . '</a> <span class="text-muted small">by ' . e($h['uploader_name']) . ' ｜ ' . e($h['created_at']) . '</span></span>' . $btn . '</li>';
            }
            echo '</ul>';
        }
    } else {
        echo '<div class="text-muted small">作业上传后由管理员/干事查看与评选。</div>';
    }
    echo '</div></div>';
    page_footer();
    exit;
}

if ($view === 'tools') {
    require_login();
    page_header('工具', 'tools');
    $info = get_tools_list();
    echo '<div class="card mb-4"><div class="card-body py-3 d-flex align-items-center justify-content-between"><h4 class="mb-0">插件工具</h4><span class="badge badge-pill badge-light">共 ' . $info['count'] . ' 个</span></div></div>';
    if ($info['count'] === 0) {
        echo '<div class="alert alert-info">暂无工具，管理员可在 web/tools/ 目录添加 .html 或 .php 文件。</div>';
    } else {
        echo '<div class="row">';
        foreach ($info['tools'] as $t) {
            echo '<div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h6>' . e($t['name']) . '</h6><span class="badge badge-light">.' . e($t['ext']) . '</span><div class="mt-2"><a class="btn btn-sm btn-primary" href="index.php?view=tool&amp;file=' . urlencode($t['file']) . '">打开</a></div></div></div></div>';
        }
        echo '</div>';
    }
    page_footer();
    exit;
}

if ($view === 'tool') {
    require_login();
    $file = isset($_GET['file']) ? basename($_GET['file']) : '';
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($file === '' || ($ext !== 'html' && $ext !== 'php') || !file_exists(WEB_DIR . '/tools/' . $file)) {
        page_header('工具不存在');
        echo '<div class="alert alert-warning">工具不存在</div>';
        page_footer();
        exit;
    }
    page_header('工具：' . pathinfo($file, PATHINFO_FILENAME), 'tools');
    echo '<div class="embed-responsive" style="height:80vh;"><iframe class="embed-responsive-item" src="/tools/' . urlencode($file) . '" style="border:1px solid #ddd;background:#fff;" allowfullscreen></iframe></div>';
    page_footer();
    exit;
}

if ($view === 'surveys') {
    require_login();
    page_header('问卷', 'surveys');
    $surveys = jdb_collection('surveys', 'surveys');
    // 按时间倒序
    usort($surveys, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    echo '<div class="card mb-4"><div class="card-body py-3"><h4 class="mb-0">问卷投票</h4></div></div>';
    // 创建表单
    echo '<div class="card mb-4"><div class="card-header"><strong>创建问卷</strong></div><div class="card-body">';
    echo '<form method="post" action="index.php">' . csrf_field();
    echo '<input type="hidden" name="action" value="create_survey">';
    echo '<div class="form-group"><label>标题 *</label><input type="text" name="title" class="form-control" required></div>';
    echo '<div class="form-group"><label>说明</label><textarea name="description" class="form-control" rows="2"></textarea></div>';
    echo '<div class="form-row"><div class="col-md-4 form-group"><label>截止时间</label><input type="datetime-local" name="deadline" class="form-control"></div>';
    echo '<div class="col-md-4 form-group"><label>匿名投票</label><select name="anonymous" class="form-control"><option value="1">匿名（默认）</option><option value="0">实名</option></select></div>';
    echo '<div class="col-md-4 form-group"><label>添加"你的名字"填空</label><select name="name_field" class="form-control"><option value="0">否</option><option value="1">是</option></select></div></div>';
    echo '<div class="form-group"><label>题目（每行一道：类型|标题|选项，选项用逗号分隔；类型 radio/checkbox/text）</label>';
    echo '<textarea name="questions" class="form-control" rows="5" placeholder="radio|最喜欢的科目|数学,语文,英语&#10;checkbox|感兴趣的领域|AI,剪辑,设计&#10;text|你的建议|" required></textarea></div>';
    echo '<button class="btn btn-primary">创建问卷</button></form></div></div>';

    // 问卷列表
    echo '<div class="card"><div class="card-header"><strong>问卷列表</strong></div><div class="card-body">';
    if (count($surveys) === 0) { echo '<div class="text-muted small">暂无问卷</div>'; }
    else {
        foreach ($surveys as $sv) {
            $isMine = $sv['created_by'] === $user['id'] || $user['role'] === 'admin';
            $voted = false;
            foreach ($sv['votes'] as $v) {
                if (!$sv['anonymous'] && isset($v['user_id']) && $v['user_id'] === $user['id']) { $voted = true; break; }
            }
            $expired = $sv['deadline'] !== '' && strtotime($sv['deadline']) < time();
            $statusBadge = $sv['status'] === 'closed' ? '<span class="badge badge-secondary">已关闭</span>'
                : ($expired ? '<span class="badge badge-warning">已截止</span>' : '<span class="badge badge-success">进行中</span>');
            echo '<div class="border rounded p-3 mb-3"><div class="d-flex justify-content-between">';
            echo '<div><strong>' . e($sv['title']) . '</strong> ' . $statusBadge . ' <span class="text-muted small">' . e($sv['creator_name']) . ' ｜ ' . e($sv['created_at']) . ' ｜ ' . count($sv['votes']) . ' 人参与</span></div>';
            echo '</div>';
            if ($sv['description'] !== '') echo '<div class="small text-muted mt-1">' . e($sv['description']) . '</div>';
            echo '<div class="mt-2">';
            echo '<a class="btn btn-sm btn-outline-primary" href="index.php?view=survey&amp;id=' . urlencode($sv['id']) . '">查看/参与</a>';
            if ($isMine) {
                echo '<form method="post" action="index.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="survey_op"><input type="hidden" name="survey_id" value="' . e($sv['id']) . '"><input type="hidden" name="op" value="toggle"><button class="btn btn-sm btn-outline-warning ml-1">' . ($sv['status'] === 'open' ? '关闭' : '重开') . '</button></form>';
                echo '<form method="post" action="index.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="survey_op"><input type="hidden" name="survey_id" value="' . e($sv['id']) . '"><input type="hidden" name="op" value="copy"><button class="btn btn-sm btn-outline-info ml-1">复制</button></form>';
                echo '<form method="post" action="index.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="survey_op"><input type="hidden" name="survey_id" value="' . e($sv['id']) . '"><input type="hidden" name="op" value="delete"><button class="btn btn-sm btn-outline-danger ml-1" onclick="return confirm(\'确定删除该问卷？\')">删除</button></form>';
                echo '<form method="post" action="index.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="export_csv"><input type="hidden" name="survey_id" value="' . e($sv['id']) . '"><button class="btn btn-sm btn-outline-success ml-1">导出 CSV</button></form>';
            }
            echo '</div></div>';
        }
    }
    echo '</div></div>';
    page_footer();
    exit;
}

if ($view === 'survey') {
    require_login();
    $sid = isset($_GET['id']) ? $_GET['id'] : '';
    $surveys = jdb_collection('surveys', 'surveys');
    $sv = jdb_find_by_id($surveys, $sid);
    if (!$sv) {
        page_header('问卷不存在');
        echo '<div class="alert alert-warning">问卷不存在</div>';
        page_footer();
        exit;
    }
    page_header($sv['title'], 'surveys');
    $expired = $sv['deadline'] !== '' && strtotime($sv['deadline']) < time();
    $canVote = $sv['status'] === 'open' && !$expired;
    // 是否已投
    $already = false;
    if (!$sv['anonymous']) {
        foreach ($sv['votes'] as $v) {
            if (isset($v['user_id']) && $v['user_id'] === $user['id']) { $already = true; break; }
        }
    }
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="index.php">首页</a></li><li class="breadcrumb-item"><a href="index.php?view=surveys">问卷</a></li><li class="breadcrumb-item active">' . e($sv['title']) . '</li></ol></nav>';
    echo '<div class="card mb-4"><div class="card-body">';
    echo '<h4>' . e($sv['title']) . '</h4>';
    if ($sv['description'] !== '') echo '<div class="text-muted mb-2">' . e($sv['description']) . '</div>';
    echo '<div class="small text-muted mb-3">' . ($sv['anonymous'] ? '匿名问卷' : '实名问卷') . ' ｜ 截止：' . e($sv['deadline'] !== '' ? $sv['deadline'] : '不限') . ' ｜ 已参与 ' . count($sv['votes']) . ' 人</div>';
    if ($canVote && !$already) {
        echo '<form method="post" action="index.php">' . csrf_field();
        echo '<input type="hidden" name="action" value="vote">';
        echo '<input type="hidden" name="survey_id" value="' . e($sv['id']) . '">';
        if ($sv['name_field']) {
            echo '<div class="form-group"><label>你的名字</label><input type="text" name="respondent_name" class="form-control"></div>';
        }
        foreach ($sv['questions'] as $qi => $q) {
            echo '<div class="form-group"><label><strong>' . ($qi + 1) . '. ' . e($q['title']) . '</strong></label>';
            if ($q['type'] === 'radio') {
                foreach ($q['options'] as $op) {
                    echo '<div class="form-check"><input class="form-check-input" type="radio" name="q_' . e($q['id']) . '" value="' . e($op) . '" id="q_' . e($q['id']) . '_' . e(md5($op)) . '"><label class="form-check-label" for="q_' . e($q['id']) . '_' . e(md5($op)) . '">' . e($op) . '</label></div>';
                }
            } elseif ($q['type'] === 'checkbox') {
                foreach ($q['options'] as $op) {
                    echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="q_' . e($q['id']) . '[]" value="' . e($op) . '" id="q_' . e($q['id']) . '_' . e(md5($op)) . '"><label class="form-check-label" for="q_' . e($q['id']) . '_' . e(md5($op)) . '">' . e($op) . '</label></div>';
                }
            } else {
                echo '<textarea name="q_' . e($q['id']) . '" class="form-control" rows="2"></textarea>';
            }
            echo '</div>';
        }
        echo '<button class="btn btn-primary">提交投票</button></form>';
    } elseif ($already) {
        echo '<div class="alert alert-info">您已参与过该问卷。</div>';
    } else {
        echo '<div class="alert alert-warning">问卷已关闭或已过截止时间。</div>';
    }
    // 结果统计（创建者/管理员可见）
    $isOwner = $sv['created_by'] === $user['id'] || $user['role'] === 'admin';
    if ($isOwner && count($sv['votes']) > 0) {
        echo '<hr><h6>投票结果</h6>';
        foreach ($sv['questions'] as $q) {
            echo '<div class="mt-2"><strong>' . e($q['title']) . '</strong></div>';
            if ($q['type'] === 'text') {
                $texts = array();
                foreach ($sv['votes'] as $v) {
                    if (isset($v['answers'][$q['id']]) && $v['answers'][$q['id']] !== '') $texts[] = $v['answers'][$q['id']];
                }
                if (count($texts) === 0) { echo '<div class="text-muted small">无回答</div>'; }
                else { foreach ($texts as $t) echo '<div class="small">· ' . e($t) . '</div>'; }
            } else {
                $counts = array();
                foreach ($q['options'] as $op) $counts[$op] = 0;
                $total = 0;
                foreach ($sv['votes'] as $v) {
                    $val = isset($v['answers'][$q['id']]) ? $v['answers'][$q['id']] : '';
                    $vals = is_array($val) ? $val : array($val);
                    foreach ($vals as $vv) {
                        if (isset($counts[$vv])) { $counts[$vv]++; $total++; }
                    }
                }
                foreach ($q['options'] as $op) {
                    $pct = $total > 0 ? round($counts[$op] / $total * 100) : 0;
                    echo '<div class="d-flex align-items-center mb-1"><span class="small" style="width:140px;">' . e($op) . '</span><div class="progress flex-grow-1 mr-2"><div class="progress-bar" style="width:' . $pct . '%;background-color:var(--main);">' . $pct . '%</div></div><span class="small text-muted">' . $counts[$op] . ' 票</span></div>';
                }
            }
        }
        echo '<form method="post" action="index.php" class="mt-3">' . csrf_field() . '<input type="hidden" name="action" value="export_csv"><input type="hidden" name="survey_id" value="' . e($sv['id']) . '"><button class="btn btn-sm btn-success">导出 CSV</button></form>';
    }
    echo '</div></div>';
    page_footer();
    exit;
}

if ($view === 'search') {
    require_login();
    page_header('站内搜索', 'search');
    $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
    $stype = isset($_GET['stype']) ? $_GET['stype'] : 'all';
    if (!in_array($stype, array('all', 'notice', 'project', 'tool', 'file', 'paste'), true)) $stype = 'all';
    echo '<div class="card mb-4"><div class="card-body py-3">';
    echo '<form method="get" action="index.php" class="form-inline"><input type="hidden" name="view" value="search">';
    echo '<input type="text" name="q" value="' . e($q) . '" class="form-control mr-2" style="flex:1;min-width:200px;" placeholder="搜索通知、项目、工具、网盘、代码分享…" required>';
    echo '<select name="stype" class="form-control mr-2" style="max-width:140px;">';
    $stOptions = array('all' => '全部类型', 'notice' => '通知公告', 'project' => '项目', 'tool' => '插件工具', 'file' => '网盘文件', 'paste' => '代码分享');
    foreach ($stOptions as $k => $label) {
        echo '<option value="' . $k . '"' . ($stype === $k ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select>';
    echo '<button class="btn btn-primary">搜索</button></form>';
    echo '</div></div>';
    if ($q !== '') {
        $hits = array('notice' => array(), 'project' => array(), 'tool' => array(), 'file' => array(), 'paste' => array());
        if ($stype === 'all' || $stype === 'notice') {
        foreach (jdb_collection('notices', 'notices') as $n) {
            if (stripos($n['title'] . (isset($n['content_md']) ? $n['content_md'] : ''), $q) !== false) {
                $hits['notice'][] = $n;
            }
        }
        }
        if ($stype === 'all' || $stype === 'project') {
        foreach (jdb_collection('projects', 'branches') as $br) {
            if (empty($br['projects'])) continue;
            foreach ($br['projects'] as $p) {
                if (stripos($p['name'] . (isset($p['intro_md']) ? $p['intro_md'] : '') . $br['name'], $q) !== false) {
                    $p['_branch'] = $br['name'];
                    $hits['project'][] = $p;
                }
            }
        }
        }
        if ($stype === 'all' || $stype === 'tool') {
        $toolsInfo = get_tools_list();
        foreach ($toolsInfo['tools'] as $t) {
            if (stripos($t['name'], $q) !== false) $hits['tool'][] = $t;
        }
        }
        if ($stype === 'all' || $stype === 'file') {
        foreach (jdb_collection('drive', 'files') as $f) {
            if (stripos(isset($f['orig']) ? $f['orig'] : '', $q) !== false) $hits['file'][] = $f;
        }
        }
        if ($stype === 'all' || $stype === 'paste') {
        foreach (jdb_collection('pastes', 'pastes') as $p) {
            if (stripos($p['title'] . "\n" . (isset($p['code']) ? $p['code'] : ''), $q) !== false) $hits['paste'][] = $p;
        }
        }
        $total = 0;
        foreach ($hits as $h) $total += count($h);
        if ($total === 0) {
            echo '<div class="alert alert-info">没有找到与「' . e($q) . '」相关的内容</div>';
        } else {
            echo '<div class="alert alert-success py-2">共找到 ' . $total . ' 条结果</div>';
            if (count($hits['notice']) > 0) {
                echo '<div class="card mb-3"><div class="card-header"><strong>通知 / 公告（' . count($hits['notice']) . '）</strong></div><div class="card-body p-0"><div class="list-group list-group-flush">';
                foreach ($hits['notice'] as $n) {
                    echo '<a class="list-group-item list-group-item-action" href="/index.php?view=notice&amp;id=' . urlencode($n['id']) . '"><strong>' . e($n['title']) . '</strong><div class="small text-muted">' . e($n['created_at']) . '</div></a>';
                }
                echo '</div></div></div>';
            }
            if (count($hits['project']) > 0) {
                echo '<div class="card mb-3"><div class="card-header"><strong>项目（' . count($hits['project']) . '）</strong></div><div class="card-body p-0"><div class="list-group list-group-flush">';
                foreach ($hits['project'] as $p) {
                    echo '<a class="list-group-item list-group-item-action" href="/index.php?view=project&amp;id=' . urlencode($p['id']) . '"><strong>' . e($p['name']) . '</strong> <span class="badge badge-light">' . e($p['_branch']) . '</span></a>';
                }
                echo '</div></div></div>';
            }
            if (count($hits['tool']) > 0) {
                echo '<div class="card mb-3"><div class="card-header"><strong>插件工具（' . count($hits['tool']) . '）</strong></div><div class="card-body p-0"><div class="list-group list-group-flush">';
                foreach ($hits['tool'] as $t) {
                    echo '<a class="list-group-item list-group-item-action" href="/index.php?view=tool&amp;file=' . urlencode($t['file']) . '"><strong>' . e($t['name']) . '</strong><span class="badge badge-light ml-2">.' . e($t['ext']) . '</span></a>';
                }
                echo '</div></div></div>';
            }
            if (count($hits['file']) > 0) {
                echo '<div class="card mb-3"><div class="card-header"><strong>网盘文件（' . count($hits['file']) . '）</strong></div><div class="card-body p-0"><div class="list-group list-group-flush">';
                foreach ($hits['file'] as $f) {
                    $folder = isset($f['folder']) ? $f['folder'] : '';
                    $dl = '/api/drive.php?action=download&amp;folder=' . urlencode($folder) . '&amp;file=' . urlencode($f['stored']);
                    echo '<a class="list-group-item list-group-item-action" href="' . $dl . '"><strong>' . e($f['orig']) . '</strong> <span class="small text-muted">' . e($folder === '' ? '根目录' : '/' . $folder) . ' · ' . fmt_size($f['size']) . '</span></a>';
                }
                echo '</div></div></div>';
            }
            if (count($hits['paste']) > 0) {
                echo '<div class="card mb-3"><div class="card-header"><strong>代码分享（' . count($hits['paste']) . '）</strong></div><div class="card-body p-0"><div class="list-group list-group-flush">';
                foreach ($hits['paste'] as $p) {
                    echo '<a class="list-group-item list-group-item-action" href="/paste.php?id=' . urlencode($p['id']) . '"><strong>' . e($p['title']) . '</strong> <span class="badge badge-light">' . e($p['lang']) . '</span><div class="small text-muted">' . e($p['author']) . ' · ' . e(fmt_time($p['created_at'])) . '</div></a>';
                }
                echo '</div></div></div>';
            }
        }
    } else {
        echo '<div class="text-muted small">输入关键字搜索站内内容：通知公告、项目资料、插件工具、网盘文件、代码分享。可按类型过滤。</div>';
    }
    page_footer();
    exit;
}

if ($view === 'profile') {
    require_login();
    page_header('个人中心', 'profile');
    if ($flash !== '') flash_toast($flash);

    // 数据统计：未读私信 / 收到 / 发出 / 参与问卷
    $allMsg = jdb_collection('messages', 'messages');
    $inCount = 0; $outCount = 0;
    foreach ($allMsg as $m) {
        if ($m['to_id'] === $user['id']) $inCount++;
        if ($m['from_id'] === $user['id']) $outCount++;
    }
    $surveys = jdb_collection('surveys', 'surveys');
    $voteCount = 0;
    foreach ($surveys as $sv) {
        if (empty($sv['anonymous'])) {
            foreach ($sv['votes'] as $v) {
                if (isset($v['user_id']) && $v['user_id'] === $user['id']) $voteCount++;
            }
        }
    }

    $roleName = array('admin' => '管理员', 'officer' => '干事', 'member' => '成员');
    $roleLabel = isset($roleName[$user['role']]) ? $roleName[$user['role']] : '成员';

    echo '<div class="row"><div class="col-lg-8 mx-auto">';

    // ---- 用户卡（洛谷式：大头像 + 用户名 + 签名 + 徽章） ----
    echo '<div class="card mb-4"><div class="card-body">';
    echo '<div class="d-flex align-items-center">';
    echo '<span class="profile-avatar">' . e(mb_substr($user['name'], 0, 1)) . '</span>';
    echo '<div class="ml-3">';
    echo '<div class="d-flex align-items-center"><span class="profile-name">' . e($user['name']) . '</span>';
    echo '<span class="profile-role ' . ($user['role'] === 'admin' ? 'role-admin' : ($user['role'] === 'officer' ? 'role-officer' : 'role-member')) . ' ml-2">' . e($roleLabel) . '</span></div>';
    echo '<div class="profile-uname">@' . e($user['username']) . '</div>';
    $sig = isset($user['signature']) && $user['signature'] !== '' ? $user['signature'] : '这个人很懒，什么都没写。';
    echo '<div class="profile-sig">' . e($sig) . '</div>';
    echo '</div></div></div></div>';

    // ---- 数据统计（洛谷式数字卡片） ----
    echo '<div class="row mb-4">';
    $stats = array(
        array('未读私信', unread_count($user['id'])),
        array('收到的私信', $inCount),
        array('发出的私信', $outCount),
        array('参与的问卷', $voteCount)
    );
    foreach ($stats as $st) {
        echo '<div class="col-6 col-md-3 mb-2"><div class="card text-center"><div class="card-body py-3"><div class="profile-stat-num">' . $st[1] . '</div><div class="profile-stat-label">' . e($st[0]) . '</div></div></div></div>';
    }
    echo '</div>';

    // ---- 头像 ----
    echo '<div class="card mb-4"><div class="card-header"><strong>头像</strong></div><div class="card-body">';
    echo '<form method="post" action="index.php" enctype="multipart/form-data" class="form-inline">' . csrf_field();
    echo '<input type="hidden" name="action" value="save_avatar">';
    echo '<input type="file" name="avatar" class="form-control-file form-control-sm mr-2" accept="image/*" required>';
    echo '<button class="btn btn-sm btn-primary">上传头像</button> <span class="text-muted small ml-2">jpg/png/gif/webp，≤2MB</span>';
    echo '</form></div></div>';

    // ---- 个性签名 ----
    echo '<div class="card mb-4"><div class="card-header"><strong>个性签名</strong></div><div class="card-body">';
    echo '<form method="post" action="index.php">' . csrf_field();
    echo '<input type="hidden" name="action" value="save_signature">';
    echo '<div class="form-group"><input type="text" name="signature" class="form-control" maxlength="60" value="' . e(isset($user['signature']) ? $user['signature'] : '') . '" placeholder="写点什么介绍自己（最多 60 字）"></div>';
    echo '<button class="btn btn-primary btn-sm">保存签名</button></form>';
    echo '</div></div>';

    // ---- 修改密码 ----
    echo '<div class="card"><div class="card-header"><strong>修改密码</strong></div><div class="card-body">';
    echo '<form method="post" action="index.php">' . csrf_field();
    echo '<input type="hidden" name="action" value="change_password">';
    echo '<div class="form-group"><label>旧密码</label><input type="password" name="old_password" class="form-control" required></div>';
    echo '<div class="form-group"><label>新密码（至少 6 位）</label><input type="password" name="new_password" class="form-control" required></div>';
    echo '<button class="btn btn-primary">修改密码</button></form>';
    echo '</div></div>';

    // ---- 个人主题（Smart-Luogu 式固定背景 + 主色） ----
    $myTheme = (isset($user['theme']) && is_array($user['theme'])) ? $user['theme'] : array();
    $myMain = isset($myTheme['main']) ? $myTheme['main'] : (isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8');
    $myBg = isset($myTheme['bg']) ? $myTheme['bg'] : (isset($s['default_bg']) ? $s['default_bg'] : '');
    echo '<div class="card"><div class="card-header"><strong>个人主题</strong></div><div class="card-body">';
    echo '<p class="text-muted small mb-3">自定义你的主色与背景图；恢复默认即使用站点主题（由管理员在“网站设置”中设定）。</p>';
    echo '<form method="post" action="index.php" enctype="multipart/form-data">' . csrf_field();
    echo '<input type="hidden" name="action" value="save_theme">';
    echo '<div class="form-group"><label>主色调</label><div class="input-group"><input type="color" name="main" class="form-control" style="max-width:70px;height:38px;padding:2px;" value="' . e($myMain) . '"><input type="text" class="form-control" value="' . e($myMain) . '" disabled></div><small class="form-text text-muted">选择后保存立即生效，覆盖站点默认主色</small></div>';
    echo '<div class="form-group"><label>背景图（固定铺满页面，仅 jpg/png/gif/webp，≤5MB）</label>';
    if ($myBg !== '') echo '<div class="mb-2"><img src="' . e($myBg) . '" style="max-height:120px;border-radius:12px;border:1px solid var(--line);" alt="当前背景"></div>';
    echo '<input type="file" name="bg" class="form-control-file" accept="image/*"></div>';
    echo '<button class="btn btn-primary">保存主题</button></form>';
    echo '<form method="post" action="index.php" class="mt-2">' . csrf_field() . '<input type="hidden" name="action" value="reset_theme"><button class="btn btn-outline-secondary btn-sm">恢复默认主题</button></form>';
    echo '</div></div>';

    echo '</div></div>';
    page_footer();
    exit;
}

// ---------------- 默认首页分流 ----------------
page_header(is_logged_in() ? '首页' : $orgName . '官网', 'home');

if ($flash !== '') {
    flash_toast($flash);
}

// ---- 活动倒计时组件（指向最近一场未到期活动/截止日，管理员在网站设置中手动添加） ----
$cdEvents = (isset($s['countdown_events']) && is_array($s['countdown_events'])) ? $s['countdown_events'] : array();
$nextEvent = null;
$nowTs = time();
foreach ($cdEvents as $ev) {
    $t = isset($ev['target']) ? strtotime($ev['target']) : 0;
    if ($t && $t > $nowTs) {
        if ($nextEvent === null || $t < $nextEvent['ts']) {
            $nextEvent = array('name' => isset($ev['name']) ? $ev['name'] : '活动', 'ts' => $t);
        }
    }
}
if ($nextEvent) {
    echo '<div class="card mb-4"><div class="card-body d-flex align-items-center justify-content-between flex-wrap">';
    echo '<div><div class="small text-muted">距离「' . e($nextEvent['name']) . '」还有</div>';
    echo '<div class="countdown-time" id="countdownDisplay" style="font-size:30px;font-weight:700;color:var(--main);font-variant-numeric:tabular-nums;line-height:1.2;">--</div></div>';
    echo '<div class="small text-muted" id="countdownDate"></div></div></div>';
    echo '<script>var CD=' . json_encode(array('name' => $nextEvent['name'], 'ts' => $nextEvent['ts'])) . ';' .
        '(function(){var d=document.getElementById("countdownDisplay"),dd=document.getElementById("countdownDate");' .
        'function pad(n){return n<10?"0"+n:""+n;}function fmt(ms){if(ms<=0){d.textContent="已开始/已到期";return;}var s=Math.floor(ms/1000);var day=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60),sec=s%60;d.textContent=(day>0?day+"天 ":"")+pad(h)+":"+pad(m)+":"+pad(sec);}' .
        'var d0=new Date(CD.ts*1000);dd.textContent="活动时间："+d0.getFullYear()+"-"+pad(d0.getMonth()+1)+"-"+pad(d0.getDate())+" "+pad(d0.getHours())+":"+pad(d0.getMinutes());' .
        'function tick(){fmt(CD.ts*1000-Date.now());}tick();setInterval(tick,1000);})();</script>';
}

if (is_logged_in()) {
    // 成员首页：内部通知 + 快捷入口
    $notices = jdb_collection('notices', 'notices');
    $internal = array();
    foreach ($notices as $n) {
        if ($n['type'] === 'internal_activity' || $n['type'] === 'internal_class' || $n['type'] === 'internal') {
            $internal[] = $n;
        }
    }
    // 置顶 + 时间排序
    usort($internal, function ($a, $b) {
        $pa = !empty($a['pinned']) ? 1 : 0;
        $pb = !empty($b['pinned']) ? 1 : 0;
        if ($pa !== $pb) return $pb - $pa;
        return strcmp($b['created_at'], $a['created_at']);
    });
    $internal = array_slice($internal, 0, 5);
    echo '<div class="row">';
    echo '<div class="col-lg-8">';
    echo '<div class="card mb-4"><div class="card-header d-flex justify-content-between"><strong>内部通知</strong><a class="small" href="index.php?view=notices&amp;type=internal">查看全部</a></div><div class="card-body p-0">';
    if (count($internal) === 0) { echo '<div class="p-3 text-muted small">暂无内部通知</div>'; }
    else {
        echo '<ul class="list-group list-group-flush">';
        foreach ($internal as $n) {
            $isNew = !in_array($user['id'], $n['read_by']);
            echo '<li class="list-group-item d-flex justify-content-between align-items-center">';
            echo '<span>' . (!empty($n['pinned']) ? '<span class="badge badge-danger mr-1">置顶</span>' : '') . ($isNew ? '<span class="badge badge-primary mr-1">新</span>' : '');
            $cat = $n['type'] === 'internal_class' ? '上课' : '活动';
            echo '<span class="badge badge-light mr-1">' . $cat . '</span>';
            echo '<a href="index.php?view=notice&amp;id=' . urlencode($n['id']) . '">' . e($n['title']) . '</a></span>';
            echo '<span class="text-muted small">' . e(substr($n['created_at'], 0, 16)) . '</span></li>';
        }
        echo '</ul>';
    }
    echo '</div></div></div>';
    // 快捷入口
    echo '<div class="col-lg-4">';
    echo '<div class="card"><div class="card-header"><strong>快捷入口</strong></div><div class="card-body">';
    $quick = array(
        array('项目与资料', 'index.php?view=projects'),
        array('OJ 训练', 'oj.php'),
        array('插件工具', 'index.php?view=tools'),
        array('膜拜', 'worship.php'),
        array('问卷投票', 'index.php?view=surveys'),
        array('站内私信', 'messages.php'),
        array('个人中心', 'index.php?view=profile')
    );
    foreach ($quick as $i => $q) {
        echo '<a class="quick-link" href="' . $q[1] . '"><span class="quick-num">' . ($i + 1) . '</span><span class="quick-txt">' . $q[0] . '</span><span class="quick-arrow">→</span></a>';
    }
    echo '</div></div></div>';
    echo '</div>';
} else {
    // 游客首页：对外公告 + 社团介绍
    echo '<div class="row">';
    echo '<div class="col-lg-8">';
    echo '<div class="card mb-4"><div class="card-header d-flex justify-content-between"><strong>对外公告</strong><a class="small" href="index.php?view=notices&amp;type=public">查看全部</a></div><div class="card-body p-0">';
    $notices = jdb_collection('notices', 'notices');
    $pub = array();
    foreach ($notices as $n) {
        if ($n['type'] === 'public') $pub[] = $n;
    }
    usort($pub, function ($a, $b) {
        $pa = !empty($a['pinned']) ? 1 : 0;
        $pb = !empty($b['pinned']) ? 1 : 0;
        if ($pa !== $pb) return $pb - $pa;
        return strcmp($b['created_at'], $a['created_at']);
    });
    $pub = array_slice($pub, 0, 8);
    if (count($pub) === 0) { echo '<div class="p-3 text-muted small">暂无公告</div>'; }
    else {
        echo '<ul class="list-group list-group-flush">';
        foreach ($pub as $n) {
            echo '<li class="list-group-item d-flex justify-content-between align-items-center"><span>' . (!empty($n['pinned']) ? '<span class="badge badge-danger mr-1">置顶</span>' : '') . '<a href="index.php?view=notice&amp;id=' . urlencode($n['id']) . '">' . e($n['title']) . '</a></span><span class="text-muted small">' . e(substr($n['created_at'], 0, 16)) . '</span></li>';
        }
        echo '</ul>';
    }
    echo '</div></div></div>';
    echo '<div class="col-lg-4">';
    echo '<div class="card mb-4"><div class="card-header"><strong>' . e($orgName) . '介绍</strong></div><div class="card-body markdown-body">' . markdown_parse($s['home_intro']) . '</div></div>';
    echo '<a class="btn btn-primary btn-block" href="login.php" style="background-color:var(--main);border-color:var(--main);">登录成员系统</a>';
    echo '</div></div>';
}

page_footer();
