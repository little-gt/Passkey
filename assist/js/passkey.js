/**
 * Passkey Manager
 * WebAuthn API 封装，提供注册和登录功能
 * 增强了浏览器兼容性（Firefox、Safari）和前端验证
 */
var PasskeyManager = (function() {
    'use strict';
    
    /**
     * SVG 图标库
     */
    var SVGIcons = {
        fingerprint: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/></svg>',
        check: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        x: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        alert: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        info: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        loading: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>'
    };
    
    /**
     * 浏览器和平台检测（增强版）
     */
    var BrowserDetector = {
        userAgent: navigator.userAgent.toLowerCase(),
        platform: navigator.platform.toLowerCase(),
        
        isFirefox: function() {
            return this.userAgent.indexOf('firefox') > -1;
        },
        
        isSafari: function() {
            return this.userAgent.indexOf('safari') > -1 && 
                   this.userAgent.indexOf('chrome') === -1 &&
                   this.userAgent.indexOf('chromium') === -1 &&
                   this.userAgent.indexOf('fxios') === -1;
        },
        
        isChrome: function() {
            return this.userAgent.indexOf('chrome') > -1 || 
                   this.userAgent.indexOf('crios') > -1;
        },
        
        isEdge: function() {
            return this.userAgent.indexOf('edg') > -1 || 
                   this.userAgent.indexOf('edge') > -1;
        },
        
        isMac: function() {
            return this.platform.indexOf('mac') > -1 || 
                   this.userAgent.indexOf('mac') > -1;
        },
        
        isIOS: function() {
            return /iphone|ipad|ipod/.test(this.userAgent);
        },
        
        isIPad: function() {
            return this.userAgent.indexOf('ipad') > -1 ||
                   (this.userAgent.indexOf('mac') > -1 && this.userAgent.indexOf('touch') > -1);
        },
        
        isAndroid: function() {
            return this.userAgent.indexOf('android') > -1;
        },
        
        isWindows: function() {
            return this.platform.indexOf('win') > -1;
        },
        
        isLinux: function() {
            return this.platform.indexOf('linux') > -1;
        },
        
        isMobile: function() {
            return this.isIOS() || this.isAndroid() || 
                   /mobile|android|iphone|ipod|blackberry|iemobile|opera mini/i.test(this.userAgent);
        },
        
        isTouchDevice: function() {
            return 'ontouchstart' in window || 
                   navigator.maxTouchPoints > 0 ||
                   navigator.msMaxTouchPoints > 0;
        },
        
        isStandaloneMode: function() {
            return window.navigator.standalone === true || 
                   window.matchMedia('(display-mode: standalone)').matches;
        },
        
        getBrowserName: function() {
            if (this.isFirefox()) return 'Firefox';
            if (this.isSafari()) return 'Safari';
            if (this.isEdge()) return 'Edge';
            if (this.isChrome()) return 'Chrome';
            return 'Unknown';
        },
        
        getBrowserVersion: function() {
            var match;
            if (this.isFirefox()) {
                match = this.userAgent.match(/firefox\/(\d+)/);
            } else if (this.isSafari()) {
                match = this.userAgent.match(/version\/(\d+)/);
            } else if (this.isEdge()) {
                match = this.userAgent.match(/edg\/(\d+)/) || this.userAgent.match(/edge\/(\d+)/);
            } else if (this.isChrome()) {
                match = this.userAgent.match(/chrome\/(\d+)/) || this.userAgent.match(/crios\/(\d+)/);
            }
            return match ? parseInt(match[1]) : 0;
        },
        
        getPlatformName: function() {
            if (this.isIOS()) return 'iOS';
            if (this.isMac()) return 'macOS';
            if (this.isAndroid()) return 'Android';
            if (this.isWindows()) return 'Windows';
            if (this.isLinux()) return 'Linux';
            return 'Unknown';
        },
        
        getDetailedInfo: function() {
            return {
                browser: this.getBrowserName(),
                browserVersion: this.getBrowserVersion(),
                platform: this.getPlatformName(),
                isMobile: this.isMobile(),
                isTouch: this.isTouchDevice(),
                isStandalone: this.isStandaloneMode()
            };
        }
    };
    
    /**
     * 前端验证工具
     */
    var Validator = {
        // 验证用户名（3-20个字符，字母数字下划线）
        username: function(value) {
            if (!value || typeof value !== 'string') {
                return { valid: false, error: '用户名不能为空' };
            }
            
            var trimmed = value.trim();
            if (trimmed.length < 3) {
                return { valid: false, error: '用户名至少需要3个字符' };
            }
            if (trimmed.length > 20) {
                return { valid: false, error: '用户名不能超过20个字符' };
            }
            if (!/^[a-zA-Z0-9_]+$/.test(trimmed)) {
                return { valid: false, error: '用户名只能包含字母、数字和下划线' };
            }
            
            return { valid: true, value: trimmed };
        },
        
        // 验证邮箱
        email: function(value) {
            if (!value || typeof value !== 'string') {
                return { valid: false, error: '邮箱不能为空' };
            }
            
            var trimmed = value.trim();
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (!emailRegex.test(trimmed)) {
                return { valid: false, error: '邮箱格式不正确' };
            }
            if (trimmed.length > 100) {
                return { valid: false, error: '邮箱地址过长' };
            }
            
            return { valid: true, value: trimmed };
        },
        
        // 验证昵称（可选，1-30个字符）
        screenName: function(value) {
            if (!value || typeof value !== 'string') {
                return { valid: true, value: '' };
            }
            
            var trimmed = value.trim();
            if (trimmed.length > 30) {
                return { valid: false, error: '昵称不能超过30个字符' };
            }
            
            return { valid: true, value: trimmed };
        }
    };
    
    /**
     * 检查浏览器是否支持 WebAuthn（增强版）
     */
    function isSupported() {
        var info = BrowserDetector.getDetailedInfo();
        
        // 基础 API 检查
        if (typeof window.PublicKeyCredential === 'undefined') {
            console.warn('Passkey: PublicKeyCredential API not available');
            return false;
        }
        
        if (typeof navigator.credentials === 'undefined') {
            console.warn('Passkey: navigator.credentials not available');
            return false;
        }
        
        if (typeof navigator.credentials.create !== 'function' ||
            typeof navigator.credentials.get !== 'function') {
            console.warn('Passkey: credentials.create/get not available');
            return false;
        }
        
        // 检查是否在 HTTPS 环境下（WebAuthn 要求）
        if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            console.warn('Passkey: WebAuthn requires HTTPS (except localhost)');
            return false;
        }
        
        // Firefox 特殊检查
        if (BrowserDetector.isFirefox()) {
            var firefoxVersion = BrowserDetector.getBrowserVersion();
            if (firefoxVersion > 0 && firefoxVersion < 60) {
                console.warn('Passkey: Firefox version too old, require 60+, got ' + firefoxVersion);
                return false;
            }
            // Firefox Android 特殊处理
            if (BrowserDetector.isAndroid() && firefoxVersion < 92) {
                console.warn('Passkey: Firefox Android requires 92+ for full WebAuthn support');
                return false;
            }
        }
        
        // Safari 特殊检查
        if (BrowserDetector.isSafari()) {
            var safariVersion = BrowserDetector.getBrowserVersion();
            
            if (BrowserDetector.isIOS()) {
                // iOS Safari 14.5+ 支持完整的 WebAuthn
                if (safariVersion > 0 && safariVersion < 14) {
                    console.warn('Passkey: iOS Safari version too old, require 14+, got ' + safariVersion);
                    return false;
                }
                // iPadOS 13+ 需要特殊检测
                if (BrowserDetector.isIPad() && safariVersion < 13) {
                    console.warn('Passkey: iPadOS version too old, require 13+, got ' + safariVersion);
                    return false;
                }
            } else if (BrowserDetector.isMac()) {
                // macOS Safari 13+ 支持
                if (safariVersion > 0 && safariVersion < 13) {
                    console.warn('Passkey: macOS Safari version too old, require 13+, got ' + safariVersion);
                    return false;
                }
            }
        }
        
        // Chrome/Edge 特殊检查
        if (BrowserDetector.isChrome() || BrowserDetector.isEdge()) {
            var chromeVersion = BrowserDetector.getBrowserVersion();
            if (chromeVersion > 0 && chromeVersion < 67) {
                console.warn('Passkey: Chrome/Edge version too old, require 67+, got ' + chromeVersion);
                return false;
            }
            // Android Chrome 特殊处理
            if (BrowserDetector.isAndroid() && chromeVersion < 70) {
                console.warn('Passkey: Android Chrome requires 70+ for full WebAuthn support');
                return false;
            }
        }
        
        // 检查用户验证支持
        try {
            if (!PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
                console.warn('Passkey: isUserVerifyingPlatformAuthenticatorAvailable not available');
            }
        } catch (e) {
            // 某些旧浏览器不支持此方法
        }
        
        return true;
    }
    
    /**
     * 获取不支持时的详细错误信息
     */
    function getUnsupportedReason() {
        var info = BrowserDetector.getDetailedInfo();
        
        if (typeof window.PublicKeyCredential === 'undefined') {
            return '您的浏览器不支持 WebAuthn API';
        }
        
        if (typeof navigator.credentials === 'undefined') {
            return '您的浏览器不支持凭证管理 API';
        }
        
        if (location.protocol !== 'https:' && 
            location.hostname !== 'localhost' && 
            location.hostname !== '127.0.0.1') {
            return 'WebAuthn 需要使用 HTTPS 协议';
        }
        
        if (BrowserDetector.isFirefox()) {
            var version = BrowserDetector.getBrowserVersion();
            if (version > 0 && version < 60) {
                return 'Firefox 版本过低，需要 60 或更高版本（当前: ' + version + '）';
            }
            if (BrowserDetector.isAndroid() && version < 92) {
                return 'Android Firefox 版本过低，需要 92 或更高版本（当前: ' + version + '）';
            }
        }
        
        if (BrowserDetector.isSafari()) {
            var version = BrowserDetector.getBrowserVersion();
            if (BrowserDetector.isIOS() && version > 0 && version < 14) {
                return 'iOS Safari 版本过低，需要 14 或更高版本（当前: ' + version + '）';
            }
            if (BrowserDetector.isMac() && version > 0 && version < 13) {
                return 'macOS Safari 版本过低，需要 13 或更高版本（当前: ' + version + '）';
            }
        }
        
        if (BrowserDetector.isChrome() || BrowserDetector.isEdge()) {
            var version = BrowserDetector.getBrowserVersion();
            if (version > 0 && version < 67) {
                return '浏览器版本过低，需要 67 或更高版本（当前: ' + version + '）';
            }
        }
        
        return '您的设备或浏览器不支持 Passkey 功能';
    }
    
    /**
     * 检查网络连接
     */
    function checkNetwork() {
        if (typeof navigator.onLine !== 'undefined' && !navigator.onLine) {
            return { connected: false, error: '网络连接已断开，请检查您的网络' };
        }
        return { connected: true };
    }
    
    /**
     * 页面内通知系统（使用SVG图标）
     */
    function showNotification(message, type) {
        type = type || 'info'; // info, success, error, warning
        
        // 查找或创建通知容器
        var container = document.getElementById('passkey-notification-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'passkey-notification-container';
            container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;max-width:400px;';
            document.body.appendChild(container);
        }
        
        // 创建通知元素
        var notification = document.createElement('div');
        notification.className = 'passkey-notification passkey-notification-' + type;
        notification.style.cssText = 'background:var(--passport-card-bg,#fff);border:1px solid;padding:15px 20px;margin-bottom:10px;' +
            'animation:slideInRight 0.3s ease;display:flex;align-items:flex-start;gap:12px;color:var(--passport-text,#1f2937);';
        
        // 设置边框颜色和图标
        var borderColor = '#467b96';
        var iconSvg = SVGIcons.info;
        var iconColor = '#467b96';
        
        if (type === 'success') {
            borderColor = '#10b981';
            iconSvg = SVGIcons.check;
            iconColor = '#10b981';
        } else if (type === 'error') {
            borderColor = '#ef4444';
            iconSvg = SVGIcons.x;
            iconColor = '#ef4444';
        } else if (type === 'warning') {
            borderColor = '#f59e0b';
            iconSvg = SVGIcons.alert;
            iconColor = '#f59e0b';
        }
        notification.style.borderColor = borderColor;
        
        // SVG图标容器
        var iconSpan = document.createElement('span');
        iconSpan.innerHTML = iconSvg;
        iconSpan.style.cssText = 'flex-shrink:0;display:flex;align-items:center;justify-content:center;' +
            'width:24px;height:24px;color:' + iconColor + ';';
        
        // 消息文本
        var messageDiv = document.createElement('div');
        messageDiv.style.cssText = 'flex:1;color:var(--passport-text,#1f2937);font-size:14px;line-height:1.5;word-break:break-word;';
        messageDiv.textContent = message;
        
        // 关闭按钮
        var closeBtn = document.createElement('button');
        closeBtn.innerHTML = '×';
        closeBtn.style.cssText = 'background:none;border:none;font-size:24px;line-height:1;cursor:pointer;' +
            'color:var(--passport-placeholder,#6b7280);padding:0;margin-left:8px;flex-shrink:0;width:20px;height:20px;' +
            'transition:color 0.2s ease;';
        closeBtn.onmouseover = function() { this.style.color = 'var(--passport-text,#1f2937)'; };
        closeBtn.onmouseout = function() { this.style.color = 'var(--passport-placeholder,#6b7280)'; };
        closeBtn.onclick = function() {
            notification.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(function() {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        };
        
        notification.appendChild(iconSpan);
        notification.appendChild(messageDiv);
        notification.appendChild(closeBtn);
        container.appendChild(notification);
        
        // 自动关闭
        setTimeout(function() {
            if (notification.parentNode) {
                closeBtn.onclick();
            }
        }, 5000);
        
        // 添加动画样式（如果还没有）
        if (!document.getElementById('passkey-notification-styles')) {
            var style = document.createElement('style');
            style.id = 'passkey-notification-styles';
            style.textContent = '@keyframes slideInRight{from{opacity:0;transform:translateX(100px)}to{opacity:1;transform:translateX(0)}}' +
                '@keyframes slideOutRight{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(100px)}}' +
                '@keyframes passkeyRotate{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }
    }
    
    /**
     * Base64URL 解码
     */
    function base64urlDecode(str) {
        str = str.replace(/-/g, '+').replace(/_/g, '/');
        while (str.length % 4) {
            str += '=';
        }
        
        var binary = atob(str);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }
    
    /**
     * Base64URL 编码
     */
    function base64urlEncode(buffer) {
        var binary = '';
        var bytes = new Uint8Array(buffer);
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=/g, '');
    }
    
    /**
     * 数组缓冲区转 Base64
     */
    function arrayBufferToBase64(buffer) {
        var binary = '';
        var bytes = new Uint8Array(buffer);
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }
    
    /**
     * 注册 Passkey（增强版）
     */
    function register() {
        return new Promise(function(resolve, reject) {
            // 检查浏览器支持
            if (!isSupported()) {
                var errorMsg = getUnsupportedReason();
                showNotification(errorMsg, 'error');
                reject(new Error('Browser not supported'));
                return;
            }
            
            // 检查网络连接
            var networkStatus = checkNetwork();
            if (!networkStatus.connected) {
                showNotification(networkStatus.error, 'error');
                reject(new Error('Network disconnected'));
                return;
            }
            
            var info = BrowserDetector.getDetailedInfo();
            
            // iOS Safari 特殊提示
            if (BrowserDetector.isSafari() && BrowserDetector.isIOS()) {
                if (BrowserDetector.isIPad()) {
                    showNotification('请在 iPad 上使用 Face ID 或触控 ID 完成验证', 'info');
                } else {
                    showNotification('请在 iPhone 上使用 Face ID 或触控 ID 完成验证', 'info');
                }
            } else if (BrowserDetector.isSafari() && BrowserDetector.isMac()) {
                showNotification('请在 Mac 上使用 Touch ID 完成验证', 'info');
            } else if (BrowserDetector.isAndroid()) {
                showNotification('请使用指纹或设备锁屏密码完成验证', 'info');
            } else {
                showNotification('请使用您的设备完成身份验证...', 'info');
            }
            
            showNotification('正在准备注册...', 'info');
            
            // 获取注册选项
            var timeout = BrowserDetector.isMobile() ? 20000 : 15000;
            var fetchTimeout = setTimeout(function() {
                reject(new Error('请求超时，请检查网络连接'));
            }, timeout);
            
            fetch(PASSKEY_ACTION_URL + '?do=register-options')
                .then(function(response) {
                    clearTimeout(fetchTimeout);
                    if (!response.ok) {
                        throw new Error('服务器响应错误: ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        throw new Error(data.error || '获取注册选项失败');
                    }
                    
                    var options = data.data;
                    
                    // 验证服务器返回的数据
                    if (!options.challenge || !options.user || !options.rp) {
                        throw new Error('服务器返回的数据不完整');
                    }
                    
                    // 转换为 WebAuthn 格式
                    var publicKeyOptions = {
                        challenge: base64urlDecode(options.challenge),
                        rp: options.rp,
                        user: {
                            id: base64urlDecode(options.user.id),
                            name: options.user.name,
                            displayName: options.user.displayName
                        },
                        pubKeyCredParams: options.pubKeyCredParams,
                        timeout: options.timeout || 60000,
                        attestation: options.attestation || 'none',
                        authenticatorSelection: options.authenticatorSelection || {}
                    };
                    
                    // Safari 特殊处理
                    if (BrowserDetector.isSafari()) {
                        // Safari 可能需要用户手势才能调用 WebAuthn API
                        // 某些 Safari 版本对 authenticatorSelection 支持不完整
                        if (!publicKeyOptions.authenticatorSelection.userVerification) {
                            publicKeyOptions.authenticatorSelection.userVerification = 'preferred';
                        }
                        
                        // iOS Safari 14.5+ 需要 residentKey 参数
                        if (BrowserDetector.isIOS() && typeof publicKeyOptions.authenticatorSelection.residentKey === 'undefined') {
                            publicKeyOptions.authenticatorSelection.residentKey = 'preferred';
                        }
                        
                        // macOS Safari 可能需要 excludeCredentials
                        if (BrowserDetector.isMac() && options.excludeCredentials && options.excludeCredentials.length > 0) {
                            publicKeyOptions.excludeCredentials = options.excludeCredentials.map(function(cred) {
                                return {
                                    type: cred.type,
                                    id: base64urlDecode(cred.id)
                                };
                            });
                        }
                    }
                    
                    // Android Chrome 特殊处理
                    if (BrowserDetector.isAndroid()) {
                        // Android 可能需要调整超时时间
                        if (!publicKeyOptions.timeout || publicKeyOptions.timeout < 90000) {
                            publicKeyOptions.timeout = 90000;
                        }
                        
                        // 确保支持 residentKey
                        if (typeof publicKeyOptions.authenticatorSelection.residentKey === 'undefined') {
                            publicKeyOptions.authenticatorSelection.residentKey = 'preferred';
                        }
                    }
                    
                    // Firefox 特殊处理
                    if (BrowserDetector.isFirefox()) {
                        // Firefox 可能需要更长的超时时间
                        if (!publicKeyOptions.timeout || publicKeyOptions.timeout < 90000) {
                            publicKeyOptions.timeout = 90000;
                        }
                    }
                    
                    // 移动设备优化
                    if (BrowserDetector.isMobile()) {
                        // 移动设备可能需要更长的超时时间
                        if (!publicKeyOptions.timeout || publicKeyOptions.timeout < 90000) {
                            publicKeyOptions.timeout = 90000;
                        }
                    }
                    
                    // 创建凭证
                    return navigator.credentials.create({
                        publicKey: publicKeyOptions
                    });
                })
                .then(function(credential) {
                    if (!credential) {
                        throw new Error('创建凭证失败');
                    }
                    
                    // 验证凭证数据
                    if (!credential.id || !credential.rawId || !credential.response) {
                        throw new Error('凭证数据不完整');
                    }
                    
                    showNotification('正在保存凭证...', 'info');
                    
                    // 准备要发送的数据
                    // 注意：id 是 base64url 编码，rawId 是 ArrayBuffer
                    // 为了一致性，我们统一使用 id（它已是字符串）
                    var data = {
                        id: credential.id,
                        rawId: credential.id,  // 直接使用 id（base64url）
                        type: credential.type,
                        response: {
                            clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                            attestationObject: arrayBufferToBase64(credential.response.attestationObject)
                        }
                    };
                    
                    // 发送到服务器验证
                    timeout = BrowserDetector.isMobile() ? 20000 : 15000;
                    var verifyTimeout = setTimeout(function() {
                        reject(new Error('验证超时，请重试'));
                    }, timeout);
                    
                    return fetch(PASSKEY_ACTION_URL + '?do=register-verify', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data)
                    }).then(function(response) {
                        clearTimeout(verifyTimeout);
                        return response;
                    });
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('服务器响应错误: ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        throw new Error(data.error || '注册失败');
                    }
                    
                    showNotification('Passkey 注册成功！', 'success');
                    resolve(data.data);
                })
                .catch(function(error) {
                    clearTimeout(fetchTimeout);
                    console.error('Passkey register error:', error);
                    
                    var errorMessage = error.message || '未知错误';
                    
                    // 友好的错误信息
                    if (error.name === 'NotAllowedError') {
                        if (BrowserDetector.isMobile()) {
                            errorMessage = '操作已取消或超时，请重试';
                        } else {
                            errorMessage = '操作已取消或超时，请确保您的设备已准备好';
                        }
                    } else if (error.name === 'InvalidStateError') {
                        errorMessage = '此设备已经注册过 Passkey';
                    } else if (error.name === 'NotSupportedError') {
                        errorMessage = '您的设备不支持此认证方式';
                        if (BrowserDetector.isIOS()) {
                            errorMessage = '您的设备不支持 Passkey，需要支持 Face ID 或触控 ID 的设备';
                        } else if (BrowserDetector.isAndroid()) {
                            errorMessage = '您的设备不支持 Passkey，需要支持指纹或设备锁屏的设备';
                        }
                    } else if (error.name === 'SecurityError') {
                        errorMessage = '安全错误：请确保网站使用 HTTPS 连接';
                    } else if (error.name === 'AbortError') {
                        errorMessage = '操作被中断，请重试';
                    } else if (error.name === 'TimeoutError') {
                        errorMessage = '操作超时，请检查您的设备并重试';
                    } else if (error.message && error.message.indexOf('timeout') > -1) {
                        errorMessage = '操作超时，请检查网络连接后重试';
                    } else if (error.message && error.message.indexOf('Network') > -1) {
                        errorMessage = '网络错误，请检查您的网络连接';
                    }
                    
                    showNotification('注册失败: ' + errorMessage, 'error');
                    reject(error);
                });
        });
    }
    
    /**
     * 使用 Passkey 登录（增强版）
     */
    function login() {
        return new Promise(function(resolve, reject) {
            // 检查浏览器支持
            if (!isSupported()) {
                var errorMsg = getUnsupportedReason();
                showNotification(errorMsg, 'error');
                reject(new Error('Browser not supported'));
                return;
            }
            
            // 检查网络连接
            var networkStatus = checkNetwork();
            if (!networkStatus.connected) {
                showNotification(networkStatus.error, 'error');
                reject(new Error('Network disconnected'));
                return;
            }
            
            var info = BrowserDetector.getDetailedInfo();
            
            // iOS Safari 特殊提示
            if (BrowserDetector.isSafari() && BrowserDetector.isIOS()) {
                if (BrowserDetector.isIPad()) {
                    showNotification('请在 iPad 上使用 Face ID 或触控 ID 完成验证', 'info');
                } else {
                    showNotification('请在 iPhone 上使用 Face ID 或触控 ID 完成验证', 'info');
                }
            } else if (BrowserDetector.isSafari() && BrowserDetector.isMac()) {
                showNotification('请在 Mac 上使用 Touch ID 完成验证', 'info');
            } else if (BrowserDetector.isAndroid()) {
                showNotification('请使用指纹或设备锁屏密码完成验证', 'info');
            } else {
                showNotification('请使用您的设备完成身份验证...', 'info');
            }
            
            showNotification('正在准备登录...', 'info');
            
            // 获取登录选项
            var timeout = BrowserDetector.isMobile() ? 20000 : 15000;
            var fetchTimeout = setTimeout(function() {
                reject(new Error('请求超时，请检查网络连接'));
            }, timeout);
            
            fetch(PASSKEY_ACTION_URL + '?do=login-options')
                .then(function(response) {
                    clearTimeout(fetchTimeout);
                    if (!response.ok) {
                        throw new Error('服务器响应错误: ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        throw new Error(data.error || '获取登录选项失败');
                    }
                    
                    var options = data.data;
                    
                    // 验证服务器返回的数据
                    if (!options.challenge) {
                        throw new Error('服务器返回的数据不完整');
                    }
                    
                    // 转换 challenge 为 ArrayBuffer
                    var publicKeyOptions = {
                        challenge: base64urlDecode(options.challenge),
                        timeout: options.timeout || 60000,
                        rpId: options.rpId,
                        userVerification: options.userVerification || 'preferred'
                    };
                    
                    // 如果有 allowCredentials，也要处理
                    if (options.allowCredentials && options.allowCredentials.length > 0) {
                        publicKeyOptions.allowCredentials = options.allowCredentials.map(function(cred) {
                            return {
                                type: cred.type,
                                id: base64urlDecode(cred.id)
                            };
                        });
                    }
                    
                    // Safari 特殊处理
                    if (BrowserDetector.isSafari()) {
                        // iOS Safari 可能需要调整超时
                        if (BrowserDetector.isIOS() && !publicKeyOptions.timeout || publicKeyOptions.timeout < 90000) {
                            publicKeyOptions.timeout = 90000;
                        }
                        
                        // macOS Safari 可能需要更长的超时
                        if (BrowserDetector.isMac() && !publicKeyOptions.timeout || publicKeyOptions.timeout < 90000) {
                            publicKeyOptions.timeout = 90000;
                        }
                    }
                    
                    // Android Chrome 特殊处理
                    if (BrowserDetector.isAndroid()) {
                        // Android 可能需要调整超时时间
                        if (!publicKeyOptions.timeout || publicKeyOptions.timeout < 90000) {
                            publicKeyOptions.timeout = 90000;
                        }
                    }
                    
                    // Firefox 特殊处理
                    if (BrowserDetector.isFirefox()) {
                        // Firefox 可能需要更长的超时时间
                        if (!publicKeyOptions.timeout || publicKeyOptions.timeout < 90000) {
                            publicKeyOptions.timeout = 90000;
                        }
                    }
                    
                    // 移动设备优化
                    if (BrowserDetector.isMobile()) {
                        // 移动设备可能需要更长的超时时间
                        if (!publicKeyOptions.timeout || publicKeyOptions.timeout < 90000) {
                            publicKeyOptions.timeout = 90000;
                        }
                    }
                    
                    // 获取凭证
                    return navigator.credentials.get({
                        publicKey: publicKeyOptions
                    });
                })
                .then(function(assertion) {
                    if (!assertion) {
                        throw new Error('认证失败');
                    }
                    
                    // 验证断言数据
                    if (!assertion.id || !assertion.response) {
                        throw new Error('认证数据不完整');
                    }
                    
                    showNotification('正在验证身份...', 'info');
                    
                    // 准备要发送的数据
                    // 注意：id 是 base64url 编码，rawId 是 ArrayBuffer
                    // 为了一致性，我们统一使用 id（它已是字符串）
                    var data = {
                        id: assertion.id,
                        rawId: assertion.id,  // 直接使用 id（base64url）
                        type: assertion.type,
                        response: {
                            authenticatorData: arrayBufferToBase64(assertion.response.authenticatorData),
                            clientDataJSON: arrayBufferToBase64(assertion.response.clientDataJSON),
                            signature: arrayBufferToBase64(assertion.response.signature),
                            userHandle: assertion.response.userHandle ? 
                                arrayBufferToBase64(assertion.response.userHandle) : null
                        }
                    };
                    
                    // 发送到服务器验证
                    timeout = BrowserDetector.isMobile() ? 20000 : 15000;
                    var verifyTimeout = setTimeout(function() {
                        reject(new Error('验证超时，请重试'));
                    }, timeout);
                    
                    return fetch(PASSKEY_ACTION_URL + '?do=login-verify', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data)
                    }).then(function(response) {
                        clearTimeout(verifyTimeout);
                        return response;
                    });
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('服务器响应错误: ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    // 检查是否需要注册
                    if (data.needRegister) {
                        showNotification('此设备尚未注册，准备创建新账户...', 'info');
                        // 显示注册表单
                        return showRegisterForm().then(function(userInfo) {
                            // 用户填写完信息，开始注册流程
                            return registerWithInfo(userInfo);
                        });
                    }
                    
                    if (!data.success) {
                        throw new Error(data.error || '登录失败');
                    }
                    
                    // 登录成功，立即跳转
                    showNotification('登录成功！欢迎回来，' + (data.data.user ? data.data.user.screenName : ''), 'success');
                    
                    // 立即跳转，不要延迟
                    if (data.data.redirect) {
                        window.location.href = data.data.redirect;
                    } else {
                        // 如果没有 redirect，刷新页面
                        window.location.reload();
                    }
                    
                    resolve(data.data);
                })
                .catch(function(error) {
                    clearTimeout(fetchTimeout);
                    console.error('Passkey login error:', error);
                    
                    var errorMessage = error.message || '未知错误';
                    
                    // 友好的错误信息
                    if (error.name === 'NotAllowedError') {
                        if (BrowserDetector.isMobile()) {
                            errorMessage = '操作已取消或超时，请重试';
                        } else {
                            errorMessage = '操作已取消或超时，请确保您的设备已准备好';
                        }
                    } else if (error.name === 'InvalidStateError') {
                        errorMessage = '您的设备尚未注册通行秘钥';
                    } else if (error.name === 'NotSupportedError') {
                        errorMessage = '您的设备不支持此认证方式';
                        if (BrowserDetector.isIOS()) {
                            errorMessage = '您的设备不支持 Passkey，需要支持 Face ID 或触控 ID 的设备';
                        } else if (BrowserDetector.isAndroid()) {
                            errorMessage = '您的设备不支持 Passkey，需要支持指纹或设备锁屏的设备';
                        }
                    } else if (error.name === 'SecurityError') {
                        errorMessage = '安全错误：请确保网站使用 HTTPS 连接';
                    } else if (error.name === 'AbortError') {
                        errorMessage = '操作被中断，请重试';
                    } else if (error.name === 'TimeoutError') {
                        errorMessage = '操作超时，请检查您的设备并重试';
                    } else if (error.message && error.message.indexOf('timeout') > -1) {
                        errorMessage = '操作超时，请检查网络连接后重试';
                    } else if (error.message && error.message.indexOf('Network') > -1) {
                        errorMessage = '网络错误，请检查您的网络连接';
                    }
                    
                    showNotification('登录失败: ' + errorMessage, 'error');
                    reject(error);
                });
        });
    }
    
    /**
     * 显示注册表单，收集用户信息（增强表单验证）
     */
    function showRegisterForm() {
        return new Promise(function(resolve, reject) {
            // 创建遮罩层
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);' +
                'z-index:9999;display:flex;align-items:center;justify-content:center;animation:fadeIn 0.3s ease;';
            
            // 创建表单容器
            var formBox = document.createElement('div');
            formBox.style.cssText = 'background:var(--passport-card-bg,#fff);padding:30px;' +
                'border:1px solid var(--passport-border,#e1e4e8);max-width:420px;width:90%;animation:scaleIn 0.3s ease;color:var(--passport-text,#1f2937);';
            
            formBox.innerHTML = 
                '<h3 style="margin:0 0 20px;color:var(--passport-text,#1f2937);font-size:20px;display:flex;align-items:center;gap:10px;">' +
                    '<span style="color:var(--passport-primary,#467b96);">' + SVGIcons.fingerprint + '</span>' +
                    '创建新账户' +
                '</h3>' +
                '<p style="color:var(--passport-placeholder,#6b7280);margin-bottom:20px;font-size:14px;line-height:1.5;">请填写以下信息以完成注册。所有字段仅用于创建您的账户，不会与第三方共享。</p>' +
                '<form id="passkey-register-form">' +
                    '<div style="margin-bottom:15px;">' +
                        '<label style="display:block;margin-bottom:5px;color:var(--passport-text,#374151);font-weight:500;font-size:14px;">用户名 *</label>' +
                        '<input type="text" name="username" required ' +
                            'style="width:100%;padding:10px 12px;border:2px solid var(--passport-border,#e1e4e8);font-size:14px;' +
                            'transition:border-color 0.2s ease;background:var(--passport-input-bg,#f8f9fa);color:var(--passport-text,#1f2937);" ' +
                            'placeholder="3-20个字符，仅限字母数字下划线" ' +
                            'pattern="[a-zA-Z0-9_]{3,20}" ' +
                            'title="用户名只能包含字母、数字和下划线，长度3-20个字符">' +
                        '<div id="username-error" style="color:#ef4444;font-size:12px;margin-top:5px;display:none;"></div>' +
                    '</div>' +
                    '<div style="margin-bottom:15px;">' +
                        '<label style="display:block;margin-bottom:5px;color:var(--passport-text,#374151);font-weight:500;font-size:14px;">邮箱 *</label>' +
                        '<input type="email" name="email" required ' +
                            'style="width:100%;padding:10px 12px;border:2px solid var(--passport-border,#e1e4e8);font-size:14px;' +
                            'transition:border-color 0.2s ease;background:var(--passport-input-bg,#f8f9fa);color:var(--passport-text,#1f2937);" ' +
                            'placeholder="your@email.com">' +
                        '<div id="email-error" style="color:#ef4444;font-size:12px;margin-top:5px;display:none;"></div>' +
                    '</div>' +
                    '<div style="margin-bottom:20px;">' +
                        '<label style="display:block;margin-bottom:5px;color:var(--passport-text,#374151);font-weight:500;font-size:14px;">昵称</label>' +
                        '<input type="text" name="screenName" ' +
                            'style="width:100%;padding:10px 12px;border:2px solid var(--passport-border,#e1e4e8);font-size:14px;' +
                            'transition:border-color 0.2s ease;background:var(--passport-input-bg,#f8f9fa);color:var(--passport-text,#1f2937);" ' +
                            'placeholder="显示名称（可选，最多30个字符）" ' +
                            'maxlength="30">' +
                        '<div id="screenName-error" style="color:#ef4444;font-size:12px;margin-top:5px;display:none;"></div>' +
                    '</div>' +
                    '<div style="display:flex;gap:10px;">' +
                        '<button type="submit" id="passkey-submit-btn" ' +
                            'style="flex:1;padding:11px;background:var(--passport-primary,#467b96);color:white;border:none;cursor:pointer;' +
                            'font-size:14px;font-weight:500;transition:background 0.2s ease;">' +
                            '确认注册' +
                        '</button>' +
                        '<button type="button" id="passkey-cancel-btn" ' +
                            'style="flex:1;padding:11px;background:var(--passport-border,#e5e7eb);color:var(--passport-text,#374151);border:none;cursor:pointer;' +
                            'font-size:14px;font-weight:500;transition:background 0.2s ease;">' +
                            '取消' +
                        '</button>' +
                    '</div>' +
                    '<div style="margin-top:15px;padding:10px;background:var(--passport-input-bg,#f3f4f6);border:1px solid var(--passport-border,#e1e4e8);font-size:12px;color:var(--passport-placeholder,#6b7280);">' +
                        '<strong style="color:var(--passport-text,#374151);">提示：</strong>注册后您将使用设备的生物识别功能（如指纹、面容）进行安全登录。' +
                    '</div>' +
                '</form>';
            
            overlay.appendChild(formBox);
            document.body.appendChild(overlay);
            
            // 添加动画样式
            if (!document.getElementById('passkey-form-styles')) {
                var style = document.createElement('style');
                style.id = 'passkey-form-styles';
                style.textContent = '@keyframes fadeIn{from{opacity:0}to{opacity:1}}' +
                    '@keyframes fadeOut{from{opacity:1}to{opacity:0}}' +
                    '@keyframes scaleIn{from{opacity:0;transform:scale(0.9)}to{opacity:1;transform:scale(1)}}' +
                    'input:focus{outline:none;border-color:var(--passport-primary,#467b96)!important;background:var(--passport-card-bg,#fff)!important;}' +
                    'button:hover{opacity:0.9;}';
                document.head.appendChild(style);
            }
            
            // 获取表单元素
            var form = document.getElementById('passkey-register-form');
            var usernameInput = form.querySelector('[name="username"]');
            var emailInput = form.querySelector('[name="email"]');
            var screenNameInput = form.querySelector('[name="screenName"]');
            var submitBtn = document.getElementById('passkey-submit-btn');
            var cancelBtn = document.getElementById('passkey-cancel-btn');
            
            // 实时验证函数
            function validateField(input, validatorFunc, errorElementId) {
                var result = validatorFunc(input.value);
                var errorDiv = document.getElementById(errorElementId);
                
                if (!result.valid) {
                    input.style.borderColor = 'var(--passport-error,#ef4444)';
                    errorDiv.textContent = result.error;
                    errorDiv.style.display = 'block';
                    return false;
                } else {
                    input.style.borderColor = 'var(--passport-success,#10b981)';
                    errorDiv.style.display = 'none';
                    return true;
                }
            }
            
            // 添加实时验证
            usernameInput.addEventListener('blur', function() {
                validateField(this, Validator.username, 'username-error');
            });
            
            usernameInput.addEventListener('input', function() {
                if (this.value.length > 0) {
                    validateField(this, Validator.username, 'username-error');
                } else {
                    this.style.borderColor = 'var(--passport-border,#e1e4e8)';
                    document.getElementById('username-error').style.display = 'none';
                }
            });
            
            emailInput.addEventListener('blur', function() {
                validateField(this, Validator.email, 'email-error');
            });
            
            emailInput.addEventListener('input', function() {
                if (this.value.length > 0) {
                    validateField(this, Validator.email, 'email-error');
                } else {
                    this.style.borderColor = 'var(--passport-border,#e1e4e8)';
                    document.getElementById('email-error').style.display = 'none';
                }
            });
            
            screenNameInput.addEventListener('blur', function() {
                validateField(this, Validator.screenName, 'screenName-error');
            });
            
            // 取消按钮
            cancelBtn.onclick = function() {
                overlay.style.animation = 'fadeOut 0.3s ease';
                setTimeout(function() {
                    if (document.body.contains(overlay)) {
                        document.body.removeChild(overlay);
                    }
                }, 300);
                reject(new Error('用户取消注册'));
            };
            
            // 表单提交
            form.onsubmit = function(e) {
                e.preventDefault();
                
                // 验证所有字段
                var usernameValid = validateField(usernameInput, Validator.username, 'username-error');
                var emailValid = validateField(emailInput, Validator.email, 'email-error');
                var screenNameValid = validateField(screenNameInput, Validator.screenName, 'screenName-error');
                
                if (!usernameValid || !emailValid || !screenNameValid) {
                    showNotification('请检查表单中的错误', 'error');
                    return;
                }
                
                // 获取验证后的值
                var usernameResult = Validator.username(usernameInput.value);
                var emailResult = Validator.email(emailInput.value);
                var screenNameResult = Validator.screenName(screenNameInput.value);
                
                var userInfo = {
                    username: usernameResult.value,
                    email: emailResult.value,
                    screenName: screenNameResult.value || usernameResult.value
                };
                
                // 禁用提交按钮
                submitBtn.disabled = true;
                submitBtn.textContent = '正在处理...';
                submitBtn.style.opacity = '0.6';
                
                if (document.body.contains(overlay)) {
                    document.body.removeChild(overlay);
                }
                resolve(userInfo);
            };
            
            // 聚焦到第一个输入框
            setTimeout(function() {
                usernameInput.focus();
            }, 300);
        });
    }
    
    /**
     * 使用用户信息注册 Passkey（增强版）
     */
    function registerWithInfo(userInfo) {
        return new Promise(function(resolve, reject) {
            // 前端验证用户信息
            var usernameResult = Validator.username(userInfo.username);
            if (!usernameResult.valid) {
                showNotification('用户名验证失败: ' + usernameResult.error, 'error');
                reject(new Error(usernameResult.error));
                return;
            }
            
            var emailResult = Validator.email(userInfo.email);
            if (!emailResult.valid) {
                showNotification('邮箱验证失败: ' + emailResult.error, 'error');
                reject(new Error(emailResult.error));
                return;
            }
            
            var screenNameResult = Validator.screenName(userInfo.screenName);
            if (!screenNameResult.valid) {
                showNotification('昵称验证失败: ' + screenNameResult.error, 'error');
                reject(new Error(screenNameResult.error));
                return;
            }
            
            // 检查网络连接
            var networkStatus = checkNetwork();
            if (!networkStatus.connected) {
                showNotification(networkStatus.error, 'error');
                reject(new Error('Network disconnected'));
                return;
            }
            
            showNotification('正在创建账户...', 'info');
            
            // 获取注册选项（带用户信息）
            var fetchTimeout = setTimeout(function() {
                reject(new Error('请求超时，请检查网络连接'));
            }, 15000);
            
            fetch(PASSKEY_ACTION_URL + '?do=register-options', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    username: usernameResult.value,
                    email: emailResult.value,
                    screenName: screenNameResult.value || usernameResult.value
                })
            })
            .then(function(response) {
                clearTimeout(fetchTimeout);
                if (!response.ok) {
                    throw new Error('服务器响应错误: ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.error || '获取注册选项失败');
                }
                
                var options = data.data;
                
                // 验证服务器返回的数据
                if (!options.challenge || !options.user || !options.rp) {
                    throw new Error('服务器返回的数据不完整');
                }
                
                // 转换为 WebAuthn 格式
                var publicKeyOptions = {
                    challenge: base64urlDecode(options.challenge),
                    rp: options.rp,
                    user: {
                        id: base64urlDecode(options.user.id),
                        name: options.user.name,
                        displayName: options.user.displayName
                    },
                    pubKeyCredParams: options.pubKeyCredParams,
                    timeout: options.timeout || 60000,
                    attestation: options.attestation || 'none',
                    authenticatorSelection: options.authenticatorSelection || {}
                };
                
                // Safari 特殊处理
                if (BrowserDetector.isSafari()) {
                    showNotification('您正在使用 Safari 浏览器，请在弹出窗口中完成验证', 'info');
                    if (!publicKeyOptions.authenticatorSelection.userVerification) {
                        publicKeyOptions.authenticatorSelection.userVerification = 'preferred';
                    }
                } else if (BrowserDetector.isFirefox()) {
                    showNotification('您正在使用 Firefox 浏览器，请使用您的设备完成身份验证', 'info');
                } else {
                    showNotification('请使用您的设备完成身份验证...', 'info');
                }
                
                // 创建凭证
                return navigator.credentials.create({
                    publicKey: publicKeyOptions
                });
            })
            .then(function(credential) {
                if (!credential) {
                    throw new Error('创建凭证失败');
                }
                
                // 验证凭证数据
                if (!credential.id || !credential.rawId || !credential.response) {
                    throw new Error('凭证数据不完整');
                }
                
                showNotification('正在完成注册...', 'info');
                
                // 准备要发送的数据
                // 注意：使用 credential.id（Base64URL 格式）保持与其他函数一致
                var data = {
                    id: credential.id,
                    rawId: credential.id,  // 直接使用 id（base64url）
                    type: credential.type,
                    response: {
                        clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                        attestationObject: arrayBufferToBase64(credential.response.attestationObject)
                    }
                };
                
                // 发送到服务器验证
                var verifyTimeout = setTimeout(function() {
                    reject(new Error('验证超时，请重试'));
                }, 15000);
                
                return fetch(PASSKEY_ACTION_URL + '?do=register-verify', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                }).then(function(response) {
                    clearTimeout(verifyTimeout);
                    return response;
                });
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('服务器响应错误: ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.error || '注册失败');
                }
                
                showNotification('注册成功！欢迎使用 Passkey 登录', 'success');
                
                // 延迟跳转
                setTimeout(function() {
                    if (data.data.redirect) {
                        window.location.href = data.data.redirect;
                    } else {
                        window.location.reload();
                    }
                }, 1500);
                
                resolve(data.data);
            })
            .catch(function(error) {
                clearTimeout(fetchTimeout);
                console.error('Passkey register error:', error);
                
                var errorMessage = error.message;
                
                if (error.name === 'NotAllowedError') {
                    errorMessage = '注册已取消或超时，请重试';
                } else if (error.name === 'InvalidStateError') {
                    errorMessage = '此设备已经注册过 Passkey';
                } else if (error.name === 'NotSupportedError') {
                    errorMessage = '您的设备不支持此认证方式';
                } else if (error.name === 'SecurityError') {
                    errorMessage = '安全错误：请确保网站使用 HTTPS 连接';
                } else if (error.name === 'AbortError') {
                    errorMessage = '操作被中断，请重试';
                } else if (error.message && error.message.indexOf('timeout') > -1) {
                    errorMessage = '操作超时，请检查网络连接后重试';
                }
                
                showNotification('注册失败: ' + errorMessage, 'error');
                reject(error);
            });
        });
    }
    
    /**
     * 检查是否可以使用条件中介
     */
    function checkConditionalMediation() {
        if (!isSupported()) {
            return Promise.resolve(false);
        }
        
        if (PublicKeyCredential.isConditionalMediationAvailable) {
            return PublicKeyCredential.isConditionalMediationAvailable();
        }
        
        return Promise.resolve(false);
    }
    
    // 公开接口
    return {
        isSupported: isSupported,
        register: register,
        login: login,
        checkConditionalMediation: checkConditionalMediation,
        showNotification: showNotification,
        // 暴露工具类和检测器（供高级用户使用）
        BrowserDetector: BrowserDetector,
        Validator: Validator,
        SVGIcons: SVGIcons
    };
})();

