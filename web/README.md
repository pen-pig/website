---
AIGC:
    Label: "1"
    ContentProducer: 001191440300708461136T1XGW3
    ProduceID: bc49c4ac6fb27cdef0692ccde63abaf1_6d792c3cb7db11f1a59e525400248c00
    ReservedCode1: 6kfeSn0JCdz5XHR7NhYeR15ZrnppoycOOu3SNEUyTP8wGzpKnATdCm1dMyIj+H4Cb0FKB1LKheDO3MP0X7W55IMWos9YgBMs9NMQDcBaov5CcND4GkzHS9AevCZxeslKfARF0Rp/hze74wLphoafB5q0cWVGicH7UMuU89T30eYp0NHT183nzdQdWvE=
    ContentPropagator: 001191440300708461136T1XGW3
    PropagateID: bc49c4ac6fb27cdef0692ccde63abaf1_6d792c3cb7db11f1a59e525400248c00
    ReservedCode2: 6kfeSn0JCdz5XHR7NhYeR15ZrnppoycOOu3SNEUyTP8wGzpKnATdCm1dMyIj+H4Cb0FKB1LKheDO3MP0X7W55IMWos9YgBMs9NMQDcBaov5CcND4GkzHS9AevCZxeslKfARF0Rp/hze74wLphoafB5q0cWVGicH7UMuU89T30eYp0NHT183nzdQdWvE=
---

# 社团官网系统（Club Website System）v1.1

基于 **PHP 5.6 + JSON 文件存储** 的社团官网系统，代码与数据完全分离，适配 Linux + Nginx + PHP 5.6 共享虚拟主机（不依赖 .htaccess）。

---

## 一、目录结构

```
F:\club\website\
├── web\                    # 网站代码（部署到公网根目录）
│   ├── index.php           # 入口页：首页分流 / 公告 / 通知 / 项目 / 问卷 / 工具 / 个人中心
│   ├── login.php           # 登录页（算术题验证码 + password_hash 校验）
│   ├── logout.php          # 退出登录
│   ├── download.php        # 统一文件下载入口（登录 + 角色鉴权 + 防路径穿越）
│   ├── messages.php        # 站内私信页面（收件箱/发件箱/对话/写私信）
│   ├── admin\              # 后台管理（7 页）
│   │   ├── index.php       # 后台仪表盘（管理员/干事）
│   │   ├── notices.php     # 通知管理（管理员/干事）
│   │   ├── projects.php    # 项目与资料管理（管理员/干事）
│   │   ├── surveys.php     # 问卷管理（管理员/干事）
│   │   ├── tools.php       # 工具管理（查看插件列表）
│   │   ├── members.php     # 成员管理（仅管理员）
│   │   └── settings.php    # 网站设置（仅管理员）
│   ├── api\                # AJAX 接口（逐接口校验登录/角色）
│   │   ├── auth.php        # 登录信息 / 修改密码
│   │   ├── notices.php     # 通知列表 / 详情 / 已读标记
│   │   ├── projects.php    # 项目列表 / 详情
│   │   ├── surveys.php     # 问卷列表 / 详情 / 投票
│   │   ├── messages.php    # 私信未读 / 列表 / 对话 / 发送 / 已读
│   │   └── upload.php      # 统一上传（管理员/干事）
│   ├── tools\              # 插件式工具（自动扫描 .html / .php）
│   │   └── base64.html     # 示例工具：Base64 编解码
│   ├── assets\             # 静态资源
│   │   ├── css\style.css   # 全局样式（主色调由 settings.json 动态注入）
│   │   └── js\main.js      # 全局脚本（私信未读 30 秒轮询等）
│   └── includes\           # 核心库
│       ├── config.php      # 全局配置 / 路径常量 / 会话 / 站点设置
│       ├── json_db.php     # JSON 读写封装（flock 文件锁 + 临时文件原子替换）
│       ├── auth.php        # 登录认证 / 角色权限校验
│       ├── markdown.php    # Markdown 安全解析（防 XSS）
│       └── functions.php   # 公共函数（转义 / CSRF / 验证码 / 上传 / 模板）
└── data\                   # 数据目录（放在 web/ 上一级，公网不可访问）
    ├── json\
    │   ├── users.json      # 成员账号（默认含 admin 管理员）
    │   ├── notices.json    # 公告 + 内部通知
    │   ├── projects.json   # 项目分支 / 小项目 / 资料 / 作业
    │   ├── surveys.json    # 问卷与投票结果
    │   ├── messages.json   # 站内私信
    │   └── settings.json   # 网站设置（主色调等）
    └── uploads\
        ├── materials\      # 项目资料
        ├── homework\       # 成员作业
        └── notices\        # 通知附件
```

---

## 二、部署说明

### 1. 上传与目录配置

1. 将 `web/` 目录整体上传到虚拟主机，**站点根目录指向 `web/`**（如 `/www/wwwroot/你的域名/`）。
2. 将 `data/` 目录上传到 **`web/` 的上一级**（如 `/www/wwwroot/data/`），保证公网无法直接访问。
   - 若无法放到上一级，也可将 `data/` 与 `web/` 放在同一目录，但务必在主机面板中为 `data/` 设置**禁止访问**。
