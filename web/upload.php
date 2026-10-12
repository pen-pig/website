<?php
$rootSaveDir = __DIR__ . DIRECTORY_SEPARATOR;
$tmpChunkDir = $rootSaveDir . 'chunks_tmp' . DIRECTORY_SEPARATOR;

$expireSeconds = 7200; // 过期时间：2小时 = 7200秒

// 创建分片临时目录
if (!is_dir($tmpChunkDir)) {
    mkdir($tmpChunkDir, 0755, true);
}

// ========== 自动清理过期分片 ==========
function cleanExpireChunks($dir, $expSec){
    $now = time();
    $files = glob($dir . '*');
    if(!$files) return;
    foreach ($files as $file) {
        if(is_file($file)){
            $fileMtime = filemtime($file);
            if( ($now - $fileMtime) > $expSec ){
                unlink($file);
            }
        }
    }
}
cleanExpireChunks($tmpChunkDir, $expireSeconds);
// =====================================

$action = isset($_POST['action']) ? $_POST['action'] : '';

// 1.接收分片
if ($action === 'upload_chunk') {
    header('Content-Type: application/json');

    $fileName = isset($_POST['filename']) ? $_POST['filename'] : '';
    $filePathRel = isset($_POST['filepath']) ? $_POST['filepath'] : '';
    $chunkIndex = isset($_POST['chunkindex']) ? intval($_POST['chunkindex']) : 0;
    $totalChunks = isset($_POST['totalchunks']) ? intval($_POST['totalchunks']) : 0;
    $fileMd5 = isset($_POST['filemd5']) ? $_POST['filemd5'] : '';

    if(empty($fileName) || empty($fileMd5)){
        echo json_encode(array('code'=>1,'msg'=>'参数缺失'));
        exit;
    }

    $chunkTmpFile = $tmpChunkDir . $fileMd5 . '_' . $chunkIndex;
    if (!empty($_FILES['chunkfile']) && $_FILES['chunkfile']['error'] === UPLOAD_ERR_OK) {
        if(!move_uploaded_file($_FILES['chunkfile']['tmp_name'], $chunkTmpFile)){
            echo json_encode(array('code'=>1,'msg'=>'分片写入失败'));
            exit;
        }
    } else {
        echo json_encode(array('code'=>1,'msg'=>'分片上传错误'));
        exit;
    }

    echo json_encode(array('code'=>0));
    exit;
}

