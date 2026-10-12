<?php
/**
 * 登录页（算术题验证码 + password_hash 校验）
 * 首次运行：users.json 无任何账号时自动创建默认管理员 admin/admin123
 */
require_once __DIR__ . '/includes/config.php';

// 已登录用户直接进首页
if (is_logged_in()) {
    redirect('index.php');
}

// ---- 首次安装初始化：创建默认管理员 ----
$users = jdb_collection('users', 'users');
if (count($users) === 0) {
    $users[] = array(
        'id'         => 'u_admin',
        'username'   => 'admin',
        'password'   => password_hash('admin123', PASSWORD_BCRYPT),
        'name'       => '管理员',
        'role'       => 'admin',
        'status'     => 'active',
        'created_at' => date('Y-m-d H:i:s')
    );
    jdb_save_collection('users', 'users', $users);
}

// ---- 处理登录提交 ----
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 登录失败限次：连续 5 次失败锁定 10 分钟
    if (isset($_SESSION['login_locked_until']) && time() < (int)$_SESSION['login_locked_until']) {
        $remain = (int)ceil(((int)$_SESSION['login_locked_until'] - time()) / 60);
        $error = '登录失败次数过多，请约 ' . $remain . ' 分钟后再试';
    } else {
    check_csrf();
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $captcha  = isset($_POST['captcha']) ? trim($_POST['captcha']) : '';

    if (!check_captcha($captcha)) {
        $error = '验证码错误，请重新输入';
    } else {
        $users = jdb_collection('users', 'users');
        $found = null;
        foreach ($users as $u) {
            if (isset($u['username']) && $u['username'] === $username) {
                $found = $u;
                break;
            }
        }
        if (!$found || !password_verify($password, $found['password'])) {
            $error = '用户名或密码错误';
            $_SESSION['login_fails'] = (isset($_SESSION['login_fails']) ? (int)$_SESSION['login_fails'] : 0) + 1;
            if ($_SESSION['login_fails'] >= 5) {
                $_SESSION['login_locked_until'] = time() + 600;
                unset($_SESSION['login_fails']);
                $error = '失败次数过多，登录已被锁定 10 分钟';
                log_action('登录锁定', '连续失败 5 次，锁定 10 分钟', $username);
            }
        } elseif (isset($found['status']) && $found['status'] !== 'active') {
            $error = '该账号已被禁用，请联系管理员';
        } else {
            unset($_SESSION['login_fails'], $_SESSION['login_locked_until']);
            login_user($found);
            redirect('index.php');
        }
    }
    }
}

$captchaText = arithmetic_captcha();
$s = $GLOBALS['SETTINGS'];
$color = isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8';
$site  = isset($s['site_name']) ? $s['site_name'] : '社团官网';
$orgName = isset($s['org_name']) ? $s['org_name'] : '社团';
$loginImage = isset($s['login_image']) ? trim($s['login_image']) : '';
$pageBg = (isset($s['default_bg']) && $s['default_bg'] !== '' && preg_match('#^/assets/bg/[A-Za-z0-9_.\-]+$#', $s['default_bg'])) ? $s['default_bg'] : 'none';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>登录 - <?php echo e($site); ?></title>
<link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/style.css?v=11">
<style>
    :root {
        --main: <?php echo e($color); ?>;
        --main-rgb: <?php echo implode(',', hex_rgb($color)); ?>;
        --main-dark: <?php echo e(hex_shade($color, 0.82)); ?>;
        --main-deep: <?php echo e(hex_shade($color, 0.60)); ?>;
        --accent: #d9a441;
        --accent-rgb: 217, 164, 65;
        --accent-dark: #c18f2f;
        --bg: #f3f6fb;
        --ink: #2b3340;
        --muted: #7a8494;
        --line: #e6ebf2;
        --card: #ffffff;
        --page-bg: <?php echo $pageBg === 'none' ? 'none' : 'url("' . e($pageBg) . '")'; ?>;
    }
</style>
</head>
<body class="login-wrap">
<div class="login-panel">
  <div class="login-side">
    <?php if ($loginImage !== '') { ?>
      <img src="<?php echo e($loginImage); ?>" alt="<?php echo e($site); ?>" class="login-img">
    <?php } else { ?>
      <div class="login-placeholder">
        <div class="login-ph-logo"><?php echo e(mb_substr($orgName, 0, 1)); ?></div>
        <div class="login-ph-title"><?php echo e($orgName); ?></div>
        <div class="login-ph-sub"><?php echo e($site); ?> · 成员系统</div>
      </div>
    <?php } ?>
  </div>
  <div class="login-main">
    <div class="card login-card">
      <div class="card-header py-3"><h4 class="mb-0"><?php echo e($site); ?> 登录</h4></div>
      <div class="card-body">
        <?php if ($error !== '') { ?>
          <div class="alert alert-danger py-2"><?php echo e($error); ?></div>
        <?php } ?>
        <?php if (isset($_GET['registered'])) { ?>
          <div class="alert alert-success py-2">注册成功，请登录</div>
        <?php } ?>
        <form method="post" action="login.php" autocomplete="off">
          <?php echo csrf_field(); ?>
          <div class="form-group">
            <label>用户名</label>
            <input type="text" name="username" class="form-control" required>
          </div>
          <div class="form-group">
            <label>密码</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <div class="form-group">
            <label>验证码</label>
            <div class="d-flex align-items-center">
              <input type="text" name="captcha" class="form-control mr-2" placeholder="请输入图中字符" required autocomplete="off" style="flex:1;min-width:0;">
              <img src="/captcha.php?ts=<?php echo time(); ?>" alt="验证码" id="captcha-img" title="看不清？点击刷新"
                   style="height:40px;flex:0 0 auto;border-radius:8px;cursor:pointer;border:1px solid var(--line);"
                   onclick="this.src='/captcha.php?ts='+Date.now();">
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-block">登 录</button>
        </form>
      </div>
      <div class="card-footer text-center text-muted small">账号由管理员统一发放 · <a href="register.php">凭邀请码注册</a></div>
    </div>
  </div>
</div>
</body>
</html>
