# Passkey 登录插件 for Typecho

一个为 Typecho 博客系统提供 Passkey（WebAuthn）登录功能的插件，使用生物识别（指纹、面容）或设备 PIN 快速安全登录。

[![GitHub](https://img.shields.io/badge/GitHub-Passkey-blue?style=flat-square)](https://github.com/little-gt/PLUGION-Passkey/)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)
[![Typecho](https://img.shields.io/badge/Typecho-1.3.0-orange?style=flat-square)](https://typecho.org/)

## 📸 截图预览

### 后台插件设置
![插件设置页面](screenshots/screenshot1.png)
*配置注入模式、RP 信息和注册选项*

### Passkey 管理界面
![Passkey 管理页面](screenshots/screenshot2.png)
*管理所有已绑定的 Passkey 凭证*

> 💡 **提示**：请在 `screenshots/` 目录下添加 `settings.png` 和 `panel.png` 两张截图

## ✨ 功能特性

🔐 **Passkey 登录** - 使用生物识别（指纹、面容）或设备 PIN 快速登录  
⚙️ **后台管理** - 在 Typecho 后台管理和绑定 Passkey  
🚀 **自动注入** - 自动在登录页面添加 Passkey 登录选项  
🎯 **手动模式** - 支持手动控制登录按钮的显示位置  
📱 **多设备支持** - 可以绑定多个设备的 Passkey  
🛡️ **安全可靠** - 基于 FIDO2/WebAuthn 国际标准  
👤 **注册支持** - 允许新用户通过 Passkey 创建账户  
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
   ├── README.md
   ├── INSTALL.md
   ├── MANUAL.md
   ├── DEVELOPMENT.md
   ├── CHANGELOG.md
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

插件自动创建 `typecho_passkey_credentials` 表：

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

### API 端点

通过 `/action/passkey` 访问，支持以下操作：

| 端点 | 方法 | 说明 | 登录要求 |
|------|------|------|----------|
| `?do=register-options` | GET/POST | 获取注册选项 | 否（支持新用户注册） |
| `?do=register-verify` | POST | 验证注册凭证 | 否 |
| `?do=login-options` | GET | 获取登录选项 | 否 |
| `?do=login-verify` | POST | 验证登录凭证 | 否 |
| `?do=list` | GET | 列出用户的凭证 | 是 |
| `?do=delete` | POST | 删除凭证 | 是 |

### 前端 JavaScript API

```javascript
// 检查浏览器支持
if (PasskeyManager.isSupported()) {
    console.log('浏览器支持 WebAuthn');
}

// 注册 Passkey（后台管理页面）
PasskeyManager.register()
    .then(result => {
        console.log('注册成功', result);
    })
    .catch(error => {
        console.error('注册失败', error.message);
    });

// 使用 Passkey 登录
PasskeyManager.login()
    .then(result => {
        console.log('登录成功', result);
        // 自动跳转到后台
    })
    .catch(error => {
        console.error('登录失败', error.message);
    });
```

### 版本号管理

插件使用版本号控制资源缓存：

```php
// Plugin.php
const VERSION = '1.0.1';

// 资源 URL 自动带版本号
css/style.css?v=1.0.1
js/passkey.js?v=1.0.1
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

### 部署建议

1. **必须使用 HTTPS**（生产环境）
2. 启用 CSP 头增强安全性
3. 定期备份数据库
4. 监控异常登录行为
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

-- 检查表结构
DESC typecho_passkey_credentials;
```

## 📜 更新日志

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