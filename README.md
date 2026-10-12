\---  
AIGC:  
    Label: "1"  
    ContentProducer: 001191440300708461136T1XGW3  
    ProduceID: bc49c4ac6fb27cdef0692ccde63abaf1_6d792c3cb7db11f1a59e525400248c00  
    ReservedCode1: 6kfeSn0JCdz5XHR7NhYeR15ZrnppoycOOu3SNEUyTP8wGzpKnATdCm1dMyIj+H4Cb0FKB1LKheDO3MP0X7W55IMWos9YgBMs9NMQDcBaov5CcND4GkzHS9AevCZxeslKfARF0Rp/hze74wLphoafB5q0cWVGicH7UMuU89T30eYp0NHT183nzdQdWvE=  
    ContentPropagator: 001191440300708461136T1XGW3  
    PropagateID: bc49c4ac6fb27cdef0692ccde63abaf1_6d792c3cb7db11f1a59e525400248c00  
    ReservedCode2: 6kfeSn0JCdz5XHR7NhYeR15ZrnppoycOOu3SNEUyTP8wGzpKnATdCm1dMyIj+H4Cb0FKB1LKheDO3MP0X7W55IMWos9YgBMs9NMQDcBaov5CcND4GkzHS9AevCZxeslKfARF0Rp/hze74wLphoafB5q0cWVGicH7UMuU89T30eYp0NHT183nzdQdWvE=  
\---

# 社团官网系统（Club Website System）v1.5

基于 **PHP 5.6+ + JSON 文件存储** 的编程社团官网系统，代码与数据完全分离，适配 Linux + Nginx + PHP 5.6 共享虚拟主机（不依赖 .htaccess），兼容 PHP 8.x。

> 当前版本 v1.5（2026-09-28）。更新记录见 `data/docs/CHANGELOG.md`（首页底部「更新日志」卡片实时渲染）。

---

## 一、目录结构

```
website\
├── web\                    # 网站代码（部署到公网根目录）
│   ├── index.php           # 主入口：首页 / 公告 / 通知 / 项目 / 工具 / 问卷 / 搜索 / 个人中心
│   ├── login.php           # 登录页（图形验证码 + bcrypt + 失败锁定）
│   ├── register.php        # 邀请码注册（一次性邀请码 + 验证码）
│   ├── logout.php          # 退出登录
│   ├── checkin.php         # 每日签到（连续/累计 + 排行 Top10）
│   ├── avatar.php          # 头像输出（严格文件名校验）
│   ├── paste.php           # 代码片段分享（高亮 + 行号 + 复制）
│   ├── code.php            # 附件代码文件在线预览（行号 + 高亮）
│   ├── download.php        # 统一文件下载入口（登录 + 角色鉴权 + 防路径穿越）
│   ├── img.php             # 图片安全输出（防路径穿越）
│   ├── drive.php           # 网盘页面（文件夹树 / 上传 / 改名 / 移动）
│   ├── oj.php              # OJ 训练：题库列表 / 题面 / 提交 / 评测结果 / 评测记录
│   ├── messages.php        # 站内私信（收发 / 删除 / 未读轮询）
│   ├── worship.php         # 膜拜互动页
│   ├── md_editor.php       # Markdown 编辑器（内联模态 + 实时预览）
│   ├── captcha.php         # GD 图形验证码
│   ├── router.php          # 本地开发服务器路由（php -S 用）
│   ├── 404.php             # 404 页面
│   ├── admin\              # 后台管理
│   │   ├── index.php       # 后台仪表盘（管理员/干事）
│   │   ├── notices.php     # 通知管理（管理员/干事）
│   │   ├── projects.php    # 项目与资料管理（管理员/干事）
│   │   ├── surveys.php     # 问卷管理（管理员/干事）
│   │   ├── tools.php       # 工具管理（上传/删除插件，文件名规范化）
│   │   ├── worship.php     # 膜拜管理（仅管理员）
│   │   ├── members.php     # 成员管理 + 邀请码管理（仅管理员）
│   │   ├── backup.php      # 数据备份（一键打包 data/ 下载，仅管理员）
│   │   ├── oj.php          # OJ 管理：人工判题队列 / 题目与测试数据编辑
│   │   └── settings.php    # 网站设置（仅管理员）
│   ├── api\                # AJAX 接口（逐接口校验登录/角色）
│   │   ├── auth.php        # 登录信息 / 修改密码
│   │   ├── notices.php     # 通知列表 / 详情 / 已读标记
│   │   ├── projects.php    # 项目列表 / 详情
│   │   ├── surveys.php     # 问卷列表 / 详情 / 投票
│   │   ├── messages.php    # 私信未读 / 列表 / 对话 / 发送 / 已读 / 删除
│   │   ├── drive.php       # 网盘上传 / 删除 / 新建夹 / 改名 / 移动 / 下载
│   │   └── upload.php      # 统一上传（管理员/干事）
│   ├── tools\              # 插件式工具（自动扫描 .html / .php，iframe 嵌入）
│   ├── assets\             # 静态资源（全部本地化，无 CDN 外链）
│   │   ├── css\style.css   # 全局样式（主色调动态注入）
│   │   ├── js\main.js      # 全局脚本（未读轮询等）
│   │   ├── js\mdpreview.js # Markdown 实时预览（语言标签同步）
│   │   └── vendor\         # highlight.js 11.9 / Bootstrap 4.6 / jQuery 3.5
│   └── includes\           # 核心库
│       ├── config.php      # 全局配置 / 路径常量 / 会话 / 站点设置
│       ├── json_db.php     # JSON 读写封装（flock 文件锁 + 临时文件原子替换）
│       ├── auth.php        # 登录认证 / 角色权限校验
│       ├── markdown.php    # Markdown 安全解析（防 XSS + 代码块语言标签）
│       ├── oj_lib.php      # OJ 评测引擎（g++ 编译 / 限时运行 / 输出比对 / 降级人工）
│       └── functions.php   # 公共函数（模板 / CSRF / 验证码 / 上传 / 日志 / 导航）
├── tools\                  # 维护脚本（不参与部署）
│   ├── gen_oj_problems.py  # 生成 152 道题目与测试数据
│   ├── fill_oj_archive.py  # 为真题条目补齐题面与测试数据
│   └── oj_selftest.php     # 命令行验证评测引擎
├── start.bat               # 一键启动本地服务器（Windows 双击运行）
├── data\                   # 数据目录（放在 web/ 上一级，公网不可访问）
│   ├── json\               # users/notices/projects/surveys/messages/drive/
│   │                       # checkins/invites/pastes/logs/oj_problems/oj_submissions.json
│   ├── oj\tests\<题目id>\  # OJ 测试数据 t1.in/.out ...（共 508 组）
│   ├── docs\               # README.md / CHANGELOG.md（首页「关于」区渲染）
│   └── uploads\
│       ├── materials\      # 项目资料
│       ├── homework\       # 成员作业
│       ├── notices\        # 通知附件
│       └── avatars\        # 用户头像
└── drive\                  # 网盘物理存储（20G 限额）
```

