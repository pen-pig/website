<?php
/**
 * 自助注册（邀请码制）
 * - 管理员在「成员管理」生成一次性邀请码，新人凭码注册，默认 member 角色
 * - 图形验证码 + bcrypt，与登录一致；注册成功邀请码立即作废
 */
require_once __DIR__ . '/includes/config.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$old = array('username' => '', 'name' => '', 'invite' => '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username  = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $name      = trim(isset($_POST['name']) ? $_POST['name'] : '');
    $password  = isset($_POST['password']) ? $_POST['password'] : '';
    $password2 = isset($_POST['password2']) ? $_POST['password2'] : '';
    $invite    = strtoupper(trim(isset($_POST['invite']) ? $_POST['invite'] : ''));
    $captcha   = trim(isset($_POST['captcha']) ? $_POST['captcha'] : '');
    $old = array('username' => $username, 'name' => $name, 'invite' => $invite);

    do {
        if (!check_captcha($captcha)) { $error = '验证码错误，请重新输入'; break; }
        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) { $error = '用户名仅允许字母、数字、下划线，长度 3-20 位'; break; }
        if (mb_strlen($name) > 20) { $error = '姓名过长（最多 20 字）'; break; }
        if (strlen($password) < 6) { $error = '密码至少 6 位'; break; }
        if ($password !== $password2) { $error = '两次输入的密码不一致'; break; }

        $invites = jdb_collection('invites', 'invites');
        $invIdx = -1;
        foreach ($invites as $k => $iv) {
            if (isset($iv['code']) && strtoupper($iv['code']) === $invite) { $invIdx = $k; break; }
        }
        if ($invIdx === -1) { $error = '邀请码无效'; break; }
        $iv = $invites[$invIdx];
        if (!empty($iv['used_by'])) { $error = '邀请码已被使用'; break; }
        if (isset($iv['expires_at']) && $iv['expires_at'] !== '' && strtotime($iv['expires_at']) < time()) { $error = '邀请码已过期'; break; }

        $users = jdb_collection('users', 'users');
        $dup = false;
        foreach ($users as $u) {
            if ($u['username'] === $username) { $dup = true; break; }
        }
        if ($dup) { $error = '用户名已存在'; break; }

        $uid = 'u_' . uniqid();
        $users[] = array(
            'id'         => $uid,
            'username'   => $username,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'name'       => $name !== '' ? $name : $username,
            'role'       => 'member',
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s')
        );
        jdb_save_collection('users', 'users', $users);

        $invites[$invIdx]['used_by'] = $uid;
        $invites[$invIdx]['used_name'] = ($name !== '' ? $name : $username);
        $invites[$invIdx]['used_at'] = date('Y-m-d H:i:s');
        jdb_save_collection('invites', 'invites', $invites);

        log_action('注册', '新成员注册：' . ($name !== '' ? $name : $username) . '（邀请码 ' . $invite . '）');
        redirect('login.php?registered=1');
    } while (false);
}

$s = $GLOBALS['SETTINGS'];
$site = isset($s['site_name']) ? $s['site_name'] : '社团官网';
page_header('注册', '');
if ($error !== '') flash_toast($error);
?>
<div class="row"><div class="col-lg-5 mx-auto">
<div class="card">
<div class="card-header"><strong>凭邀请码注册 · <?php echo e($site); ?></strong></div>
<div class="card-body">
<form method="post" action="register.php" autocomplete="off">
  <?php echo csrf_field(); ?>
  <div class="form-group"><label>邀请码 *</label><input type="text" name="invite" class="form-control" value="<?php echo e($old['invite']); ?>" required placeholder="向管理员索取一次性邀请码"></div>
  <div class="form-group"><label>用户名 *（字母/数字/下划线，3-20 位）</label><input type="text" name="username" class="form-control" value="<?php echo e($old['username']); ?>" required></div>
  <div class="form-group"><label>姓名（站内显示，可留空）</label><input type="text" name="name" class="form-control" value="<?php echo e($old['name']); ?>"></div>
  <div class="form-group"><label>密码 *（至少 6 位）</label><input type="password" name="password" class="form-control" required></div>
  <div class="form-group"><label>确认密码 *</label><input type="password" name="password2" class="form-control" required></div>
  <div class="form-group"><label>验证码</label><div class="d-flex align-items-center">
    <input type="text" name="captcha" class="form-control mr-2" required autocomplete="off" style="flex:1;min-width:0;">
    <img src="/captcha.php?ts=<?php echo time(); ?>" alt="验证码" title="看不清？点击刷新" onclick="this.src='/captcha.php?ts='+Date.now();" style="height:40px;flex:0 0 auto;border-radius:8px;cursor:pointer;border:1px solid var(--line);">
  </div></div>
  <button type="submit" class="btn btn-primary btn-block">注 册</button>
</form>
</div>
<div class="card-footer text-center text-muted small">已有账号？<a href="login.php">去登录</a></div>
</div>
</div></div>
<?php page_footer();