// 2.合并分片
if ($action === 'merge') {
    header('Content-Type: application/json');
    $fileName = isset($_POST['filename']) ? $_POST['filename'] : '';
    $filePathRel = isset($_POST['filepath']) ? $_POST['filepath'] : '';
    $totalChunks = isset($_POST['totalchunks']) ? intval($_POST['totalchunks']) : 0;
    $fileMd5 = isset($_POST['filemd5']) ? $_POST['filemd5'] : '';

    $fullDestPath = $rootSaveDir . $filePathRel;
    $destDir = dirname($fullDestPath);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $outFp = fopen($fullDestPath, 'wb');
    if(!$outFp){
        echo json_encode(array('code'=>1,'msg'=>'无法创建目标文件'));
        exit;
    }
    for($i=0;$i<$totalChunks;$i++){
        $chunkFile = $tmpChunkDir . $fileMd5 . '_' . $i;
        if(!file_exists($chunkFile)){
            fclose($outFp);
            unlink($fullDestPath);
            echo json_encode(array('code'=>1,'msg'=>'分片丢失，合并失败'));
            exit;
        }
        $inFp = fopen($chunkFile, 'rb');
        while(!feof($inFp)){
            fwrite($outFp, fread($inFp, 8192));
        }
        fclose($inFp);
        unlink($chunkFile);
    }
    fclose($outFp);
    echo json_encode(array('code'=>0));
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>分片上传（并发+自动清理过期分片）</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:system-ui, sans-serif}
.container{max-width:860px;margin:30px auto;padding:20px}
.drop-box{border:3px dashed #999;border-radius:10px;padding:40px;text-align:center;transition:0.2s}
.drop-box.active{border-color:#2563eb;background:#e8f2ff}
.btns{margin:15px 0;position:relative;display:inline-block}
#mainBtn{padding:10px 18px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;}
#menu{display:none;position:absolute;top:100%;left:0;margin-top:4px;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 2px 8px #00000018;z-index:99;white-space:nowrap;}
#menu div{padding:8px 16px;cursor:pointer;}
#menu div:hover{background:#f2f6ff}
#file-input, #folder-input{display:none}
#fileList{margin-top:20px;border:1px solid #eee;border-radius:8px;padding:15px;max-height:440px;overflow:auto}
.tree-item{padding:6px 0;font-family:monospace;font-size:13px;display:flex;justify-content:space-between;align-items:center}
.tree-folder{color:#2563eb}
.tree-file{color:#333}
.info{margin:15px 0;padding:10px;background:#edf7ff;border-radius:6px}
.status-wait{color:#666}
.status-uploading{color:#2563eb}
.status-ok{color:#16a34a}
/* 失败状态：红色、单行不换行 */
.status-fail{color:#dc2626;white-space:nowrap;cursor:pointer;text-decoration:underline;}
.progress-bar{display:inline-block;width:80px;height:6px;background:#eee;border-radius:3px;margin:0 8px;overflow:hidden}
.progress-inner{height:100%;background:#2563eb;width:0%}
</style>
</head>
<body>
<div class="container">
    <h2>拖放 / 点击上传 分片并发上传</h2>
    <div class="info">
        ✅拖拽区：可一次性拖入【文件+文件夹混合】，自动列出文件夹内所有子文件<br>
        ✅点击【点击上传】按钮：选择文件 或 选择文件夹<br>
        ✅分片并发上传，成功条目自动消失；文件夹下属全部文件传完，文件夹条目自动消失<br>
        ✅每次上传自动清理2小时过期分片碎片<br>
        ✅分片失败自动重试3次；失败条目显示红字，点击可重新上传<br>
        ⚠无文件格式限制
    </div>

    <div class="drop-box" id="dropBox">
        <p>将文件或文件夹拖放到此处</p>
        <div class="btns">
            <button id="mainBtn">点击上传</button>
            <div id="menu">
                <div id="selectFileBtn">选择文件</div>
                <div id="selectFolderBtn">选择文件夹</div>
            </div>
            <input type="file" id="file-input" multiple>
            <input type="file" id="folder-input" webkitdirectory directory multiple>
        </div>
    </div>

    <div id="fileList"></div>
</div>

<script>
const dropBox = document.getElementById('dropBox');
const fileInput = document.getElementById('file-input');
const folderInput = document.getElementById('folder-input');
const fileListEl = document.getElementById('fileList');
const mainBtn = document.getElementById('mainBtn');
const menu = document.getElementById('menu');
const selectFileBtn = document.getElementById('selectFileBtn');
const selectFolderBtn = document.getElementById('selectFolderBtn');

// ========== 配置项 ==========
const CHUNK_SIZE = 256 * 1024;        // 分片大小 256KB
const MAX_CONCURRENT = 4;             // 并发数量
const FETCH_TIMEOUT = 60 * 1000;      // 单次请求超时 60 秒
const MAX_RETRY = 3;                  // 单分片最大重试次数

let fileItems = [];
let folderFileMap = {};
let runningCount = 0;

// ========== 全局唯一ID ==========
let uidCounter = 0;
function genId(){
    return 'id_' + (++uidCounter) + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
}

function simpleMd5(s){
    let hash = 0;
    for (let i = 0; i < s.length; i++) {
        const char = s.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash;
    }
    return Math.abs(hash).toString(16);
}

// ========== 带超时的 fetch ==========
function fetchWithTimeout(url, options, timeout){
    const controller = new AbortController();
    const timer = setTimeout(function(){ controller.abort(); }, timeout || FETCH_TIMEOUT);
    const opt = Object.assign({}, options, { signal: controller.signal });
    return fetch(url, opt).finally(function(){ clearTimeout(timer); });
}

// 下拉菜单
mainBtn.onclick = function(e){
    e.stopPropagation();
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}
document.body.onclick = function(){ menu.style.display = 'none'; }
selectFileBtn.onclick = function(){ menu.style.display = 'none'; fileInput.click(); }
selectFolderBtn.onclick = function(){ menu.style.display = 'none'; folderInput.click(); }

// 拖拽上传
dropBox.addEventListener('dragover', function(e){e.preventDefault();dropBox.classList.add('active')})
dropBox.addEventListener('dragleave', function(){dropBox.classList.remove('active')})
dropBox.addEventListener('drop', async function(e){
    e.preventDefault();
    dropBox.classList.remove('active');
    fileItems = [];
    folderFileMap = {};
    fileListEl.innerHTML = '';
    runningCount = 0;
    const items = e.dataTransfer.items;
    const entries = [];
    for(let i=0;i<items.length;i++){
        const entry = items[i].webkitGetAsEntry();
        if(entry) entries.push(entry);
    }
    for(let entry of entries){
        await scanEntry(entry, "", null);
    }
    renderTree();
    startQueue();
})

fileInput.onchange = function(){
    buildFileList(fileInput.files);
    fileInput.value = '';
}
folderInput.onchange = function(){
    buildFileList(folderInput.files);
    folderInput.value = '';
}

function readAllEntries(reader) {
    return new Promise((resolve) => {
        let allEntries = [];
        function readBatch() {
            reader.readEntries(entries => {
                if(entries.length === 0){
                    resolve(allEntries);
                }else{
                    allEntries = allEntries.concat(entries);
                    readBatch();
                }
            });
        }
        readBatch();
    });
}

async function scanEntry(entry, basePath, parentFolderId=null){
    const fullPath = basePath + entry.name;
    if(entry.isDirectory){
        const fid = genId();
        fileItems.push({ type:'folder', path:fullPath, id:fid });
        folderFileMap[fid] = {total:0, finished:0};
        const reader = entry.createReader();
        const entries = await readAllEntries(reader);
        for(let j=0;j<entries.length;j++){
            await scanEntry(entries[j], fullPath + "/", fid);
        }
    }else{
        const fileObj = await getFileFromEntry(entry);
        const fid = genId();
        fileItems.push({
            type:'file',
            path:fullPath,
            file:fileObj,
            id:fid,
            status:'wait',
            parentFolderId: parentFolderId
        });
        if(parentFolderId && folderFileMap[parentFolderId]){
            folderFileMap[parentFolderId].total += 1;
        }
    }
}
function getFileFromEntry(entry){
    return new Promise(function(res){ entry.file(function(f){res(f)}) })
}

function buildFileList(files){
    fileItems = [];
    folderFileMap = {};
    fileListEl.innerHTML = '';
    runningCount = 0;
    for(let i=0;i<files.length;i++){
        const f = files[i];
        fileItems.push({
            type:'file',
            path:f.webkitRelativePath || f.name,
            file:f,
            id:genId(),
            status:'wait',
            parentFolderId: null
        });
    }
    renderTree();
    startQueue();
}

function renderTree(){
    let html = '';
    for(let i=0;i<fileItems.length;i++){
        const item = fileItems[i];
        if(item.type === 'folder'){
            html += '<div class="tree-item tree-folder" data-id="'+item.id+'">📁 ' + escapeHtml(item.path) + '</div>';
        }else{
            let statusText = '等待上传';
            if(item.status === 'uploading') statusText = '上传中';
            else if(item.status === 'fail') statusText = '上传失败，请重新上传';
            else if(item.status === 'ok') statusText = '完成';
            const failClick = (item.status === 'fail') ? 'onclick="retryFile(\''+item.id+'\')"' : '';
            html += `
            <div class="tree-item tree-file" data-id="${item.id}">
                <span>📄 ${escapeHtml(item.path)}</span>
                <div style="display:flex;align-items:center">
                    <div class="progress-bar"><div class="progress-inner" data-pid="${item.id}"></div></div>
                    <span class="status-${item.status}" data-st="${item.id}" ${failClick}>${statusText}</span>
                </div>
            </div>`;
        }
    }
    fileListEl.innerHTML = html;
}

function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(m){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}

function checkFolderFinish(parentFolderId){
    if(!parentFolderId || !folderFileMap[parentFolderId]) return;
    const info = folderFileMap[parentFolderId];
    info.finished += 1;
    if(info.finished >= info.total){
        const folderDom = document.querySelector('[data-id="'+parentFolderId+'"]');
        if(folderDom) folderDom.remove();
    }
}

// ========== 失败重试入口 ==========
window.retryFile = function(id){
    const item = fileItems.find(function(x){ return x.id === id; });
    if(!item || item.status !== 'fail') return;
    item.status = 'wait';
    setStatus(id, 'wait', '等待上传');
    updateProgress(id, 0);
    startQueue();
}

// ========== 并发队列调度 ==========
function startQueue(){
    if(runningCount >= MAX_CONCURRENT) return;
    const nextItem = fileItems.find(function(x){ return x.type==='file' && x.status === 'wait'; });
    if(!nextItem) return;
    nextItem.status = 'uploading';
    runningCount++;
    setStatus(nextItem.id, 'uploading', '上传中');
    updateProgress(nextItem.id, 0);

    uploadFileChunk(nextItem).then(function(){
        runningCount--;
        startQueue();
    }).catch(function(err){
        // 失败：标记状态，释放并发
        nextItem.status = 'fail';
        setStatus(nextItem.id, 'fail', '上传失败，请重新上传');
        console.error('上传失败：', nextItem.path, err);
        runningCount--;
        startQueue();
    });
    startQueue();
}

// ========== 单分片上传（带重试） ==========
async function uploadOneChunk(fileItem, chunkPrefix, fileName, filePathRel, c, totalChunks, chunkBlob){
    let lastErr = null;
    for(let attempt = 1; attempt <= MAX_RETRY; attempt++){
        try{
            const fd = new FormData();
            fd.append('action','upload_chunk');
            fd.append('chunkfile', chunkBlob, fileName + '.part' + c);
            fd.append('filename', fileName);
            fd.append('filepath', filePathRel);
            fd.append('chunkindex', c);
            fd.append('totalchunks', totalChunks);
            fd.append('filemd5', chunkPrefix);

            const res = await fetchWithTimeout("", {method:"POST", body:fd}, FETCH_TIMEOUT);
            if(!res.ok) throw new Error('HTTP ' + res.status);
            const ret = await res.json();
            if(ret.code !== 0) throw new Error(ret.msg || '分片上传失败');
            return; // 成功
        }catch(e){
            lastErr = e;
            console.warn('分片重试', c, '第', attempt, '次失败：', e.message);
            if(attempt < MAX_RETRY){
                await new Promise(function(r){ setTimeout(r, 500 * attempt); });
            }
        }
    }
    throw lastErr || new Error('分片上传失败');
}

async function uploadFileChunk(fileItem){
    const file = fileItem.file;
    const filePathRel = fileItem.path;
    const fileName = file.name;
    const fileSize = file.size;
    const totalChunks = Math.ceil(fileSize / CHUNK_SIZE);

    const fileTextSample = await readFileSample(file);
    const fileMd5 = simpleMd5(filePathRel + fileSize + fileTextSample);
    const chunkPrefix = fileMd5 + '_' + fileItem.id;

    for(let c=0;c<totalChunks;c++){
        const start = c * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, fileSize);
        const chunkBlob = file.slice(start, end);

        await uploadOneChunk(fileItem, chunkPrefix, fileName, filePathRel, c, totalChunks, chunkBlob);

        const pct = Math.round(((c+1)/totalChunks)*100);
        updateProgress(fileItem.id, pct);
    }

    // 合并
    const fdMerge = new FormData();
    fdMerge.append('action','merge');
    fdMerge.append('filename',fileName);
    fdMerge.append('filepath',filePathRel);
    fdMerge.append('totalchunks',totalChunks);
    fdMerge.append('filemd5', chunkPrefix);

    const mergeResp = await fetchWithTimeout("", {method:"POST", body:fdMerge}, FETCH_TIMEOUT);
    if(!mergeResp.ok) throw new Error('HTTP ' + mergeResp.status);
    const mergeRet = await mergeResp.json();
    if(mergeRet.code !== 0){
        throw new Error(mergeRet.msg || '合并失败');
    }

    // 成功
    fileItem.status = 'ok';
    const dom = document.querySelector('[data-id="'+fileItem.id+'"]');
    if(dom) dom.remove();
    checkFolderFinish(fileItem.parentFolderId);
}

function readFileSample(file){
    return new Promise(function(res){
        const reader = new FileReader();
        reader.onload = function(e){ res(e.target.result.slice(0,200)); }
        reader.onerror = function(){ res(''); }
        reader.readAsText(file.slice(0,200));
    })
}

function updateProgress(fid, percent){
    const bar = document.querySelector('.progress-inner[data-pid="'+fid+'"]');
    if(bar) bar.style.width = percent + '%';
}
function setStatus(fid, cls, text){
    const el = document.querySelector('[data-st="'+fid+'"]');
    if(!el) return;
    el.className = 'status-' + cls;
    el.innerText = text;
    if(cls === 'fail'){
        el.setAttribute('onclick', 'retryFile(\''+fid+'\')');
    }else{
        el.removeAttribute('onclick');
    }
}
</script>
</body>
</html>