// 自动初始化 - 优化适配 Typecho 登录页面（增强版）
(function() {
    'use strict';
    
    // 检查是否支持 WebAuthn
    if (!PasskeyManager.isSupported()) {
        console.warn('Passkey: WebAuthn is not supported in this browser (' + 
            PasskeyManager.BrowserDetector.getBrowserName() + ' on ' + 
            PasskeyManager.BrowserDetector.getPlatformName() + ')');
        return;
    }
    
    // 如果已经通过 PHP 自动注入，则不再执行 JS 自动注入
    if (window.PASSKEY_AUTO_INJECTED === true) {
        console.log('Passkey: Already injected via PHP auto mode');
        return;
    }
    
    // 等待 DOM 加载完成
    function init() {
        // 更智能的登录页面检测
        var isLoginPage = window.location.pathname.indexOf('login.php') !== -1 ||
                         (window.location.pathname.indexOf('/admin/') !== -1 && 
                          document.querySelector('form[action*="login"]'));
        
        if (!isLoginPage) {
            console.log('Passkey: Not a login page');
            return;
        }
        
        // 检查是否已经注入
        if (document.getElementById('passkey-login-container')) {
            console.log('Passkey: Already injected');
            return;
        }
        
        // 查找登录表单 - 支持多种选择器
        var loginForm = document.querySelector('form[action*="login"]') ||
                       document.querySelector('form[method="post"]') ||
                       document.querySelector('.typecho-login form') ||
                       document.querySelector('form[name="login"]');
        
        if (!loginForm) {
            console.warn('Passkey: Login form not found');
            return;
        }
        
        // 创建 Passkey 登录按钮容器
        var container = document.createElement('div');
        container.id = 'passkey-login-container';
        container.style.cssText = 'margin-top:24px;';
        
        // 创建分隔文字
        var divider = document.createElement('div');
        divider.style.cssText = 'text-align:center;margin-bottom:10px;color:var(--passport-placeholder,#999);font-size:13px;';
        divider.textContent = '或';
        
        // 创建 Passkey 按钮
        var button = document.createElement('button');
        button.type = 'button';
        button.id = 'passkey-login-btn';
        button.className = 'passport-btn';
        button.style.cssText = 'width:100%;height:48px;padding:0 24px;font-size:16px;font-weight:600;cursor:pointer;' +
            'background:var(--passport-primary,#467b96);color:#ffffff;border:none;' +
            'transition:all 0.2s ease;display:flex;align-items:center;justify-content:center;gap:8px;';
        
        // 创建按钮内容（SVG + 文字）
        var buttonIcon = document.createElement('span');
        buttonIcon.innerHTML = PasskeyManager.SVGIcons.fingerprint;
        buttonIcon.style.cssText = 'display:flex;align-items:center;';
        
        var buttonText = document.createElement('span');
        buttonText.textContent = '使用 Passkey 登录';
        
        button.appendChild(buttonIcon);
        button.appendChild(buttonText);
        
        // 按钮悬停效果
        button.onmouseover = function() {
            this.style.background = 'var(--passport-primary-light,#5a8bb3)';
        };
        button.onmouseout = function() {
            this.style.background = 'var(--passport-primary,#467b96)';
        };
        
        container.appendChild(divider);
        container.appendChild(button);
        
        // 智能插入位置
        if (loginForm.nextSibling) {
            loginForm.parentNode.insertBefore(container, loginForm.nextSibling);
        } else {
            loginForm.parentNode.appendChild(container);
        }
        
        // 绑定点击事件
        button.addEventListener('click', function() {
            if (button.disabled) return;
            
            button.disabled = true;
            
            // 显示加载状态
            buttonIcon.innerHTML = '<span style="display:inline-block;animation:passkeyRotate 1s linear infinite;">' + 
                                   PasskeyManager.SVGIcons.loading + '</span>';
            buttonText.textContent = '正在登录...';
            button.style.opacity = '0.8';
            
            PasskeyManager.login().catch(function(error) {
                // 登录失败时恢复按钮状态
                console.error('Login failed:', error);
            }).finally(function() {
                // 恢复按钮状态
                button.disabled = false;
                buttonIcon.innerHTML = PasskeyManager.SVGIcons.fingerprint;
                buttonText.textContent = '使用 Passkey 登录';
                button.style.opacity = '1';
            });
        });
        
        console.log('Passkey: Auto injection completed (' + 
            PasskeyManager.BrowserDetector.getBrowserName() + ' on ' + 
            PasskeyManager.BrowserDetector.getPlatformName() + ')');
    }
    
    // 等待 DOM 完全加载
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM 已经加载完成，延迟一点确保所有元素都渲染好
        setTimeout(init, 100);
    }
})();
