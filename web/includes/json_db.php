<?php
/**
 * JSON 文件存储封装（含文件锁）
 * - 读：加共享锁 LOCK_SH
 * - 写：加独占锁 LOCK_EX，先写临时文件再 rename，避免写一半损坏
 * 兼容 PHP 5.6，无任何 PHP 7+ 语法
 */

/**
 * 读取 JSON 文件并解码为数组
 * @param string $file 完整文件路径
 * @return array
 */
function jdb_read($file) {
    $data = array();
    if (!file_exists($file)) {
        return $data;
    }
    $fp = @fopen($file, 'rb');
    if (!$fp) {
        return $data;
    }
    flock($fp, LOCK_SH);              // 共享锁
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
    return $data;
}

/**
 * 写入数组到 JSON 文件（先写临时文件再重命名）
 * 兼容 Windows：不提前 fopen 目标文件（否则 rename 因句柄占用失败），
 * 改用独立 .lock 文件加独占锁实现并发互斥，rename 失败时 copy 兜底。
 * @param string $file 完整文件路径
 * @param array  $data 要写入的数据
 * @return bool
 */
function jdb_write($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $lockFile = $file . '.lock';
    $lfp = @fopen($lockFile, 'c');   // 锁文件，避免占用目标文件句柄
    if (!$lfp) {
        return false;
    }
    flock($lfp, LOCK_EX);            // 独占锁：同一时刻只允许一个写者
    $ok = @file_put_contents($tmp, $json);
    $ren = false;
    if ($ok !== false) {
        $ren = @rename($tmp, $file); // 原子替换
        if (!$ren) {
            $ren = @copy($tmp, $file); // Windows 下 rename 偶发失败时兜底
        }
    }
    flock($lfp, LOCK_UN);
    fclose($lfp);
    return $ren;
}

/**
 * 读取某个 JSON 集合文件（形如 {"key": [...]}）
 * @param string $name 数据文件名（不含 .json）
 * @param string $key  集合键名
 * @return array
 */
function jdb_collection($name, $key) {
    $data = jdb_read(JSON_DIR . '/' . $name . '.json');
    if (isset($data[$key]) && is_array($data[$key])) {
        return $data[$key];
    }
    return array();
}

/**
 * 保存某个 JSON 集合文件（形如 {"key": [...]}）
 */
function jdb_save_collection($name, $key, $list) {
    return jdb_write(JSON_DIR . '/' . $name . '.json', array($key => $list));
}

/**
 * 在列表中按 id 查找元素
 */
function jdb_find_by_id($list, $id) {
    foreach ($list as $item) {
        if (isset($item['id']) && $item['id'] === $id) {
            return $item;
        }
    }
    return null;
}

/**
 * 在列表中按指定字段查找第一个匹配元素
 */
function jdb_find_by_field($list, $field, $value) {
    foreach ($list as $item) {
        if (isset($item[$field]) && $item[$field] == $value) {
            return $item;
        }
    }
    return null;
}