3. 代码通过 `dirname(__FILE__)` 自动定位 `data/`，**无需修改任何配置文件**即可识别目录位置。

### 2. 目录权限

- `data/json/`：**可写**（755 或 775，视主机用户而定），用于读写 JSON 数据。
- `data/uploads/`：**可写**（755 或 775），用于保存上传文件。
- `web/`：**只读**即可（644 / 755），运行期不需要写入代码目录。
- `data/json/*.tmp`：JSON 写入时的临时文件，由系统自动清理，无需干预。

### 3. 环境要求

- PHP **5.6 及以上**（本系统完全兼容 5.6，未使用任何 7+ 语法）。
- 无需数据库（MySQL 等），数据全部存于 `data/json/`。
- 无需伪静态 / 重写规则，Nginx / Apache 默认配置即可运行。
- 无需 .htaccess（Nginx 环境不读取，系统也不依赖）。
- 前端依赖 jQuery 3.5 / Bootstrap 4.6，通过 BootCDN 加载，主机需能访问外网 CDN。

---

## 三、默认管理员账号

| 用户名 | 密码 | 角色 |
| --- | --- | --- |
| `admin` | `admin123` | 管理员 |

> 首次登录后请立即到「我的 → 修改密码」更换默认密码。

---

## 四、功能与角色权限速查

| 功能 | 游客 | 成员 | 干事 | 管理员 |
| --- | --- | --- | --- | --- |
| 浏览对外公告 / 首页 | ✅ | ✅ | ✅ | ✅ |
| 登录 / 修改密码 | ✅ | ✅ | ✅ | ✅ |
| 浏览内部通知 / 标记已读 | ❌ | ✅ | ✅ | ✅ |
| 项目资料下载 | ❌ | ✅ | ✅ | ✅ |
| 作业上传 | ❌ | ✅ | ✅ | ✅ |
| 下载/管理作业 | ❌ | ❌ | ✅ | ✅ |
| 发布公告 / 通知 / 项目管理 / 问卷管理 / 工具 | ❌ | ❌ | ✅ | ✅ |
| 成员管理 / 网站设置 | ❌ | ❌ | ❌ | ✅ |
| 发起问卷（投票） | ❌ | ✅ | ✅ | ✅ |

---

## 五、使用指南

### 1. 如何添加新工具（插件）

- 在 `web/tools/` 下新建任意 `.html` 或 `.php` 文件，系统会自动扫描并显示在「工具」页。
- 工具页以 iframe 方式嵌入，工具内可自由编写 HTML / JS / PHP。
- 参考示例：`web/tools/base64.html`。
- 删除文件即可下架工具，无需修改代码。

### 2. 如何修改网站设置

- 使用管理员账号登录 → 「后台管理 → 网站设置」。
- 可修改：网站标题、社团名称、Logo、**主色调（全站即时生效）**、首页简介（Markdown）、指导老师、联系方式。
- 设置保存在 `data/json/settings.json`，也可直接编辑该文件。

### 3. 如何管理成员

- 管理员登录 → 「后台管理 → 成员管理」。
- 支持：新增成员、批量导入（粘贴名单/CSV，格式 `用户名,密码,角色,姓名`）、改角色、重置密码、禁用/启用、删除。
- 角色取值：`admin`（管理员）/ `officer`（干事）/ `member`（成员）。

### 4. 私信规则

- 所有登录成员可互发私信，但**普通成员只能发送给管理员/干事**（后端强制拦截越权）。
- 管理员/干事可单发或批量群发（按角色全选）。
- 导航栏私信菜单显示未读数，页面打开后每 30 秒自动轮询更新。

### 5. 项目与资料

- 干事/管理员在「后台管理 → 项目管理」中维护：分支（如「人工智能」「Web 开发」）→ 小项目 → 项目详情（Markdown）。
- 每个项目可上传资料（成员可下载），成员可上传作业（仅干事/管理员可见与下载），支持将优秀作业设为展示。

### 6. 问卷投票

- 登录后任意成员可发起问卷；支持单选 / 多选 / 填空，可设匿名 / 实名、截止时间。
- 管理员/干事可在后台导出 CSV 结果。

---

## 六、数据备份建议

- 系统全部数据都在 `data/` 目录，备份时**只需备份 `data/`**（json + uploads）。
- 恢复时整目录还原即可，与代码目录无关。
- 建议定期打包下载 `data/` 到本地存档。

---

## 七、安全说明

- 密码使用 `password_hash()`（bcrypt）存储，不保存明文。
- 登录含算术题验证码 + CSRF Token 防护。
- 上传拒绝可执行脚本扩展名（php/phtml/php5 等）并随机重命名。
- 下载统一走 `download.php`，含登录鉴权、角色校验与路径穿越防护。
- Markdown 解析先转义全文再渲染，天然防 XSS。
- 所有 AJAX 接口逐接口校验登录与角色权限。
*（内容由AI生成，仅供参考）*
