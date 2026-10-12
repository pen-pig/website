<?php
/**
 * 退出登录
 */
require_once __DIR__ . '/includes/config.php';
logout_user();
redirect('login.php');
