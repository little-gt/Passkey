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
 * @version 1.2.0
 * @link https://www.garfieldtom.cool
 */
class Plugin implements PluginInterface
{
    /**
     * 插件版本号 - 用于资源缓存控制
     */
    const VERSION = '1.2.0';
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
        if (false !== stristr($adapter, 'Pgsql')) {
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
        } else if (false !== stristr($adapter, 'SQLite')) {
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
        if (false !== stristr($adapter, 'Pgsql')) {
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
        } else if (false !== stristr($adapter, 'SQLite')) {
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
            if (false !== stristr($adapter, 'Pgsql')) {
                $checkSql = "SELECT column_name FROM information_schema.columns WHERE table_name='" . $prefix . "passkey_credentials' AND column_name='last_used'";
            } else if (false !== stristr($adapter, 'SQLite')) {
                $checkSql = "PRAGMA table_info(" . $prefix . "passkey_credentials)";
            } else {
                $checkSql = "SHOW COLUMNS FROM " . $prefix . "passkey_credentials LIKE 'last_used'";
            }
            
            $result = $db->fetchAll($checkSql);
            
            if (false !== stristr($adapter, 'SQLite')) {
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
                if (false !== stristr($adapter, 'Pgsql')) {
                    $alterSql = "ALTER TABLE " . $prefix . "passkey_credentials ADD COLUMN last_used INTEGER DEFAULT NULL";
                } else if (false !== stristr($adapter, 'SQLite')) {
                    $alterSql = "ALTER TABLE " . $prefix . "passkey_credentials ADD COLUMN last_used INTEGER DEFAULT NULL";
                } else {
                    $alterSql = "ALTER TABLE " . $prefix . "passkey_credentials ADD COLUMN last_used INT DEFAULT NULL";
                }
                $db->query($alterSql);
            }
            
            // 2. 检查并修复 credential_id 字段长度（仅 MySQL）
            if (false === stristr($adapter, 'Pgsql') && false === stristr($adapter, 'SQLite')) {
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
        
        $modeDescription .= '<style>
        /* Passkey 插件 - 基础样式与暗色模式适配 */
        .pk-info-box--blue { background: #f0f9ff; border-left: 3px solid #0ea5e9; }
        .pk-info-box--gray { background: #f5f5f5; border-left: 3px solid #467B96; }
        .pk-info-box--yellow { background: #fff3cd; border-left: 3px solid #ffc107; }
        .pk-text-muted { color: #666; }
        .pk-text-dim { color: #555; }
        .pk-text-success { color: #155724; }
        .pk-text-danger { color: #721c24; }
        .pk-textarea-code { border: 1px solid #ddd; background: #fff; color: inherit; }
        .pk-warn-danger { color: #dc2626; }
        @media (prefers-color-scheme: dark) {
            .pk-info-box--blue { background: #0c2d48; border-left-color: #0ea5e9; }
            .pk-info-box--gray { background: #1a1a2e; border-left-color: #6ba3be; }
            .pk-info-box--yellow { background: #332b00; border-left-color: #fbbf24; }
            .pk-text-muted { color: #9ca3af; }
            .pk-text-dim { color: #a3a3a3; }
            .pk-text-success { color: #4ade80; }
            .pk-text-danger { color: #f87171; }
            .pk-textarea-code { border-color: #444; background: #1e1e1e; color: #e5e5e5; }
            .pk-warn-danger { color: #f87171; }
        }
        </style>';
        
        // 根据模式显示不同的说明
        if ($plugin && isset($plugin->mode)) {
            $version = self::VERSION;
            $actionUrl = $options->index . '/action/passkey';
            
            if ($plugin->mode == 'auto') {
                // 自动模式说明
                $modeDescription .= '<div class="pk-info-box--blue" style="padding:15px;margin-top:10px;">';
                $modeDescription .= '<strong>自动注入模式</strong><br>';
                $modeDescription .= '<p style="margin:10px 0;" class="pk-text-muted">插件会自动在 Typecho 登录页面注入 Passkey 登录按钮，无需修改任何代码。</p>';
                $modeDescription .= '<strong>实现方式：</strong><br>';
                $modeDescription .= '<ul style="margin:5px 0;padding-left:20px;" class="pk-text-dim">';
                $modeDescription .= '<li>在主题 <code>header.php</code> 中调用 <code>$this->header()</code> 时自动注入 CSS 和 JS 资源</li>';
                $modeDescription .= '<li>JavaScript 检测登录表单并自动插入 Passkey 登录按钮</li>';
                $modeDescription .= '<li>支持多种主题结构，智能适配不同的表单布局</li>';
                $modeDescription .= '</ul>';
                $modeDescription .= '<p style="margin:10px 0;"><strong>注：</strong>如果自动注入在您的后台主题中不生效，请切换为"手动添加"模式。</p>';
                $modeDescription .= '</div>';
                
            } else if ($plugin->mode == 'manual') {
                // 手动模式说明
                $modeDescription .= '<div class="pk-info-box--gray" style="padding:15px;margin-top:10px;">';
                $modeDescription .= '<strong>手动添加方法</strong><br>';
                $modeDescription .= '<p style="margin:10px 0;" class="pk-text-muted">在您的主题登录页面中手动添加 Passkey 登录代码。</p>';
                $modeDescription .= '<strong>步骤：</strong><br>';
                $modeDescription .= '<ol style="margin:5px 0;padding-left:20px;" class="pk-text-dim">';
                $modeDescription .= '<li>找到主题的登录模板文件（通常是 <code>/admin/login.php</code> 或 <code>page-login.php</code>）</li>';
                $modeDescription .= '<li>找到登录表单 <code>&lt;form&gt;...&lt;/form&gt;</code></li>';
                $modeDescription .= '<li>在表单结束标签 <code>&lt;/form&gt;</code> 后面添加以下代码</li>';
                $modeDescription .= '</ol>';
                $modeDescription .= '<textarea readonly onclick="this.select()" class="pk-textarea-code" style="width:100%;height:220px;font-family:Consolas,Monaco,monospace;font-size:11px;margin-top:10px;padding:10px;">';
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
                $modeDescription .= '<p style="margin-top:10px;"><small class="pk-text-muted"><strong>提示：</strong>点击文本框自动全选，按 Ctrl+C 复制代码。代码已包含版本号 ?v=' . $version . '，更新插件后会自动使用新资源，无需修改代码。</small></p>';
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
            $options->title,
            'Relying Party 名称',
            '这是显示给用户的名称'
        );
        $form->addInput($rpName);
        
        // 从站点地址中提取域名（去除协议和路径）
        $siteHost = parse_url($options->siteUrl, PHP_URL_HOST) ?: 'localhost';
        
        $rpId = new Text(
            'rpId',
            NULL,
            $siteHost,
            'Relying Party ID',
            '默认自动从站点地址提取域名（仅域名部分，不含协议和路径），例如：example.com'
        );
        $form->addInput($rpId);
        
        // 检查全局注册设置
        $globalAllowRegister = $options->allowRegister ? true : false;
        
        $registerDescription = '启用后，未登录用户可以在登录页面使用 Passkey 创建新账户（无需输入用户名密码）。';
        $registerDescription .= '<br><br><div class="pk-info-box--yellow" style="padding:10px;margin-top:8px;">';
        $registerDescription .= '<strong>重要：</strong>此设置受 Typecho 全局注册设置控制。<br>';
        
        if ($globalAllowRegister) {
            $registerDescription .= '<span class="pk-text-success">√ 全局注册已开启</span>，此选项才能生效。<br>';
        } else {
            $registerDescription .= '<span class="pk-text-danger">× 全局注册已关闭</span>，即使此处启用也无法注册。<br>';
            $registerDescription .= '请先到 <strong>设置 → 基本 → 允许注册</strong> 中开启全局注册功能。';
        }
        
        $registerDescription .= '</div>';
        $registerDescription .= '<br><strong>注册流程说明：</strong><br>';
        $registerDescription .= '<ol style="margin:5px 0;padding-left:20px;line-height:1.6;" class="pk-text-dim">';
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
        $removeDataDescription .= '<br><br><div class="pk-info-box--yellow" style="padding:10px;margin-top:8px;">';
        $removeDataDescription .= '<strong>警告：</strong>如果选择"删除"，禁用插件时将永久删除以下数据：<br>';
        $removeDataDescription .= '<ul style="margin:5px 0;padding-left:20px;line-height:1.6;" class="pk-text-danger">';
        $removeDataDescription .= '<li>所有用户的 Passkey 凭证</li>';
        $removeDataDescription .= '<li>所有 Passkey 登录日志</li>';
        $removeDataDescription .= '</ul>';
        $removeDataDescription .= '<strong>此操作不可恢复！</strong>请谨慎选择。';
        $removeDataDescription .= '</div>';
        $removeDataDescription .= '<br><strong>建议：</strong>如果您只是临时禁用插件，选择"保留"，以便之后重新启用时恢复数据。';
        
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
        
        // 认证器类型限制配置
        // 控制是否仅允许平台内置验证器（如 Windows Hello、Touch ID）
        // 关闭此选项可支持 Bitwarden、1Password、YubiKey 等第三方跨平台验证器
        $authenticatorDescription = '选择注册 Passkey 时允许使用的认证器类型。<br><br>';
        $authenticatorDescription .= '<div class="pk-info-box--blue" style="padding:10px;margin-top:8px;">';
        $authenticatorDescription .= '<strong>说明：</strong><br>';
        $authenticatorDescription .= '<ul style="margin:5px 0;padding-left:20px;line-height:1.6;" class="pk-text-dim">';
        $authenticatorDescription .= '<li><strong>仅限平台验证器</strong>：只允许设备内置认证器（Windows Hello、Touch ID、Face ID），安全性最高</li>';
        $authenticatorDescription .= '<li><strong>允许所有验证器</strong>：同时支持第三方密码管理器的 Passkey 功能（Bitwarden、1Password 等）</li>';
        $authenticatorDescription .= '</ul>';
        $authenticatorDescription .= '<p style="margin-top:8px;" class="pk-text-muted"><strong>注意：</strong>在<strong>严格模式</strong>下，无论此处如何设置都将强制使用"仅平台验证器"。</p>';
        $authenticatorDescription .= '</div>';
        
        // 获取当前认证器设置（默认为允许所有验证器，即不限制平台）
        $currentAuthMode = ($plugin && isset($plugin->platformOnly)) ? $plugin->platformOnly : '0';
        
        $platformOnly = new Radio(
            'platformOnly',
            array(
                '0' => '允许所有验证器（推荐）',
                '1' => '仅允许平台验证器'
            ),
            $currentAuthMode,
            '认证器类型限制',
            $authenticatorDescription
        );
        $form->addInput($platformOnly);
        
        // 安全模式配置
        self::addSecurityConfig($form, $plugin);
        
        // IP 地址获取策略配置
        self::addIpSourceConfig($form, $plugin);
    }
    
    /**
     * 添加安全配置选项
     */
    private static function addSecurityConfig($form, $plugin)
    {
        // 定义三种预设模式
        $presets = self::getSecurityPresets();
        
        // 获取当前配置的安全模式
        $storedMode = ($plugin && isset($plugin->securityMode)) ? $plugin->securityMode : 'normal';
        
        // 检测当前参数是否匹配预设模式
        $currentMode = '';
        if ($plugin) {
            // 检查每个预设模式
            foreach ($presets as $mode => $params) {
                $isMatch = true;
                foreach ($params as $key => $value) {
                    $currentValue = isset($plugin->$key) ? $plugin->$key : null;
                    if ($currentValue != $value) {
                        $isMatch = false;
                        break;
                    }
                }
                if ($isMatch) {
                    $currentMode = $mode;
                    break;
                }
            }
            // 如果没有匹配到任何预设，但有存储的模式或参数被修改过，说明是自定义模式
            if (!$currentMode && $storedMode) {
                $currentMode = 'custom';  // 自定义模式：参数不匹配任何预设
            }
        }
        
        // 如果是首次使用，默认选择 normal
        if (!$plugin) {
            $currentMode = 'normal';
        }
        
        // 安全模式说明 - 动态切换
        $securityModeDesc = '<style>
            .security-mode-info { margin: 15px 0; padding: 15px; border-left: 3px solid #467b96; background: #f8fafc; }
            .security-mode-info h4 { margin: 0 0 10px 0; color: #1f2937; font-size: 14px; font-weight: 600; }
            .security-mode-info p { margin: 5px 0; color: #6b7280; font-size: 13px; line-height: 1.6; }
            .security-mode-info ul { margin: 8px 0; padding-left: 20px; color: #6b7280; font-size: 13px; }
            .security-mode-info li { margin: 4px 0; }
            .security-mode-detail { display: none; margin-top: 15px; padding: 12px; background: #f9fafb; }
            .security-mode-detail.active { display: block; }
            .security-param-info { display: inline-block; margin-left: 5px; cursor: help; }
            .security-param-info svg { width: 16px; height: 16px; vertical-align: middle; }
            .security-param-tooltip { display: none; position: absolute; background: #1f2937; color: #fff; padding: 12px; font-size: 12px; line-height: 1.5; max-width: 400px; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
            .security-param-tooltip.show { display: block; }
            .security-reset-btn { margin-top: 10px; padding: 8px 16px; background: #467b96; color: #fff; border: none; cursor: pointer; font-size: 13px; transition: background 0.2s; }
            .security-reset-btn:hover { background: #3a6378; }
            .security-custom-params { margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #e5e7eb; }
            .security-custom-params h4 { margin: 0 0 10px 0; font-size: 14px; font-weight: 600; }
            .security-custom-params .param-group { margin: 10px 0; }
            .security-custom-params label { display: inline-block; min-width: 250px; color: #374151; font-size: 13px; }
            .security-custom-params input { padding: 6px 10px; border: 1px solid #d1d5db; width: 150px; font-size: 13px; }
            .security-custom-desc { color: #6b7280; }
            @media (prefers-color-scheme: dark) {
                .security-mode-info { background: #1e1e1e; border-left-color: #5a8fa8; }
                .security-mode-info h4 { color: #e5e5e5; }
                .security-mode-info p { color: #9ca3af; }
                .security-mode-info ul { color: #9ca3af; }
                .security-mode-detail { background: #252525; }
                .security-param-tooltip { background: #374151; box-shadow: 0 4px 12px rgba(0,0,0,0.4); }
                .security-reset-btn { background: #3a6378; }
                .security-reset-btn:hover { background: #2d5060; }
                .security-custom-params { background: #1e1e1e; border-color: #333; }
                .security-custom-params label { color: #d1d5db; }
                .security-custom-params input { border-color: #444; background: #2a2a2a; color: #e5e5e5; }
                .security-custom-desc { color: #9ca3af; }
            }
        </style>
        
        <div class="security-mode-info">
            <h4>安全模式说明</h4>
            <p>选择适合您网站的安全级别，系统将自动配置相关参数。您也可以手动自定义高级参数。</p>
            
            <div class="security-mode-detail" id="security-mode-development">
                <strong style="color:#f59e0b;">开发模式</strong>
                <ul>
                    <li>适用于开发和测试环境</li>
                    <li>较宽松的限制，便于调试</li>
                    <li>允许更长的数据长度和更多的尝试次数</li>
                    <li><strong>认证器</strong>：受"认证器类型限制"设置控制（非严格模式下可自由选择）</li>
                    <li><strong style="color:#dc2626;">不建议在生产环境使用</strong></li>
                </ul>
            </div>
            
            <div class="security-mode-detail" id="security-mode-normal">
                <strong style="color:#10b981;">常规模式</strong>
                <ul>
                    <li>适用于大多数生产环境</li>
                    <li>平衡安全性和兼容性</li>
                    <li>符合 WebAuthn 标准的推荐配置</li>
                    <li><strong>认证器</strong>：受"认证器类型限制"设置控制（非严格模式下可自由选择）</li>
                    <li><strong>默认推荐配置</strong></li>
                </ul>
            </div>
            
            <div class="security-mode-detail" id="security-mode-strict">
                <strong style="color:#3b82f6;">严格模式</strong>
                <ul>
                    <li>适用于高安全要求的环境</li>
                    <li>最严格的安全限制</li>
                    <li>更短的超时时间和更少的尝试次数</li>
                    <li><strong>认证器</strong>：强制仅允许平台验证器（Windows Hello、Touch ID 等），忽略"认证器类型限制"设置</li>
                    <li>可能影响用户体验（无法使用 Bitwarden 等第三方验证器）</li>
                </ul>
            </div>
            
            <div class="security-mode-detail" id="security-mode-custom">
                <strong style="color:#8b5cf6;">自定义模式</strong>
                <ul>
                    <li>当您手动修改了高级参数后自动进入此模式</li>
                    <li>参数不再跟随任何预设，完全由您自行控制</li>
                    <li><strong>认证器</strong>：受"认证器类型限制"设置控制（非严格模式下可自由选择）</li>
                    <li>可通过选择预设模式或点击"重置为预设参数"返回预设配置</li>
                </ul>
            </div>
            
            <button type="button" class="security-reset-btn" onclick="resetSecurityParams()">
                重置为预设参数
            </button>
        </div>
        
        <script>
        /**
         * PasskeySettings - 插件设置面板统一管理器
         * 
         * 架构设计：
         * - 命名空间：所有设置相关逻辑统一在 window.PasskeySettings 下
         * - 事件驱动：通过事件系统实现模块间解耦，支持外部监听和扩展
         * - 联动规则注册表：新增功能只需调用 registerLinkage() 注册规则，无需修改核心逻辑
         * - 闭环同步：模式变更 → 联动执行 → 参数同步 → 预设校验 → 状态反馈（双向）
         * - 生命周期钩子：提供 init / change / reset / param 各阶段钩子，便于扩展
         *
         * 扩展接口示例（未来新增设置项时使用）：
         *   // 注册联动规则
         *   PasskeySettings.registerLinkage({
         *       mode: \'strict\',              // 触发模式（\'development\' / \'normal\' / \'strict\' / \'custom\' / \'*\' 全部）
         *       priority: 10,                 // 执行优先级（数字越小越先执行，默认 100）
         *       targets: [{
         *           selector: \'input[name="newOption"]\',
         *           action: \'forceValue\',    // 支持: disable | enable | forceValue | hide | show | callback
         *           value: \'1\',             // forceValue 时强制设定的值
         *           disabled: true,          // 是否禁用交互
         *           overlayHtml: \'<span>提示文字</span>\',  // 禁用时显示的叠加提示
         *           callback: function(el, ctx) { ... }  // 自定义处理函数
         *       }]
         *   });
         *
         *   // 监听事件
         *   PasskeySettings.on(\'modeChange\', function(data) { console.log(data.mode, data.prevMode); });
         *   PasskeySettings.on(\'paramChange\', function(data) { console.log(data.paramName, data.value); });
         *   PasskeySettings.on(\'linkageApply\', function(data) { console.log(data.mode, data.rules); });
         */
        var PasskeySettings = (function() {
            // ====== 内部状态 ======
            var _presets = {};                    // 安全模式预设参数（由 PHP 注入）
            var _linkageRules = [];               // 联动规则注册表
            var _listeners = {};                  // 事件监听器字典
            var _state = {
                currentMode: null,                // 当前选中的安全模式标识
                previousMode: null,               // 上一次的安全模式（用于变更检测）
                isCustom: false                   // 当前是否处于自定义参数状态
            };
            
            // ====== 事件系统 ======
            /**
             * 注册事件监听器
             * @param {string} event - 事件名称（modeChange / paramChange / linkageApply / reset / init）
             * @param {Function} callback - 回调函数，接收 data 参数
             * @returns {PasskeySettings} 支持链式调用
             */
            function on(event, callback) {
                if (!_listeners[event]) {
                    _listeners[event] = [];
                }
                _listeners[event].push(callback);
                return _public;
            }
            
            /**
             * 移除事件监听器
             * @param {string} event - 事件名称
             * @param {Function} callback - 要移除的回调引用（不传则清空该事件全部监听器）
             */
            function off(event, callback) {
                if (!_listeners[event]) return;
                if (!callback) {
                    _listeners[event] = [];
                } else {
                    _listeners[event] = _listeners[event].filter(function(cb) { return cb !== callback; });
                }
            }
            
            /**
             * 触发事件
             * @param {string} event - 事件名称
             * @param {Object} data - 传递给监听器的数据
             */
            function emit(event, data) {
                if (!_listeners[event]) return;
                _listeners[event].forEach(function(callback) {
                    try {
                        callback(data || {});
                    } catch (e) {
                        if (typeof console !== \'undefined\') {
                            console.error(\'[PasskeySettings] Event "\' + event + \'" handler error:\', e);
                        }
                    }
                });
            }
            
            // ====== 联动规则引擎 ======
            /**
             * 注册联动规则
             * 规则在安全模式切换时自动执行，用于控制非参数类设置的启用/禁用/强制值等行为
             * 
             * @param {Object} rule - 联动规则配置
             *   @param {string} rule.mode - 触发的安全模式（\'development\' / \'normal\' / \'strict\' / \'*\' 全部）
             *   @param {number} [rule.priority=100] - 执行优先级（越小越先执行）
             *   @param {Array}  rule.targets - 目标操作列表
             *     @param {string} target.selector - CSS 选择器定位目标元素
             *     @param {string} target.action - 操作类型（disable / enable / forceValue / hide / show / callback）
             *     @param {string} [target.value] - forceValue 操作的强制值
             *     @param {boolean} [target.disabled] - 是否禁用目标元素
             *     @param {string} [target.overlayHtml] - 禁用时在目标容器内追加的提示 HTML
             *     @param {Function} [target.callback] - callback 操作的自定义函数(el, context)
             * @returns {PasskeySettings} 支持链式调用
             */
            function registerLinkage(rule) {
                // 参数校验与默认值填充
                if (!rule || !rule.mode || !Array.isArray(rule.targets)) {
                    if (typeof console !== \'undefined\') {
                        console.warn(\'[PasskeySettings] Invalid linkage rule:\', rule);
                    }
                    return _public;
                }
                
                rule.priority = typeof rule.priority === \'number\' ? rule.priority : 100;
                rule.targets = rule.targets.map(function(target) {
                    return {
                        selector: target.selector || \'\',
                        action: target.action || \'disable\',
                        value: target.value,
                        disabled: target.disabled !== undefined ? target.disabled : (target.action === \'disable\'),
                        overlayHtml: target.overlayHtml || \'\',
                        callback: typeof target.callback === \'function\' ? target.callback : null
                    };
                });
                
                _linkageRules.push(rule);
                // 按优先级排序（小优先）
                _linkageRules.sort(function(a, b) { return a.priority - b.priority; });
                
                return _public;
            }
            
            /**
             * 执行指定模式的联动规则
             * 匹配逻辑：mode 严格匹配 或 rule.mode === \'*\'（通配符匹配所有模式）
             * 
             * @param {string} mode - 当前安全模式（\'development\' / \'normal\' / \'strict\' / \'custom\'）
             * @param {boolean} isInit - 是否为初始化调用（init 时不清除之前的 overlay）
             */
            function applyLinkage(mode, isInit) {
                var appliedRules = [];
                
                // ---- 前置清理：移除上一次联动遗留的 overlay 和禁用状态 ----
                //    非初始化调用时，先清除所有联动产生的 overlay，避免切换模式后残留
                //    例如：从 strict 切换到 normal 时，strict 的"已强制锁定"提示必须被移除
                if (!isInit) {
                    _cleanupAllOverlays();
                }
                
                _linkageRules.forEach(function(rule) {
                    // 匹配检查：严格匹配或通配符
                    if (rule.mode !== mode && rule.mode !== \'*\') {
                        return;
                    }
                    
                    appliedRules.push(rule);
                    
                    rule.targets.forEach(function(target) {
                        var elements = document.querySelectorAll(target.selector);
                        if (!elements.length) return;
                        
                        elements.forEach(function(el) {
                            switch (target.action) {
                                case \'disable\':
                                    _applyDisableState(el, target, true);
                                    break;
                                case \'enable\':
                                    _applyDisableState(el, target, false);
                                    break;
                                case \'forceValue\':
                                    _applyForceValue(el, target);
                                    break;
                                case \'hide\':
                                    el.style.display = \'none\';
                                    break;
                                case \'show\':
                                    el.style.display = \'\';
                                    break;
                                case \'callback\':
                                    if (target.callback) {
                                        target.callback(el, { mode: mode, state: _state });
                                    }
                                    break;
                            }
                        });
                        
                        // 处理容器级 overlay（仅对有 overlayHtml 的首个目标元素处理）
                        if (target.overlayHtml && elements.length > 0) {
                            _handleOverlay(elements[0], target, target.action === \'disable\' || target.disabled);
                        }
                    });
                });
                
                emit(\'linkageApply\', { mode: mode, rules: appliedRules, isInit: !!isInit });
            }
            
            /**
             * 应用禁用/启用状态到单个元素
             * @private
             */
            function _applyDisableState(el, target, disable) {
                el.disabled = disable;
                el.style.cursor = disable ? \'not-allowed\' : \'\';
                el.style.opacity = disable ? \'0.6\' : \'\';
                if (disable) {
                    el.title = el.title || \'当前模式下此选项不可更改\';
                } else {
                    // 恢复可用时清除提示文字（由 _cleanupAllOverlays 在非初始化调用前统一清理）
                    el.title = \'\';
                }
            }
            
            /**
             * 应用强制值到 Radio/Checkbox/Select 元素
             * @private
             */
            function _applyForceValue(el, target) {
                var tagName = (el.tagName || \'\').toLowerCase();
                var type = (el.type || \'\').toLowerCase();
                
                if (tagName === \'input\' && (type === \'radio\' || type === \'checkbox\')) {
                    // Radio/Checkbox：通过值匹配来选中
                    if (String(el.value) === String(target.value)) {
                        el.checked = true;
                    } else {
                        el.checked = false;
                    }
                    // 同步应用禁用状态
                    if (target.disabled) {
                        _applyDisableState(el, target, true);
                    }
                } else if (tagName === \'select\' || tagName === \'textarea\' || (tagName === \'input\' && (type === \'text\' || type === \'number\' || type === \'hidden\'))) {
                    el.value = target.value;
                    if (target.disabled) {
                        _applyDisableState(el, target, true);
                    }
                }
            }
            
            /**
             * 夹具容器级 Overlay 提示
             * 在目标元素的最近 .typecho-option 父容器上添加/移除提示层
             * @private
             */
            function _handleOverlay(rootEl, target, shouldShow) {
                var wrapper = rootEl.closest(\'.typecho-option\') || rootEl.parentElement;
                if (!wrapper) return;
                
                var overlayClass = \'pk-linkage-overlay-\' + target.selector.replace(/[^a-zA-Z0-9_-]/g, \'-\');
                var existingOverlay = wrapper.querySelector(\'.\' + overlayClass);
                
                if (shouldShow && target.overlayHtml) {
                    // 标签样式联动
                    var labels = wrapper.querySelectorAll(\'label\');
                    labels.forEach(function(label) {
                        label.style.cursor = \'not-allowed\';
                        label.style.opacity = \'0.6\';
                    });
                    
                    if (!existingOverlay) {
                        var overlay = document.createElement(\'div\');
                        overlay.className = overlayClass + \' pk-linkage-overlay\';
                        overlay.innerHTML = target.overlayHtml;
                        overlay.style.cssText = \'pointer-events:none;margin-top:6px;\';
                        wrapper.appendChild(overlay);
                    }
                } else {
                    // 恢复标签样式
                    var labels = wrapper.querySelectorAll(\'label\');
                    labels.forEach(function(label) {
                        label.style.cursor = \'\';
                        label.style.opacity = \'\';
                    });
                    
                    if (existingOverlay) {
                        existingOverlay.remove();
                    }
                }
            }
            
            /**
             * 清理所有联动规则产生的 Overlay 和禁用状态
             * 在切换模式时调用，确保上一次联动的视觉效果被完全清除
             * 
             * 清理范围：
             * - 所有 .pk-linkage-overlay 元素（联动提示层）
             * - 被联动禁用的元素的 disabled / cursor / opacity / title 状态
             * - 被联动影响的 label 样式
             * @private
             */
            function _cleanupAllOverlays() {
                // 1. 移除所有联动 overlay 提示层
                document.querySelectorAll(\'.pk-linkage-overlay\').forEach(function(el) {
                    el.remove();
                });
                
                // 2. 恢复所有被联动控制的元素状态
                //    遍历所有已注册规则的 target 选择器，恢复匹配元素的默认样式
                _linkageRules.forEach(function(rule) {
                    rule.targets.forEach(function(target) {
                        if (!target.selector) return;
                        var elements = document.querySelectorAll(target.selector);
                        elements.forEach(function(el) {
                            el.disabled = false;
                            el.style.cursor = \'\';
                            el.style.opacity = \'\';
                        });
                        
                        // 恢复关联容器的 label 样式
                        if (elements.length > 0) {
                            var wrapper = elements[0].closest(\'.typecho-option\') || elements[0].parentElement;
                            if (wrapper) {
                                wrapper.querySelectorAll(\'label\').forEach(function(label) {
                                    label.style.cursor = \'\';
                                    label.style.opacity = \'\';
                                });
                            }
                        }
                    });
                });
            }
            
            // ====== 模式管理 ======
            /**
             * 获取当前选中的安全模式
             * @returns {string} 模式标识（始终有值，无选中时返回 \'custom\'）
             */
            function getMode() {
                var checked = document.querySelector(\'input[name="securityMode"]:checked\');
                return checked ? checked.value : \'custom\';
            }
            
            /**
             * 切换安全模式（完整闭环流程）
             * 流程：记录旧模式 → 更新 UI 说明 → 同步预设参数（仅预设模式）→ 执行联动规则 → 触发事件
             * 
             * @param {string} mode - 目标模式（\'development\' / \'normal\' / \'strict\' / \'custom\'）
             */
            function setMode(mode) {
                var prevMode = _state.currentMode;
                
                // 触发前置钩子（可拦截）
                emit(\'beforeModeChange\', { mode: mode, prevMode: prevMode, state: _state });
                
                // 更新内部状态
                _state.previousMode = prevMode;
                _state.currentMode = mode;
                _state.isCustom = (mode === \'custom\');
                
                // 1. 切换模式说明面板的显隐
                _updateModeDetail(mode);
                
                // 2. 如果是预设模式（非 custom），自动将所有参数同步为该模式的预设值
                //    自定义模式保留用户当前修改的参数，不做覆盖
                if (mode !== \'custom\' && _presets[mode]) {
                    _applyPresetParams(mode);
                }
                
                // 3. 执行联动规则（控制 platformOnly 等非参数选项 + 刷新验证器状态）
                //    无论哪种模式都执行联动，确保验证器状态与当前模式一致
                applyLinkage(mode);
                
                // 4. 触发后置事件
                emit(\'modeChange\', { mode: mode, prevMode: prevMode, state: _state });
            }
            
            /**
             * 更新模式说明面板的 active 状态
             * @private
             */
            function _updateModeDetail(mode) {
                // 先隐藏所有说明面板
                document.querySelectorAll(\'.security-mode-detail\').forEach(function(el) {
                    el.classList.remove(\'active\');
                });
                
                // 再显示当前模式对应的面板
                if (mode) {
                    var detail = document.getElementById(\'security-mode-\' + mode);
                    if (detail) {
                        detail.classList.add(\'active\');
                    }
                }
            }
            
            // ====== 参数同步 ======
            /**
             * 将指定模式的所有预设参数应用到可见输入框和隐藏表单字段
             * 此方法是 setMode（切换模式自动应用）和 resetToPreset（手动重置）的共同底层实现
             * 
             * @param {string} mode - 要应用预设参数的安全模式标识
             * @private
             */
            function _applyPresetParams(mode) {
                var params = _presets[mode];
                if (!params) return;
                
                Object.keys(params).forEach(function(key) {
                    var value = params[key];
                    
                    // 更新可见输入框
                    var visibleInput = document.querySelector(\'#visible-\' + key);
                    if (visibleInput) {
                        visibleInput.value = value;
                        syncParam(visibleInput);  // 同步到隐藏框 + 触发 paramChange 事件
                    }
                    
                    // 直接更新隐藏输入框（兜底，确保即使无可见框也能正确提交）
                    var hiddenInput = document.querySelector(\'input[name="\' + key + \'"]\');
                    if (hiddenInput) {
                        hiddenInput.value = value;
                    }
                });
            }
            
            /**
             * 将可见输入框的值同步到隐藏的表单提交字段
             * 同时触发 paramChange 事件供外部监听
             * 
             * @param {HTMLElement} visibleInput - 带有 data-sync-target 属性的可见输入框
             */
            function syncParam(visibleInput) {
                var targetName = visibleInput.getAttribute(\'data-sync-target\');
                if (!targetName) return;
                
                var hiddenInput = document.querySelector(\'input[name="\' + targetName + \'"]\');
                if (hiddenInput) {
                    hiddenInput.value = visibleInput.value;
                }
                
                emit(\'paramChange\', {
                    paramName: targetName,
                    value: visibleInput.value,
                    element: visibleInput
                });
            }
            
            /**
             * 校验当前参数是否仍匹配选中的预设模式
             * 若不匹配则自动切换到自定义模式、隐藏原说明面板、重新执行联动（恢复非限制态）
             * 这是闭环的关键环节：参数改动 → 反向影响模式选择
             */
            function checkPresetMatch() {
                var selectedMode = getMode();
                // 自定义模式下无需校验
                if (selectedMode === \'custom\' || !_presets[selectedMode]) return;
                
                // 收集当前所有参数值
                var currentParams = {};
                document.querySelectorAll(\'[data-sync-target]\').forEach(function(input) {
                    var paramName = input.getAttribute(\'data-sync-target\');
                    currentParams[paramName] = parseInt(input.value) || input.value;
                });
                
                // 与预设值逐一比对
                var presetParams = _presets[selectedMode];
                var isMatch = true;
                for (var key in presetParams) {
                    if (currentParams[key] != presetParams[key]) {
                        isMatch = false;
                        break;
                    }
                }
                
                if (!isMatch) {
                    // 不匹配：切换到"自定义模式"（选中 custom 单选框，而非取消选择导致空值）
                    var customRadio = document.querySelector(\'input[name="securityMode"][value="custom"]\');
                    if (customRadio) {
                        customRadio.checked = true;
                    }
                    
                    // 以 custom 模式执行联动（恢复所有限制项为可用状态）
                    setMode(\'custom\');
                    
                    emit(\'presetMismatch\', { expectedMode: selectedMode, currentParams: currentParams });
                }
                
                return isMatch;
            }
            
            // ====== 重置功能 ======
            /**
             * 重置当前模式的所有参数为预设值
             * 完整流程：验证模式存在 → 调用 _applyPresetParams → 重新执行联动 → 触发事件
             * 
             * @returns {boolean} 是否成功重置
             */
            function resetToPreset() {
                var mode = getMode();
                if (!mode) {
                    alert(\'请先选择一个安全模式。\');
                    return false;
                }
                
                if (!_presets[mode]) {
                    alert(\'不支持 " \' + mode + \' " 模式的预设参数。\');
                    return false;
                }
                
                emit(\'beforeReset\', { mode: mode });
                
                // 复用预设参数应用逻辑（与 setMode 切换模式时使用同一方法）
                _applyPresetParams(mode);
                
                // 重置后重新执行联动（确保 strict 模式下 platformOnly 等也被正确锁定）
                applyLinkage(mode);
                
                emit(\'afterReset\', { mode: mode, params: _presets[mode] });
                
                alert(\'已重置为 " \' + mode + \' " 模式的预设参数。\');
                return true;
            }
            
            // ====== 初始化 ======
            /**
             * 初始化设置管理器
             * 绑定所有 DOM 事件监听器，加载初始状态，执行首次联动
             * 
             * @param {Object} presets - PHP 注入的安全模式预设参数对象
             */
            function init(presets) {
                _presets = presets || {};
                
                // 注册内置联动规则（插件核心功能的联动定义集中在此处）
                _registerBuiltInLinkages();
                
                // 获取初始模式（无选中时默认为 custom，下面会做兜底处理）
                var initialMode = getMode();
                
                // ---- 安全模式兜底：确保始终有选中项 ----
                //    如果没有任何 radio 被选中（如旧配置迁移、数据异常等情况），
                //    自动选中推荐选项"常规模式"，避免出现空选状态
                if (!document.querySelector(\'input[name="securityMode"]:checked\')) {
                    var defaultRadio = document.querySelector(\'input[name="securityMode"][value="normal"]\');
                    if (defaultRadio) {
                        defaultRadio.checked = true;
                        initialMode = \'normal\';
                    }
                }
                
                _state.currentMode = initialMode;
                _state.isCustom = (initialMode === \'custom\');
                
                // 首次联动（带 isInit 标记，保留已有的 overlay 不重复创建）
                _updateModeDetail(initialMode);
                applyLinkage(initialMode, true);
                
                // ---- 事件绑定 ----
                
                // 安全模式 Radio 切换 → 触发完整的 setMode 闭环
                document.querySelectorAll(\'input[name="securityMode"]\').forEach(function(radio) {
                    radio.addEventListener(\'change\', function() {
                        setMode(this.value);
                    });
                });
                
                // 可见参数输入框变化 → 同步到隐藏框 + 校验预设匹配
                document.querySelectorAll(\'[data-sync-target]\').forEach(function(input) {
                    // 初始同步
                    syncParam(input);
                    
                    // 输入变化时同步 + 校验
                    input.addEventListener(\'input\', function() {
                        syncParam(this);
                        checkPresetMatch();
                    });
                    
                    // 失去焦点时也校验（捕获粘贴等场景）
                    input.addEventListener(\'change\', function() {
                        syncParam(this);
                        checkPresetMatch();
                    });
                });
                
                // 认证器类型限制（platformOnly）变化时也需校验是否偏离预设模式
                document.querySelectorAll(\'input[name="platformOnly"]\').forEach(function(radio) {
                    radio.addEventListener(\'change\', function() {
                        // 仅在非严格模式下才响应变化（严格模式下被禁用不应触发）
                        if (!this.disabled) {
                            emit(\'platformOnlyChange\', { value: this.value });
                            // platformOnly 变化也可能导致偏离预设（如果预设隐含了特定认证器要求）
                            // 这里暂不做强制清除模式，因为 platformOnly 不是预设参数的一部分
                            // 但可以通过事件让外部代码自行决定是否需要 clearMode
                        }
                    });
                });
                
                emit(\'init\', { mode: initialMode, state: _state, presetsCount: Object.keys(_presets).length });
            }
            
            /**
             * 注册内置联动规则
             * 所有插件原生的联动逻辑集中定义于此
             * 未来新增联动只需在此方法中添加新的 registerLinkage 调用即可
             * @private
             */
            function _registerBuiltInLinkages() {
                // ---- 规则 1：严格模式 → 强制锁定"认证器类型限制"为"仅平台验证器" ----
                registerLinkage({
                    mode: \'strict\',
                    priority: 10,               // 高优先级，最先执行
                    targets: [{
                        selector: \'input[name="platformOnly"]\',
                        action: \'forceValue\',
                        value: \'1\',             // 强制选中"仅允许平台验证器"
                        disabled: true,          // 禁止用户修改
                        overlayHtml: \'<span style="color:#dc2626;font-size:12px;display:inline-flex;align-items:center;gap:4px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> 严格模式下已强制锁定为"仅平台验证器"</span>\'
                    }]
                });
                
                // ---- 规则 2：自定义模式 → 恢复"认证器类型限制"为可用状态 ----
                //    当用户手动修改参数进入自定义模式，或从严格模式切换到自定义模式时，
                //    确保 platformOnly 选项恢复可用（由 _cleanupAllOverlays 配合完成清理）
                //    此规则显式声明 custom 模式下 platformOnly 的行为：启用且不强制值
                registerLinkage({
                    mode: \'custom\',
                    priority: 20,
                    targets: [{
                        selector: \'input[name="platformOnly"]\',
                        action: \'enable\'           // 恢复为可交互状态，保留用户之前的选择
                    }]
                });
                
                // ---- 规则 3（预留）：开发模式下的特殊联动 ----
                // 示例：如果将来开发模式下需要自动开启某些调试选项，可以在这里添加：
                //
                // registerLinkage({
                //     mode: \'development\',
                //     priority: 20,
                //     targets: [{
                //         selector: \'input[name="debugMode"]\',
                //         action: \'forceValue\',
                //         value: \'1\'
                //     }]
                // });
                //
                // ---- 规则 N（预留）：通配符规则对所有模式生效 ----
                // 示例：如果某个选项需要在任何模式下都根据条件联动：
                //
                // registerLinkage({
                //     mode: \'*\',
                //     priority: 999,
                //     targets: [{
                //         selector: \'some-selector\',
                //         action: \'callback\',
                //         callback: function(el, ctx) {
                //             // 自定义联动逻辑
                //         }
                //     }]
                // });
            }
            
            // ====== 公开 API ======
            var _public = {
                // 核心
                init: init,
                getMode: getMode,
                setMode: setMode,
                
                // 联动系统
                registerLinkage: registerLinkage,
                applyLinkage: applyLinkage,
                
                // 参数管理
                syncParam: syncParam,
                checkPresetMatch: checkPresetMatch,
                resetToPreset: resetToPreset,
                
                // 事件系统
                on: on,
                off: off,
                emit: emit,
                
                // 状态只读访问
                getState: function() { return Object.assign({}, _state); },
                getPresets: function() { return Object.assign({}, _presets); },
                getLinkageRules: function() { return _linkageRules.slice(); }
            };
            
            return _public;
        })();
        
        // ====== 向后兼容的全局函数 ======
        // 保留原有全局函数名作为 PasskeySettings 的代理，确保 HTML 中 onclick 等内联调用不受影响
        
        /** @deprecated 使用 Passkey.syncParam() 替代 */
        function syncParamValue(visibleInput) { return PasskeySettings.syncParam(visibleInput); }
        
        /** @deprecated 使用 Passkey.checkPresetMatch() 替代 */
        function checkAndClearModeSelection() { return PasskeySettings.checkPresetMatch(); }
        
        /** @deprecated 使用 Passkey.resetToPreset() 替代 */
        function resetSecurityParams() { return PasskeySettings.resetToPreset(); }
        
        /**
         * 参数说明 Tooltip（独立工具函数，保持全局可用）
         * 此函数与设置联动无关，属于纯 UI 辅助功能
         */
        function showParamInfo(event, paramName) {
            event.preventDefault();
            event.stopPropagation();
            
            var descriptions = {
                "maxChallengeLength": "Challenge 是服务器生成的随机字符串，用于防止重放攻击。较长的 Challenge 更安全，但会占用更多带宽。",
                "maxClientDataLength": "客户端数据（ClientData）包含 origin、challenge 等信息。限制长度可防止恶意客户端发送超大数据。",
                "maxAttestationLength": "认证对象（AttestationObject）包含认证器数据和公钥。限制长度可防止缓冲区溢出攻击。",
                "maxAuthenticatorDataLength": "认证器数据包含 RP ID 哈希、标志位、计数器等。限制长度确保数据完整性。",
                "maxSignatureLength": "签名数据的最大长度。RSA 签名通常为 256-512 字节，ECDSA 签名约为 64-72 字节。",
                "maxPublicKeyLength": "公钥数据的最大长度。RSA 公钥较大（2048-4096位），ECDSA 公钥较小（256位）。",
                "maxCborDepth": "CBOR（Concise Binary Object Representation）解码的最大嵌套深度，防止递归攻击。",
                "maxAttemptsPerHour": "每个用户每小时允许的最大认证尝试次数，防止暴力破解。",
                "maxAttemptsPerIp": "每个 IP 地址每小时允许的最大尝试次数，防止分布式攻击。",
                "sessionTimeout": "会话超时时间（秒）。Challenge 生成后必须在此时间内使用，过期则需重新生成。",
                "maxCredentialIdLength": "凭证 ID 的最大长度。正常情况下为 16-256 字节，限制可防止异常数据。"
            };
            
            var tooltip = document.getElementById(\'security-tooltip\');
            if (!tooltip) {
                tooltip = document.createElement(\'div\');
                tooltip.id = \'security-tooltip\';
                tooltip.className = \'security-param-tooltip\';
                document.body.appendChild(tooltip);
                
                // 全局点击关闭 Tooltip（仅绑定一次）
                document.addEventListener(\'click\', function(e) {
                    if (!e.target.closest(\'.security-param-info\')) {
                        tooltip.className = \'security-param-tooltip\';
                    }
                });
            }
            
            tooltip.textContent = descriptions[paramName] || \'参数说明\';
            tooltip.className = \'security-param-tooltip show\';
            tooltip.style.left = (event.pageX + 10) + \'px\';
            tooltip.style.top = (event.pageY + 10) + \'px\';
        }
        
        // ====== 启动初始化 ======
        (function() {
            // DOMReady 后初始化（兼容动态加载场景）
            var initFn = function() {
                PasskeySettings.init(' . json_encode($presets) . ');
            };
            
            if (document.readyState === \'loading\') {
                document.addEventListener(\'DOMContentLoaded\', initFn);
            } else {
                initFn();
            }
        })();
        </script>';
        
        // 高级自定义参数
        $advancedParamsHtml = '<div class="security-custom-params">
            <h4>高级自定义参数</h4>
            <p class="security-custom-desc" style="font-size:13px;margin-bottom:15px;">
                以下参数会根据所选安全模式自动设置。如需自定义，请修改下方数值后保存。
                <span class="pk-warn-danger">修改这些参数可能影响系统安全性，请谨慎操作！</span>
            </p>';
        
        // 为每个参数添加说明图标
        $advancedParamsHtml .= self::generateParamField('maxChallengeLength', 'Challenge 最大长度（字节）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxClientDataLength', 'ClientData 最大长度（字节）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxAttestationLength', 'AttestationObject 最大长度（字节）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxAuthenticatorDataLength', 'AuthenticatorData 最大长度（字节）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxSignatureLength', '签名最大长度（字节）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxPublicKeyLength', '公钥最大长度（字节）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxCborDepth', 'CBOR 解码最大深度', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxAttemptsPerHour', '每小时最大尝试次数（用户）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxAttemptsPerIp', '每小时最大尝试次数（IP）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('sessionTimeout', 'Session 超时时间（秒）', $plugin, $presets, $currentMode);
        $advancedParamsHtml .= self::generateParamField('maxCredentialIdLength', '凭证 ID 最大长度（字节）', $plugin, $presets, $currentMode);
        
        $advancedParamsHtml .= '</div>';
        
        // 将高级参数HTML添加到安全模式描述中
        $securityModeDesc .= $advancedParamsHtml;
        
        $securityMode = new Radio(
            'securityMode',
            array(
                'development' => '开发模式（Development）',
                'normal' => '常规模式（Normal，推荐）',
                'strict' => '严格模式（Strict）',
                'custom' => '自定义模式（Custom）'
            ),
            $currentMode,
            '安全模式',
            $securityModeDesc
        );
        $form->addInput($securityMode);
        
        // 为每个参数创建隐藏的输入字段（用于表单提交）
        // 这些字段会与上面HTML中的输入框同步
        foreach (array_keys($presets['normal']) as $paramKey) {
            $currentValue = ($plugin && isset($plugin->$paramKey)) ? $plugin->$paramKey : $presets[$currentMode][$paramKey];
            $paramField = new Text(
                $paramKey,
                NULL,
                $currentValue,
                '',
                ''
            );
            $paramField->input->setAttribute('style', 'display:none'); // 隐藏，使用上面的自定义HTML
            $form->addInput($paramField);
        }
    }
    
    /**
     * 生成参数字段HTML
     */
    private static function generateParamField($key, $label, $plugin, $presets, $currentMode)
    {
        $currentValue = ($plugin && isset($plugin->$key)) ? $plugin->$key : $presets[$currentMode][$key];
        
        // 使用独特的ID来标识可见的输入框，并通过JavaScript同步到隐藏的表单字段
        return '<div class="param-group">
            <label>' . $label . '
                <a href="#" class="security-param-info" onclick="showParamInfo(event, \'' . $key . '\')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                </a>
            </label>
            <input type="number" id="visible-' . $key . '" data-sync-target="' . $key . '" value="' . $currentValue . '" min="0" onchange="syncParamValue(this)" oninput="syncParamValue(this)" />
        </div>';
    }
    
    /**
     * 获取安全预设配置
     */
    public static function getSecurityPresets()
    {
        return array(
            'development' => array(
                'maxChallengeLength' => 2048,       // 开发模式：更宽松
                'maxClientDataLength' => 16384,
                'maxAttestationLength' => 131072,
                'maxAuthenticatorDataLength' => 131072,
                'maxSignatureLength' => 2048,
                'maxPublicKeyLength' => 16384,
                'maxCborDepth' => 15,
                'maxAttemptsPerHour' => 50,
                'maxAttemptsPerIp' => 30,
                'sessionTimeout' => 600,            // 10分钟
                'maxCredentialIdLength' => 1024
            ),
            'normal' => array(
                'maxChallengeLength' => 1024,       // 常规模式：平衡
                'maxClientDataLength' => 8192,
                'maxAttestationLength' => 65536,
                'maxAuthenticatorDataLength' => 65536,
                'maxSignatureLength' => 1024,
                'maxPublicKeyLength' => 8192,
                'maxCborDepth' => 10,
                'maxAttemptsPerHour' => 20,
                'maxAttemptsPerIp' => 10,
                'sessionTimeout' => 300,            // 5分钟
                'maxCredentialIdLength' => 512
            ),
            'strict' => array(
                'maxChallengeLength' => 512,        // 严格模式：最严格
                'maxClientDataLength' => 4096,
                'maxAttestationLength' => 32768,
                'maxAuthenticatorDataLength' => 32768,
                'maxSignatureLength' => 512,
                'maxPublicKeyLength' => 4096,
                'maxCborDepth' => 5,
                'maxAttemptsPerHour' => 10,
                'maxAttemptsPerIp' => 5,
                'sessionTimeout' => 180,            // 3分钟
                'maxCredentialIdLength' => 256
            )
        );
    }
    
    /**
     * 个人用户的配置面板（空实现）
     */
    public static function personalConfig(Form $form) {}
    
    /**
     * 添加 IP 地址获取策略配置
     */
    private static function addIpSourceConfig($form, $plugin)
    {
        $ipSourceDescription = '如果您的站点位于 CDN (如 Cloudflare) 或反向代理之后，请选择"代理头"或"自定义请求头"，否则速率限制功能将无法正确识别用户 IP。';
        
        $currentIpSource = ($plugin && isset($plugin->ipSource)) ? $plugin->ipSource : 'default';
        
        $ipSource = new \Typecho\Widget\Helper\Form\Element\Select(
            'ipSource',
            array(
                'default' => '默认 (REMOTE_ADDR)',
                'proxy' => '代理头 (X-Forwarded-For / Client-IP)',
                'custom' => '自定义请求头'
            ),
            $currentIpSource,
            '<h2>IP 识别策略</h2>IP 地址获取方式',
            $ipSourceDescription
        );
        $form->addInput($ipSource);
        
        $currentCustomHeader = ($plugin && isset($plugin->customIpHeader)) ? $plugin->customIpHeader : 'HTTP_CF_CONNECTING_IP';
        
        $customIpHeader = new \Typecho\Widget\Helper\Form\Element\Text(
            'customIpHeader',
            NULL,
            $currentCustomHeader,
            '自定义 IP 请求头名称',
            '仅当上面的选项选择"自定义请求头"时生效。例如 Cloudflare 用户可填写 <code>HTTP_CF_CONNECTING_IP</code>。'
        );
        $form->addInput($customIpHeader);
        
        // 添加动态切换 JS
        echo self::getIpSourceJs();
    }
    
    /**
     * 获取 IP 策略切换的 JavaScript
     */
    private static function getIpSourceJs()
    {
        return <<<JS
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var ipSourceSelector = document.querySelector('[name="ipSource"]');
                var customIpHeaderInput = document.querySelector('[name="customIpHeader"]');
                
                if (!ipSourceSelector || !customIpHeaderInput) return;
                
                function toggleIpSettings() {
                    var type = ipSourceSelector.value;
                    var container = customIpHeaderInput.closest('li');
                    
                    // 仅在选择"自定义请求头"时显示输入框
                    if (type === 'custom') {
                        container.style.display = '';
                    } else {
                        container.style.display = 'none';
                    }
                }
                
                ipSourceSelector.addEventListener('change', toggleIpSettings);
                toggleIpSettings(); // 初始化
            });
        </script>
JS;
    }
    
    /**
     * 获取客户端 IP 地址（根据配置）
     * 
     * @return string IP 地址
     */
    public static function getClientIp()
    {
        $options = Options::alloc();
        
        // 安全获取插件配置
        $plugin = null;
        try {
            $plugin = $options->plugin('Passkey');
        } catch (\Exception $e) {
            // 配置不存在，使用默认方式
        }
        
        $ipSource = ($plugin && isset($plugin->ipSource)) ? $plugin->ipSource : 'default';
        
        $ip = '';
        
        switch ($ipSource) {
            case 'proxy':
                // 从代理头获取（X-Forwarded-For 或 Client-IP）
                $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
                if (empty($ip)) {
                    $ip = isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : '';
                }
                // X-Forwarded-For 可能包含多个 IP，取第一个
                if (!empty($ip) && strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                break;
                
            case 'custom':
                // 从自定义请求头获取
                $customHeader = ($plugin && isset($plugin->customIpHeader)) ? $plugin->customIpHeader : 'HTTP_CF_CONNECTING_IP';
                $ip = isset($_SERVER[$customHeader]) ? $_SERVER[$customHeader] : '';
                break;
                
            case 'default':
            default:
                // 默认方式：直接获取 REMOTE_ADDR
                $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
                break;
        }
        
        // 验证 IP 地址格式
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        }
        
        return $ip;
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
        <style>
        .pk-login-divider { border-top: 1px solid #e5e7eb; }
        .pk-login-divider-text { color: #9ca3af; }
        @media (prefers-color-scheme: dark) {
            .pk-login-divider { border-top-color: #333; }
            .pk-login-divider-text { color: #6b7280; }
        }
        </style>
        <script>var PASSKEY_ACTION_URL = "<?php echo $options->index; ?>/action/passkey";</script>
        <script src="<?php echo $pluginUrl; ?>/assist/js/passkey.js?v=<?php echo $version; ?>"></script>
        <script>
        // 标记为自动注入模式，防止 passkey.js 重复注入
        window.PASSKEY_AUTO_INJECTED = true;
        </script>
        <div id="passkey-login-container" class="pk-login-divider" style="margin-top:15px;padding-top:15px;">
            <button type="button" id="passkey-login-btn" class="btn btn-l w-100" 
                style="width:100%;padding:10px;font-size:14px;cursor:pointer;background:#4f46e5;color:white;border:1px solid #4338ca;transition:all 0.2s ease;">
                使用 Passkey 登录
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