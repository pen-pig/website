<?php
/**
 * GD 图形验证码（免费、零第三方依赖）
 * 4 位小写字符 + 干扰弧线 + 噪点，答案存 session 供 check_captcha 校验
 */
require_once __DIR__ . '/includes/config.php';

$chars = 'abcdefghjkmnpqrstuvwxy23456789'; // 去掉易混淆的 0/o/1/l/i
$code = '';
for ($i = 0; $i < 4; $i++) {
    $code .= $chars[rand(0, strlen($chars) - 1)];
}
$_SESSION['captcha_answer'] = $code;
$_SESSION['captcha_ts'] = time();

$w = 130; $h = 44;
$img = imagecreatetruecolor($w, $h);
$bg = imagecolorallocate($img, 244, 248, 252);
imagefilledrectangle($img, 0, 0, $w, $h, $bg);

// 干扰弧线
for ($i = 0; $i < 4; $i++) {
    $c = imagecolorallocate($img, rand(150, 210), rand(160, 215), rand(180, 235));
    imagearc($img, rand(0, $w), rand(0, $h), rand(30, 90), rand(20, 60), rand(0, 360), rand(0, 360), $c);
}
// 噪点
for ($i = 0; $i < 140; $i++) {
    imagesetpixel($img, rand(0, $w - 1), rand(0, $h - 1), imagecolorallocate($img, rand(130, 220), rand(130, 220), rand(130, 220)));
}
// 逐字符随机抖动绘制
$x = 14;
for ($i = 0; $i < 4; $i++) {
    $col = imagecolorallocate($img, rand(30, 110), rand(50, 130), rand(90, 180));
    imagestring($img, 5, $x, rand(8, 16), $code[$i], $col);
    $x += 26;
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
imagepng($img);
imagedestroy($img);
