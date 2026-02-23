<?php

namespace TypechoPlugin\Passkey;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Widget\Options;
use Typecho\Common;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Passkey 登录插件，
 * 为 Typecho 提供基于WebAuthn的无密码登录功能，支持FIDO2认证器和平台内置生物识别，提升网站安全性和用户体验。
 * 
 * @package Passkey
 * @author GARFIELDTOM
 * @version 1.0.4
 * @link https://www.garfieldtom.cool
 */
class Plugin implements PluginInterface
{
    /**
     * 插件版本号 - 用于资源缓存控制
     */
    const VERSION = '1.0.4';
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        // 检查必需的 PHP 扩展
        $requiredExtensions = array(
            'openssl' => 'OpenSSL 扩展（用于加密签名验证）',
            'json' => 'JSON 扩展（用于数据解析）',
            'session' => 'Session 扩展（用于会话管理）',
            'mbstring' => 'Mbstring 扩展（用于字符串处理）'
        );
        
        $missingExtensions = array();
        foreach ($requiredExtensions as $ext => $description) {
            if (!extension_loaded($ext)) {
                $missingExtensions[] = $ext . ' - ' . $description;
            }
        }
        
        if (!empty($missingExtensions)) {
            throw new \Typecho\Plugin\Exception(
                '缺少必需的 PHP 扩展，请安装以下扩展后重试：<br>' . 
                implode('<br>', $missingExtensions)
            );
        }
        
