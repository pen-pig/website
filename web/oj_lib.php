<?php
/**
 * OJ 评测引擎（自研，Windows/Linux 兼容）
 * - 检测 g++（PATH 中），编译 C++14 / C11，捕获编译错误
 * - proc_open 逐测试点运行：限时、stdin 喂入、捕获 stdout、退出码判 RE
 * - 输出比对：统一换行符、去每行行尾空白、去文末空行
 * - 无编译器/无测试数据 → 自动降级人工评测（verdict=manual）
 * 测试数据目录：DATA_DIR/oj/tests/<题目id>/t<N>.in / t<N>.out
 */
if (!defined('DATA_DIR')) exit('no direct access');

// ---------------- 题库读取 ----------------

function oj_load_problems() {
    return jdb_collection('oj_problems', 'problems');
}

function oj_find_problem($pid) {
    foreach (oj_load_problems() as $p) {
        if (isset($p['id']) && $p['id'] === $pid) return $p;
    }
    return null;
}

function oj_source_label($src) {
    $map = array('basic' => '浅入浅出', 'cspj' => 'CSP-J', 'csps' => 'CSP-S', 'gesp' => 'GESP');
    return isset($map[$src]) ? $map[$src] : $src;
}

function oj_verdict_label($v) {
    $map = array(
        'AC' => '通过', 'WA' => '答案错误', 'TLE' => '超时', 'RE' => '运行错误',
        'CE' => '编译错误', 'manual' => '待人工评测', 'pending' => '评测中'
    );
    return isset($map[$v]) ? $map[$v] : $v;
}

function oj_verdict_class($v) {
    $map = array('AC' => 'vj-ac', 'WA' => 'vj-wa', 'TLE' => 'vj-tle', 'RE' => 'vj-re',
                 'CE' => 'vj-ce', 'manual' => 'vj-manual', 'pending' => 'vj-manual');
    return isset($map[$v]) ? $map[$v] : 'vj-ce';
}

// ---------------- 运行外部命令（限时） ----------------

/**
 * 执行一条命令，限时返回（Windows 安全版：文件重定向 + 状态轮询）
 * 不使用管道读写——Windows 上 proc_open 管道阻塞且 stream_set_blocking 无效，
 * 死循环程序会让 fread 永久挂起；改用临时文件做 stdin/stdout/stderr。
 * @return array(stdout, stderr, exit, timed_out)
 */
function oj_run_cmd($cmd, $cwd, $stdin, $timeoutMs) {
    $ioDir = sys_get_temp_dir() . '/ojio_' . uniqid();
    @mkdir($ioDir, 0700, true);
    $inFile = $ioDir . '/in.txt';
    $outFile = $ioDir . '/out.txt';
    $errFile = $ioDir . '/err.txt';
    file_put_contents($inFile, (string)$stdin);
    $descriptors = array(
        0 => array('file', $inFile, 'r'),
        1 => array('file', $outFile, 'w'),
        2 => array('file', $errFile, 'w')
    );
    $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        oj_rrmdir($ioDir);
        return array('', '', -1, false);
    }
    // proc_get_status 的 pid 仅第一次调用有效，先取一次保存
    $first = proc_get_status($proc);
    $procPid = (int)(isset($first['pid']) ? $first['pid'] : 0);
    $start = microtime(true);
    $timedOut = false;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $start) * 1000 > $timeoutMs) {
            $timedOut = true;
            // Windows 下 proc_terminate 对控制台程序不可靠，用 taskkill 强杀进程树
            if ($procPid > 0 && DIRECTORY_SEPARATOR === '\\') {
                @exec('taskkill /F /T /PID ' . $procPid . ' 2>NUL');
            } else {
                proc_terminate($proc, 9);
            }
            usleep(100000); // 等 100ms 让进程真正退出
            break;
        }
        usleep(10000); // 10ms 轮询
    }
    $exit = proc_close($proc);
    $stdout = (string)@file_get_contents($outFile);
    $stderr = (string)@file_get_contents($errFile);
    oj_rrmdir($ioDir);
    return array($stdout, $stderr, $exit, $timedOut);
}

function oj_normalize_out($s) {
    $s = str_replace("\r\n", "\n", $s);
    $lines = explode("\n", $s);
    foreach ($lines as $i => $l) {
        $lines[$i] = rtrim($l, " \t");
    }
    while (count($lines) > 0 && $lines[count($lines) - 1] === '') {
        array_pop($lines);
    }
    return implode("\n", $lines);
}

// ---------------- 编译器检测 ----------------

function oj_compiler_available() {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!function_exists('proc_open') || !function_exists('exec')) {
        $ok = false;
        return $ok;
    }
    $dis = @ini_get('disable_functions');
    if ($dis && (stripos($dis, 'proc_open') !== false)) {
        $ok = false;
        return $ok;
    }
    $r = oj_run_cmd('g++ --version', sys_get_temp_dir(), null, 5000);
    $ok = ($r[2] === 0);
    return $ok;
}

