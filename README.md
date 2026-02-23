# Passkey 登录插件 for Typecho

一个为 Typecho 博客系统提供 Passkey（WebAuthn）登录功能的插件，使用生物识别（指纹、面容）或设备 PIN 快速安全登录。支持登录历史审计、完整数据管理、企业级安全配置和优雅的网页内通知系统。

![Passkey Logo](https://img.shields.io/badge/Passkey-v1.0.3--rc3-007EC6?style=for-the-badge&logo=securityscorecard&logoColor=white)
![Typecho](https://img.shields.io/badge/Typecho-1.0+-orange?style=for-the-badge)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)
![WebAuthn](https://img.shields.io/badge/WebAuthn-FIDO2-4c1?style=for-the-badge)

参与讨论：https://forum.typecho.org/viewtopic.php?p=62488#p62488

技术细节：https://blog.csdn.net/qq_43011259/article/details/158292076

## 📸 截图预览

### 后台插件设置
![插件设置页面](screenshots/screenshot1.png)
*配置注入模式、RP 信息和注册选项*

### Passkey 管理界面
![Passkey 管理页面](screenshots/screenshot2.png)
*管理已绑定的凭证，查看登录历史记录*

### Passkey 登录界面
![Passkey 登录页面](screenshots/screenshot3.png)
*后台登录页面启用 Passkey 登录*

> 💡 **提示**：v1.0.2 新增登录记录功能，在管理界面可查看完整登录历史

## ✨ 功能特性

🔐 **Passkey 登录** - 使用生物识别（指纹、面容）或设备 PIN 快速登录  
⚙️ **后台管理** - 在 Typecho 后台管理和绑定 Passkey  
� **登录记录** - 仪表盘查询近期 Passkey 登录历史，掌握账户安全状况  
🚀 **自动注入** - 自动在登录页面添加 Passkey 登录选项  
🎯 **手动模式** - 支持手动控制登录按钮的显示位置  
📱 **多设备支持** - 可以绑定多个设备的 Passkey  
🛡️ **安全可靠** - 基于 FIDO2/WebAuthn 国际标准  
👤 **注册支持** - 允许新用户通过 Passkey 创建账户  
💬 **网页内通知** - 所有操作反馈使用优雅的网页内通知系统  
🖥️ **响应式设计** - 优化 PC 宽屏幕仪表盘显示效果  
🗑️ **完整卸载** - 移除插件时可选择删除所有数据，不留痕迹  
🔄 **版本控制** - 资源文件带版本号，避免缓存问题

## 🔐 工作原理

### 注册阶段
1. 用户在后台"Passkey 管理"页面添加凭证
2. 系统生成公私钥对，私钥存储在用户设备（TPM、安全芯片）
3. 公钥存储在服务器数据库

### 登录阶段
1. 用户点击"使用 Passkey 登录"
2. 浏览器调用设备认证（指纹/面容/PIN）
3. 使用存储的私钥签名挑战
4. 服务器验证签名后自动登录

### 注册流程（新用户）
1. 用户在登录页点击"使用 Passkey 登录"
2. 系统检测到该设备尚无凭证，提示是否创建新账户
3. 用户填写注册信息（用户名、邮箱、昵称）
4. 用户提交信息后，使用设备生物识别创建 Passkey 凭证
5. 系统创建账户并自动登录

## 📋 系统要求

### 服务器要求
- ✅ Typecho 1.0+
- ✅ PHP 7.0+
- ✅ MySQL 5.5+ / PostgreSQL 9.0+ / SQLite 3.0+
- ✅ HTTPS 环境（本地开发可使用 localhost）

### 浏览器要求
- Chrome 67+（2018年5月）
- Firefox 60+（2018年5月）
- Safari 13+（2019年9月）
- Edge 18+（2018年11月）

### 平台支持
- ✅ Windows 10 1903+ (Windows Hello)
- ✅ macOS (Touch ID / Face ID)
- ✅ iOS 14+ (Face ID / Touch ID)
- ✅ Android 7+ (指纹 / 面部识别)
- ✅ Linux (外部安全密钥)

## 📦 安装步骤

### 方法一：手动安装

1. **下载插件**
   ```bash
   cd /var/www/typecho/usr/plugins/
   # 上传或克隆 Passkey 文件夹
   ```

2. **设置权限**
   ```bash
   chmod -R 755 Passkey
   chown -R www-data:www-data Passkey
   ```

3. **目录结构确认**
   ```
   usr/plugins/Passkey/
   ├── Plugin.php
   ├── Action.php
   ├── Panel.php
   ├── LICENSE
   └── assist/
       ├── css/
       │   └── style.css
       └── js/
           └── passkey.js
   ```

4. **启用插件**
   - 登录 Typecho 后台
   - 进入「控制台」→「插件」
   - 找到 "Passkey" 插件，点击「启用」

5. **配置插件**
   - 点击「设置」进入配置页面
   - 根据需要配置各项选项

### 方法二：Git 克隆

```bash
cd /var/www/typecho/usr/plugins/
git clone https://github.com/little-gt/PLUGION-Passkey/Passkey.git
chmod -R 755 Passkey
```

## ⚙️ 插件配置

进入「控制台」→「插件」→「Passkey」→「设置」

### 1. 注入模式

#### 自动注入（推荐）✅
插件会自动在 Typecho 登录页面注入 Passkey 登录按钮，无需修改任何代码。

**实现方式：**
- 在主题 `header.php` 中调用 `$this->header()` 时自动注入 CSS 和 JS 资源
- JavaScript 检测登录表单并自动插入 Passkey 登录按钮
- 支持多种主题结构，智能适配不同的表单布局

> 💡 **注意**：如果自动注入在您的主题中不生效，请切换为"手动添加"模式

#### 手动添加 📝
需要在主题登录页面中手动添加 Passkey 登录代码。

**步骤：**
1. 找到主题的登录模板文件（通常是 `themes/你的主题/login.php` 或 `page-login.php`）
2. 找到登录表单 `<form>...</form>`
3. 在表单结束标签 `</form>` 后面添加以下代码：

```php
<!-- Passkey 登录 -->
<link rel="stylesheet" href="<?php echo $this->options->pluginUrl; ?>/Passkey/assist/css/style.css?v=1.0.1">
<script>var PASSKEY_ACTION_URL = "<?php echo $this->options->index; ?>/action/passkey";</script>
<script src="<?php echo $this->options->pluginUrl; ?>/Passkey/assist/js/passkey.js?v=1.0.1"></script>
<div id="passkey-login-container" style="margin-top: 20px;">
    <div style="text-align: center; margin-bottom: 10px;">
        <span style="color: #999;">或</span>
    </div>
    <button type="button" id="passkey-login-btn" class="btn primary" style="width: 100%;">
        使用 Passkey 登录
    </button>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('passkey-login-btn');
    if (btn) {
        btn.addEventListener('click', function() {
            PasskeyManager.login();
        });
    }
});
</script>
```

### 2. Relying Party 配置

**RP 名称**
- 显示给用户的网站名称，例如："我的博客"
- 这个名称会在用户注册 Passkey 时显示

**RP ID**
- 通常留空，插件会自动使用当前域名
- 如需指定，输入域名（不含协议和端口），例如："example.com"

### 3. 允许 Passkey 注册新用户

启用后，未登录用户可以在登录页面使用 Passkey 创建新账户（无需输入用户名密码）。

⚠️ **重要：此设置受 Typecho 全局注册设置控制**

- ✅ **全局注册已开启** - 此选项才能生效
- ❌ **全局注册已关闭** - 即使此处启用也无法注册

请先到「设置」→「基本」→「允许注册」中开启全局注册功能。

### 4. 卸载时删除数据

控制卸载插件时是否删除所有数据：

- ✅ **启用删除** - 卸载时自动删除所有 Passkey 凭证、登录记录和配置
- ❌ **禁用删除** - 卸载时保留数据，方便将来重新启用插件

⚠️ **注意：**
- 默认情况下，卸载不会删除数据（保护用户数据）
- 如需完全清理，请在卸载前勾选此选项
- 数据删除后无法恢复，请谨慎操作

## 📖 使用说明

### 后台管理 Passkey

1. **进入管理页面**
   - 登录 Typecho 后台
   - 在左侧菜单找到「Passkey 管理」

2. **添加新凭证**
   - 点击右上角「添加新凭证」按钮
   - 根据设备提示完成生物识别或 PIN 验证
   - 绑定成功后，该设备即可使用 Passkey 登录

3. **管理凭证**
   - 查看所有已绑定的 Passkey（ID、凭证标识符、创建时间）
   - 删除不再使用的 Passkey

4. **查看登录记录**
   - 在仪表盘查看近期 Passkey 登录历史
   - 查看每次登录的时间、设备信息、IP 地址
   - 及时发现异常登录行为

### 使用 Passkey 登录

1. **访问登录页面**
   - 访问 Typecho 登录页面
   - 看到「🔐 使用 Passkey 登录」按钮

2. **进行身份验证**
   - 点击按钮
   - 按照浏览器提示完成身份验证（指纹/面容/PIN）

3. **自动登录**
   - 验证成功后自动登录并跳转到后台

### 新用户注册

如果启用了 Passkey 注册功能：

1. **触发注册流程**
   - 点击「使用 Passkey 登录」
   - 系统检测到设备未注册，弹出注册表单

2. **填写注册信息**
   - 用户名（3-32位，字母/数字/下划线）
   - 邮箱（有效邮箱地址）
   - 昵称（可选，默认为用户名）

3. **创建凭证**
   - 提交信息后进行生物识别
   - 系统自动创建账户并登录

## 🔧 技术说明

### 数据库结构

插件自动创建 2 个数据表：

#### 1. 凭证表 `typecho_passkey_credentials`

```sql
CREATE TABLE typecho_passkey_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id TEXT NOT NULL,
    public_key TEXT NOT NULL,
    counter INT DEFAULT 0,
    created_at INT NOT NULL,
    UNIQUE KEY unique_credential (credential_id(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**字段说明：**
- `id` - 主键
- `user_id` - 关联的 Typecho 用户 ID
- `credential_id` - WebAuthn 凭证唯一标识符（Base64 编码）
- `public_key` - 公钥数据
- `counter` - 签名计数器（防重放攻击）
- `created_at` - 创建时间戳

#### 2. 登录记录表 `typecho_passkey_login_logs`

```sql
CREATE TABLE typecho_passkey_login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id INT NOT NULL,
    challenge TEXT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    login_time INT NOT NULL,
    status VARCHAR(20) DEFAULT 'success',
    INDEX idx_user_id (user_id),
    INDEX idx_login_time (login_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**字段说明：**
- `id` - 主键
- `user_id` - 关联的 Typecho 用户 ID
- `credential_id` - 使用的凭证 ID（外键关联 passkey_credentials.id）
- `challenge` - 本次登录使用的挑战值（用于审计）
- `ip_address` - 登录 IP 地址（支持 IPv4 和 IPv6）
- `user_agent` - 浏览器用户代理字符串
- `login_time` - 登录时间戳
- `status` - 登录状态（success/failed）

**数据库兼容性：**

插件自动检测数据库类型并使用对应的 SQL 语法，支持：
- ✅ **MySQL / MariaDB** - 使用 InnoDB 引擎，UTF-8MB4 字符集
- ✅ **PostgreSQL** - 使用 SERIAL 主键，TEXT 类型
- ✅ **SQLite** - 使用 AUTOINCREMENT，简化索引

**自动升级机制：**

插件激活时会自动检测并升级数据表结构：
- 检查 `last_used` 字段是否存在（v1.0.2 新增）
- 自动添加缺失的字段，不影响现有数据
- 升级失败不会阻止插件激活

### API 端点

通过 `/action/passkey` 访问，支持以下操作：

| 端点 | 方法 | 说明 | 登录要求 |
|------|------|------|----------|
| `?do=register-options` | GET/POST | 获取注册选项 | 否（支持新用户注册） |
| `?do=register-verify` | POST | 验证注册凭证 | 否 |
| `?do=login-options` | GET | 获取登录选项 | 否 |
| `?do=login-verify` | POST | 验证登录凭证 | 否 |
| `?do=list` | GET | 列出用户的凭证 | 是 |
| `?do=login-logs` | GET | 获取登录历史记录 | 是 |
| `?do=delete` | POST | 删除凭证 | 是 |

#### 详细的 API 说明

**1. 获取注册选项** `GET/POST /action/passkey?do=register-options`

已登录用户添加凭证或新用户注册时调用。

**请求体（新用户注册时）：**
```json
{
  "username": "myusername",
  "email": "user@example.com",
  "screenName": "My Display Name"
}
```

**响应：**
```json
{
  "success": true,
  "data": {
    "challenge": "base64_encoded_challenge",
    "rp": {
      "name": "My Website",
      "id": "example.com"
    },
    "user": {
      "id": "base64_encoded_user_id",
      "name": "username",
      "displayName": "Display Name"
    },
    "pubKeyCredParams": [
      {"type": "public-key", "alg": -7},
      {"type": "public-key", "alg": -257}
    ],
    "timeout": 60000,
    "attestation": "none"
  }
}
```

**2. 获取登录选项** `GET /action/passkey?do=login-options`

**响应：**
```json
{
  "success": true,
  "data": {
    "challenge": "base64_encoded_challenge",
    "timeout": 60000,
    "rpId": "example.com",
    "userVerification": "preferred"
  }
}
```

**3. 获取登录日志** `GET /action/passkey?do=login-logs&limit=20`

**参数：**
- `limit` - 返回记录数（1-100，默认 10）

**响应：**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "credential_id": "YWJjZGVm...",
      "ip_address": "192.168.1.1",
      "user_agent": "Chrome / Windows",
      "login_time": "2026-02-22 14:30:00",
      "status": "success"
    }
  ]
}
```

**4. 删除凭证** `POST /action/passkey?do=delete`

**请求体：**
```json
{
  "id": 1
}
```

**响应：**
```json
{
  "success": true,
  "data": {
    "message": "凭证已删除"
  }
}
```

### WebAuthn 认证流程

#### 注册流程（Registration）

```
客户端                     服务器                    认证器
  │                         │                         │
  ├──1. 请求注册选项────────>│                         │
  │                         ├──2. 生成 challenge      │
  │                         ├──3. 保存到 session      │
  │<──4. 返回 PublicKeyCredentialCreationOptions───┤
  │                         │                         │
  ├──5. navigator.credentials.create()──────────────>│
  │                         │                   6. 用户验证
  │                         │                   7. 生成密钥对
  │                         │                   8. 私钥存储在设备
  │<──9. 返回 attestation（包含公钥）─────────────────┤
  │                         │                         │
  ├──10. 发送 attestation──>│                         │
  │                         ├──11. 验证 challenge     │
  │                         ├──12. 存储公钥到数据库   │
  │<──13. 注册成功───────────┤                         │
```

**关键点：**
- Challenge 存储在 PHP Session 中，60 秒有效期
- 支持 ES256（-7）和 RS256（-257）算法
- 公钥以 Base64 编码存储在数据库
- 私钥永不离开用户设备

#### 登录流程（Authentication）

```
客户端                     服务器                    认证器
  │                         │                         │
  ├──1. 请求登录选项────────>│                         │
  │                         ├──2. 生成新 challenge    │
  │                         ├──3. 保存到 session      │
  │<──4. 返回 PublicKeyCredentialRequestOptions────┤
  │                         │                         │
  ├──5. navigator.credentials.get()─────────────────>│
  │                         │                   6. 用户验证
  │                         │                   7. 使用私钥签名
  │<──8. 返回签名数据─────────────────────────────────┤
  │                         │                         │
  ├──9. 发送签名───────────>│                         │
  │                         ├──10. 查找凭证记录       │
  │                         ├──11. 验证签名           │
  │                         ├──12. 验证 challenge     │
  │                         ├──13. 记录登录日志       │
  │                         ├──14. 创建登录会话       │
  │<──15. 登录成功───────────┤                         │
  │                         │                         │
  ├──16. 跳转到后台          │                         │
```

**安全机制：**
- 每次登录生成新的 challenge，防止重放攻击
- Challenge 绑定到 session，验证后立即销毁
- 签名使用设备私钥，服务器用公钥验证
- 完整记录登录日志（时间、IP、设备）

### 会话管理

**Session 数据结构：**

```php
// 注册阶段
$_SESSION['passkey_register_challenge'] = 'base64_challenge';
$_SESSION['passkey_register_user_id'] = 123; // 已登录用户
$_SESSION['passkey_register_is_new_user'] = false;

// 新用户注册
$_SESSION['passkey_register_is_new_user'] = true;
$_SESSION['passkey_register_username'] = 'newuser';
$_SESSION['passkey_register_email'] = 'user@example.com';
$_SESSION['passkey_register_screenname'] = 'New User';

// 登录阶段
$_SESSION['passkey_login_challenge'] = 'base64_challenge';
```

**会话安全：**
- Challenge 一次性使用，验证后立即删除
- Session 数据在服务器端存储，客户端无法篡改
- 支持 PHP 原生 Session 或自定义 Session 处理器

**登录持久化：**

```php
// 使用 Typecho 标准登录方法
$userWidget->simpleLogin($userId, $remember = false, $expire = 30天);

// 自动设置 Cookie：
// - __typecho_uid（用户 ID）
// - __typecho_authCode（验证码）
```

### 前端 JavaScript API

**PasskeyManager 对象：**

```javascript
// 检查浏览器支持
if (PasskeyManager.isSupported()) {
    console.log('浏览器支持 WebAuthn');
} else {
    console.log('浏览器不支持，需要升级');
}

// 注册 Passkey（后台管理页面）
PasskeyManager.register()
    .then(result => {
        console.log('注册成功', result);
        // result 包含服务器返回的数据
    })
    .catch(error => {
        console.error('注册失败', error.message);
        // 错误类型：NotAllowedError, InvalidStateError 等
    });

// 使用 Passkey 登录（登录页面）
PasskeyManager.login()
    .then(result => {
        console.log('登录成功', result);
        // 自动跳转到 result.redirect
        window.location.href = result.redirect;
    })
    .catch(error => {
        console.error('登录失败', error.message);
    });

// 显示网页内通知
PasskeyManager.showNotification('操作成功', 'success');
PasskeyManager.showNotification('操作失败', 'error');
PasskeyManager.showNotification('提示信息', 'info');
```

**通知系统：**

v1.0.2 引入优雅的网页内通知系统，替代浏览器原生 `alert()`：

```javascript
// 样式类型
- success: 绿色，成功操作
- error: 红色，错误信息
- info: 蓝色，提示信息

// 特性
- 自动定位到页面顶部
- 3 秒后自动消失
- 支持多条通知队列
- 响应式设计，移动端友好
```

### 插件架构

```
Passkey/
├── Plugin.php          # 主插件类
│   ├── activate()      # 激活：创建数据表、注册路由
│   ├── deactivate()    # 禁用：可选删除数据、移除路由
│   ├── config()        # 配置面板：注入模式、RP 配置等
│   ├── header()        # 注入 CSS 资源
│   ├── footer()        # 注入 JS 资源
│   └── render()        # 自动注入登录按钮
│
├── Action.php          # API 处理类
│   ├── registerOptions()   # 生成注册选项
│   ├── registerVerify()    # 验证注册
│   ├── loginOptions()      # 生成登录选项
│   ├── loginVerify()       # 验证登录
│   ├── listCredentials()   # 列出凭证
│   ├── deleteCredential()  # 删除凭证
│   ├── getLoginLogs()      # 获取日志
│   └── logLoginActivity()  # 记录日志
│
├── Panel.php           # 管理面板
│   ├── 凭证列表展示
│   ├── 添加新凭证
│   ├── 删除凭证
│   ├── 登录记录展示
│   └── 使用说明
│
└── assist/
    ├── css/
    │   └── style.css   # 样式文件
    │       ├── 登录按钮样式
    │       ├── 通知系统样式
    │       └── 管理面板样式
    └── js/
        └── passkey.js  # 核心 JavaScript
            ├── PasskeyManager 对象
            ├── WebAuthn API 封装
            ├── 通知系统
            └── 自动注入逻辑
```

### 自动注入机制

当配置为"自动注入"模式时：

1. **检测登录页面**
   ```php
   // Plugin.php render() 方法
   $requestUri = $_SERVER['REQUEST_URI'];
   $isLoginPage = strpos($requestUri, 'login.php') !== false;
   ```

2. **注入资源和 HTML**
   - CSS 样式表
   - JavaScript 库（PasskeyManager）
   - 登录按钮 HTML
   - 初始化脚本

3. **JavaScript 自动初始化**
   ```javascript
   // 等待 DOM 加载完成
   document.addEventListener('DOMContentLoaded', function() {
       // 查找登录表单
       var form = document.querySelector('form');
       
       // 绑定 Passkey 按钮事件
       var btn = document.getElementById('passkey-login-btn');
       btn.addEventListener('click', function() {
           PasskeyManager.login();
       });
   });
   ```

### 用户代理解析

登录日志中的 User Agent 会被智能解析：

```php
function parseUserAgent($ua) {
    // 检测浏览器
    if (strpos($ua, 'Edg')) return 'Edge';
    if (strpos($ua, 'Chrome')) return 'Chrome';
    if (strpos($ua, 'Safari')) return 'Safari';
    if (strpos($ua, 'Firefox')) return 'Firefox';
    
    // 检测操作系统
    if (strpos($ua, 'Windows')) return 'Windows';
    if (strpos($ua, 'Mac OS')) return 'macOS';
    if (strpos($ua, 'Android')) return 'Android';
    if (strpos($ua, 'iOS')) return 'iOS';
    
    return $browser . ' / ' . $os;
}
```

**示例输出：**
- Chrome / Windows
- Safari / macOS
- Firefox / Linux
- Edge / Android

### 版本号管理

插件使用版本号控制资源缓存：

```php
// Plugin.php
const VERSION = '1.0.2';

// 资源 URL 自动带版本号
css/style.css?v=1.0.2
js/passkey.js?v=1.0.2
```

更新插件后版本号会变化，浏览器自动加载新资源。

## 🛡️ 安全性说明

### FIDO2/WebAuthn 标准

- 私钥永不离开设备，存储在 TPM、安全芯片或操作系统密钥库
- 防钓鱼：浏览器自动验证域名，无法跨域使用
- 防重放：每次认证使用一次性 challenge
- 无密码：无需记忆密码，避免密码泄露

### 插件安全措施

- ✅ Challenge 验证（一次性使用）
- ✅ Session 存储（防止跨请求攻击）
- ✅ 签名计数器（防重放攻击）
- ✅ 用户名、邮箱验证（注册时）
- ✅ 重复检查（防止重复注册）
- ✅ 登录审计日志（完整记录登录历史）

### 部署建议

1. **必须使用 HTTPS**（生产环境）
2. 启用 CSP 头增强安全性
3. 定期备份数据库
4. 定期检查登录记录，及时发现异常登录行为
5. 保持插件更新

## ❓ 常见问题

### 1. 提示"不支持 WebAuthn"

**原因：**
- 未使用 HTTPS（生产环境要求）
- 浏览器版本过旧
- 浏览器隐私模式可能不支持

**解决方案：**
- 确保使用 HTTPS 或 localhost
- 更新浏览器到最新版本
- 退出隐私/无痕模式

### 2. Passkey 注册失败

**原因：**
- 设备不支持生物识别
- Windows Hello 未启用
- 浏览器权限被阻止

**解决方案：**
- 检查设备是否支持指纹/面容识别
- Windows 用户：设置 → 账户 → 登录选项 → Windows Hello
- 允许浏览器的权限请求弹窗

### 3. 登录页面没有 Passkey 按钮

**原因：**
- 未选择"自动注入"模式
- 主题结构不兼容
- JavaScript 加载失败

**解决方案：**
- 检查插件设置，确认选择了"自动注入"
- 查看浏览器控制台是否有错误
- 切换到"手动添加"模式，参考配置说明

### 4. 全局注册已关闭无法注册

**原因：**
- Typecho 全局注册设置关闭

**解决方案：**
- 进入「设置」→「基本」→「允许注册」
- 勾选"允许注册"复选框
- 保存设置后即可使用 Passkey 注册

### 5. 可以在多个设备上使用吗？

**答案：** 可以！

- 每个设备可以单独注册 Passkey
- 在后台"Passkey 管理"页面管理所有设备
- 建议至少绑定 2 个设备（主设备 + 备用）

### 6. 忘记密码还能登录吗？

**答案：** 可以！

- 如果已绑定 Passkey，即使忘记密码也可以通过 Passkey 登录
- 建议至少绑定一个可靠的设备作为备用

### 7. Passkey 比密码更安全吗？

**答案：** 是的，更安全！

- ✅ 防钓鱼（无法跨域使用）
- ✅ 防泄露（私钥不离开设备）
- ✅ 防暴力破解（生物识别）
- ✅ 防重放攻击（一次性 challenge）

### 8. 卸载插件会删除数据吗？

**答案：** 可以自由选择！

- 在卸载插件时，系统会询问是否删除所有数据
- ✅ **删除数据**：移除所有凭证、登录记录和配置（完全卸载）
- ❌ **保留数据**：仅停用插件，数据保留（方便重新启用）
- 建议：测试环境选择删除，生产环境谨慎选择

### 9. 如何查看我的登录历史？

**答案：** 在仪表盘查看！

1. 进入「Passkey 管理」页面
2. 在仪表盘可以看到最近的登录记录
3. 记录包含：登录时间、IP 地址、设备信息
4. 如有异常登录，请及时删除相关凭证并修改密码

## 🐛 故障排查

### 启用调试模式

编辑 `config.inc.php`：

```php
/** 开启调试模式 */
define('__TYPECHO_DEBUG__', true);
```

### 查看浏览器控制台

按 F12 打开开发者工具：

```javascript
// 检查支持
console.log('WebAuthn 支持:', PasskeyManager.isSupported());

// 查看详细错误
PasskeyManager.login().catch(error => {
    console.error('错误名称:', error.name);
    console.error('错误信息:', error.message);
});
```

### 常见错误代码

| 错误 | 说明 | 解决方案 |
|------|------|----------|
| `NotAllowedError` | 用户取消或超时 | 重新尝试，不要取消弹窗 |
| `InvalidStateError` | 设备未注册 | 先在后台添加 Passkey |
| `NotSupportedError` | 设备不支持 | 更换支持的设备或浏览器 |
| `SecurityError` | 安全上下文错误 | 使用 HTTPS 或 localhost |

### 检查数据表

```sql
-- 查看数据表
SHOW TABLES LIKE '%passkey%';

-- 查看凭证数据
SELECT * FROM typecho_passkey_credentials;

-- 查看登录记录
SELECT * FROM typecho_passkey_login_logs ORDER BY login_time DESC LIMIT 10;

-- 检查表结构
DESC typecho_passkey_credentials;
DESC typecho_passkey_login_logs;
```

## 📜 更新日志

### v2.0.0 (2026-02-23)

**重大更新 - 企业级安全增强：**
- 🔐 完整的 WebAuthn 验证：实现服务器端签名验证（ES256、RS256），使用 PHP OpenSSL
- 🛡️ 安全配置管理：插件管理界面新增 6 项安全配置，支持可视化调整
- ⚙️ 可配置安全参数：Challenge 超时、注册/登录频率限制、严格模式开关
- 📊 智能速率限制：基于 Session 的防暴力破解机制，独立计数
- 🔍 签名计数器验证：防止克隆的认证器攻击（Clone Detection）
- 📏 数据长度限制：防止恶意超大数据导致的 DoS 攻击
- 🔒 CBOR 安全解析：最大嵌套深度 10 层，数组/对象最大 1000 元素
- 🌐 Origin 严格验证：支持宽松模式（开发）和严格模式（生产）
- 📝 安全日志系统：记录所有验证失败事件，便于审计

**浏览器兼容性增强：**
- 🔧 智能浏览器检测：自动识别 Chrome、Firefox、Safari、Edge 及版本
- 🍎 Safari 适配：Safari < 14 自动跳过不支持的 `authenticatorAttachment` 选项
- 🦊 Firefox 版本检查：拒绝 Firefox < 60，显示友好升级提示
- 🎯 条件特性支持：根据浏览器版本动态调整 WebAuthn 选项
- 💬 浏览器特定错误提示：根据不同浏览器显示针对性的错误信息

**安全文档与测试：**
- 📚 新增 `SECURITY.md`：详细的安全机制说明和配置最佳实践
- ✅ 新增 `TESTING.md`：完整的安全测试清单和测试用例
- 📖 配置文档：开发环境、标准生产、高安全生产三套推荐配置

**默认配置（推荐）：**
```
Challenge 超时: 300 秒
注册频率限制: 5 次/300 秒
登录频率限制: 10 次/300 秒
严格计数器: 警告模式（开发友好）
严格 Origin: 宽松模式（支持 localhost）
安全日志: 启用
```

### v1.0.2 (2026-02-22)

**新增功能：**
- ✨ 登录历史记录：仪表盘支持查询近期 Passkey 登录记录
- ✨ 完整卸载支持：移除插件时可选择删除所有数据（凭证、登录记录、配置）
- ✨ 网页内通知系统：所有操作反馈使用优雅的网页内通知，替代浏览器原生 alert

**改进优化：**
- 🎨 响应式优化：PC 宽屏幕下仪表盘显示效果更佳，布局更合理
- 📊 数据展示：登录记录展示时间、IP 地址、设备信息
- 🗂️ 数据管理：新增登录日志表，支持索引查询
- 💾 数据隔离：卸载时可保留或删除数据，用户自主选择

**安全增强：**
- 🔒 登录审计：完整记录每次 Passkey 登录，便于安全审查
- 👁️ 异常检测：用户可查看登录历史，及时发现异常行为

### v1.0.1

**新增功能：**
- ✨ 支持新用户通过 Passkey 注册账户
- ✨ 注册表单弹窗（用户名、邮箱、昵称）
- ✨ 全局注册设置优先级控制
- ✨ 详细的工作原理说明
- ✨ 版本号管理，避免缓存问题

**改进优化：**
- 🎨 专业企业级管理界面设计
- 📝 完善的配置页面说明（自动/手动模式）
- 🔒 增强的输入验证（用户名、邮箱格式）
- 💡 友好的错误提示信息

**bug 修复：**
- 🐛 修复配置不存在时的异常错误
- 🐛 修复 Session 数据丢失问题

### v1.0.0

- 🎉 初始版本发布
- 支持 Passkey 注册和登录
- 后台管理界面
- 自动/手动注入模式
- 多设备支持

## 🔗 参考资源

- [WebAuthn 规范](https://www.w3.org/TR/webauthn-2/)
- [Web Authentication API (MDN)](https://developer.mozilla.org/en-US/docs/Web/API/Web_Authentication_API)
- [FIDO Alliance](https://fidoalliance.org/)
- [Typecho 官网](https://typecho.org/)
- [Can I Use: WebAuthn](https://caniuse.com/webauthn)

## 📄 许可证

MIT License - 详见 [LICENSE](LICENSE) 文件

## 💖 支持与反馈

如有问题或建议：
- 提交 [Issue](https://github.com/little-gt/PLUGION-Passkey/issues)
- 发送邮件：coolerxde@gt.ac.cn

---

**Made with ❤️ by AI little-gt**