        // 检查 PHP 版本（要求 PHP 7.0+）
        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            throw new \Typecho\Plugin\Exception('此插件需要 PHP 7.0 或更高版本');
        }
        
        // 创建数据表
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $adapter = $db->getAdapterName();
        
        // 创建 passkey_credentials 表
        if ($adapter == 'Pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS " . $prefix . "passkey_credentials (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                credential_id TEXT NOT NULL,
                public_key TEXT NOT NULL,
                counter INTEGER DEFAULT 0,
                created_at INTEGER NOT NULL,
                last_used INTEGER DEFAULT NULL,
                UNIQUE(credential_id)
            )";
        } else if ($adapter == 'SQLite') {
            $sql = "CREATE TABLE IF NOT EXISTS " . $prefix . "passkey_credentials (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                credential_id TEXT NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                counter INTEGER DEFAULT 0,
                created_at INTEGER NOT NULL,
                last_used INTEGER DEFAULT NULL
            )";
        } else {
            // MySQL: 使用 VARCHAR 而不是 TEXT + 前缀索引，避免截断导致的冲突
            // credential_id 是 base64 编码的，通常 86-172 字符，使用 VARCHAR(512) 足够
            // 对于 utf8mb4，VARCHAR(512) = 512 * 4 = 2048 字节，在 MySQL 索引限制 (3072 字节) 之内
            $sql = "CREATE TABLE IF NOT EXISTS " . $prefix . "passkey_credentials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                credential_id VARCHAR(512) NOT NULL,
                public_key TEXT NOT NULL,
                counter INT DEFAULT 0,
                created_at INT NOT NULL,
                last_used INT DEFAULT NULL,
                UNIQUE KEY unique_credential (credential_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        
        try {
            $db->query($sql);
        } catch (\Exception $e) {
            throw new \Typecho\Plugin\Exception('创建凭证表失败: ' . $e->getMessage());
        }
        
        // 创建 passkey_login_logs 表
        if ($adapter == 'Pgsql') {
            $logSql = "CREATE TABLE IF NOT EXISTS " . $prefix . "passkey_login_logs (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                credential_id INTEGER NOT NULL,
                challenge TEXT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT,
                login_time INTEGER NOT NULL,
                status VARCHAR(20) DEFAULT 'success'
            )";
        } else if ($adapter == 'SQLite') {
            $logSql = "CREATE TABLE IF NOT EXISTS " . $prefix . "passkey_login_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                credential_id INTEGER NOT NULL,
                challenge TEXT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT,
                login_time INTEGER NOT NULL,
                status VARCHAR(20) DEFAULT 'success'
            )";
        } else {
            $logSql = "CREATE TABLE IF NOT EXISTS " . $prefix . "passkey_login_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        
        try {
            $db->query($logSql);
        } catch (\Exception $e) {
            throw new \Typecho\Plugin\Exception('创建登录日志表失败: ' . $e->getMessage());
        }
        
        // 升级数据表：检查并添加缺失的字段
        self::upgradeDatabase($db, $prefix, $adapter);
        
        // 注册路由
        \Utils\Helper::addRoute('passkey_action', '/action/passkey', 'TypechoPlugin\Passkey\Action', 'action');
        \Utils\Helper::addPanel(3, 'Passkey/Panel.php', 'Passkey 管理', '管理您的 Passkey', 'administrator');
        
        return '插件已激活，请到设置中配置';
    }
    
    /**
     * 升级数据库表结构
     */
    private static function upgradeDatabase($db, $prefix, $adapter)
    {
        try {
            // 1. 检查 passkey_credentials 表是否有 last_used 字段
            if ($adapter == 'Pgsql') {
                $checkSql = "SELECT column_name FROM information_schema.columns WHERE table_name='" . $prefix . "passkey_credentials' AND column_name='last_used'";
            } else if ($adapter == 'SQLite') {
                $checkSql = "PRAGMA table_info(" . $prefix . "passkey_credentials)";
            } else {
                $checkSql = "SHOW COLUMNS FROM " . $prefix . "passkey_credentials LIKE 'last_used'";
            }
            
            $result = $db->fetchAll($checkSql);
            
            if ($adapter == 'SQLite') {
                // SQLite 返回所有字段，需要检查是否包含 last_used
                $hasLastUsed = false;
                foreach ($result as $row) {
                    if (isset($row['name']) && $row['name'] == 'last_used') {
                        $hasLastUsed = true;
                        break;
                    }
                }
                if (!$hasLastUsed) {
                    $result = array();
                }
            }
            
            // 如果字段不存在，添加它
            if (empty($result)) {
                if ($adapter == 'Pgsql') {
                    $alterSql = "ALTER TABLE " . $prefix . "passkey_credentials ADD COLUMN last_used INTEGER DEFAULT NULL";
                } else if ($adapter == 'SQLite') {
                    $alterSql = "ALTER TABLE " . $prefix . "passkey_credentials ADD COLUMN last_used INTEGER DEFAULT NULL";
                } else {
                    $alterSql = "ALTER TABLE " . $prefix . "passkey_credentials ADD COLUMN last_used INT DEFAULT NULL";
                }
                $db->query($alterSql);
            }
            
            // 2. 检查并修复 credential_id 字段长度（仅 MySQL）
            if ($adapter != 'Pgsql' && $adapter != 'SQLite') {
                try {
                    $columnInfo = $db->fetchAll("SHOW FULL COLUMNS FROM " . $prefix . "passkey_credentials WHERE Field = 'credential_id'");
                    if (!empty($columnInfo)) {
                        $columnType = $columnInfo[0]['Type'];
                        // 如果是 varchar(1024)，修改为 varchar(512)
                        if (stripos($columnType, 'varchar(1024)') !== false) {
                            // 先检查现有数据是否都在 512 字符以内
                            $maxLength = $db->fetchRow($db->select('MAX(LENGTH(credential_id)) as max_len')
                                ->from($prefix . 'passkey_credentials'));
                            
                            $currentMaxLen = isset($maxLength['max_len']) ? (int)$maxLength['max_len'] : 0;
                            
                            if ($currentMaxLen <= 512) {
                                // 安全修改字段长度
                                $db->query("ALTER TABLE " . $prefix . "passkey_credentials MODIFY COLUMN credential_id VARCHAR(512) NOT NULL");
                                error_log('[Passkey][INFO] Successfully updated credential_id column length to VARCHAR(512)');
                            } else {
                                error_log('[Passkey][WARNING] credential_id column has data longer than 512 characters, skipping migration');
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log('[Passkey][ERROR] Failed to check/update credential_id column: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            // 升级失败不影响插件激活，只记录错误
            error_log('[Passkey][ERROR] Plugin upgrade failed: ' . $e->getMessage());
        }
    }
    
    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {        
        $options = Options::alloc();
        
        // 检查是否需要删除数据库
        try {
            $plugin = $options->plugin('Passkey');
            $removeData = isset($plugin->removeDataOnUninstall) && $plugin->removeDataOnUninstall == '1';
            
            if ($removeData) {
                // 删除数据表
                $db = \Typecho\Db::get();
                $prefix = $db->getPrefix();
                
                try {
                    // 删除凭证表
                    $db->query("DROP TABLE IF EXISTS " . $prefix . "passkey_credentials");
                    // 删除登录日志表
                    $db->query("DROP TABLE IF EXISTS " . $prefix . "passkey_login_logs");
                } catch (\Exception $e) {
                    error_log('[Passkey][ERROR] Failed to drop tables: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            // 如果配置不存在，忽略错误
        }
        
        \Utils\Helper::removeRoute('passkey_action');
        \Utils\Helper::removePanel(3, 'Passkey/Panel.php');
        
        return '插件已禁用';
    }
    
    /**
     * 获取插件配置面板
     */
    public static function config(Form $form)
    {
        $options = Options::alloc();
        $pluginUrl = $options->pluginUrl . '/Passkey';
        
        // 安全获取插件配置（激活时配置可能还不存在）
        $plugin = null;
        try {
            $plugin = $options->plugin('Passkey');
        } catch (\Exception $e) {
            // 插件配置不存在（首次激活时）
        }
        
        $modeDescription = '选择如何将 Passkey 登录添加到登录页面';
        
        // 根据模式显示不同的说明
        if ($plugin && isset($plugin->mode)) {
            $version = self::VERSION;
            $actionUrl = $options->index . '/action/passkey';
            
            if ($plugin->mode == 'auto') {
                // 自动模式说明
                $modeDescription .= '<div style="background:#f0f9ff;padding:15px;margin-top:10px;border-left:3px solid #0ea5e9;">';
                $modeDescription .= '<strong>自动注入模式</strong><br>';
                $modeDescription .= '<p style="margin:10px 0;color:#666;">插件会自动在 Typecho 登录页面注入 Passkey 登录按钮，无需修改任何代码。</p>';
                $modeDescription .= '<strong>实现方式：</strong><br>';
                $modeDescription .= '<ul style="margin:5px 0;padding-left:20px;color:#555;">';
                $modeDescription .= '<li>在主题 <code>header.php</code> 中调用 <code>$this->header()</code> 时自动注入 CSS 和 JS 资源</li>';
                $modeDescription .= '<li>JavaScript 检测登录表单并自动插入 Passkey 登录按钮</li>';
                $modeDescription .= '<li>支持多种主题结构，智能适配不同的表单布局</li>';
                $modeDescription .= '</ul>';
                $modeDescription .= '<p style="margin:10px 0;"><strong>注：</strong>如果自动注入在您的后台主题中不生效，请切换为"手动添加"模式。</p>';
                $modeDescription .= '</div>';
                
            } else if ($plugin->mode == 'manual') {
                // 手动模式说明
                $modeDescription .= '<div style="background:#f5f5f5;padding:15px;margin-top:10px;border-left:3px solid #467B96;">';
                $modeDescription .= '<strong>手动添加方法</strong><br>';
                $modeDescription .= '<p style="margin:10px 0;color:#666;">在您的主题登录页面中手动添加 Passkey 登录代码。</p>';
                $modeDescription .= '<strong>步骤：</strong><br>';
                $modeDescription .= '<ol style="margin:5px 0;padding-left:20px;color:#555;">';
                $modeDescription .= '<li>找到主题的登录模板文件（通常是 <code>/admin/login.php</code> 或 <code>page-login.php</code>）</li>';
                $modeDescription .= '<li>找到登录表单 <code>&lt;form&gt;...&lt;/form&gt;</code></li>';
                $modeDescription .= '<li>在表单结束标签 <code>&lt;/form&gt;</code> 后面添加以下代码</li>';
                $modeDescription .= '</ol>';
                $modeDescription .= '<textarea readonly onclick="this.select()" style="width:100%;height:220px;font-family:Consolas,Monaco,monospace;font-size:11px;margin-top:10px;padding:10px;border:1px solid #ddd;background:#fff;">';
                $modeDescription .= htmlspecialchars('<!-- Passkey 登录 -->
<link rel="stylesheet" href="' . $pluginUrl . '/assist/css/style.css?v=' . $version . '">
<script>var PASSKEY_ACTION_URL = "' . $actionUrl . '";</script>
<script src="' . $pluginUrl . '/assist/js/passkey.js?v=' . $version . '"></script>
<div id="passkey-login-container" style="margin-top: 20px;">
    <div style="text-align: center; margin-bottom: 10px;">
        <span style="color: #999;">或</span>
    </div>
    <button type="button" id="passkey-login-btn" class="btn primary" style="width: 100%;">
        使用 Passkey 登录
    </button>
</div>
<script>
document.addEventListener(\'DOMContentLoaded\', function() {
    var btn = document.getElementById(\'passkey-login-btn\');
    if (btn) {
        btn.addEventListener(\'click\', function() {
            PasskeyManager.login();
        });
    }
});
</script>');
                $modeDescription .= '</textarea>';
                $modeDescription .= '<p style="margin-top:10px;"><small style="color:#666;"><strong>提示：</strong>点击文本框自动全选，按 Ctrl+C 复制代码。代码已包含版本号 ?v=' . $version . '，更新插件后会自动使用新资源，无需修改代码。</small></p>';
                $modeDescription .= '</div>';
            }
        }
        
        $mode = new Radio(
            'mode',
            array(
                'auto' => '自动注入（推荐）',
                'manual' => '手动添加'
            ),
            'auto',
            '注入模式',
            $modeDescription
        );
        $form->addInput($mode);
        
        $rpName = new Text(
            'rpName',
            NULL,
            '我的网站',
            'Relying Party 名称',
            '这是显示给用户的名称'
        );
        $form->addInput($rpName);
        
        $rpId = new Text(
            'rpId',
            NULL,
            '',
            'Relying Party ID',
            '留空则自动使用当前域名，例如：example.com'
        );
        $form->addInput($rpId);
        
        // 检查全局注册设置
        $globalAllowRegister = $options->allowRegister ? true : false;
        
        $registerDescription = '启用后，未登录用户可以在登录页面使用 Passkey 创建新账户（无需输入用户名密码）。';
        $registerDescription .= '<br><br><div style="background:#fff3cd;padding:10px;margin-top:8px;border-left:3px solid #ffc107;">';
        $registerDescription .= '<strong>重要：</strong>此设置受 Typecho 全局注册设置控制。<br>';
        
        if ($globalAllowRegister) {
            $registerDescription .= '<span style="color:#155724;">√ 全局注册已开启</span>，此选项才能生效。<br>';
        } else {
            $registerDescription .= '<span style="color:#721c24;">× 全局注册已关闭</span>，即使此处启用也无法注册。<br>';
            $registerDescription .= '请先到 <strong>设置 → 基本 → 允许注册</strong> 中开启全局注册功能。';
        }
        
        $registerDescription .= '</div>';
        $registerDescription .= '<br><strong>注册流程说明：</strong><br>';
        $registerDescription .= '<ol style="margin:5px 0;padding-left:20px;color:#555;line-height:1.6;">';
        $registerDescription .= '<li>用户在登录页点击"使用 Passkey 登录"</li>';
        $registerDescription .= '<li>系统检测到该设备尚无凭证，提示是否创建新账户</li>';
        $registerDescription .= '<li>用户确认后，填写注册信息（用户名、邮箱、昵称）</li>';
        $registerDescription .= '<li>用户提交信息后，使用设备生物识别创建 Passkey 凭证</li>';
        $registerDescription .= '<li>系统创建账户并自动登录</li>';
        $registerDescription .= '</ol>';
        
        $enableRegister = new Radio(
            'enableRegister',
            array(
                '1' => '启用',
                '0' => '禁用'
            ),
            '0',
            '允许 Passkey 注册新用户',
            $registerDescription
        );
        $form->addInput($enableRegister);
        
        // 卸载时删除数据库选项
        $removeDataDescription = '选择在禁用插件时是否删除数据库中的所有 Passkey 数据。';
        $removeDataDescription .= '<br><br><div style="background:#fff3cd;padding:10px;margin-top:8px;border-left:3px solid #ffc107;">';
        $removeDataDescription .= '<strong>警告：</strong>如果选择“删除”，禁用插件时将永久删除以下数据：<br>';
        $removeDataDescription .= '<ul style="margin:5px 0;padding-left:20px;color:#721c24;line-height:1.6;">';
        $removeDataDescription .= '<li>所有用户的 Passkey 凭证</li>';
        $removeDataDescription .= '<li>所有 Passkey 登录日志</li>';
        $removeDataDescription .= '</ul>';
        $removeDataDescription .= '<strong>此操作不可恢复！</strong>请谨慎选择。';
        $removeDataDescription .= '</div>';
        $removeDataDescription .= '<br><strong>建议：</strong>如果您只是临时禁用插件，选择“保留”，以便之后重新启用时恢复数据。';
        
        $removeDataOnUninstall = new Radio(
            'removeDataOnUninstall',
            array(
                '0' => '保留数据（推荐）',
                '1' => '删除数据'
            ),
            '0',
            '禁用插件时的数据处理',
            $removeDataDescription
        );
        $form->addInput($removeDataOnUninstall);
    }
    
    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Form $form)
    {
        // 个人配置项（如果需要）
    }
    
    /**
     * 在后台头部注入资源
     */
    public static function header()
    {
        $options = Options::alloc();
        $pluginUrl = $options->pluginUrl . '/Passkey';
        $version = self::VERSION;
        
        echo '<link rel="stylesheet" href="' . $pluginUrl . '/assist/css/style.css?v=' . $version . '">';
        echo '<script>var PASSKEY_ACTION_URL = "' . $options->index . '/action/passkey";</script>';
    }
    
    /**
     * 在后台底部注入资源
     */
    public static function footer()
    {
        $options = Options::alloc();
        $pluginUrl = $options->pluginUrl . '/Passkey';
        $version = self::VERSION;
        
        echo '<script src="' . $pluginUrl . '/assist/js/passkey.js?v=' . $version . '"></script>';
    }
    
    /**
     * 在登录页面注入 Passkey 登录界面
     * 优化适配 Typecho 登录页面
     */
    public static function render()
    {
        $options = Options::alloc();
        
        // 安全获取插件配置
        $plugin = null;
        try {
            $plugin = $options->plugin('Passkey');
        } catch (\Exception $e) {
            // 插件配置不存在
            return;
        }
        
        // 仅在自动模式下注入
        if (!$plugin || !isset($plugin->mode) || $plugin->mode != 'auto') {
            return;
        }
        
        // 检查是否在登录页面
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        // 判断是否为登录页面
        $isLoginPage = strpos($requestUri, 'login.php') !== false || 
                       strpos($scriptName, 'login.php') !== false ||
                       (strpos($requestUri, '/admin/') !== false && !isset($_COOKIE['__typecho_uid']));
        
        if (!$isLoginPage) {
            return;
        }
        
        $pluginUrl = $options->pluginUrl . '/Passkey';
        $version = self::VERSION;
        ?>
        <!-- Passkey 自动注入 -->
        <link rel="stylesheet" href="<?php echo $pluginUrl; ?>/assist/css/style.css?v=<?php echo $version; ?>">
        <script>var PASSKEY_ACTION_URL = "<?php echo $options->index; ?>/action/passkey";</script>
        <script src="<?php echo $pluginUrl; ?>/assist/js/passkey.js?v=<?php echo $version; ?>"></script>
        <script>
        // 标记为自动注入模式，防止 passkey.js 重复注入
        window.PASSKEY_AUTO_INJECTED = true;
        </script>
        <div id="passkey-login-container" style="margin-top:15px;padding-top:15px;border-top:1px solid #e5e7eb;">
            <div style="text-align:center;margin-bottom:12px;color:#9ca3af;font-size:13px;">
                或使用 Passkey 登录
            </div>
            <button type="button" id="passkey-login-btn" class="btn btn-l w-100" 
                style="width:100%;padding:10px;font-size:14px;cursor:pointer;background:#4f46e5;color:white;border:1px solid #4338ca;border-radius:4px;transition:all 0.2s ease;">
                🔐 使用 Passkey 登录
            </button>
        </div>
        <script>
        (function() {
            // 等待 DOM 完全加载
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPasskeyButton);
            } else {
                initPasskeyButton();
            }
            
            function initPasskeyButton() {
                var passkeyBtn = document.getElementById('passkey-login-btn');
                if (passkeyBtn && typeof PasskeyManager !== 'undefined') {
                    // 添加悬停效果
                    passkeyBtn.onmouseover = function() {
                        this.style.background = '#4338ca';
                    };
                    passkeyBtn.onmouseout = function() {
                        this.style.background = '#4f46e5';
                    };
                    
                    // 点击事件
                    passkeyBtn.addEventListener('click', function() {
                        this.disabled = true;
                        this.innerHTML = '🔐 正在登录...';
                        PasskeyManager.login()
                            .finally(function() {
                                passkeyBtn.disabled = false;
                                passkeyBtn.innerHTML = '🔐 使用 Passkey 登录';
                            });
                    });
                }
            }
        })();
        </script>
        <?php
    }
}