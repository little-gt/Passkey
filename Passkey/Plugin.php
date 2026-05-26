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
 * @version 1.1.0
 * @link https://www.garfieldtom.cool
 */
class Plugin implements PluginInterface
{
    /**
     * 插件版本号 - 用于资源缓存控制
     */
    const VERSION = '1.1.0';
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
        
        // 安全模式配置
        self::addSecurityConfig($form, $plugin);
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
            // 如果没有匹配到任何预设，但有存储的模式，说明是自定义参数
            if (!$currentMode && $storedMode) {
                $currentMode = ''; // 不选中任何模式
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
                    <li><strong style="color:#dc2626;">不建议在生产环境使用</strong></li>
                </ul>
            </div>
            
            <div class="security-mode-detail" id="security-mode-normal">
                <strong style="color:#10b981;">常规模式</strong>
                <ul>
                    <li>适用于大多数生产环境</li>
                    <li>平衡安全性和兼容性</li>
                    <li>符合 WebAuthn 标准的推荐配置</li>
                    <li><strong>默认推荐配置</strong></li>
                </ul>
            </div>
            
            <div class="security-mode-detail" id="security-mode-strict">
                <strong style="color:#3b82f6;">严格模式</strong>
                <ul>
                    <li>适用于高安全要求的环境</li>
                    <li>最严格的安全限制</li>
                    <li>更短的超时时间和更少的尝试次数</li>
                    <li>可能影响用户体验</li>
                </ul>
            </div>
            
            <button type="button" class="security-reset-btn" onclick="resetSecurityParams()">
                重置为预设参数
            </button>
        </div>
        
        <script>
        (function() {
            // 切换模式说明
            function updateSecurityModeDescription() {
                var mode = document.querySelector(\'input[name="securityMode"]:checked\');
                if (!mode) return;
                
                // 隐藏所有说明
                document.querySelectorAll(\'.security-mode-detail\').forEach(function(el) {
                    el.classList.remove(\'active\');
                });
                
                // 显示当前模式的说明
                var detail = document.getElementById(\'security-mode-\' + mode.value);
                if (detail) {
                    detail.classList.add(\'active\');
                }
            }
            
            // 页面加载时显示当前模式
            document.addEventListener(\'DOMContentLoaded\', function() {
                updateSecurityModeDescription();
                
                // 监听模式切换
                document.querySelectorAll(\'input[name="securityMode"]\').forEach(function(radio) {
                    radio.addEventListener(\'change\', updateSecurityModeDescription);
                });
                
                // 初始化：将可见输入框的值同步到隐藏输入框
                document.querySelectorAll(\'[data-sync-target]\').forEach(function(input) {
                    syncParamValue(input);
                    
                    // 监听参数变化，如果用户手动修改参数，取消模式选择
                    input.addEventListener(\'input\', function() {
                        checkAndClearModeSelection();
                    });
                });
            });
            
            // 如果DOM已加载，立即执行
            if (document.readyState !== \'loading\') {
                updateSecurityModeDescription();
            }
        })();
        
        // 同步可见输入框的值到隐藏的表单字段
        function syncParamValue(visibleInput) {
            var targetName = visibleInput.getAttribute(\'data-sync-target\');
            var hiddenInput = document.querySelector(\'input[name="\' + targetName + \'"]\');
            if (hiddenInput) {
                hiddenInput.value = visibleInput.value;
            }
        }
        
        // 检查当前参数是否匹配预设模式，如果不匹配则取消选择
        function checkAndClearModeSelection() {
            var presets = ' . json_encode($presets) . ';
            var selectedMode = document.querySelector(\'input[name="securityMode"]:checked\');
            
            if (!selectedMode) return;
            
            var currentParams = {};
            document.querySelectorAll(\'[data-sync-target]\').forEach(function(input) {
                var paramName = input.getAttribute(\'data-sync-target\');
                currentParams[paramName] = parseInt(input.value) || input.value;
            });
            
            // 检查当前参数是否匹配所选模式的预设
            var presetParams = presets[selectedMode.value];
            var isMatch = true;
            
            for (var key in presetParams) {
                if (currentParams[key] != presetParams[key]) {
                    isMatch = false;
                    break;
                }
            }
            
            // 如果不匹配，取消选择
            if (!isMatch) {
                selectedMode.checked = false;
                // 隐藏所有模式说明
                document.querySelectorAll(\'.security-mode-detail\').forEach(function(el) {
                    el.classList.remove(\'active\');
                });
            }
        }
        function resetSecurityParams() {
            var mode = document.querySelector(\'input[name="securityMode"]:checked\');
            if (!mode) {
                alert(\'请先选择安全模式\');
                return;
            }
            
            var presets = ' . json_encode($presets) . ';
            var params = presets[mode.value];
            
            if (!params) {
                alert(\'无法获取预设参数\');
                return;
            }
            
            // 更新可见输入框和隐藏输入框的值
            Object.keys(params).forEach(function(key) {
                // 更新可见输入框
                var visibleInput = document.querySelector(\'#visible-\' + key);
                if (visibleInput) {
                    visibleInput.value = params[key];
                    syncParamValue(visibleInput);
                }
                
                // 直接更新隐藏输入框（双重保险）
                var hiddenInput = document.querySelector(\'input[name="\' + key + \'"]\');
                if (hiddenInput) {
                    hiddenInput.value = params[key];
                }
            });
            
            alert(\'已重置为 \' + mode.value + \' 模式的预设参数\');
        }
        
        // 参数说明提示
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
                
                // 创建全局点击监听器（只创建一次）
                document.addEventListener(\'click\', function(e) {
                    // 如果点击的不是 info 图标，则隐藏 tooltip
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
                'normal' => '常规模式（Normal）',
                'strict' => '严格模式（Strict）'
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