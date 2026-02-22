/**
 * Passkey Manager
 * WebAuthn API 封装
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
     * Base64URL 解码
     */
    function base64urlDecode(str) {
        // 将 base64url 转换为 base64
        str = str.replace(/-/g, '+').replace(/_/g, '/');
        // 添加填充
        while (str.length % 4) {
            str += '=';
        }
        
        // 解码
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
                reject(new Error('您的浏览器不支持 WebAuthn'));
                return;
            }
            
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
                    
                    // 转换 challenge 和 user.id 为 ArrayBuffer
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
                    
                    // 创建凭证
                    return navigator.credentials.create({
                        publicKey: publicKeyOptions
                    });
                })
                .then(function(credential) {
                    if (!credential) {
                        throw new Error('创建凭证失败');
                    }
                    
                    // 准备要发送的数据
                    var attestationObject = credential.response.attestationObject;
                    var clientDataJSON = credential.response.clientDataJSON;
                    
                    var data = {
                        id: credential.id,
                        rawId: arrayBufferToBase64(credential.rawId),
                        type: credential.type,
                        response: {
                            attestationObject: arrayBufferToBase64(attestationObject),
                            clientDataJSON: arrayBufferToBase64(clientDataJSON)
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
                        throw new Error(data.error || '验证失败');
                    }
                    resolve(data.data);
                })
                .catch(function(error) {
                    console.error('Passkey registration error:', error);
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
                reject(new Error('您的浏览器不支持 WebAuthn'));
                return;
            }
            
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
                    
                    // 获取凭证
                    return navigator.credentials.get({
                        publicKey: publicKeyOptions
                    });
                })
                .then(function(assertion) {
                    if (!assertion) {
                        throw new Error('认证失败');
                    }
                    
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
                        // 显示注册表单
                        return showRegisterForm().then(function(userInfo) {
                            // 用户填写完信息，开始注册流程
                            return registerWithInfo(userInfo);
                        });
                    }
                    
                    if (!data.success) {
                        throw new Error(data.error || '登录失败');
                    }
                    
                    // 检查是否为新注册用户
                    if (data.data.isNewUser) {
                        alert('您好，' + (data.data.message || '注册成功！欢迎使用 Passkey 登录。'));
                    }
                    
                    // 登录成功，跳转
                    if (data.data.redirect) {
                        window.location.href = data.data.redirect;
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
                        errorMessage = '此设备尚未注册 Passkey，无法登录。\n\n如需使用 Passkey 登录，请先登录后台在"Passkey 管理"页面添加凭证。';
                    } else if (error.name === 'NotSupportedError') {
                        errorMessage = '您的设备不支持此认证方式';
                    }
                    
                    alert('登录失败: ' + errorMessage);
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
            overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
            
            // 创建表单容器
            var formBox = document.createElement('div');
            formBox.style.cssText = 'background:#fff;padding:30px;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.15);max-width:400px;width:90%;';
            
            formBox.innerHTML = '<h3 style="margin:0 0 20px 0;color:#333;font-size:18px;">创建 Passkey 账户</h3>' +
                '<p style="margin:0 0 20px 0;color:#666;font-size:14px;line-height:1.6;">检测到您尚未注册，请填写以下信息创建账户：</p>' +
                '<form id="passkey-register-form" style="margin:0;">' +
                '<div style="margin-bottom:15px;">' +
                '<label style="display:block;margin-bottom:5px;color:#333;font-size:14px;">用户名 *</label>' +
                '<input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,32}" ' +
                'placeholder="字母、数字、下划线，3-32位" ' +
                'style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;" />' +
                '<small style="color:#999;font-size:12px;">用户名只能包含字母、数字和下划线</small>' +
                '</div>' +
                '<div style="margin-bottom:15px;">' +
                '<label style="display:block;margin-bottom:5px;color:#333;font-size:14px;">邮箱 *</label>' +
                '<input type="email" name="email" required placeholder="your@email.com" ' +
                'style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;" />' +
                '</div>' +
                '<div style="margin-bottom:20px;">' +
                '<label style="display:block;margin-bottom:5px;color:#333;font-size:14px;">昵称（可选）</label>' +
                '<input type="text" name="screenName" placeholder="显示名称" ' +
                'style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;" />' +
                '</div>' +
                '<div style="display:flex;gap:10px;">' +
                '<button type="submit" style="flex:1;padding:12px;background:#3b82f6;color:#fff;border:none;border-radius:4px;font-size:14px;cursor:pointer;">创建账户</button>' +
                '<button type="button" id="passkey-cancel-btn" style="flex:1;padding:12px;background:#6b7280;color:#fff;border:none;border-radius:4px;font-size:14px;cursor:pointer;">取消</button>' +
                '</div>' +
                '</form>';
            
            overlay.appendChild(formBox);
            document.body.appendChild(overlay);
            
            // 表单提交事件
            var form = document.getElementById('passkey-register-form');
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                var username = form.username.value.trim();
                var email = form.email.value.trim();
                var screenName = form.screenName.value.trim() || username;
                
                if (!username || !email) {
                    alert('请填写必填项');
                    return;
                }
                
                document.body.removeChild(overlay);
                resolve({
                    username: username,
                    email: email,
                    screenName: screenName
                });
            });
            
            // 取消按钮
            document.getElementById('passkey-cancel-btn').addEventListener('click', function() {
                document.body.removeChild(overlay);
                reject(new Error('用户取消注册'));
            });
        });
    }
    
    /**
     * 使用用户信息注册 Passkey
     */
    function registerWithInfo(userInfo) {
        return new Promise(function(resolve, reject) {
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
                
                // 转换数据
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
                
                // 创建凭证
                return navigator.credentials.create({
                    publicKey: publicKeyOptions
                });
            })
            .then(function(credential) {
                if (!credential) {
                    throw new Error('创建凭证失败');
                }
                
                // 准备发送数据
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
                
                // 注册成功，显示提示并跳转
                if (data.data.isNewUser) {
                    alert('注册成功！欢迎使用 Passkey 登录。');
                }
                
                if (data.data.redirect) {
                    window.location.href = data.data.redirect;
                }
                
                resolve(data.data);
            })
            .catch(function(error) {
                console.error('Passkey register error:', error);
                alert('注册失败: ' + error.message);
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
        checkConditionalMediation: checkConditionalMediation
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
        // 检查是否在登录页面
        var isLoginPage = window.location.pathname.indexOf('login.php') !== -1 ||
                         window.location.pathname.indexOf('/admin/') !== -1;
        
        if (!isLoginPage) {
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
        container.style.cssText = 'margin-top: 20px;';
        
        // 创建分隔线
        var divider = document.createElement('div');
        divider.style.cssText = 'text-align: center; margin-bottom: 10px; color: #999; position: relative;';
        divider.innerHTML = '<span style="background: #fff; padding: 0 10px; position: relative; z-index: 1;">或</span>' +
                           '<hr style="position: absolute; top: 50%; left: 0; right: 0; margin: 0; border: none; border-top: 1px solid #e0e0e0; z-index: 0;">';
        
        // 创建 Passkey 按钮
        var button = document.createElement('button');
        button.type = 'button';
        button.id = 'passkey-login-btn';
        button.className = 'btn primary';
        button.style.cssText = 'width: 100%; padding: 10px; font-size: 14px; cursor: pointer;';
        button.innerHTML = '使用 Passkey 登录';
        
        container.appendChild(divider);
        container.appendChild(button);
        
        // 智能插入位置：
        // 1. 尝试插入到表单后面
        // 2. 如果表单在容器内，插入到容器后面
        // 3. 否则插入到表单的父元素中
        var insertTarget = loginForm.nextSibling;
        var insertParent = loginForm.parentNode;
        
        // 如果表单的父元素有特定类名，可能需要插入到更外层
        if (insertParent.className && insertParent.className.indexOf('typecho-page') !== -1) {
            insertParent = insertParent.parentNode;
        }
        
        if (insertTarget) {
            insertParent.insertBefore(container, insertTarget);
        } else {
            insertParent.appendChild(container);
        }
        
        // 绑定点击事件
        button.addEventListener('click', function() {
            button.disabled = true;
            button.textContent = '正在验证...';
            
            PasskeyManager.login()
                .catch(function(error) {
                    console.error('Passkey login failed:', error);
                    button.disabled = false;
                    button.innerHTML = '使用 Passkey 登录';
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