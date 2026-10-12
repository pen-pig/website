<?php
/**
 * 数据备份下载（仅管理员）
 * 四种备份均直接打包为 ZIP 下载到本地：
 * 全部（源码+数据+网盘）/ 源码+数据 / 仅数据 / 仅网盘。
 * 临时 ZIP 写入站点自身 data/ 目录，下载完成后立即删除，服务器不留存备份文件。
 * 依赖 ZipArchive 扩展（PHP 5.6 内置 zip，共享主机一般默认启用）。
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin'));

function backup_size_str($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

function backup_dir_size($dir) {
    $total = 0;
    if (!is_dir($dir)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) $total += $f->getSize();
    }
    return $total;
}

/**
 * 递归将目录内容加入 ZIP（兼容 PHP 5.6），ZIP 内路径统一为「前缀/相对路径」
 * @param ZipArchive $zip
 * @param string     $dir      源目录
 * @param string     $prefix   ZIP 内顶层前缀，如 web / data / drive
 * @param array      $skipTop  需要跳过的顶层条目名（仅第一层生效）
 */
function backup_add_dir($zip, $dir, $prefix, $skipTop = array()) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, $skipTop)) continue;
        $sp = $dir . '/' . $item;
        $zp = $prefix . '/' . $item;
        if (is_dir($sp)) {
            backup_add_dir($zip, $sp, $zp);
        } elseif (is_file($sp)) {
            $zip->addFile($sp, $zp);
        }
    }
}

// 执行打包下载（4 种范围，均直接下载 ZIP，不在服务器留存）
$backupTypes = array(
    'backup_all'      => array('label' => '备份全部（源码+数据+网盘）', 'code' => true,  'data' => true,  'drive' => true,  'suffix' => 'full'),
    'backup_codedata' => array('label' => '备份源码+数据（不含网盘）', 'code' => true,  'data' => true,  'drive' => false, 'suffix' => 'codedata'),
    'backup_data'     => array('label' => '备份数据',                  'code' => false, 'data' => true,  'drive' => false, 'suffix' => 'data'),
    'backup_drive'    => array('label' => '备份网盘',                  'code' => false, 'data' => false, 'drive' => true,  'suffix' => 'drive'),
);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($backupTypes[$_POST['action']])) {
    check_csrf();
    if (!class_exists('ZipArchive')) {
        $_SESSION['flash'] = '服务器未启用 ZipArchive 扩展，无法打包下载';
        redirect('backup.php');
    }
    set_time_limit(0);
    $type = $_POST['action'];
    $cfg = $backupTypes[$type];
    $zipName = 'gg_backup_' . date('Ymd_His') . '_' . $cfg['suffix'] . '.zip';
    $tmpZip = DATA_DIR . '/' . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $_SESSION['flash'] = '无法创建临时备份文件（请检查 data/ 目录写权限）';
        redirect('backup.php');
    }
    if ($cfg['code']) {
        $zip->addEmptyDir('web');
        backup_add_dir($zip, WEB_DIR, 'web', array('data', 'drive'));
    }
    if ($cfg['data']) {
        $zip->addEmptyDir('data');
        backup_add_dir($zip, DATA_DIR, 'data');
    }
    if ($cfg['drive']) {
        $zip->addEmptyDir('drive');
        backup_add_dir($zip, DRIVE_DIR, 'drive');
    }
    $zip->close();

    if (!is_file($tmpZip)) {
        $_SESSION['flash'] = '备份文件生成失败';
        redirect('backup.php');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$dataSize = backup_dir_size(DATA_DIR);
$driveSize = backup_dir_size(DRIVE_DIR);
$codeSize = backup_dir_size(WEB_DIR) - $dataSize - $driveSize;

page_header('数据备份', 'admin');
if ($flash !== '') flash_toast($flash);
?>
<div class="card">
  <div class="card-header"><strong>数据备份下载</strong></div>
  <div class="card-body">
    <p class="small text-muted">四种备份均直接打包为 ZIP 下载到本地，临时 ZIP 在服务器生成后立即删除，不在服务器留存备份文件。备份内容：</p>
    <table class="table table-sm table-bordered">
      <tr><th style="width:170px;">备份类型</th><th>范围说明</th><th>大小</th><th>下载文件名</th><th style="width:110px;">操作</th></tr>
      <tr>
        <td>备份全部</td>
        <td class="small">源码 web/（站点全部代码，不含 data/drive）+ 数据 data/ + 网盘 drive/</td>
        <td><?php echo backup_size_str($codeSize + $dataSize + $driveSize); ?></td>
        <td><code>gg_backup_时间戳_full.zip</code></td>
        <td>
          <form method="post" action="backup.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="backup_all">
            <button class="btn btn-primary btn-sm" onclick="return confirm('确定下载全部备份（源码+数据+网盘）？网盘体积较大，可能需要较长时间。')">下载</button>
          </form>
        </td>
      </tr>
      <tr>
        <td>备份源码+数据</td>
        <td class="small">源码 web/（不含 data/drive）+ 数据 data/，不含网盘 drive/</td>
        <td><?php echo backup_size_str($codeSize + $dataSize); ?></td>
        <td><code>gg_backup_时间戳_codedata.zip</code></td>
        <td>
          <form method="post" action="backup.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="backup_codedata">
            <button class="btn btn-outline-secondary btn-sm" onclick="return confirm('确定下载源码+数据备份（不含网盘）？')">下载</button>
          </form>
        </td>
      </tr>
      <tr>
        <td>备份数据</td>
        <td class="small">仅数据目录 data/（json/ 配置与记录、uploads/ 上传文件、img/ 图片等）</td>
        <td><?php echo backup_size_str($dataSize); ?></td>
        <td><code>gg_backup_时间戳_data.zip</code></td>
        <td>
          <form method="post" action="backup.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="backup_data">
            <button class="btn btn-outline-secondary btn-sm" onclick="return confirm('确定下载数据备份？')">下载</button>
          </form>
        </td>
      </tr>
      <tr>
        <td>备份网盘</td>
        <td class="small">仅网盘物理目录 drive/（可能较大）</td>
        <td><?php echo backup_size_str($driveSize); ?></td>
        <td><code>gg_backup_时间戳_drive.zip</code></td>
        <td>
          <form method="post" action="backup.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="backup_drive">
            <button class="btn btn-outline-secondary btn-sm" onclick="return confirm('确定下载网盘备份？文件较多时可能需要较长时间。')">下载</button>
          </form>
        </td>
      </tr>
    </table>
    <p class="small text-muted mb-0">ZIP 内目录结构：<code>web/</code> 对应站点根目录（不含 data/drive），<code>data/</code> 对应 data 目录，<code>drive/</code> 对应网盘目录，可直接覆盖回对应位置。</p>
  </div>
</div>
<?php page_footer(); ?>
