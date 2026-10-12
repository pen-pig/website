<?php
/**
 * 后台首页：仪表盘统计（管理员 / 干事可见）
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin', 'officer'));

$usersCount = count(jdb_collection('users', 'users'));
$notices = jdb_collection('notices', 'notices');
$branches = jdb_collection('projects', 'branches');
$surveys = jdb_collection('surveys', 'surveys');
$messages = jdb_collection('messages', 'messages');
$toolsInfo = get_tools_list();

$projectCount = 0;
$materialCount = 0;
$homeworkCount = 0;
foreach ($branches as $br) {
    if (empty($br['projects'])) continue;
    foreach ($br['projects'] as $p) {
        $projectCount++;
        $materialCount += count(isset($p['materials']) ? $p['materials'] : array());
        $homeworkCount += count(isset($p['homework']) ? $p['homework'] : array());
    }
}

page_header('后台管理', 'admin');
?>
<div class="row">
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo $usersCount; ?></h2><div class="text-muted">成员</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo count($notices); ?></h2><div class="text-muted">通知/公告</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo $projectCount; ?></h2><div class="text-muted">小项目（<?php echo count($branches); ?> 分支）</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo count($surveys); ?></h2><div class="text-muted">问卷</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo $materialCount; ?></h2><div class="text-muted">资料文件</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo $homeworkCount; ?></h2><div class="text-muted">作业文件</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo count($messages); ?></h2><div class="text-muted">私信</div></div></div></div>
  <div class="col-md-3 mb-3"><div class="card text-center"><div class="card-body"><h2 class="text-primary"><?php echo $toolsInfo['count']; ?></h2><div class="text-muted">插件工具</div></div></div></div>
</div>

<div class="card"><div class="card-header"><strong>后台功能</strong></div><div class="card-body">
<div class="row">
  <div class="col-md-3"><a class="btn btn-outline-primary btn-block mb-2" href="notices.php">通知管理</a></div>
  <div class="col-md-3"><a class="btn btn-outline-primary btn-block mb-2" href="projects.php">项目管理</a></div>
  <div class="col-md-3"><a class="btn btn-outline-primary btn-block mb-2" href="surveys.php">问卷管理</a></div>
  <div class="col-md-3"><a class="btn btn-outline-primary btn-block mb-2" href="tools.php">工具管理</a></div>
  <?php if ($user['role'] === 'admin') { ?>
  <div class="col-md-3"><a class="btn btn-outline-success btn-block mb-2" href="members.php">成员管理</a></div>
  <div class="col-md-3"><a class="btn btn-outline-success btn-block mb-2" href="settings.php">网站设置</a></div>
  <div class="col-md-3"><a class="btn btn-outline-warning btn-block mb-2" href="messages.php">聊天记录管理</a></div>
  <div class="col-md-3"><a class="btn btn-outline-warning btn-block mb-2" href="/filemanager.php">文件管理器</a></div>
  <div class="col-md-3"><a class="btn btn-outline-warning btn-block mb-2" href="backup.php">备份数据</a></div>
  <?php } ?>
</div>
</div></div>
<?php page_footer(); ?>
