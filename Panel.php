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
$pluginVersion = '1.0.8'; // 与 Plugin.php 中的版本号保持一致
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="color-scheme" content="light dark">
    <title>Passkey 身份验证管理 - <?php echo htmlspecialchars($options->title); ?></title>
    <style>
        :root {
            --bg-primary: #f0f2f5;
            --bg-secondary: #ffffff;
            --bg-tertiary: #fafbfc;
            --bg-hover: #f3f4f6;
            --text-primary: #1f2937;
            --text-secondary: #374151;
            --text-tertiary: #6b7280;
            --text-quaternary: #9ca3af;
            --border-color: #e5e7eb;
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --primary-active: #1d4ed8;
            --danger-color: #dc2626;
            --success-bg: #f0fdf4;
            --success-border: #22c55e;
            --success-text: #166534;
            --error-bg: #fef2f2;
            --error-border: #ef4444;
            --error-text: #991b1b;
            --info-bg: #eff6ff;
            --info-border: #3b82f6;
            --info-text: #1e40af;
            --notification-bg: #ffffff;
            --modal-bg: #ffffff;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-primary: #0d1117;
                --bg-secondary: #161b22;
                --bg-tertiary: #21262d;
                --bg-hover: #21262d;
                --text-primary: #e6edf3;
                --text-secondary: #c9d1d9;
                --text-tertiary: #8b949e;
                --text-quaternary: #6e7681;
                --border-color: #30363d;
                --primary-color: #58a6ff;
                --primary-hover: #79b8ff;
                --primary-active: #a5d6ff;
                --danger-color: #f85149;
                --success-bg: rgba(46, 160, 67, 0.1);
                --success-border: #2ea043;
                --success-text: #3fb950;
                --error-bg: rgba(248, 81, 73, 0.1);
                --error-border: #f85149;
                --error-text: #f85149;
                --info-bg: rgba(88, 166, 255, 0.1);
                --info-border: #58a6ff;
                --info-text: #58a6ff;
                --notification-bg: #1c2128;
                --modal-bg: #161b22;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, "Microsoft YaHei", sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.5;
            font-size: 14px;
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        .passkey-layout {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* 顶部导航栏 */
        .passkey-navbar {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
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
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .passkey-navbar-title::before {
            content: '';
            width: 3px;
            height: 18px;
            background: var(--primary-color);
            display: inline-block;
        }
        
        .passkey-navbar-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .passkey-menu-toggle {
            display: none;
            background: none;
            border: none;
            padding: 8px;
            cursor: pointer;
            color: var(--text-secondary);
        }
        
        .passkey-menu-toggle svg {
            width: 24px;
            height: 24px;
        }
        
        .passkey-mobile-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 16px;
            z-index: 999;
        }
        
        .passkey-mobile-menu.active {
            display: block;
        }
        
        .passkey-mobile-menu-item {
            display: block;
            padding: 12px 16px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .passkey-mobile-menu-item:hover {
            background: var(--bg-hover);
        }
        
        .passkey-mobile-menu-item:last-child {
            margin-bottom: 0;
        }
        
        .passkey-mobile-user {
            padding: 12px 16px;
            background: var(--bg-tertiary);
            border-radius: 6px;
            margin-bottom: 12px;
            font-size: 13px;
            color: var(--text-tertiary);
        }

        .passkey-mobile-user strong {
            color: var(--text-primary);
        }

        .passkey-badge {
            padding: 4px 12px;
            background: var(--bg-hover);
            color: var(--text-tertiary);
            font-size: 12px;
            border: 1px solid var(--border-color);
        }

        .passkey-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }

        .passkey-link:hover {
            color: var(--primary-hover);
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
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            margin-bottom: 16px;
        }

        .passkey-section-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-tertiary);
        }

        .passkey-section-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
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
            min-height: 44px;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }
        
        .passkey-btn-primary {
            background: var(--primary-color);
            color: #ffffff;
        }

        .passkey-btn-primary:hover {
            background: var(--primary-hover);
        }

        .passkey-btn-primary:active {
            background: var(--primary-active);
        }

        .passkey-btn-primary:disabled {
            background: var(--text-tertiary);
            cursor: not-allowed;
        }

        .passkey-btn-danger {
            background: var(--bg-secondary);
            color: var(--danger-color);
            border: 1px solid var(--border-color);
        }

        .passkey-btn-danger:hover {
            background: var(--error-bg);
            border-color: var(--danger-color);
        }
        
        /* 状态消息 */
        .passkey-alert {
            padding: 12px 16px;
            margin-bottom: 16px;
            border-left: 3px solid;
            display: none;
        }
        
        .passkey-alert-success {
            background: var(--success-bg);
            border-color: var(--success-border);
            color: var(--success-text);
        }

        .passkey-alert-error {
            background: var(--error-bg);
            border-color: var(--error-border);
            color: var(--error-text);
        }

        .passkey-alert-info {
            background: var(--info-bg);
            border-color: var(--info-border);
            color: var(--info-text);
        }
        
        /* 表格样式 */
        .passkey-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .passkey-table thead {
            background: var(--bg-tertiary);
        }

        .passkey-table th {
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .passkey-table td {
            padding: 14px 16px;
            font-size: 13px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
        }

        .passkey-table tbody tr:hover {
            background: var(--bg-hover);
        }
        
        .passkey-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .passkey-table code {
            background: var(--bg-tertiary);
            padding: 3px 8px;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 12px;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .passkey-table-empty {
            text-align: center;
            padding: 48px 20px;
            color: var(--text-quaternary);
        }
        
        .passkey-table-actions {
            text-align: right;
        }
        
        .passkey-btn-delete {
            padding: 6px 12px;
            font-size: 12px;
            background: var(--bg-secondary);
            color: var(--danger-color);
            border: 1px solid var(--border-color);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            min-height: 44px;
            min-width: 44px;
            -webkit-tap-highlight-color: transparent;
        }

        .passkey-btn-delete:hover {
            background: var(--error-bg);
            border-color: var(--danger-color);
        }

        .passkey-card-item {
            display: none;
        }

        .passkey-card-row {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-secondary);
        }

        .passkey-card-row:last-child {
            border-bottom: none;
        }

        .passkey-card-row:hover {
            background: var(--bg-hover);
        }

        .passkey-card-field {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 8px 0;
        }

        .passkey-card-field:not(:last-child) {
            border-bottom: 1px solid var(--border-color);
        }

        .passkey-card-label {
            font-size: 12px;
            color: var(--text-tertiary);
            font-weight: 500;
            flex-shrink: 0;
            min-width: 80px;
        }

        .passkey-card-value {
            font-size: 13px;
            color: var(--text-secondary);
            text-align: right;
            word-break: break-all;
        }

        .passkey-card-actions {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
        }
        
        /* 操作链接 */
        .passkey-action-link {
            color: var(--danger-color);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }

        .passkey-action-link:hover {
            color: var(--danger-color);
            text-decoration: underline;
            opacity: 0.8;
        }

        /* 信息卡片 */
        .passkey-info-box {
            border: 1px solid var(--border-color);
            padding: 16px;
            margin-bottom: 16px;
        }

        .passkey-info-box-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
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
            color: var(--text-secondary);
            line-height: 1.6;
            position: relative;
            padding-left: 16px;
        }

        .passkey-info-box li::before {
            content: '•';
            position: absolute;
            left: 0;
            color: var(--text-quaternary);
        }
        
        /* 统计卡片 */
        .passkey-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .passkey-stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            padding: 20px;
            transition: box-shadow 0.2s;
        }

        .passkey-stat-card:active {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .passkey-stat-label {
            font-size: 12px;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .passkey-stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* 页脚 */
        .passkey-footer {
            background: var(--bg-secondary);
            border-top: 1px solid var(--border-color);
            padding: 20px 0;
            margin-top: auto;
        }

        .passkey-footer-inner {
            width: 100%;
            margin: 0 auto;
            padding: 0 24px;
            text-align: center;
            font-size: 12px;
            color: var(--text-tertiary);
        }

        .passkey-footer-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .passkey-footer-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .passkey-footer-ieee {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .passkey-footer-ieee svg {
            width: 40px;
            height: auto;
        }

        .passkey-footer-ieee span {
            font-size: 11px;
            color: var(--text-tertiary);
        }
        
        /* 响应式 */
        @media (max-width: 768px) {
            body {
                padding-bottom: env(safe-area-inset-bottom);
            }
            
            .passkey-navbar-inner {
                padding: 12px 16px;
                position: relative;
            }
            
            .passkey-navbar-title {
                font-size: 15px;
            }
            
            .passkey-navbar-actions {
                display: none;
            }
            
            .passkey-menu-toggle {
                display: block;
            }
            
            .passkey-main {
                padding: 12px;
            }
            
            .passkey-section {
                margin-bottom: 12px;
            }
            
            .passkey-section-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                padding: 16px;
            }
            
            .passkey-section-title {
                font-size: 15px;
            }
            
            .passkey-section-body {
                padding: 12px;
            }
            
            .passkey-table {
                display: none;
            }
            
            .passkey-card-item {
                display: block;
            }
            
            .passkey-table-empty {
                display: block;
                padding: 32px 16px;
            }
            
            .passkey-btn {
                width: 100%;
                justify-content: center;
                padding: 12px 20px;
                font-size: 14px;
            }
            
            .passkey-btn-delete {
                width: auto;
                min-width: 80px;
            }
            
            .passkey-stats {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .passkey-stat-card {
                padding: 16px;
            }
            
            .passkey-stat-label {
                font-size: 11px;
            }
            
            .passkey-stat-value {
                font-size: 24px;
            }
            
            .passkey-info-box {
                padding: 14px;
                margin-bottom: 12px;
            }
            
            .passkey-info-box-title {
                font-size: 13px;
            }
            
            .passkey-info-box li {
                font-size: 12px;
                padding: 5px 0 5px 14px;
            }
            
            .passkey-footer-inner {
                padding: 0 16px;
                padding-bottom: env(safe-area-inset-bottom);
            }
            
            #passkey-notification-container {
                left: 12px !important;
                right: 12px !important;
                top: calc(12px + env(safe-area-inset-top)) !important;
                max-width: none !important;
            }

            .passkey-notification {
                padding: 14px 16px;
                font-size: 13px;
            }
            
            .passkey-link {
                padding: 8px 0;
                display: inline-block;
            }

            .passkey-footer-content {
                flex-direction: column;
                gap: 12px;
            }
        }
        
        @media (max-width: 480px) {
            .passkey-navbar-title {
                font-size: 14px;
            }
            
            .passkey-section-title {
                font-size: 14px;
            }
            
            .passkey-stat-value {
                font-size: 20px;
            }
            
            .passkey-card-row {
                padding: 12px;
            }
            
            .passkey-card-field {
                flex-direction: column;
                gap: 4px;
            }
            
            .passkey-card-value {
                text-align: left;
                font-size: 12px;
            }
            
            .passkey-card-label {
                min-width: auto;
            }
            
            .passkey-card-actions {
                justify-content: center;
            }
            
            .passkey-btn-delete {
                width: 100%;
            }
        }
        
        @media (hover: none) and (pointer: coarse) {
            .passkey-btn:hover,
            .passkey-btn-delete:hover,
            .passkey-link:hover,
            .passkey-action-link:hover {
                background: inherit;
                color: inherit;
                text-decoration: none;
            }
            
            .passkey-btn:active {
                opacity: 0.8;
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
        #passkey-notification-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .passkey-notification {
            position: relative !important;
            top: auto !important;
            right: auto !important;
            left: auto !important;
            min-width: 0;
            max-width: none;
            width: 100%;
            padding: 16px 20px;
            background: white;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: passkeySlideIn 0.3s ease;
            margin: 0 !important;
            pointer-events: auto;
        }
        
        .passkey-notification.success {
            border-left: 4px solid var(--success-border);
        }

        .passkey-notification.error {
            border-left: 4px solid var(--error-border);
        }

        .passkey-notification.info {
            border-left: 4px solid var(--info-border);
        }

        .passkey-notification.warning {
            border-left: 4px solid var(--warning-border, #f59e0b);
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
            background: var(--success-bg);
            color: var(--success-text);
        }

        .passkey-status-badge.failed {
            background: var(--error-bg);
            color: var(--error-text);
        }
        
        .passkey-status-badge svg {
            width: 14px;
            height: 14px;
        }
        
        /* 弹窗遮罩层 */
        .passkey-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10001;
            animation: passkeyFadeIn 0.2s ease;
        }
        
        .passkey-modal-overlay.active {
            display: block;
        }
        
        /* 弹窗 */
        .passkey-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--modal-bg);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            z-index: 10002;
            min-width: 320px;
            max-width: 90%;
            max-height: 90vh;
            overflow: hidden;
            animation: passkeyModalIn 0.3s ease;
        }
        
        .passkey-modal.active {
            display: block;
        }
        
        /* 弹窗头部 */
        .passkey-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
        }
        
        .passkey-modal-title {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin: 0;
        }

        .passkey-modal-close {
            background: none;
            border: none;
            padding: 6px;
            cursor: pointer;
            color: var(--text-quaternary);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s;
            min-width: 28px;
            min-height: 28px;
            -webkit-tap-highlight-color: transparent;
        }

        .passkey-modal-close:hover {
            color: var(--text-secondary);
        }
        
        /* 弹窗内容 */
        .passkey-modal-body {
            padding: 0 20px 20px 20px;
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.5;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .passkey-modal-body p {
            margin: 0;
        }
        
        /* 弹窗底部 */
        .passkey-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 0 20px 16px 20px;
        }
        
        /* 弹窗动画 */
        @keyframes passkeyFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        @keyframes passkeyModalIn {
            from {
                opacity: 0;
                transform: translate(-50%, -50%) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }
        
        /* 移动端弹窗优化 */
        @media (max-width: 768px) {
            .passkey-modal {
                min-width: 90%;
                max-width: 90%;
            }
            
            .passkey-modal-header {
                padding: 14px 16px;
            }
            
            .passkey-modal-body {
                padding: 16px;
                font-size: 13px;
            }
            
            .passkey-modal-footer {
                padding: 10px 16px;
                flex-direction: column-reverse;
            }
            
            .passkey-modal-footer .passkey-btn {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .passkey-modal {
                min-width: 95%;
                max-width: 95%;
            }
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
                <button type="button" class="passkey-menu-toggle" id="passkey-menu-toggle" aria-label="菜单">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="passkey-mobile-menu" id="passkey-mobile-menu">
                <div class="passkey-mobile-user">
                    当前用户: <strong><?php echo htmlspecialchars($user->screenName); ?></strong>
                </div>
                <a href="<?php echo $options->adminUrl; ?>" class="passkey-mobile-menu-item">返回控制台</a>
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
                    <div class="passkey-card-item" id="passkey-list-mobile">
                        <div class="passkey-table-empty">加载中...</div>
                    </div>
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
                    <div class="passkey-card-item" id="passkey-login-logs-mobile">
                        <div class="passkey-table-empty">加载中...</div>
                    </div>
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
                <div class="passkey-footer-content">
                    <div class="passkey-footer-text">
                        <span>Passkey 身份验证插件</span>
                        <span style="opacity: 0.7;">基于 FIDO2/WebAuthn 标准</span>
                    </div>
                    <div class="passkey-footer-ieee">
                        <img src="<?php echo $pluginUrl; ?>/assist/css/ieee.svg" alt="Build by IEEE Engineer" width="40" height="auto">
                        <span>IEEE Standard</span>
                    </div>
                </div>
            </div>
        </footer>
    </div>
    
    <div class="passkey-modal-overlay" id="passkey-modal-overlay"></div>
    <div class="passkey-modal" id="passkey-modal">
        <div class="passkey-modal-header">
            <h3 class="passkey-modal-title" id="passkey-modal-title">确认</h3>
            <button type="button" class="passkey-modal-close" id="passkey-modal-close" aria-label="关闭">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="passkey-modal-body" id="passkey-modal-body">
        </div>
        <div class="passkey-modal-footer">
            <button type="button" class="passkey-btn passkey-btn-danger" id="passkey-modal-cancel">取消</button>
            <button type="button" class="passkey-btn passkey-btn-primary" id="passkey-modal-confirm">确定</button>
        </div>
    </div>
    
    <script>
        var PASSKEY_ACTION_URL = "<?php echo $options->index; ?>/action/passkey";
    </script>
    <script src="<?php echo $pluginUrl; ?>/assist/js/passkey.js?v=<?php echo $pluginVersion; ?>"></script>
    <script>
    (function() {
        var listTable = document.getElementById('passkey-list');
        var listTableMobile = document.getElementById('passkey-list-mobile');
        var registerBtn = document.getElementById('register-passkey-btn');
        var countDiv = document.getElementById('passkey-count');
        var lastDiv = document.getElementById('passkey-last');
        var menuToggle = document.getElementById('passkey-menu-toggle');
        var mobileMenu = document.getElementById('passkey-mobile-menu');
        
        if (menuToggle && mobileMenu) {
            menuToggle.addEventListener('click', function() {
                mobileMenu.classList.toggle('active');
            });
            
            document.addEventListener('click', function(e) {
                if (!menuToggle.contains(e.target) && !mobileMenu.contains(e.target)) {
                    mobileMenu.classList.remove('active');
                }
            });
        }
        
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
        
        var modalOverlay = document.getElementById('passkey-modal-overlay');
        var modal = document.getElementById('passkey-modal');
        var modalTitle = document.getElementById('passkey-modal-title');
        var modalBody = document.getElementById('passkey-modal-body');
        var modalClose = document.getElementById('passkey-modal-close');
        var modalCancel = document.getElementById('passkey-modal-cancel');
        var modalConfirm = document.getElementById('passkey-modal-confirm');
        var currentResolve = null;
        
        function showModal(title, message, confirmText, cancelText) {
            return new Promise(function(resolve) {
                currentResolve = resolve;
                modalTitle.textContent = title || '确认';
                modalBody.innerHTML = '<p>' + message.replace(/\n\n/g, '</p><p style="margin-top:12px;">') + '</p>';
                modalConfirm.textContent = confirmText || '确定';
                modalCancel.textContent = cancelText || '取消';
                modalOverlay.classList.add('active');
                modal.classList.add('active');
            });
        }
        
        function hideModal(result) {
            modalOverlay.classList.remove('active');
            modal.classList.remove('active');
            if (currentResolve) {
                currentResolve(result);
                currentResolve = null;
            }
        }
        
        modalClose.addEventListener('click', function() {
            hideModal(false);
        });
        
        modalCancel.addEventListener('click', function() {
            hideModal(false);
        });
        
        modalConfirm.addEventListener('click', function() {
            hideModal(true);
        });
        
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) {
                hideModal(false);
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalOverlay.classList.contains('active')) {
                hideModal(false);
            }
        });
        
        function confirmDialog(message) {
            return showModal('确认', message, '确定', '取消');
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
            if (listTableMobile) {
                listTableMobile.innerHTML = '<div class="passkey-table-empty">' + message + '</div>';
            }
            countDiv.textContent = '0';
            lastDiv.textContent = '无';
        }
        
        // 渲染凭证列表
        function renderCredentials(credentials) {
            if (credentials.length === 0) {
                var emptyIcon = '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--text-quaternary)" stroke-width="1.5" style="display:block;margin:20px auto 10px;">' +
                    '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/>' +
                    '<path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/>' +
                    '<path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/>' +
                    '<path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/>' +
                    '<path d="M9 6.8a6 6 0 0 1 9 5.2v2"/></svg>';
                var emptyHtml = '<tr><td colspan="4" class="passkey-table-empty" style="padding:40px 20px;">' +
                    emptyIcon + '<div style="color:var(--text-tertiary);">暂无凭证，点击右上角按钮添加</div></td></tr>';
                listTable.innerHTML = emptyHtml;
                if (listTableMobile) {
                    listTableMobile.innerHTML = '<div class="passkey-table-empty" style="padding:40px 20px;">' +
                        emptyIcon + '<div style="color:var(--text-tertiary);">暂无凭证，点击上方按钮添加</div></div>';
                }
                countDiv.textContent = '0';
                lastDiv.textContent = '无';
                return;
            }
            
            countDiv.textContent = credentials.length;
            lastDiv.textContent = credentials[credentials.length - 1].created_at;
            
            var trashIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>' +
                '<line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
            
            var html = '';
            var mobileHtml = '';
            
            credentials.forEach(function(cred) {
                html += '<tr>';
                html += '<td>' + cred.id + '</td>';
                html += '<td><code>' + cred.credential_id.substr(0, 32) + '...</code></td>';
                html += '<td>' + cred.created_at + '</td>';
                html += '<td class="passkey-table-actions">';
                html += '<button class="passkey-btn-delete" data-id="' + cred.id + '" style="display:inline-flex;align-items:center;gap:6px;">' + trashIcon + '<span>删除</span></button>';
                html += '</td>';
                html += '</tr>';
                
                mobileHtml += '<div class="passkey-card-row">';
                mobileHtml += '<div class="passkey-card-field"><span class="passkey-card-label">ID</span><span class="passkey-card-value">' + cred.id + '</span></div>';
                mobileHtml += '<div class="passkey-card-field"><span class="passkey-card-label">凭证标识符</span><span class="passkey-card-value"><code style="font-size:11px;word-break:break-all;">' + cred.credential_id.substr(0, 32) + '...</code></span></div>';
                mobileHtml += '<div class="passkey-card-field"><span class="passkey-card-label">创建时间</span><span class="passkey-card-value">' + cred.created_at + '</span></div>';
                mobileHtml += '<div class="passkey-card-actions">';
                mobileHtml += '<button class="passkey-btn-delete passkey-btn-danger" data-id="' + cred.id + '" style="display:inline-flex;align-items:center;gap:6px;">' + trashIcon + '<span>删除</span></button>';
                mobileHtml += '</div>';
                mobileHtml += '</div>';
            });
            
            listTable.innerHTML = html;
            if (listTableMobile) {
                listTableMobile.innerHTML = mobileHtml;
            }
            
            var deleteBtns = document.querySelectorAll('.passkey-btn-delete');
            deleteBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.getAttribute('data-id');
                    confirmDialog('确定要删除此凭证吗？').then(function(result) {
                        if (result) {
                            deleteCredential(id);
                        }
                    });
                });
            });
        }
        
        // 删除凭证
        async function deleteCredential(id) {
            var result = await confirmDialog('确定要删除此凭证吗？\n\n删除后该设备将无法使用 Passkey 登录。');
            if (!result) {
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
            var logsTableMobile = document.getElementById('passkey-login-logs-mobile');
            
            var loadingHtml = '<tr><td colspan="4" class="passkey-table-empty">' +
                '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;animation:passkeyRotate 1s linear infinite;margin-right:8px;vertical-align:middle;">' +
                '<line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>' +
                '<line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/>' +
                '<line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>' +
                '<line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>' +
                '</svg>加载中...</td></tr>';
            logsTable.innerHTML = loadingHtml;
            if (logsTableMobile) {
                logsTableMobile.innerHTML = '<div class="passkey-table-empty">' +
                    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;animation:passkeyRotate 1s linear infinite;margin-right:8px;vertical-align:middle;">' +
                    '<line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/>' +
                    '<line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/>' +
                    '<line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/>' +
                    '<line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>' +
                    '</svg>加载中...</div>';
            }
            
            fetch(PASSKEY_ACTION_URL + '?do=login-logs&limit=20')
                .then(parseJSON)
                .then(function(data) {
                    if (data.success) {
                        renderLoginLogs(data.data, logsTable, logsTableMobile);
                    } else {
                        var errorHtml = '<tr><td colspan="4" class="passkey-table-empty">加载失败: ' + data.error + '</td></tr>';
                        logsTable.innerHTML = errorHtml;
                        if (logsTableMobile) {
                            logsTableMobile.innerHTML = '<div class="passkey-table-empty">加载失败: ' + data.error + '</div>';
                        }
                    }
                })
                .catch(function(error) {
                    console.error('加载登录记录失败:', error);
                    var errorHtml = '<tr><td colspan="4" class="passkey-table-empty">加载失败: ' + error.message + '</td></tr>';
                    logsTable.innerHTML = errorHtml;
                    if (logsTableMobile) {
                        logsTableMobile.innerHTML = '<div class="passkey-table-empty">加载失败: ' + error.message + '</div>';
                    }
                });
        }
        
        // 渲染登录记录
        function renderLoginLogs(logs, table, tableMobile) {
            if (!logs || logs.length === 0) {
                var historyIcon = '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--text-quaternary)" stroke-width="1.5" style="display:block;margin:20px auto 10px;">' +
                    '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                var emptyHtml = '<tr><td colspan="4" class="passkey-table-empty" style="padding:40px 20px;">' +
                    historyIcon + '<div style="color:var(--text-tertiary);">暂无登录记录</div></td></tr>';
                table.innerHTML = emptyHtml;
                if (tableMobile) {
                    tableMobile.innerHTML = '<div class="passkey-table-empty" style="padding:40px 20px;">' +
                        historyIcon + '<div style="color:var(--text-tertiary);">暂无登录记录</div></div>';
                }
                return;
            }
            
            var successIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<polyline points="20 6 9 17 4 12"/></svg>';
            var failedIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            
            var html = '';
            var mobileHtml = '';
            
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
                
                mobileHtml += '<div class="passkey-card-row">';
                mobileHtml += '<div class="passkey-card-field"><span class="passkey-card-label">登录时间</span><span class="passkey-card-value">' + log.login_time + '</span></div>';
                mobileHtml += '<div class="passkey-card-field"><span class="passkey-card-label">设备/浏览器</span><span class="passkey-card-value" style="font-size:12px;word-break:break-word;">' + (log.user_agent || 'Unknown') + '</span></div>';
                mobileHtml += '<div class="passkey-card-field"><span class="passkey-card-label">IP 地址</span><span class="passkey-card-value"><code style="font-size:11px;">' + log.ip_address + '</code></span></div>';
                mobileHtml += '<div class="passkey-card-field"><span class="passkey-card-label">状态</span><span class="passkey-card-value"><span class="passkey-status-badge ' + statusClass + '">' + statusIcon + ' ' + log.status + '</span></span></div>';
                mobileHtml += '</div>';
            });
            
            table.innerHTML = html;
            if (tableMobile) {
                tableMobile.innerHTML = mobileHtml;
            }
        }
        
        // 页面加载时获取凭证列表和登录记录
        loadCredentials();
        loadLoginLogs();
    })();
    </script>
</body>
</html>