---

## 二、部署说明

### 1. 上传与目录配置

1. 将 `web/` 目录整体上传到虚拟主机，**站点根目录指向 `web/`**。
2. 将 `data/`、`drive/` 目录上传到 **`web/` 的上一级**，保证公网无法直接访问。
   - 若无法放到上一级，务必在主机面板中为这两个目录设置**禁止访问**。
3. 代码通过 `dirname(__FILE__)` 自动定位数据目录，**无需修改配置文件**。

### 2. 目录权限

- `data/`（含 json/docs/uploads）与 `drive/`：**可写**（755 或 775）。
- `web/`：**只读**即可，运行期不需要写入代码目录。
- `*.json.tmp` / `*.lock`：写入时的临时/锁文件，系统自动处理。

### 3. 环境要求

- PHP **5.6 及以上**（完全兼容 5.6，未使用 7+ 语法；已在 PHP 8.3 实测）。
- 无需数据库、无需伪静态、无需 .htaccess。
- 前端依赖（Bootstrap 4.6 / jQuery 3.5 / highlight.js 11.9）**全部本地化**，离线内网可运行。
- 需 GD 扩展（验证码 / 头像 / 图片处理）、ZipArchive（备份功能，可选）。

---

## 三、默认管理员账号

| 用户名     | 密码         | 角色  |
| ------- | ---------- | --- |
| `admin` | `admin123` | 管理员 |

> 首次部署请立即修改默认密码；登录连续失败 5 次将锁定 10 分钟。

---

## 四、功能与角色权限速查

| 功能                            | 游客 | 成员 | 干事 | 管理员 |
| ----------------------------- | -- | -- | -- | --- |
| 浏览对外公告 / 首页 / 更新日志            | ✅  | ✅  | ✅  | ✅   |
| 邀请码注册（需有效邀请码）                 | ✅  | —  | —  | —   |
| 登录 / 改密码 / 签到 / 换头像主题         | —  | ✅  | ✅  | ✅   |
| 浏览内部通知 / 标记已读                 | ❌  | ✅  | ✅  | ✅   |
| 项目资料下载 / 代码在线预览               | ❌  | ✅  | ✅  | ✅   |
| 作业上传 / 代码分享发布                 | ❌  | ✅  | ✅  | ✅   |
| 下载/管理作业 / 发布公告 / 项目 / 问卷      | ❌  | ❌  | ✅  | ✅   |
| 网盘改名/移动 / 私信群发                | ❌  | ❌  | ✅  | ✅   |
| 数据备份 / 操作日志 / 成员与邀请码管理 / 网站设置 | ❌  | ❌  | ❌  | ✅   |

---

## 五、使用指南

### 1. 邀请码注册

