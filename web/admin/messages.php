<?php
/**
 * 聊天记录管理（仅管理员）
 * 展示私信统计与存储占用，支持一键清空所有人的聊天记录以释放空间。
 * 清空后保留空结构（{"messages":[]}）、统计归零，成员仍可正常收发新私信。
 * 依赖 data/json/messages.json（jdb_* 封装，flock 锁 + 临时文件重命名）。
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin'));

function msg_size_str($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function msg_file_size() {
    $f = JSON_DIR . '/messages.json';
    return is_file($f) ? filesize($f) : 0;
}

// 执行一键清空
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_chat') {
    check_csrf();
    $messages = jdb_collection('messages', 'messages');
    $beforeCount = count($messages);
    $beforeSize = msg_file_size();
    // 联动清理：先遍历现有消息，收集所有 type=image 的图片文件并物理删除（防路径穿越校验后），再清空 JSON
    $imgDir = DATA_DIR . '/img/msg';
    $imgReal = realpath($imgDir);
    $imgCount = 0;
    $imgBytes = 0;
    if ($imgReal !== false) {
        foreach ($messages as $m) {
            if (!isset($m['type']) || $m['type'] !== 'image' || empty($m['content'])) continue;
            parse_str(parse_url($m['content'], PHP_URL_QUERY), $qs);
            $name = isset($qs['file']) ? basename($qs['file']) : '';
            if ($name === '' || $name === '.' || $name === '..') continue;
            $path = $imgReal . DIRECTORY_SEPARATOR . $name;
            $realF = realpath($path);
            if ($realF === false) continue;
            // 防路径穿越：最终真实路径必须落在 data/img/msg 目录内
            if ($realF !== $imgReal && strpos($realF, $imgReal . DIRECTORY_SEPARATOR) !== 0) continue;
            if (!is_file($realF)) continue;
            $imgBytes += filesize($realF);
            if (@unlink($realF)) {
                $imgCount++;
            }
        }
    }
    $ok = jdb_save_collection('messages', 'messages', array());
    if (!$ok) {
        $_SESSION['flash'] = '清空失败：无法写入聊天记录文件，请检查 data/json 目录写权限';
        redirect('messages.php');
    }
    $afterSize = msg_file_size();
    $released = ($beforeSize > $afterSize ? $beforeSize - $afterSize : 0) + $imgBytes;
    add_log('清空聊天记录', '清空全部私信 ' . $beforeCount . ' 条（含 ' . $imgCount . ' 张图片，释放约 ' . msg_size_str($released) . '）');
    $_SESSION['flash'] = '已清空全部聊天记录 ' . $beforeCount . ' 条（含 ' . $imgCount . ' 张图片，释放约 ' . msg_size_str($released) . '），当前聊天记录文件剩余占用 ' . msg_size_str($afterSize);
    redirect('messages.php');
}

$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$messages = jdb_collection('messages', 'messages');
$total = count($messages);
$sessions = array();
foreach ($messages as $m) {
    $a = isset($m['from_id']) ? (string)$m['from_id'] : '';
    $b = isset($m['to_id']) ? (string)$m['to_id'] : '';
    $key = $a <= $b ? $a . '|' . $b : $b . '|' . $a;
    $sessions[$key] = 1;
}
$sessionCount = count($sessions);
$fileSize = msg_file_size();
$lastTime = '';
if ($total > 0) {
    $last = end($messages);
    $lastTime = isset($last['created_at']) ? $last['created_at'] : '';
}

page_header('聊天记录管理', 'admin');
if ($flash !== '') flash_toast($flash);
?>
<div class="row">
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo $total; ?></h2><div class="text-muted">私信总数</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo $sessionCount; ?></h2><div class="text-muted">会话数</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo msg_size_str($fileSize); ?></h2><div class="text-muted">聊天记录占用</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary" style="font-size:17px;"><?php echo $lastTime !== '' ? e($lastTime) : '-'; ?></h2><div class="text-muted">最后一条私信时间</div></div></div></div>
</div>

<div class="card border-danger"><div class="card-header text-danger"><strong>一键清空聊天记录</strong></div><div class="card-body">
  <p class="small text-muted">清空所有成员之间的私信聊天记录，用于释放服务器空间（聊天记录会随使用量持续增长）。<br>
  操作后记录文件保留空结构、私信统计归零，成员仍可正常收发新私信；<strong>本操作不可恢复</strong>，建议先到「数据备份」页打包留档。</p>
  <form method="post" action="messages.php" class="mt-2">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="clear_chat">
    <button class="btn btn-danger" onclick="return confirm('确定清空所有人的聊天记录？清空后不可恢复，请确认已完成备份。')">一键清空聊天记录</button>
  </form>
</div></div>
<?php page_footer(); ?>
