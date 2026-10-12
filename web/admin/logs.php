<?php
/**
 * 操作日志查看（管理员 / 干事）
 * 倒序分页展示后台写操作记录（新增/删除/切换状态/上传/清理等）
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin', 'officer'));

$logs = jdb_collection('logs', 'logs');
// 倒序：最新在前
$logs = array_reverse($logs);

$perPage = 50;
$total = count($logs);
$pages = max(1, (int) ceil($total / $perPage));
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;
$slice = array_slice($logs, $offset, $perPage);

page_header('操作日志', 'admin');
?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>操作日志</strong>
    <span class="text-muted small">共 <?php echo $total; ?> 条（仅保留最近 500 条）</span>
  </div>
  <div class="card-body p-0">
    <?php if ($total === 0) { ?>
      <div class="p-3 text-muted small">暂无日志记录。后台的增删改、开放/封闭切换、文件删除、资料上传等操作会自动记录在此。</div>
    <?php } else { ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead>
            <tr>
              <th class="pl-3">时间</th>
              <th>操作人</th>
              <th>角色</th>
              <th>动作</th>
              <th class="pr-3">详情</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($slice as $lg) { ?>
              <tr>
                <td class="pl-3 text-nowrap small"><?php echo e(isset($lg['time']) ? $lg['time'] : ''); ?></td>
                <td class="small"><?php echo e(isset($lg['actor_name']) ? $lg['actor_name'] : ''); ?></td>
                <td class="small text-muted"><?php echo e(isset($lg['role']) ? $lg['role'] : ''); ?></td>
                <td class="small"><span class="badge badge-info"><?php echo e(isset($lg['action']) ? $lg['action'] : ''); ?></span></td>
                <td class="pr-3 small"><?php echo e(isset($lg['detail']) ? $lg['detail'] : ''); ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<?php if ($pages > 1) { ?>
<nav class="mt-3"><ul class="pagination pagination-sm">
  <?php for ($i = 1; $i <= $pages; $i++) { ?>
    <li class="page-item<?php echo $i === $page ? ' active' : ''; ?>">
      <a class="page-link" href="logs.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
    </li>
  <?php } ?>
</ul></nav>
<?php } ?>

<?php page_footer(); ?>