- 管理员 →「成员管理 → 邀请码」生成 8 位一次性邀请码（有效期 1/3/7/30 天）。
- 访客在登录页点「凭邀请码注册」，填写用户名（3-20 位字母数字下划线）、姓名、密码 + 图形验证码即可注册为成员。

### 2. 代码分享与在线预览

- 登录后「代码分享」页可直接贴代码（18 种语言，≤64KB / ≤2000 行），自动语法高亮 + 行号 + 一键复制；支持按内容搜索。
- 项目资料 / 作业 / 通知附件中的代码文件（c/cpp/py/js/php 等 21 种扩展名）在列表点「查看」即可在线预览，免下载。

### 3. 如何添加新工具（插件）

- 在 `web/tools/` 下新建任意 `.html` 或 `.php` 文件，系统自动扫描显示在「工具」页。
- 管理员也可在「后台管理 → 工具管理」上传（文件名仅允许英文字母、数字、点、下划线、连字符）。

### 4. 网站设置 / 成员管理

- 「后台管理 → 网站设置」：网站标题、社团名称、Logo、主色调（全站即时生效）、首页简介（Markdown）、默认背景图等。
- 「后台管理 → 成员管理」：新增 / 批量导入（`用户名,密码,角色,姓名`） / 改角色 / 重置密码 / 禁用 / 删除 / 邀请码管理。

### 5. 私信规则

- 普通成员只能发送给管理员/干事；管理员/干事可单发或按角色批量群发。
- 导航栏私信菜单显示未读数，每 30 秒自动轮询；收件人可删除收到的私信。

### 6. 签到与排行

- 每日签到一次，统计连续天数与累计天数，首页展示签到排行 Top10。

### 7. 数据备份与日志

- 「后台管理 → 数据备份」：一键将整个 `data/` 打包为 zip 下载（仅管理员）。
- 关键操作（登录、上传、删除、注册、备份等）自动写入 `data/json/logs.json`（保留 500 条），后台可查。

---

## 六、安全说明

- 密码 `password_hash()`（bcrypt）存储；会话登录后 `session_regenerate_id` 防固定；空闲 2 小时自动过期。
- 登录失败锁定：连续 5 次失败锁定 10 分钟；图形验证码 10 分钟时效、大小写不敏感。
- 全站表单 CSRF Token 校验；所有 AJAX 接口逐接口校验登录与角色。
- 上传：拒绝可执行脚本扩展名 + 随机重命名；图片严格校验真实格式；工具上传文件名白名单。
- 下载/预览统一入口鉴权 + `basename`/`realpath` 双重路径穿越防护（含 router.php）。
- Markdown 先全文转义再渲染，天然防 XSS；代码块语言标签白名单过滤。
- 关键操作留痕（logs.json），异常行为可追溯。

---

## 七、本地开发（如何开启 PHP）

### 方式一：双击 `start.bat`（推荐）

仓库根目录的 `start.bat` 会自动探测 `php.exe`（默认 `D:\php\php.exe`，找不到则退回 PATH 中的 `php`），
检测 OJ 所需的 `g++`，然后启动内置服务器并自动打开浏览器：

```
http://127.0.0.1:8080/
```

停止服务：在窗口按 `Ctrl+C`。

### 方式二：手动命令

```bat
rem Windows
D:\php\php.exe -S 127.0.0.1:8080 -t D:/website/web D:/website/web/router.php
```

```bash
# Linux / macOS / Git Bash
php -S 127.0.0.1:8080 -t /path/to/website/web /path/to/website/web/router.php
```

- `php -S` 是 PHP 内置的 **CLI 开发服务器**，无需安装 Apache / Nginx，PHP 5.4+ 自带。
- `-t` 指定网站根目录；最后一个参数是路由脚本，必须写**绝对路径**，否则报 `Failed opening required 'router.php'`。
- 若 PHP 不在 PATH：把 PHP 目录加入 PATH，或直接写完整路径（本仓库示例为 `D:\php\php.exe`）。
- 生产环境不要用它，改用 Nginx / Apache + PHP-FPM。

### OJ 自动评测的前提

OJ 判题需要 `g++`（Dev-C++ / MinGW / 系统包管理器安装的均可）在 PATH 中：

```
g++ --version
```

- 有 `g++`：提交后自动编译（`-O2 -std=c++14` / `-std=c11`）并逐测试点运行。
- 无 `g++`：提交自动降级为「待人工评测」，进入后台人工判题队列。

### 题库维护脚本

```bash
python tools/gen_oj_problems.py     # 重建 152 道基础题 / 真题条目（含测试数据）
python tools/fill_oj_archive.py     # 为真题条目补齐题面与测试数据（样例由参考解算出）
php    tools/oj_selftest.php        # 命令行验证评测引擎（不依赖网页登录）
```

- 数据、网盘、文档均在本仓库 `data/`、`drive/` 下，改动即时生效。

*（内容由AI生成，仅供参考）*

