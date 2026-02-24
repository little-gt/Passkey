<?php
/**
 * Passkey 管理面板
 * 独立页面
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Common.php';

\Typecho\Common::init();

// 检查用户登录状态
$user = \Widget\User::alloc();
if (!$user->hasLogin()) {
    \Typecho\Response::getInstance()->redirect('/admin/login.php');
    exit;
}

$options = \Widget\Options::alloc();
$pluginUrl = $options->pluginUrl . '/Passkey';

// 获取插件版本号
$pluginVersion = '1.0.5'; // 与 Plugin.php 中的版本号保持一致
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passkey 身份验证管理 - <?php echo htmlspecialchars($options->title); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, "Microsoft YaHei", sans-serif;
            background: #f0f2f5;
            color: #1f2937;
            line-height: 1.5;
            font-size: 14px;
        }
        
        .passkey-layout {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* 顶部导航栏 */
        .passkey-navbar {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .passkey-navbar-inner {
            width: 100%;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .passkey-navbar-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .passkey-navbar-title::before {
            content: '';
            width: 3px;
            height: 18px;
            background: #3b82f6;
            display: inline-block;
        }
        
        .passkey-navbar-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .passkey-badge {
            padding: 4px 12px;
            background: #f3f4f6;
            color: #4b5563;
            font-size: 12px;
            border: 1px solid #e5e7eb;
        }
        
        .passkey-link {
            color: #3b82f6;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        
        .passkey-link:hover {
            color: #2563eb;
            text-decoration: underline;
        }
        
        /* 内容区域 */
        .passkey-main {
            width: 100%;
            margin: 0 auto;
            padding: 24px;
            flex: 1;
        }
        
        .passkey-section {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            margin-bottom: 16px;
        }
        
        .passkey-section-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafbfc;
        }
        
        .passkey-section-title {
            font-size: 15px;
            font-weight: 600;
            color: #111827;
        }
        
        .passkey-section-body {
            padding: 20px;
        }
        
        /* 按钮样式 */
        .passkey-btn {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .passkey-btn-primary {
            background: #3b82f6;
            color: #ffffff;
        }
        
        .passkey-btn-primary:hover {
            background: #2563eb;
        }
        
        .passkey-btn-primary:active {
            background: #1d4ed8;
        }
        
        .passkey-btn-primary:disabled {
            background: #93c5fd;
            cursor: not-allowed;
        }
        
        .passkey-btn-danger {
            background: #ffffff;
            color: #dc2626;
            border: 1px solid #e5e7eb;
        }
        
        .passkey-btn-danger:hover {
            background: #fef2f2;
            border-color: #dc2626;
        }
        
        /* 状态消息 */
        .passkey-alert {
            padding: 12px 16px;
            margin-bottom: 16px;
            border-left: 3px solid;
            display: none;
        }
        
        .passkey-alert-success {
            background: #f0fdf4;
            border-color: #22c55e;
            color: #166534;
        }
        
        .passkey-alert-error {
            background: #fef2f2;
            border-color: #ef4444;
            color: #991b1b;
        }
        
        .passkey-alert-info {
            background: #eff6ff;
            border-color: #3b82f6;
            color: #1e40af;
        }
        
        /* 表格样式 */
        .passkey-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .passkey-table thead {
            background: #f9fafb;
        }
        
        .passkey-table th {
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .passkey-table td {
            padding: 14px 16px;
            font-size: 13px;
            color: #374151;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .passkey-table tbody tr:hover {
            background: #f9fafb;
        }
        
        .passkey-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .passkey-table code {
            background: #f3f4f6;
            padding: 3px 8px;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 12px;
            color: #111827;
            border: 1px solid #e5e7eb;
        }
        
        .passkey-table-empty {
            text-align: center;
            padding: 48px 20px;
            color: #9ca3af;
        }
        
        .passkey-table-actions {
            text-align: right;
        }
        
        .passkey-btn-delete {
            padding: 6px 12px;
            font-size: 12px;
            background: #ffffff;
            color: #dc2626;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .passkey-btn-delete:hover {
            background: #fef2f2;
            border-color: #dc2626;
        }
        
        /* 操作链接 */
        .passkey-action-link {
            color: #dc2626;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }
        
        .passkey-action-link:hover {
            color: #b91c1c;
            text-decoration: underline;
        }
        
        /* 信息卡片 */
        .passkey-info-box {
            border: 1px solid #e5e7eb;
            padding: 16px;
            margin-bottom: 16px;
        }
        
        .passkey-info-box-title {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
        }
        
        .passkey-info-box ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .passkey-info-box li {
            padding: 6px 0;
            font-size: 13px;
            color: #4b5563;
            line-height: 1.6;
            position: relative;
            padding-left: 16px;
        }
        
        .passkey-info-box li::before {
            content: '•';
            position: absolute;
            left: 0;
            color: #9ca3af;
        }
        
        /* 统计卡片 */
        .passkey-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .passkey-stat-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            padding: 20px;
        }
        
        .passkey-stat-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .passkey-stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }
        
        /* 页脚 */
        .passkey-footer {
            background: #ffffff;
            border-top: 1px solid #e5e7eb;
            padding: 20px 0;
            margin-top: auto;
        }
        
        .passkey-footer-inner {
            width: 100%;
            margin: 0 auto;
            padding: 0 24px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
        
        /* 响应式 */
        @media (max-width: 768px) {
            .passkey-navbar-inner {
                padding: 12px 16px;
            }
            
            .passkey-navbar-title {
                font-size: 14px;
            }
            
            .passkey-main {
                padding: 16px;
            }
            
            .passkey-section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .passkey-table {
                font-size: 12px;
            }
            
            .passkey-table th,
            .passkey-table td {
                padding: 10px 12px;
            }
            
            .passkey-stats {
                grid-template-columns: 1fr;
            }
        }
        
        /* SVG 图标样式 */
        .passkey-btn svg {
            flex-shrink: 0;
        }
        
        /* 加载动画 */
        @keyframes passkeyRotate {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
        
        /* 滑入动画 */
        @keyframes passkeySlideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* 增强的通知样式 */
        .passkey-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            min-width: 300px;
            max-width: 500px;
            padding: 16px 20px;
            background: white;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10000;
            animation: passkeySlideIn 0.3s ease;
        }
        
        .passkey-notification.success {
            border-left: 4px solid #10b981;
        }
        
        .passkey-notification.error {
            border-left: 4px solid #ef4444;
        }
        
        .passkey-notification.info {
            border-left: 4px solid #3b82f6;
        }
        
        .passkey-notification.warning {
            border-left: 4px solid #f59e0b;
        }
        
        .passkey-notification svg {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
        }
        
        .passkey-notification.success svg {
            color: #10b981;
        }
        
        .passkey-notification.error svg {
            color: #ef4444;
        }
        
        .passkey-notification.info svg {
            color: #3b82f6;
        }
        
        .passkey-notification.warning svg {
            color: #f59e0b;
        }
        
        /* 状态徽章 */
        .passkey-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .passkey-status-badge.success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .passkey-status-badge.failed {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .passkey-status-badge svg {
            width: 14px;
            height: 14px;
        }
    </style>
</head>
<body>
    <div class="passkey-layout">
        <!-- 顶部导航 -->
        <nav class="passkey-navbar">
            <div class="passkey-navbar-inner">
                <div class="passkey-navbar-title">
                    Passkey 身份验证管理
                </div>
                <div class="passkey-navbar-actions">
                    <span class="passkey-badge">用户: <?php echo htmlspecialchars($user->screenName); ?></span>
                    <a href="<?php echo $options->adminUrl; ?>" class="passkey-link">返回控制台</a>
                </div>
            </div>
        </nav>
        
        <!-- 主内容 -->
        <main class="passkey-main">
            <!-- 统计概览 -->
            <div class="passkey-stats">
                <div class="passkey-stat-card">
                    <div class="passkey-stat-label">已注册凭证</div>
                    <div class="passkey-stat-value" id="passkey-count">-</div>
                </div>
                <div class="passkey-stat-card">
                    <div class="passkey-stat-label">最后添加</div>
                    <div class="passkey-stat-value" style="font-size: 16px; padding-top: 8px;" id="passkey-last">-</div>
                </div>
            </div>
            
            <!-- 凭证列表 -->
            <div class="passkey-section">
                <div class="passkey-section-header">
                    <div class="passkey-section-title">凭证管理</div>
                    <button type="button" id="register-passkey-btn" class="passkey-btn passkey-btn-primary" style="display:flex;align-items:center;gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/>
                            <path d="M14 13.12c0 2.38 0 6.38-1 8.88"/>
                            <path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/>
                            <path d="M2 12a10 10 0 0 1 18-6"/>
                            <path d="M2 16h.01"/>
                            <path d="M21.8 16c.2-2 .131-5.354 0-6"/>
                            <path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/>
                            <path d="M8.65 22c.21-.66.45-1.32.57-2"/>
                            <path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>
                        </svg>
                        <span>注册新 Passkey</span>
                    </button>
                </div>
                <div class="passkey-section-body">
                    
                    <table class="passkey-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>凭证标识符</th>
                                <th>创建时间</th>
                                <th style="text-align: right;">操作</th>
                            </tr>
                        </thead>
                        <tbody id="passkey-list">
                            <tr>
                                <td colspan="4" class="passkey-table-empty">
                                    加载中...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- 登录记录 -->
            <div class="passkey-section">
                <div class="passkey-section-header">
                    <div class="passkey-section-title">近期登录记录</div>
                </div>
                <div class="passkey-section-body">
                    <table class="passkey-table">
                        <thead>
                            <tr>
                                <th>登录时间</th>
                                <th>设备/浏览器</th>
                                <th>IP 地址</th>
                                <th>状态</th>
                            </tr>
                        </thead>
                        <tbody id="passkey-login-logs">
                            <tr>
                                <td colspan="4" class="passkey-table-empty">
                                    加载中...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- 使用说明 -->
            <div class="passkey-section">
                <div class="passkey-section-header">
                    <div class="passkey-section-title">使用说明</div>
                </div>
                <div class="passkey-section-body">
                    <div class="passkey-info-box">
                        <div class="passkey-info-box-title">功能特性</div>
                        <ul>
                            <li>使用生物识别（指纹、面容识别）或设备 PIN 码进行身份验证</li>
                            <li>支持多设备绑定，每个设备独立管理</li>
                            <li>添加成功后可在登录页面使用 Passkey 快速登录</li>
                            <li>删除凭证后该设备将无法使用 Passkey 认证</li>
                        </ul>
                    </div>
                    
                    <div class="passkey-info-box">
                        <div class="passkey-info-box-title">系统要求</div>
                        <ul>
                            <li>必须使用 HTTPS 协议（本地开发可使用 localhost）</li>
                            <li>浏览器支持：Chrome 67+、Firefox 60+、Safari 13+、Edge 18+</li>
                            <li>移动设备需支持生物识别或已设置设备锁屏密码</li>
                            <li>Windows 设备需要 Windows 10 1903+ 并启用 Windows Hello</li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- 页脚 -->
        <footer class="passkey-footer">
            <div class="passkey-footer-inner">
                Passkey 身份验证插件 | 基于 FIDO2/WebAuthn 标准
            </div>
        </footer>
    </div>
    
    <script>
        var PASSKEY_ACTION_URL = "<?php echo $options->index; ?>/action/passkey";
    </script>
    <script src="<?php echo $pluginUrl; ?>/assist/js/passkey.js?v=<?php echo $pluginVersion; ?>"></script>
    <script>
    (function() {
        var listTable = document.getElementById('passkey-list');
        var registerBtn = document.getElementById('register-passkey-btn');
        var countDiv = document.getElementById('passkey-count');
        var lastDiv = document.getElementById('passkey-last');
        
        /**
         * 安全的 JSON 解析辅助函数
         */
        function parseJSON(response) {
            return response.text().then(function(text) {
                // 检查响应是否为空
                if (!text || text.trim() === '') {
                    throw new Error('服务器返回空响应');
                }
                
                // 检查是否是 HTML 错误页面
                if (text.trim().startsWith('<')) {
                    console.error('服务器返回 HTML 而不是 JSON:', text.substring(0, 200));
                    throw new Error('服务器返回了错误页面，请检查服务器日志');
                }
                
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON 解析错误:', e);
                    console.error('响应内容:', text.substring(0, 200));
                    throw new Error('服务器响应格式错误: ' + e.message);
                }
            });
        }
        
        // 加载凭证列表
        function loadCredentials() {
            // 显示加载中
            listTable.innerHTML = '<tr><td colspan="4" class="passkey-table-empty">' +
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;animation:passkeyRotate 1s linear infinite;margin-right:8px;vertical-align:middle;">' +
                '<line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>' +
                '<line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/>' +
                '<line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>' +
                '<line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>' +
                '</svg>加载中...</td></tr>';
            
            fetch(PASSKEY_ACTION_URL + '?do=list')
                .then(parseJSON)
                .then(function(data) {
                    if (data.success) {
                        renderCredentials(data.data);
                    } else {
                        PasskeyManager.showNotification('加载失败: ' + data.error, 'error');
                        renderEmpty('加载失败');
                    }
                })
                .catch(function(error) {
                    console.error('加载凭证列表失败:', error);
                    PasskeyManager.showNotification('加载失败: ' + error.message, 'error');
                    renderEmpty('加载失败');
                });
        }
        
        // 渲染空状态
        function renderEmpty(message) {
            listTable.innerHTML = '<tr><td colspan="4" class="passkey-table-empty">' + message + '</td></tr>';
            countDiv.textContent = '0';
            lastDiv.textContent = '无';
        }
        
        // 渲染凭证列表
        function renderCredentials(credentials) {
            if (credentials.length === 0) {
                var emptyIcon = '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5" style="display:block;margin:20px auto 10px;">' +
                    '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/>' +
                    '<path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/>' +
                    '<path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/>' +
                    '<path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/>' +
                    '<path d="M9 6.8a6 6 0 0 1 9 5.2v2"/></svg>';
                listTable.innerHTML = '<tr><td colspan="4" class="passkey-table-empty" style="padding:40px 20px;">' +
                    emptyIcon + '<div style="color:#6b7280;">暂无凭证，点击右上角按钮添加</div></td></tr>';
                countDiv.textContent = '0';
                lastDiv.textContent = '无';
                return;
            }
            
            // 更新统计
            countDiv.textContent = credentials.length;
            lastDiv.textContent = credentials[credentials.length - 1].created_at;
            
            var html = '';
            var trashIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>' +
                '<line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
            
            credentials.forEach(function(cred) {
                html += '<tr>';
                html += '<td>' + cred.id + '</td>';
                html += '<td><code>' + cred.credential_id.substr(0, 32) + '...</code></td>';
                html += '<td>' + cred.created_at + '</td>';
                html += '<td class="passkey-table-actions">';
                html += '<button class="passkey-btn-delete" data-id="' + cred.id + '" style="display:inline-flex;align-items:center;gap:6px;">' + trashIcon + '<span>删除</span></button>';
                html += '</td>';
                html += '</tr>';
            });
            listTable.innerHTML = html;
            
            // 绑定删除按钮事件
            var deleteBtns = document.querySelectorAll('.passkey-btn-delete');
            deleteBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.getAttribute('data-id');
                    if (confirm('确定要删除此凭证吗？')) {
                        deleteCredential(id);
                    }
                });
            });
        }
        
        // 删除凭证
        function deleteCredential(id) {
            // 显示确认对话框
            var confirmIcon = '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" style="display:block;margin:0 auto 15px;">' +
                '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
            
            if (!confirm('确定要删除此凭证吗？\n\n删除后该设备将无法使用 Passkey 登录。')) {
                return;
            }
            
            PasskeyManager.showNotification('正在删除...', 'info');
            
            fetch(PASSKEY_ACTION_URL + '?do=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(parseJSON)
            .then(function(data) {
                if (data.success) {
                    PasskeyManager.showNotification('删除成功！', 'success');
                    loadCredentials();
                } else {
                    PasskeyManager.showNotification('删除失败: ' + data.error, 'error');
                }
            })
            .catch(function(error) {
                console.error('删除凭证失败:', error);
                PasskeyManager.showNotification('删除失败: ' + error.message, 'error');
            });
        }
        
        // 注册新的 Passkey
        registerBtn.addEventListener('click', function() {
            if (registerBtn.disabled) return;
            
            // 检查浏览器支持
            if (!PasskeyManager.isSupported()) {
                PasskeyManager.showNotification('您的浏览器不支持 Passkey 功能', 'error');
                return;
            }
            
            registerBtn.disabled = true;
            
            // 使用 SVG 加载图标
            var loadingIcon = '<span style="display:inline-block;animation:passkeyRotate 1s linear infinite;">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>' +
                '<line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/>' +
                '<line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>' +
                '<line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>' +
                '</svg></span>';
            var fingerprintIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/>' +
                '<path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/>' +
                '<path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/>' +
                '<path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/>' +
                '<path d="M9 6.8a6 6 0 0 1 9 5.2v2"/></svg>';
            
            registerBtn.innerHTML = loadingIcon + '<span style="margin-left:8px;">正在注册...</span>';
            
            PasskeyManager.register()
                .then(function(result) {
                    PasskeyManager.showNotification('Passkey 注册成功！您现在可以使用此设备快速登录', 'success');
                    // 延迟一下再刷新列表，让通知显示出来
                    setTimeout(function() {
                        loadCredentials();
                    }, 500);
                })
                .catch(function(error) {
                    // PasskeyManager.register() 内部已经显示了通知
                    console.error('Register error:', error);
                })
                .finally(function() {
                    registerBtn.disabled = false;
                    registerBtn.innerHTML = fingerprintIcon + '<span style="margin-left:8px;">注册新 Passkey</span>';
                });
        });
        
        // 加载登录记录
        function loadLoginLogs() {
            var logsTable = document.getElementById('passkey-login-logs');
            
            // 显示加载中
            logsTable.innerHTML = '<tr><td colspan="4" class="passkey-table-empty">' +
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;animation:passkeyRotate 1s linear infinite;margin-right:8px;vertical-align:middle;">' +
                '<line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>' +
                '<line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/>' +
                '<line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>' +
                '<line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>' +
                '</svg>加载中...</td></tr>';
            
            fetch(PASSKEY_ACTION_URL + '?do=login-logs&limit=20')
                .then(parseJSON)
                .then(function(data) {
                    if (data.success) {
                        renderLoginLogs(data.data, logsTable);
                    } else {
                        logsTable.innerHTML = '<tr><td colspan="4" class="passkey-table-empty">加载失败: ' + data.error + '</td></tr>';
                    }
                })
                .catch(function(error) {
                    console.error('加载登录记录失败:', error);
                    logsTable.innerHTML = '<tr><td colspan="4" class="passkey-table-empty">加载失败: ' + error.message + '</td></tr>';
                });
        }
        
        // 渲染登录记录
        function renderLoginLogs(logs, table) {
            if (!logs || logs.length === 0) {
                var historyIcon = '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5" style="display:block;margin:20px auto 10px;">' +
                    '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                table.innerHTML = '<tr><td colspan="4" class="passkey-table-empty" style="padding:40px 20px;">' +
                    historyIcon + '<div style="color:#6b7280;">暂无登录记录</div></td></tr>';
                return;
            }
            
            var successIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<polyline points="20 6 9 17 4 12"/></svg>';
            var failedIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            
            var html = '';
            logs.forEach(function(log) {
                var isSuccess = log.status.toLowerCase().indexOf('success') > -1 || log.status.toLowerCase().indexOf('成功') > -1;
                var statusClass = isSuccess ? 'success' : 'failed';
                var statusIcon = isSuccess ? successIcon : failedIcon;
                
                html += '<tr>';
                html += '<td>' + log.login_time + '</td>';
                html += '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (log.user_agent || 'Unknown') + '">' + (log.user_agent || 'Unknown') + '</td>';
                html += '<td><code style="font-size:12px;">' + log.ip_address + '</code></td>';
                html += '<td><span class="passkey-status-badge ' + statusClass + '">' + statusIcon + ' ' + log.status + '</span></td>';
                html += '</tr>';
            });
            table.innerHTML = html;
        }
        
        // 页面加载时获取凭证列表和登录记录
        loadCredentials();
        loadLoginLogs();
    })();
    </script>
</body>
</html>