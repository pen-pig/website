<?php
/**
 * 社团官网系统 - 全局配置
 * 兼容 PHP 5.6，无数据库，JSON 文件存储，代码与数据分离
 */

// 启动会话（PHP 5.4+ 支持 session_status）
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 目录常量：部署商要求网站只能在根目录运行，web 内容平铺到站点根目录
// 因此站点根目录即代码目录，data/ drive/ 与网站代码同级（都在根目录下）
define('WEB_DIR', dirname(dirname(__FILE__)));    // 网站代码目录（站点根目录）
define('BASE_DIR', WEB_DIR);                      // 数据父目录 = 站点根目录（data/drive 与代码同级）
define('DATA_DIR', BASE_DIR . '/data');           // 数据目录（公网不可访问）
define('JSON_DIR', DATA_DIR . '/json');           // JSON 数据文件
define('UPLOAD_DIR', DATA_DIR . '/uploads');      // 上传文件
define('THEME_BG_DIR', WEB_DIR . '/assets/bg');   // 主题背景图（公网可访问，位于 web 根下）
define('THEME_BG_URL', '/assets/bg');             // 主题背景图 URL 前缀
define('DRIVE_DIR', BASE_DIR . '/drive');         // 网盘文件目录（与 web/data 同级，公网不可直接访问）
define('DRIVE_LIMIT', 21474836480);               // 网盘总限额 20G（20 * 1024^3）

// 加载核心库
require_once WEB_DIR . '/includes/json_db.php';
require_once WEB_DIR . '/includes/markdown.php';
require_once WEB_DIR . '/includes/functions.php';
require_once WEB_DIR . '/includes/auth.php';

// 读取网站设置（settings.json），缺省时使用默认值
$GLOBALS['SETTINGS'] = jdb_read(JSON_DIR . '/settings.json');
if (empty($GLOBALS['SETTINGS']) || !is_array($GLOBALS['SETTINGS'])) {
    $GLOBALS['SETTINGS'] = array(
        'site_name'     => '社团官网',
        'org_name'      => '社团',
        'logo'          => '',
        'primary_color' => '#1e6bb8',
        'home_intro'    => "## 社团简介\n\n欢迎来到社团官网，这里发布社团对外公告与活动信息。",
        'teacher'       => '',
        'contact'       => ''
    );
}

// 时区统一
date_default_timezone_set('Asia/Shanghai');
