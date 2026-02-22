/**
 * Passkey Manager
 * WebAuthn API 封装 - 优化版
 */
var PasskeyManager = (function() {
    'use strict';
    
    /**
     * 检查浏览器是否支持 WebAuthn
     */
    function isSupported() {
        return window.PublicKeyCredential !== undefined && 
               navigator.credentials !== undefined &&
               navigator.credentials.create !== undefined;
    }
    
    /**
     * 页面内通知系统
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
        notification.style.cssText = 'background:#fff;border-left:4px solid;padding:15px 20px;margin-bottom:10px;' +
            'border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.15);animation:slideInRight 0.3s ease;' +
            'display:flex;align-items:flex-start;gap:12px;';
        
        // 设置边框颜色
        var borderColor = '#3b82f6';
        var icon = 'ℹ️';
        if (type === 'success') {
            borderColor = '#10b981';
            icon = '✅';
        } else if (type === 'error') {
            borderColor = '#ef4444';
            icon = '❌';
        } else if (type === 'warning') {
            borderColor = '#f59e0b';
            icon = '⚠️';
        }
        notification.style.borderLeftColor = borderColor;
        
        // 图标
        var iconSpan = document.createElement('span');
        iconSpan.textContent = icon;
        iconSpan.style.cssText = 'font-size:20px;line-height:1;flex-shrink:0;';
        
        // 消息文本
        var messageDiv = document.createElement('div');
        messageDiv.style.cssText = 'flex:1;color:#1f2937;font-size:14px;line-height:1.5;word-break:break-word;';
        messageDiv.textContent = message;
        
        // 关闭按钮
        var closeBtn = document.createElement('button');
        closeBtn.innerHTML = '×';
        closeBtn.style.cssText = 'background:none;border:none;font-size:24px;line-height:1;cursor:pointer;' +
            'color:#6b7280;padding:0;margin-left:8px;flex-shrink:0;width:20px;height:20px;';
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
                '@keyframes slideOutRight{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(100px)}}';
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
     * 注册 Passkey
     */
    function register() {
        return new Promise(function(resolve, reject) {
            if (!isSupported()) {
                showNotification('您的浏览器不支持 WebAuthn', 'error');
                reject(new Error('Browser not supported'));
                return;
            }
            
            showNotification('正在准备注册...', 'info');
            
            // 获取注册选项
            fetch(PASSKEY_ACTION_URL + '?do=register-options')
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        throw new Error(data.error || '获取注册选项失败');
                    }
                    
                    var options = data.data;
                    
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
                        timeout: options.timeout,
                        attestation: options.attestation,
                        authenticatorSelection: options.authenticatorSelection
                    };
                    
                    showNotification('请使用您的设备完成身份验证...', 'info');
                    
                    // 创建凭证
                    return navigator.credentials.create({
                        publicKey: publicKeyOptions
                    });
                })
                .then(function(credential) {
                    if (!credential) {
                        throw new Error('创建凭证失败');
                    }
                    
                    showNotification('正在保存凭证...', 'info');
                    
                    // 准备要发送的数据
                    var data = {
                        id: credential.id,
                        rawId: arrayBufferToBase64(credential.rawId),
                        type: credential.type,
                        response: {
                            clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                            attestationObject: arrayBufferToBase64(credential.response.attestationObject)
                        }
                    };
                    
                    // 发送到服务器验证
                    return fetch(PASSKEY_ACTION_URL + '?do=register-verify', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data)
                    });
                })
                .then(function(response) {
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
                    console.error('Passkey register error:', error);
                    
                    var errorMessage = error.message;
                    if (error.name === 'NotAllowedError') {
                        errorMessage = '注册已取消或超时';
                    } else if (error.name === 'InvalidStateError') {
                        errorMessage = '此设备已经注册过 Passkey';
                    }
                    
                    showNotification('注册失败: ' + errorMessage, 'error');
                    reject(error);
                });
        });
    }
    
    /**
     * 使用 Passkey 登录
     */
    function login() {
        return new Promise(function(resolve, reject) {
            if (!isSupported()) {
                showNotification('您的浏览器不支持 WebAuthn', 'error');
                reject(new Error('Browser not supported'));
                return;
            }
            
            showNotification('正在准备登录...', 'info');
            
            // 获取登录选项
            fetch(PASSKEY_ACTION_URL + '?do=login-options')
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (!data.success) {
                        throw new Error(data.error || '获取登录选项失败');
                    }
                    
                    var options = data.data;
                    
                    // 转换 challenge 为 ArrayBuffer
                    var publicKeyOptions = {
                        challenge: base64urlDecode(options.challenge),
                        timeout: options.timeout,
                        rpId: options.rpId,
                        userVerification: options.userVerification
                    };
                    
                    showNotification('请使用您的设备完成身份验证...', 'info');
                    
                    // 获取凭证
                    return navigator.credentials.get({
                        publicKey: publicKeyOptions
                    });
                })
                .then(function(assertion) {
                    if (!assertion) {
                        throw new Error('认证失败');
                    }
                    
                    showNotification('正在验证身份...', 'info');
                    
                    // 准备要发送的数据
                    var data = {
                        id: assertion.id,
                        rawId: arrayBufferToBase64(assertion.rawId),
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
                    return fetch(PASSKEY_ACTION_URL + '?do=login-verify', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data)
                    });
                })
                .then(function(response) {
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
                    console.error('Passkey login error:', error);
                    
                    // 显示友好的错误信息
                    var errorMessage = error.message;
                    if (error.name === 'NotAllowedError') {
                        errorMessage = '认证已取消或超时';
                    } else if (error.name === 'InvalidStateError') {
                        errorMessage = '您的设备尚未注册通行秘钥';
                    } else if (error.name === 'NotSupportedError') {
                        errorMessage = '您的设备不支持此认证方式';
                    }
                    
                    showNotification('登录失败: ' + errorMessage, 'error');
                    reject(error);
                });
        });
    }
    
    /**
     * 显示注册表单，收集用户信息
     */
    function showRegisterForm() {
        return new Promise(function(resolve, reject) {
            // 创建遮罩层
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);' +
                'z-index:9999;display:flex;align-items:center;justify-content:center;animation:fadeIn 0.3s ease;';
            
            // 创建表单容器
            var formBox = document.createElement('div');
            formBox.style.cssText = 'background:#fff;padding:30px;border-radius:8px;' +
                'box-shadow:0 4px 20px rgba(0,0,0,0.15);max-width:400px;width:90%;animation:scaleIn 0.3s ease;';
            
            formBox.innerHTML = 
                '<h3 style="margin:0 0 20px;color:#1f2937;font-size:20px;">创建新账户</h3>' +
                '<p style="color:#6b7280;margin-bottom:20px;font-size:14px;">请填写以下信息以完成注册</p>' +
                '<form id="passkey-register-form">' +
                    '<div style="margin-bottom:15px;">' +
                        '<label style="display:block;margin-bottom:5px;color:#374151;font-weight:500;">用户名 *</label>' +
                        '<input type="text" name="username" required style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:4px;font-size:14px;" placeholder="请输入用户名">' +
                    '</div>' +
                    '<div style="margin-bottom:15px;">' +
                        '<label style="display:block;margin-bottom:5px;color:#374151;font-weight:500;">邮箱 *</label>' +
                        '<input type="email" name="email" required style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:4px;font-size:14px;" placeholder="请输入邮箱">' +
                    '</div>' +
                    '<div style="margin-bottom:20px;">' +
                        '<label style="display:block;margin-bottom:5px;color:#374151;font-weight:500;">昵称</label>' +
                        '<input type="text" name="screenName" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:4px;font-size:14px;" placeholder="请输入昵称（可选）">' +
                    '</div>' +
                    '<div style="display:flex;gap:10px;">' +
                        '<button type="submit" style="flex:1;padding:10px;background:#3b82f6;color:white;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;">确认注册</button>' +
                        '<button type="button" id="passkey-cancel-btn" style="flex:1;padding:10px;background:#e5e7eb;color:#374151;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;">取消</button>' +
                    '</div>' +
                '</form>';
            
            overlay.appendChild(formBox);
            document.body.appendChild(overlay);
            
            // 添加动画样式
            if (!document.getElementById('passkey-form-styles')) {
                var style = document.createElement('style');
                style.id = 'passkey-form-styles';
                style.textContent = '@keyframes fadeIn{from{opacity:0}to{opacity:1}}' +
                    '@keyframes scaleIn{from{opacity:0;transform:scale(0.9)}to{opacity:1;transform:scale(1)}}';
                document.head.appendChild(style);
            }
            
            // 取消按钮
            document.getElementById('passkey-cancel-btn').onclick = function() {
                overlay.style.animation = 'fadeOut 0.3s ease';
                setTimeout(function() {
                    document.body.removeChild(overlay);
                }, 300);
                reject(new Error('用户取消注册'));
            };
            
            // 表单提交
            document.getElementById('passkey-register-form').onsubmit = function(e) {
                e.preventDefault();
                
                var formData = new FormData(e.target);
                var userInfo = {
                    username: formData.get('username').trim(),
                    email: formData.get('email').trim(),
                    screenName: formData.get('screenName').trim() || formData.get('username').trim()
                };
                
                // 简单验证
                if (!userInfo.username || !userInfo.email) {
                    showNotification('请填写必填项', 'error');
                    return;
                }
                
                document.body.removeChild(overlay);
                resolve(userInfo);
            };
        });
    }
    
    /**
     * 使用用户信息注册 Passkey
     */
    function registerWithInfo(userInfo) {
        return new Promise(function(resolve, reject) {
            showNotification('正在创建账户...', 'info');
            
            // 获取注册选项（带用户信息）
            fetch(PASSKEY_ACTION_URL + '?do=register-options', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(userInfo)
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.error || '获取注册选项失败');
                }
                
                var options = data.data;
                
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
                    timeout: options.timeout,
                    attestation: options.attestation,
                    authenticatorSelection: options.authenticatorSelection
                };
                
                showNotification('请使用您的设备完成身份验证...', 'info');
                
                // 创建凭证
                return navigator.credentials.create({
                    publicKey: publicKeyOptions
                });
            })
            .then(function(credential) {
                if (!credential) {
                    throw new Error('创建凭证失败');
                }
                
                showNotification('正在完成注册...', 'info');
                
                // 准备要发送的数据
                var data = {
                    id: credential.id,
                    rawId: arrayBufferToBase64(credential.rawId),
                    type: credential.type,
                    response: {
                        clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                        attestationObject: arrayBufferToBase64(credential.response.attestationObject)
                    }
                };
                
                // 发送到服务器验证
                return fetch(PASSKEY_ACTION_URL + '?do=register-verify', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });
            })
            .then(function(response) {
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
                    }
                }, 1500);
                
                resolve(data.data);
            })
            .catch(function(error) {
                console.error('Passkey register error:', error);
                
                var errorMessage = error.message;
                if (error.name === 'NotAllowedError') {
                    errorMessage = '注册已取消或超时';
                } else if (error.name === 'InvalidStateError') {
                    errorMessage = '此设备已经注册过 Passkey';
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
        showNotification: showNotification
    };
})();

