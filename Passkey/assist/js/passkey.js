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
     * 浏览器和平台检测
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
     * 检查浏览器是否支持 WebAuthn
     */
    function evaluateSupportStatus() {
        var profile = getCompatibilityProfile();
        var browserVersion = BrowserDetector.getBrowserVersion();

        var checks = [
            {
                valid: typeof window.PublicKeyCredential !== 'undefined',
                reason: '您的浏览器不支持 WebAuthn API',
                warning: 'Passkey: PublicKeyCredential API not available'
            },
            {
                valid: typeof navigator.credentials !== 'undefined',
                reason: '您的浏览器不支持凭证管理 API',
                warning: 'Passkey: navigator.credentials not available'
            },
            {
                valid: typeof navigator.credentials !== 'undefined' &&
                    typeof navigator.credentials.create === 'function' &&
                    typeof navigator.credentials.get === 'function',
                reason: '您的浏览器不支持完整的凭证创建/获取能力',
                warning: 'Passkey: credentials.create/get not available'
            },
            {
                valid: location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1',
                reason: 'WebAuthn 需要使用 HTTPS 协议',
                warning: 'Passkey: WebAuthn requires HTTPS (except localhost)'
            }
        ];

        if (profile.isFirefox) {
            checks.push({
                valid: !(browserVersion > 0 && browserVersion < 60),
                reason: 'Firefox 版本过低，需要 60 或更高版本（当前: ' + browserVersion + '）',
                warning: 'Passkey: Firefox version too old, require 60+, got ' + browserVersion
            });

            if (profile.isAndroid) {
                checks.push({
                    valid: !(browserVersion > 0 && browserVersion < 92),
                    reason: 'Android Firefox 版本过低，需要 92 或更高版本（当前: ' + browserVersion + '）',
                    warning: 'Passkey: Firefox Android requires 92+ for full WebAuthn support'
                });
            }
        }

        if (profile.isSafariIOS) {
            checks.push({
                valid: !(browserVersion > 0 && browserVersion < 14),
                reason: 'iOS Safari 版本过低，需要 14 或更高版本（当前: ' + browserVersion + '）',
                warning: 'Passkey: iOS Safari version too old, require 14+, got ' + browserVersion
            });

            if (BrowserDetector.isIPad()) {
                checks.push({
                    valid: !(browserVersion > 0 && browserVersion < 13),
                    reason: 'iPadOS 版本过低，需要 13 或更高版本（当前: ' + browserVersion + '）',
                    warning: 'Passkey: iPadOS version too old, require 13+, got ' + browserVersion
                });
            }
        }

        if (profile.isSafariMac) {
            checks.push({
                valid: !(browserVersion > 0 && browserVersion < 13),
                reason: 'macOS Safari 版本过低，需要 13 或更高版本（当前: ' + browserVersion + '）',
                warning: 'Passkey: macOS Safari version too old, require 13+, got ' + browserVersion
            });
        }

        if (BrowserDetector.isChrome() || BrowserDetector.isEdge()) {
            checks.push({
                valid: !(browserVersion > 0 && browserVersion < 67),
                reason: '浏览器版本过低，需要 67 或更高版本（当前: ' + browserVersion + '）',
                warning: 'Passkey: Chrome/Edge version too old, require 67+, got ' + browserVersion
            });

            if (profile.isAndroid) {
                checks.push({
                    valid: !(browserVersion > 0 && browserVersion < 70),
                    reason: 'Android Chrome 版本过低，需要 70 或更高版本（当前: ' + browserVersion + '）',
                    warning: 'Passkey: Android Chrome requires 70+ for full WebAuthn support'
                });
            }
        }

        for (var i = 0; i < checks.length; i++) {
            if (!checks[i].valid) {
                return {
                    supported: false,
                    reason: checks[i].reason,
                    warning: checks[i].warning
                };
            }
        }

        return {
            supported: true,
            reason: '',
            warning: ''
        };
    }

    function isSupported() {
        var status = evaluateSupportStatus();

        if (!status.supported && status.warning) {
            console.warn(status.warning);
            return false;
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
        var status = evaluateSupportStatus();
        if (!status.supported) {
            return status.reason;
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
     * 根据设备给出认证提示
     */
    function showDeviceVerificationHint() {
        var profile = getCompatibilityProfile();
        showNotification(getVerificationHintMessage(profile), 'info');
    }

    /**
     * 通用前置检查
     */
    function runCommonPreChecks() {
        if (!isSupported()) {
            showNotification(getUnsupportedReason(), 'error');
            throw new Error('Browser not supported');
        }

        var networkStatus = checkNetwork();
        if (!networkStatus.connected) {
            showNotification(networkStatus.error, 'error');
            throw new Error('Network disconnected');
        }
    }

    /**
     * 默认请求超时（移动端适当更长）
     */
    function getDefaultRequestTimeout() {
        return BrowserDetector.isMobile() ? 20000 : 15000;
    }

    var MIN_WEBAUTHN_TIMEOUT = 90000;

    function getCompatibilityProfile() {
        var isSafari = BrowserDetector.isSafari();
        var isIOS = BrowserDetector.isIOS();
        var isMac = BrowserDetector.isMac();
        var isAndroid = BrowserDetector.isAndroid();
        var isFirefox = BrowserDetector.isFirefox();
        var isMobile = BrowserDetector.isMobile();

        return {
            isSafariIOS: isSafari && isIOS,
            isSafariMac: isSafari && isMac,
            isAndroid: isAndroid,
            isFirefox: isFirefox,
            isMobile: isMobile,
            preferUserVerification: isSafari,
            preferResidentKey: isAndroid || (isSafari && isIOS),
            needsExtendedTimeout: isAndroid || isFirefox || isMobile || (isSafari && (isIOS || isMac)),
            supportsThirdPartyHints: BrowserDetector.isChrome() || BrowserDetector.isEdge()
        };
    }

    function getVerificationHintMessage(profile) {
        if (profile.isSafariIOS) {
            return BrowserDetector.isIPad()
                ? '请在 iPad 上使用 Face ID 或触控 ID 完成验证'
                : '请在 iPhone 上使用 Face ID 或触控 ID 完成验证';
        }

        if (profile.isSafariMac) {
            return '请在 Mac 上使用 Touch ID 完成验证';
        }

        if (profile.isAndroid) {
            return '请使用指纹或设备锁屏密码完成验证';
        }

        return '请使用您的设备完成身份验证...';
    }

    function applyCompatibilityTweaks(publicKeyOptions, mode, profile) {
        var currentProfile = profile || getCompatibilityProfile();

        if (mode === 'register') {
            if (currentProfile.preferUserVerification && !publicKeyOptions.authenticatorSelection.userVerification) {
                publicKeyOptions.authenticatorSelection.userVerification = 'preferred';
            }

            if (currentProfile.preferResidentKey && typeof publicKeyOptions.authenticatorSelection.residentKey === 'undefined') {
                publicKeyOptions.authenticatorSelection.residentKey = 'preferred';
            }
        }

        if (currentProfile.needsExtendedTimeout) {
            ensureMinTimeout(publicKeyOptions, MIN_WEBAUTHN_TIMEOUT);
        }

        return publicKeyOptions;
    }

    var ACTION_NAMES = {
        registerOptions: 'register-options',
        registerVerify: 'register-verify',
        loginOptions: 'login-options',
        loginVerify: 'login-verify'
    };

    function getActionUrl(actionName) {
        return PASSKEY_ACTION_URL + '?do=' + actionName;
    }

    function decodeCredentialDescriptors(descriptors) {
        if (!descriptors || !descriptors.length) {
            return [];
        }

        return descriptors.map(function(cred) {
            return {
                type: cred.type,
                id: base64urlDecode(cred.id),
                transports: cred.transports || []
            };
        });
    }

    function buildRegistrationPublicKeyOptions(options) {
        if (!options || !options.challenge || !options.user || !options.rp) {
            throw new Error('服务器返回的数据不完整');
        }

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

        return applyCompatibilityTweaks(publicKeyOptions, 'register');
    }

    function buildLoginPublicKeyOptions(options) {
        if (!options || !options.challenge) {
            throw new Error('服务器返回的数据不完整');
        }

        var publicKeyOptions = {
            challenge: base64urlDecode(options.challenge),
            timeout: options.timeout || 60000,
            rpId: options.rpId,
            userVerification: options.userVerification || 'preferred'
        };

        if (options.allowCredentials && options.allowCredentials.length > 0) {
            publicKeyOptions.allowCredentials = decodeCredentialDescriptors(options.allowCredentials);
        }

        return applyCompatibilityTweaks(publicKeyOptions, 'login');
    }

    function serializeAttestationCredential(credential) {
        if (!credential || !credential.id || !credential.rawId || !credential.response) {
            throw new Error('凭证数据不完整');
        }

        return {
            id: credential.id,
            rawId: credential.id,
            type: credential.type,
            response: {
                clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                attestationObject: arrayBufferToBase64(credential.response.attestationObject)
            }
        };
    }

    function serializeLoginAssertion(assertion) {
        if (!assertion || !assertion.id || !assertion.response) {
            throw new Error('认证数据不完整');
        }

        return {
            id: assertion.id,
            rawId: assertion.id,
            type: assertion.type,
            response: {
                authenticatorData: arrayBufferToBase64(assertion.response.authenticatorData),
                clientDataJSON: arrayBufferToBase64(assertion.response.clientDataJSON),
                signature: arrayBufferToBase64(assertion.response.signature),
                userHandle: assertion.response.userHandle ?
                    arrayBufferToBase64(assertion.response.userHandle) : null
            }
        };
    }

    /**
     * 带超时的 fetch，保持后端交互不变
     */
    function fetchWithTimeout(url, fetchOptions, timeout, timeoutMessage) {
        return new Promise(function(resolve, reject) {
            var didFinish = false;
            var timer = setTimeout(function() {
                if (!didFinish) {
                    didFinish = true;
                    reject(new Error(timeoutMessage));
                }
            }, timeout);

            fetch(url, fetchOptions)
                .then(function(response) {
                    if (didFinish) {
                        return;
                    }
                    didFinish = true;
                    clearTimeout(timer);
                    resolve(response);
                })
                .catch(function(error) {
                    if (didFinish) {
                        return;
                    }
                    didFinish = true;
                    clearTimeout(timer);
                    reject(error);
                });
        });
    }

    /**
     * 请求并解析 JSON
     */
    function fetchJsonWithTimeout(url, fetchOptions, timeout, timeoutMessage) {
        return fetchWithTimeout(url, fetchOptions, timeout, timeoutMessage)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('服务器响应错误: ' + response.status);
                }
                return response.json();
            });
    }

    /**
     * 设置最小超时
     */
    function ensureMinTimeout(options, minTimeout) {
        if (!options.timeout || options.timeout < minTimeout) {
            options.timeout = minTimeout;
        }
    }

    /**
     * 第三方通行密钥管理器常见传输方式
     */
    var EXTERNAL_CREDENTIAL_TRANSPORTS = ['hybrid', 'internal', 'usb', 'nfc', 'ble'];

    function mergeTransportList(transports) {
        var merged = [];

        function pushUnique(value) {
            if (!value || typeof value !== 'string') {
                return;
            }
            if (merged.indexOf(value) === -1) {
                merged.push(value);
            }
        }

        if (transports && transports.length) {
            for (var i = 0; i < transports.length; i++) {
                pushUnique(transports[i]);
            }
        }

        for (var j = 0; j < EXTERNAL_CREDENTIAL_TRANSPORTS.length; j++) {
            pushUnique(EXTERNAL_CREDENTIAL_TRANSPORTS[j]);
        }

        return merged;
    }

    function normalizeCredentialDescriptors(descriptors) {
        if (!descriptors || !descriptors.length) {
            return descriptors;
        }

        return descriptors.map(function(cred) {
            return {
                type: cred.type,
                id: cred.id,
                transports: mergeTransportList(cred.transports)
            };
        });
    }

    function mergeHints(existingHints, addedHints) {
        var merged = [];

        function pushUnique(value) {
            if (!value || typeof value !== 'string') {
                return;
            }
            if (merged.indexOf(value) === -1) {
                merged.push(value);
            }
        }

        if (existingHints && existingHints.length) {
            for (var i = 0; i < existingHints.length; i++) {
                pushUnique(existingHints[i]);
            }
        }

        if (addedHints && addedHints.length) {
            for (var j = 0; j < addedHints.length; j++) {
                pushUnique(addedHints[j]);
            }
        }

        return merged;
    }

    /**
     * 兼容第三方通行密钥管理器（如 Bitwarden）
     */
    function applyThirdPartyManagerSupport(publicKeyOptions, mode) {
        var profile = getCompatibilityProfile();

        if (publicKeyOptions.allowCredentials && publicKeyOptions.allowCredentials.length > 0) {
            publicKeyOptions.allowCredentials = normalizeCredentialDescriptors(publicKeyOptions.allowCredentials);
        }

        if (publicKeyOptions.excludeCredentials && publicKeyOptions.excludeCredentials.length > 0) {
            publicKeyOptions.excludeCredentials = normalizeCredentialDescriptors(publicKeyOptions.excludeCredentials);
        }

        if (typeof publicKeyOptions.hints !== 'undefined' || profile.supportsThirdPartyHints) {
            publicKeyOptions.hints = mergeHints(publicKeyOptions.hints, ['client-device', 'security-key']);
        }

        if (!publicKeyOptions.extensions) {
            publicKeyOptions.extensions = {};
        }

        if (mode === 'create' && typeof publicKeyOptions.extensions.credProps === 'undefined') {
            publicKeyOptions.extensions.credProps = true;
        }

        return publicKeyOptions;
    }

    function cloneCreatePublicKeyOptions(publicKeyOptions) {
        var cloned = {};
        var key;

        for (key in publicKeyOptions) {
            if (publicKeyOptions.hasOwnProperty(key)) {
                cloned[key] = publicKeyOptions[key];
            }
        }

        if (publicKeyOptions.authenticatorSelection) {
            cloned.authenticatorSelection = {};
            for (key in publicKeyOptions.authenticatorSelection) {
                if (publicKeyOptions.authenticatorSelection.hasOwnProperty(key)) {
                    cloned.authenticatorSelection[key] = publicKeyOptions.authenticatorSelection[key];
                }
            }
        }

        if (publicKeyOptions.excludeCredentials) {
            cloned.excludeCredentials = publicKeyOptions.excludeCredentials.slice();
        }

        if (publicKeyOptions.pubKeyCredParams) {
            cloned.pubKeyCredParams = publicKeyOptions.pubKeyCredParams.slice();
        }

        if (publicKeyOptions.hints) {
            cloned.hints = publicKeyOptions.hints.slice();
        }

        if (publicKeyOptions.extensions) {
            cloned.extensions = {};
            for (key in publicKeyOptions.extensions) {
                if (publicKeyOptions.extensions.hasOwnProperty(key)) {
                    cloned.extensions[key] = publicKeyOptions.extensions[key];
                }
            }
        }

        return cloned;
    }

    function createCredentialWithThirdPartyFallback(publicKeyOptions) {
        applyThirdPartyManagerSupport(publicKeyOptions, 'create');

        return navigator.credentials.create({ publicKey: publicKeyOptions })
            .catch(function(error) {
                var canRetry = error && (error.name === 'NotSupportedError' || error.name === 'ConstraintError');
                var isPlatformOnly = publicKeyOptions.authenticatorSelection &&
                    publicKeyOptions.authenticatorSelection.authenticatorAttachment === 'platform';

                if (!canRetry || !isPlatformOnly) {
                    throw error;
                }

                var retryOptions = cloneCreatePublicKeyOptions(publicKeyOptions);
                delete retryOptions.authenticatorSelection.authenticatorAttachment;
                applyThirdPartyManagerSupport(retryOptions, 'create');
                return navigator.credentials.create({ publicKey: retryOptions });
            });
    }

    function getCredentialWithThirdPartySupport(publicKeyOptions) {
        applyThirdPartyManagerSupport(publicKeyOptions, 'get');

        return navigator.credentials.get({
            publicKey: publicKeyOptions,
            mediation: 'optional'
        }).catch(function(error) {
            if (error && error.name === 'TypeError') {
                return navigator.credentials.get({
                    publicKey: publicKeyOptions
                });
            }
            throw error;
        });
    }

    /**
     * 统一映射错误文案
     */
    function getFriendlyErrorMessage(error, mode) {
        var errorMessage = error && error.message ? error.message : '未知错误';

        if (error.name === 'NotAllowedError') {
            if (mode === 'registerWithInfo') {
                return '注册已取消或超时，请重试';
            }
            return BrowserDetector.isMobile()
                ? '操作已取消或超时，请重试'
                : '操作已取消或超时，请确保您的设备已准备好';
        }

        if (error.name === 'InvalidStateError') {
            if (mode === 'login') {
                return '您的设备尚未注册通行秘钥';
            }
            return '此设备已经注册过 Passkey';
        }

        if (error.name === 'NotSupportedError') {
            if (mode === 'registerWithInfo') {
                return '您的设备不支持此认证方式';
            }
            if (BrowserDetector.isIOS()) {
                return '您的设备不支持 Passkey，需要支持 Face ID 或触控 ID 的设备';
            }
            if (BrowserDetector.isAndroid()) {
                return '您的设备不支持 Passkey，需要支持指纹或设备锁屏的设备';
            }
            return '您的设备不支持此认证方式';
        }

        if (error.name === 'SecurityError') {
            return '安全错误：请确保网站使用 HTTPS 连接';
        }

        if (error.name === 'AbortError') {
            return '操作被中断，请重试';
        }

        if (error.name === 'TimeoutError') {
            return '操作超时，请检查您的设备并重试';
        }

        if (error.message && error.message.indexOf('timeout') > -1) {
            return '操作超时，请检查网络连接后重试';
        }

        if (error.message && error.message.indexOf('Network') > -1) {
            return '网络错误，请检查您的网络连接';
        }

        return errorMessage;
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
            try {
                runCommonPreChecks();
            } catch (preCheckError) {
                reject(preCheckError);
                return;
            }

            showDeviceVerificationHint();
            showNotification('正在准备注册...', 'info');

            var timeout = getDefaultRequestTimeout();

            fetchJsonWithTimeout(
                getActionUrl(ACTION_NAMES.registerOptions),
                undefined,
                timeout,
                '请求超时，请检查网络连接'
            )
                .then(function(data) {
                    if (!data.success) {
                        throw new Error(data.error || '获取注册选项失败');
                    }

                    var options = data.data;
                    var profile = getCompatibilityProfile();
                    var publicKeyOptions = buildRegistrationPublicKeyOptions(options);

                    if (profile.isSafariMac && options.excludeCredentials && options.excludeCredentials.length > 0) {
                        publicKeyOptions.excludeCredentials = decodeCredentialDescriptors(options.excludeCredentials);
                    }

                    return createCredentialWithThirdPartyFallback(publicKeyOptions);
                })
                .then(function(credential) {
                    if (!credential) {
                        throw new Error('创建凭证失败');
                    }

                    showNotification('正在保存凭证...', 'info');

                    return fetchJsonWithTimeout(
                        getActionUrl(ACTION_NAMES.registerVerify),
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(serializeAttestationCredential(credential))
                        },
                        getDefaultRequestTimeout(),
                        '验证超时，请重试'
                    );
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
                    showNotification('注册失败: ' + getFriendlyErrorMessage(error, 'register'), 'error');
                    reject(error);
                });
        });
    }
    
    /**
     * 使用 Passkey 登录
     */
    function login() {
        return new Promise(function(resolve, reject) {
            try {
                runCommonPreChecks();
            } catch (preCheckError) {
                reject(preCheckError);
                return;
            }

            showDeviceVerificationHint();
            showNotification('正在准备登录...', 'info');

            fetchJsonWithTimeout(
                getActionUrl(ACTION_NAMES.loginOptions),
                undefined,
                getDefaultRequestTimeout(),
                '请求超时，请检查网络连接'
            )
                .then(function(data) {
                    if (!data.success) {
                        throw new Error(data.error || '获取登录选项失败');
                    }

                    var options = data.data;
                    var publicKeyOptions = buildLoginPublicKeyOptions(options);

                    return getCredentialWithThirdPartySupport(publicKeyOptions);
                })
                .then(function(assertion) {
                    if (!assertion) {
                        throw new Error('认证失败');
                    }

                    showNotification('正在验证身份...', 'info');

                    return fetchJsonWithTimeout(
                        getActionUrl(ACTION_NAMES.loginVerify),
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(serializeLoginAssertion(assertion))
                        },
                        getDefaultRequestTimeout(),
                        '验证超时，请重试'
                    );
                })
                .then(function(data) {
                    if (data.needRegister) {
                        showNotification('此设备尚未注册，准备创建新账户...', 'info');
                        return showRegisterForm().then(function(userInfo) {
                            return registerWithInfo(userInfo);
                        });
                    }

                    if (!data.success) {
                        throw new Error(data.error || '登录失败');
                    }

                    showNotification('登录成功！欢迎回来，' + (data.data.user ? data.data.user.screenName : ''), 'success');

                    if (data.data.redirect) {
                        window.location.href = data.data.redirect;
                    } else {
                        window.location.reload();
                    }

                    resolve(data.data);
                })
                .catch(function(error) {
                    console.error('Passkey login error:', error);
                    showNotification('登录失败: ' + getFriendlyErrorMessage(error, 'login'), 'error');
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
     * 使用用户信息注册 Passkey
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
            fetchJsonWithTimeout(getActionUrl(ACTION_NAMES.registerOptions), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    username: usernameResult.value,
                    email: emailResult.value,
                    screenName: screenNameResult.value || usernameResult.value
                })
            }, 15000, '请求超时，请检查网络连接')
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.error || '获取注册选项失败');
                }
                
                var options = data.data;
                var profile = getCompatibilityProfile();
                var publicKeyOptions = buildRegistrationPublicKeyOptions(options);

                if (options.excludeCredentials && options.excludeCredentials.length > 0) {
                    publicKeyOptions.excludeCredentials = decodeCredentialDescriptors(options.excludeCredentials);
                }
                
                // Safari 特殊处理
                if (profile.isSafariIOS || profile.isSafariMac) {
                    showNotification('您正在使用 Safari 浏览器，请在弹出窗口中完成验证', 'info');
                } else if (profile.isFirefox) {
                    showNotification('您正在使用 Firefox 浏览器，请使用您的设备完成身份验证', 'info');
                } else {
                    showNotification(getVerificationHintMessage(profile), 'info');
                }
                
                // 创建凭证
                return createCredentialWithThirdPartyFallback(publicKeyOptions);
            })
            .then(function(credential) {
                if (!credential) {
                    throw new Error('创建凭证失败');
                }
                
                showNotification('正在完成注册...', 'info');
                
                // 发送到服务器验证
                return fetchJsonWithTimeout(getActionUrl(ACTION_NAMES.registerVerify), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(serializeAttestationCredential(credential))
                }, 15000, '验证超时，请重试');
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
                console.error('Passkey register error:', error);

                showNotification('注册失败: ' + getFriendlyErrorMessage(error, 'registerWithInfo'), 'error');
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

// 自动初始化 - 优化适配 Typecho 登录页面
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