// ---------------- 核心评测 ----------------

/**
 * 评测一条提交记录，写回 oj_submissions.json
 * 提交记录字段：id, pid, uid, name, lang, code, verdict, score, cases, ce_msg, created_at, judged_at
 * @return array 更新后的记录
 */
function oj_judge_submission($subId) {
    $subs = jdb_collection('oj_submissions', 'submissions');
    $idx = -1; $sub = null;
    foreach ($subs as $i => $s) {
        if (isset($s['id']) && $s['id'] === $subId) { $sub = $s; $idx = $i; break; }
    }
    if ($sub === null) return null;

    $problem = oj_find_problem($sub['pid']);

    // 无题 / 无测试数据 / 编译器不可用 → 人工评测
    if ($problem === null || empty($problem['ntests']) || $problem['status'] !== 'ready' || !oj_compiler_available()) {
        $sub['verdict'] = 'manual';
        $sub['score'] = 0;
        $sub['cases'] = array();
        $sub['judged_at'] = date('Y-m-d H:i:s');
        $subs[$idx] = $sub;
        jdb_save_collection('oj_submissions', 'submissions', $subs);
        return $sub;
    }

    @set_time_limit(0);
    // ---- 编译 ----
    $tmpDir = sys_get_temp_dir() . '/oj_' . uniqid();
    @mkdir($tmpDir, 0700, true);
    $srcFile = $tmpDir . '/main.' . ($sub['lang'] === 'c' ? 'c' : 'cpp');
    file_put_contents($srcFile, $sub['code']);
    $exe = $tmpDir . '/main' . (DIRECTORY_SEPARATOR === '\\' ? '.exe' : '');
    $std = ($sub['lang'] === 'c') ? '-std=c11' : '-std=c++14';
    $cmd = 'g++ -O2 -w ' . $std . ' -o ' . escapeshellarg($exe) . ' ' . escapeshellarg($srcFile);
    list(, $cerr, $cexit, $cto) = oj_run_cmd($cmd, $tmpDir, null, 30000);
    if ($cexit !== 0) {
        $sub['verdict'] = 'CE';
        $sub['score'] = 0;
        $sub['cases'] = array();
        $sub['ce_msg'] = mb_substr($cerr, 0, 800, 'UTF-8');
        $sub['judged_at'] = date('Y-m-d H:i:s');
        $subs[$idx] = $sub;
        jdb_save_collection('oj_submissions', 'submissions', $subs);
        oj_rrmdir($tmpDir);
        return $sub;
    }

    // ---- 逐测试点 ----
    $limit = max(200, (int)(isset($problem['time_limit']) ? $problem['time_limit'] : 1000));
    $cases = array();
    $passed = 0;
    $firstFail = '';
    $total = (int)$problem['ntests'];
    for ($t = 1; $t <= $total; $t++) {
        $inFile = DATA_DIR . '/oj/tests/' . $sub['pid'] . '/t' . $t . '.in';
        $outFile = DATA_DIR . '/oj/tests/' . $sub['pid'] . '/t' . $t . '.out';
        if (!is_file($inFile) || !is_file($outFile)) {
            $cases[] = array('v' => 'manual', 'ms' => 0);
            if ($firstFail === '') $firstFail = 'manual';
            continue;
        }
        $input = file_get_contents($inFile);
        $expected = file_get_contents($outFile);
        $t0 = microtime(true);
        $exeCmd = escapeshellarg($exe);
        list($stdout, , $exit, $timedOut) = oj_run_cmd($exeCmd, $tmpDir, $input, $limit);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        $v = 'AC';
        if ($timedOut) $v = 'TLE';
        elseif ($exit !== 0) $v = 'RE';
        elseif (oj_normalize_out($stdout) !== oj_normalize_out($expected)) $v = 'WA';
        $cases[] = array('v' => $v, 'ms' => $ms);
        if ($v === 'AC') $passed++;
        elseif ($firstFail === '') $firstFail = $v;
    }
    oj_rrmdir($tmpDir);

    $sub['cases'] = $cases;
    $sub['score'] = $total > 0 ? (int)round($passed / $total * 100) : 0;
    $sub['verdict'] = ($passed === $total) ? 'AC' : $firstFail;
    $sub['judged_at'] = date('Y-m-d H:i:s');

    $subs[$idx] = $sub;
    jdb_save_collection('oj_submissions', 'submissions', $subs);
    return $sub;
}

/** 递归删除评测临时目录 */
function oj_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    foreach ((array)$items as $it) {
        if ($it === '.' || $it === '..') continue;
        $p = $dir . '/' . $it;
        is_dir($p) ? oj_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
