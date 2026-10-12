<?php
/**
 * 404 页面（router 未命中时由 router.php 调用）
 */
require_once __DIR__ . '/includes/config.php';
http_response_code(404);
$s = $GLOBALS['SETTINGS'];
$color = isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8';
$site  = isset($s['site_name']) ? $s['site_name'] : '社团官网';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>404 - <?php echo e($site); ?></title>
<link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/style.css?v=11">
<style>
    :root { --main: <?php echo e($color); ?>; --main-rgb: <?php echo implode(',', hex_rgb($color)); ?>; --ink: #2b3340; }
</style>
</head>
<body>
<div class="container d-flex align-items-center justify-content-center" style="min-height:100vh;">
  <div class="card text-center" style="max-width:420px;width:100%;">
    <div class="card-body" style="padding:3rem 2rem;">
      <div style="font-size:72px;font-weight:700;color:var(--main);line-height:1;">404</div>
      <h5 class="mt-3 mb-2">页面不存在</h5>
      <p class="text-muted mb-4">您访问的页面可能已被删除、改名或暂时不可用。</p>
      <a href="/index.php" class="btn btn-primary px-4">返回首页</a>
    </div>
  </div>
</div>
</body>
</html>