// 自动初始化 - 优化适配 Typecho 登录页面
(function() {
    'use strict';
    
    // 检查是否支持 WebAuthn
    if (!PasskeyManager.isSupported()) {
        console.warn('Passkey: WebAuthn is not supported in this browser');
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
        container.style.cssText = 'margin-top:15px;padding-top:15px;border-top:1px solid #e5e7eb;';
        
        // 创建分隔文字
        var divider = document.createElement('div');
        divider.style.cssText = 'text-align:center;margin-bottom:12px;color:#9ca3af;font-size:13px;';
        divider.textContent = '或使用 Passkey 登录';
        
        // 创建 Passkey 按钮
        var button = document.createElement('button');
        button.type = 'button';
        button.id = 'passkey-login-btn';
        button.className = 'btn btn-l w-100';
        button.style.cssText = 'width:100%;padding:10px;font-size:14px;cursor:pointer;' +
            'background:#4f46e5;color:white;border:1px solid #4338ca;border-radius:4px;' +
            'transition:all 0.2s ease;';
        button.innerHTML = '🔐 使用 Passkey 登录';
        
        // 按钮悬停效果
        button.onmouseover = function() {
            this.style.background = '#4338ca';
        };
        button.onmouseout = function() {
            this.style.background = '#4f46e5';
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
            button.disabled = true;
            button.innerHTML = '🔐 正在登录...';
            
            PasskeyManager.login().finally(function() {
                button.disabled = false;
                button.innerHTML = '🔐 使用 Passkey 登录';
            });
        });
        
        console.log('Passkey: Auto injection completed');
    }
    
    // 等待 DOM 完全加载
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM 已经加载完成，延迟一点确保所有元素都渲染好
        setTimeout(init, 100);
    }
})();
