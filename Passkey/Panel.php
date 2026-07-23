<?php
/**
 * Passkey 管理面板
 * 独立页面 - 使用 Tailwind CSS 构建
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
$pluginVersion = '1.2.0';

// 获取用户头像（矩形设计）
function getPasskeyAvatar($email, $name, $size = 28) {
    $hash = md5(strtolower($email));
    $url = 'https://weavatar.com/avatar/' . $hash . '?s=' . $size . '&d=identicon';
    return '<img src="' . $url . '" alt="' . htmlspecialchars($name) . '" width="' . $size . '" height="' . $size . '" style="display:block;">';
}
?>
<!DOCTYPE html>
<html lang="zh-CN" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="color-scheme" content="light dark">
    <title>Passkey 身份验证管理 - <?php echo htmlspecialchars($options->title); ?></title>
    <!-- 字体导入 -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cascadia+Code:ital,wght@0,200..700;1,200..700&family=Inter:wght@100..900&family=Noto+Sans+SC:wght@100..900&display=swap" rel="stylesheet">
    <!-- TailwindCSS -->
    <link rel="stylesheet" href="<?php echo $pluginUrl; ?>/assist/css/panel.css?v=<?php echo $pluginVersion; ?>">
    <!-- Passkey 样式 -->
    <link rel="stylesheet" href="<?php echo $pluginUrl; ?>/assist/css/style.css?v=<?php echo $pluginVersion; ?>">
    <!-- Font Awesome -->
    <link href="https://cdn.garfieldtom.cool/resource/libs/fontawesome/7.2.0/css/all.min.css" rel="stylesheet">
</head>
<body class="min-h-screen flex flex-col font-sans text-sm" style="background: var(--passkey-bg);">
    <script>
        // 立即应用暗色模式，避免闪烁
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.body.style.background = '#000000';
        }
    </script>

    <!-- 顶部导航栏 - 参考 BooAdmin extend-topbar -->
    <header class="h-16 flex items-center justify-between px-6 z-10 fixed top-0 left-0 right-0 border-b" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
        <div class="flex items-center">
            <a href="<?php echo $options->adminUrl('index.php'); ?>" class="p-2 transition-colors hover:opacity-70" title="返回控制台" style="color: var(--passkey-muted);">
                <i class="fas fa-arrow-left text-sm"></i>
            </a>
            <span class="w-px h-6 mx-3" style="background: var(--passkey-border);"></span>
            <h1 class="text-base font-semibold" style="color: var(--passkey-text);">Passkey 身份验证管理</h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="hidden md:flex items-center gap-2.5">
                <?php echo getPasskeyAvatar($user->mail, $user->screenName, 28); ?>
                <div class="flex flex-col">
                    <span class="text-sm font-medium leading-tight" style="color: var(--passkey-text);"><?php $user->screenName(); ?></span>
                    <span class="text-xs leading-tight" style="color: var(--passkey-muted);"><?php echo $user->group; ?></span>
                </div>
            </div>
        </div>
    </header>

    <!-- 主内容区域 -->
    <main class="flex-1 pt-16 md:pt-16">
        <div class="p-6">
            <!-- 统计概览 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="p-5 border" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs uppercase tracking-wide" style="color: var(--passkey-muted);">已注册凭证数</span>
                        <i class="fas fa-fingerprint text-lg" style="color: var(--passkey-primary);"></i>
                    </div>
                    <div class="text-3xl font-bold" id="passkey-count" style="color: var(--passkey-text);">-</div>
                </div>
                <div class="p-5 border" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs uppercase tracking-wide" style="color: var(--passkey-muted);">最后更新时间</span>
                        <i class="fas fa-clock text-lg" style="color: var(--passkey-info);"></i>
                    </div>
                    <div class="text-base font-semibold pt-1" id="passkey-last" style="color: var(--passkey-text);">-</div>
                </div>
                <div class="p-5 border" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs uppercase tracking-wide" style="color: var(--passkey-muted);">登录成功统计</span>
                        <i class="fas fa-chart-line text-lg" style="color: var(--passkey-success);"></i>
                    </div>
                    <div class="text-3xl font-bold" id="passkey-rate" style="color: var(--passkey-text);">-</div>
                </div>
                <div class="p-5 border" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs uppercase tracking-wide" style="color: var(--passkey-muted);">浏览器支持状态</span>
                        <i class="fas fa-check-circle text-lg" style="color: var(--passkey-success);" id="support-icon"></i>
                    </div>
                    <div class="text-sm font-medium pt-1" id="passkey-support" style="color: var(--passkey-muted);">检测中...</div>
                </div>
            </div>

            <!-- 凭证列表 -->
            <div class="border mb-6" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
                <div class="flex flex-col md:flex-row md:items-center justify-between p-4 border-b gap-3" style="background: var(--passkey-surface-2); border-color: var(--passkey-border);">
                    <h2 class="text-sm font-semibold" style="color: var(--passkey-text);">凭证管理</h2>
                    <button type="button" id="register-passkey-btn" class="flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white transition-all hover:opacity-90" style="background: var(--passkey-primary);">
                        <i class="fas fa-fingerprint"></i>
                        <span>注册新 Passkey</span>
                    </button>
                </div>
                <div class="p-5">
                    <!-- 桌面端表格 -->
                    <div class="overflow-x-auto hidden md:block">
                        <table class="w-full">
                            <thead>
                                <tr style="background: var(--passkey-surface-2);">
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--passkey-muted); border-color: var(--passkey-border);">ID</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--passkey-muted); border-color: var(--passkey-border);">凭证标识符</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--passkey-muted); border-color: var(--passkey-border);">创建时间</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--passkey-muted); border-color: var(--passkey-border);">操作</th>
                                </tr>
                            </thead>
                            <tbody id="passkey-list">
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center" style="color: var(--passkey-muted);">
                                        <i class="fas fa-spinner fa-spin mr-2"></i>加载中...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 移动端卡片 -->
                    <div id="passkey-list-mobile" class="md:hidden">
                        <div class="py-8 text-center" style="color: var(--passkey-muted);">
                            <i class="fas fa-spinner fa-spin mr-2"></i>加载中...
                        </div>
                    </div>
                </div>
            </div>

            <!-- 登录记录 -->
            <div class="border mb-6" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
                <div class="p-4 border-b" style="background: var(--passkey-surface-2); border-color: var(--passkey-border);">
                    <h2 class="text-sm font-semibold" style="color: var(--passkey-text);">近期登录记录</h2>
                </div>
                <div class="p-5">
                    <!-- 桌面端表格 -->
                    <div class="overflow-x-auto hidden md:block">
                        <table class="w-full">
                            <thead>
                                <tr style="background: var(--passkey-surface-2);">
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--passkey-muted); border-color: var(--passkey-border);">登录时间</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--passkey-muted); border-color: var(--passkey-border);">设备/浏览器</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--passkey-muted); border-color: var(--passkey-border);">IP 地址</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide border-b" style="color: var(--passkey-muted); border-color: var(--passkey-border);">状态</th>
                                </tr>
                            </thead>
                            <tbody id="passkey-login-logs">
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center" style="color: var(--passkey-muted);">
                                        <i class="fas fa-spinner fa-spin mr-2"></i>加载中...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 移动端卡片 -->
                    <div id="passkey-login-logs-mobile" class="md:hidden">
                        <div class="py-8 text-center" style="color: var(--passkey-muted);">
                            <i class="fas fa-spinner fa-spin mr-2"></i>加载中...
                        </div>
                    </div>
                </div>
            </div>

            <!-- 使用说明 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="border" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
                    <div class="p-4 border-b" style="background: var(--passkey-surface-2); border-color: var(--passkey-border);">
                        <h2 class="text-sm font-semibold" style="color: var(--passkey-text);">功能特性</h2>
                    </div>
                    <div class="p-5">
                        <ul class="space-y-3 text-sm" style="color: var(--passkey-muted);">
                            <li class="flex gap-3">
                                <span>使用生物识别（指纹、面容识别）或设备 PIN 码进行身份验证</span>
                            </li>
                            <li class="flex gap-3">
                                <span>支持多设备绑定，每个设备独立管理</span>
                            </li>
                            <li class="flex gap-3">
                                <span>添加成功后可在登录页面使用 Passkey 快速登录</span>
                            </li>
                            <li class="flex gap-3">
                                <span>删除凭证后该设备将无法使用 Passkey 认证登录</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="border" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
                    <div class="p-4 border-b" style="background: var(--passkey-surface-2); border-color: var(--passkey-border);">
                        <h2 class="text-sm font-semibold" style="color: var(--passkey-text);">系统要求</h2>
                    </div>
                    <div class="p-5">
                        <ul class="space-y-3 text-sm" style="color: var(--passkey-muted);">
                            <li class="flex gap-3">
                                <span>必须使用 HTTPS 协议（本地开发可使用 localhost）</span>
                            </li>
                            <li class="flex gap-3">
                                <span>Chrome 67+、Firefox 60+、Safari 13+、Edge 18+</span>
                            </li>
                            <li class="flex gap-3">
                                <span>移动设备需支持生物识别或已设置设备锁屏密码</span>
                            </li>
                            <li class="flex gap-3">
                                <span>Windows 10 1903+ 并启用 Windows Hello</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- 页脚 -->
    <footer class="border-t py-6" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
        <div class="px-6 flex flex-col md:flex-row items-center justify-center gap-4 text-xs">
            <div class="flex items-center gap-3" style="color: var(--passkey-muted);">
                <span>Passkey 身份验证插件</span>
                <span class="hidden sm:inline opacity-50">|</span>
                <span class="hidden sm:inline">基于 FIDO2/WebAuthn 标准</span>
            </div>
            <div class="flex items-center gap-2 px-3 py-1.5 border" style="border-color: var(--passkey-border); background: var(--passkey-surface-2);">
                <img src="<?php echo $pluginUrl; ?>/assist/css/ieee.svg" alt="IEEE" width="24" height="auto" class="opacity-70">
                <span class="font-medium" style="color: var(--passkey-text);">IEEE Standard</span>
            </div>
        </div>
    </footer>

    <!-- 弹窗 -->
    <div id="passkey-modal-overlay" class="hidden fixed inset-0 z-50" style="background: rgba(0, 0, 0, 0.5);"></div>
    <div id="passkey-modal" class="hidden fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-11/12 max-w-md border shadow-lg z-50" style="background: var(--passkey-surface); border-color: var(--passkey-border);">
        <div class="flex items-center justify-between p-4 border-b" style="border-color: var(--passkey-border);">
            <h3 id="passkey-modal-title" class="text-sm font-medium" style="color: var(--passkey-text);">确认</h3>
            <button type="button" id="passkey-modal-close" class="p-1.5 transition-colors hover:opacity-70" style="color: var(--passkey-muted);" aria-label="关闭">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="passkey-modal-body" class="p-5 text-sm" style="color: var(--passkey-muted);"></div>
        <div class="flex justify-end gap-2 p-4">
            <button type="button" id="passkey-modal-cancel" class="px-4 py-2 text-sm font-medium border transition-colors hover:opacity-80" style="background: var(--passkey-surface); color: var(--passkey-danger); border-color: var(--passkey-border);">
                取消
            </button>
            <button type="button" id="passkey-modal-confirm" class="px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90" style="background: var(--passkey-primary);">
                确定
            </button>
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
        var rateDiv = document.getElementById('passkey-rate');
        var supportDiv = document.getElementById('passkey-support');
        var supportIcon = document.getElementById('support-icon');

        // 检测浏览器支持
        function checkSupport() {
            if (PasskeyManager.isSupported()) {
                supportDiv.textContent = '已支持';
                supportDiv.style.color = 'var(--passkey-success)';
                supportIcon.className = 'fas fa-check-circle text-lg';
                supportIcon.style.color = 'var(--passkey-success)';
            } else {
                supportDiv.textContent = '不支持';
                supportDiv.style.color = 'var(--passkey-danger)';
                supportIcon.className = 'fas fa-times-circle text-lg';
                supportIcon.style.color = 'var(--passkey-danger)';
            }
        }
        checkSupport();

        // JSON 解析
        function parseJSON(response) {
            return response.text().then(function(text) {
                if (!text || text.trim() === '') {
                    throw new Error('服务器返回空响应');
                }
                if (text.trim().startsWith('<')) {
                    console.error('服务器返回 HTML 而不是 JSON:', text.substring(0, 200));
                    throw new Error('服务器返回了错误页面');
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON 解析错误:', e);
                    throw new Error('服务器响应格式错误');
                }
            });
        }

        // 弹窗控制
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
                modalBody.innerHTML = '<p>' + message.replace(/\n\n/g, '</p><p class="mt-3">') + '</p>';
                modalConfirm.textContent = confirmText || '确定';
                modalCancel.textContent = cancelText || '取消';
                modalOverlay.classList.remove('hidden');
                modal.classList.remove('hidden');
            });
        }

        function hideModal(result) {
            modalOverlay.classList.add('hidden');
            modal.classList.add('hidden');
            if (currentResolve) {
                currentResolve(result);
                currentResolve = null;
            }
        }

        modalClose.addEventListener('click', function() { hideModal(false); });
        modalCancel.addEventListener('click', function() { hideModal(false); });
        modalConfirm.addEventListener('click', function() { hideModal(true); });
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) hideModal(false);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !modalOverlay.classList.contains('hidden')) hideModal(false);
        });

        function confirmDialog(message) {
            return showModal('确认', message, '确定', '取消');
        }

        // 加载凭证列表
        function loadCredentials() {
            listTable.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center" style="color: var(--passkey-muted);"><i class="fas fa-spinner fa-spin mr-2"></i>加载中...</td></tr>';

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
            listTable.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center" style="color: var(--passkey-muted);">' + message + '</td></tr>';
            if (listTableMobile) {
                listTableMobile.innerHTML = '<div class="py-8 text-center" style="color: var(--passkey-muted);">' + message + '</div>';
            }
            countDiv.textContent = '0';
            lastDiv.textContent = '无';
            rateDiv.textContent = '-';
        }

        // 渲染凭证列表
        function renderCredentials(credentials) {
            if (credentials.length === 0) {
                var emptyIcon = '<i class="fas fa-fingerprint text-5xl mb-4" style="color: var(--passkey-border-strong);"></i>';
                listTable.innerHTML = '<tr><td colspan="4" class="px-4 py-12 text-center"><div>' + emptyIcon + '</div><div class="mt-2" style="color: var(--passkey-muted);">暂无凭证，点击右上角按钮添加</div></td></tr>';
                if (listTableMobile) {
                    listTableMobile.innerHTML = '<div class="py-12 text-center">' + emptyIcon + '<div class="mt-2" style="color: var(--passkey-muted);">暂无凭证，点击上方按钮添加</div></div>';
                }
                countDiv.textContent = '0';
                lastDiv.textContent = '无';
                rateDiv.textContent = '-';
                return;
            }

            countDiv.textContent = credentials.length;
            lastDiv.textContent = credentials[credentials.length - 1].created_at;

            var html = '';
            var mobileHtml = '';

            credentials.forEach(function(cred) {
                html += '<tr class="border-b transition-colors hover:opacity-80" style="border-color: var(--passkey-border);">';
                html += '<td class="px-4 py-3 text-sm" style="color: var(--passkey-text);">' + cred.id + '</td>';
                html += '<td class="px-4 py-3"><code class="px-2 py-1 text-xs" style="background: var(--passkey-surface-2); color: var(--passkey-text); border: 1px solid var(--passkey-border);">' + cred.credential_id.substr(0, 32) + '...</code></td>';
                html += '<td class="px-4 py-3 text-sm" style="color: var(--passkey-muted);">' + cred.created_at + '</td>';
                html += '<td class="px-4 py-3 text-right"><button class="delete-btn px-3 py-2 text-xs font-medium border transition-colors hover:opacity-80" data-id="' + cred.id + '" style="background: var(--passkey-surface); color: var(--passkey-danger); border-color: var(--passkey-border);"><i class="fas fa-trash mr-1"></i>删除</button></td>';
                html += '</tr>';

                mobileHtml += '<div class="p-4 border-b" style="border-color: var(--passkey-border); background: var(--passkey-surface);">';
                mobileHtml += '<div class="flex justify-between items-center py-2"><span class="text-xs font-medium" style="color: var(--passkey-muted);">ID</span><span class="text-sm" style="color: var(--passkey-text);">' + cred.id + '</span></div>';
                mobileHtml += '<div class="flex justify-between items-center py-2"><span class="text-xs font-medium" style="color: var(--passkey-muted);">凭证标识符</span><code class="text-xs px-2 py-1" style="background: var(--passkey-surface-2); color: var(--passkey-text);">' + cred.credential_id.substr(0, 32) + '...</code></div>';
                mobileHtml += '<div class="flex justify-between items-center py-2"><span class="text-xs font-medium" style="color: var(--passkey-muted);">创建时间</span><span class="text-sm" style="color: var(--passkey-text);">' + cred.created_at + '</span></div>';
                mobileHtml += '<div class="flex justify-end pt-2"><button class="delete-btn px-4 py-2 text-xs font-medium border hover:opacity-80" data-id="' + cred.id + '" style="background: var(--passkey-surface); color: var(--passkey-danger); border-color: var(--passkey-border);"><i class="fas fa-trash mr-1"></i>删除</button></div>';
                mobileHtml += '</div>';
            });

            listTable.innerHTML = html;
            if (listTableMobile) {
                listTableMobile.innerHTML = mobileHtml;
            }

            // 删除按钮事件
            var deleteBtns = document.querySelectorAll('.delete-btn');
            deleteBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.getAttribute('data-id');
                    confirmDialog('确定要删除此凭证吗？\n\n删除后该设备将无法使用 Passkey 登录。').then(function(result) {
                        if (result) deleteCredential(id);
                    });
                });
            });
        }

        // 删除凭证
        async function deleteCredential(id) {
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

            if (!PasskeyManager.isSupported()) {
                PasskeyManager.showNotification('您的浏览器不支持 Passkey 功能', 'error');
                return;
            }

            registerBtn.disabled = true;
            registerBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>正在注册...</span>';

            PasskeyManager.register()
                .then(function(result) {
                    PasskeyManager.showNotification('Passkey 注册成功！您现在可以使用此设备快速登录', 'success');
                    setTimeout(function() { loadCredentials(); }, 500);
                })
                .catch(function(error) {
                    console.error('Register error:', error);
                })
                .finally(function() {
                    registerBtn.disabled = false;
                    registerBtn.innerHTML = '<i class="fas fa-fingerprint"></i><span>注册新 Passkey</span>';
                });
        });

        // 加载登录记录
        function loadLoginLogs() {
            var logsTable = document.getElementById('passkey-login-logs');
            var logsTableMobile = document.getElementById('passkey-login-logs-mobile');

            logsTable.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center" style="color: var(--passkey-muted);"><i class="fas fa-spinner fa-spin mr-2"></i>加载中...</td></tr>';
            if (logsTableMobile) {
                logsTableMobile.innerHTML = '<div class="py-8 text-center" style="color: var(--passkey-muted);"><i class="fas fa-spinner fa-spin mr-2"></i>加载中...</div>';
            }

            fetch(PASSKEY_ACTION_URL + '?do=login-logs&limit=20')
                .then(parseJSON)
                .then(function(data) {
                    if (data.success) {
                        renderLoginLogs(data.data, logsTable, logsTableMobile);
                        // 计算成功率
                        if (data.data && data.data.length > 0) {
                            var successCount = data.data.filter(function(log) {
                                return log.status.toLowerCase().indexOf('success') > -1 || log.status.toLowerCase().indexOf('成功') > -1;
                            }).length;
                            var rate = Math.round((successCount / data.data.length) * 100);
                            rateDiv.textContent = rate + '%';
                        } else {
                            rateDiv.textContent = '-';
                        }
                    } else {
                        logsTable.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center" style="color: var(--passkey-muted);">加载失败: ' + data.error + '</td></tr>';
                    }
                })
                .catch(function(error) {
                    console.error('加载登录记录失败:', error);
                    logsTable.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center" style="color: var(--passkey-muted);">加载失败: ' + error.message + '</td></tr>';
                });
        }

        // 渲染登录记录
        function renderLoginLogs(logs, table, tableMobile) {
            if (!logs || logs.length === 0) {
                var emptyIcon = '<i class="fas fa-history text-5xl mb-4" style="color: var(--passkey-border-strong);"></i>';
                table.innerHTML = '<tr><td colspan="4" class="px-4 py-12 text-center"><div>' + emptyIcon + '</div><div class="mt-2" style="color: var(--passkey-muted);">暂无登录记录</div></td></tr>';
                if (tableMobile) {
                    tableMobile.innerHTML = '<div class="py-12 text-center">' + emptyIcon + '<div class="mt-2" style="color: var(--passkey-muted);">暂无登录记录</div></div>';
                }
                return;
            }

            var html = '';
            var mobileHtml = '';

            logs.forEach(function(log) {
                var isSuccess = log.status.toLowerCase().indexOf('success') > -1 || log.status.toLowerCase().indexOf('成功') > -1;
                var statusClass = isSuccess ? 'success' : 'failed';
                var statusIcon = isSuccess ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>';
                var statusColor = isSuccess ? 'color: var(--passkey-success);' : 'color: var(--passkey-danger);';
                var statusBg = isSuccess ? 'background: var(--passkey-success-bg);' : 'background: var(--passkey-error-bg);';

                html += '<tr class="border-b transition-colors hover:opacity-80" style="border-color: var(--passkey-border);">';
                html += '<td class="px-4 py-3 text-sm" style="color: var(--passkey-text);">' + log.login_time + '</td>';
                html += '<td class="px-4 py-3 text-sm truncate max-w-xs" style="color: var(--passkey-muted);" title="' + (log.user_agent || 'Unknown') + '">' + (log.user_agent || 'Unknown') + '</td>';
                html += '<td class="px-4 py-3"><code class="px-2 py-1 text-xs" style="background: var(--passkey-surface-2); color: var(--passkey-text); border: 1px solid var(--passkey-border);">' + log.ip_address + '</code></td>';
                html += '<td class="px-4 py-3"><span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium" style="' + statusBg + statusColor + '">' + statusIcon + ' ' + log.status + '</span></td>';
                html += '</tr>';

                mobileHtml += '<div class="p-4 border-b" style="border-color: var(--passkey-border); background: var(--passkey-surface);">';
                mobileHtml += '<div class="flex justify-between items-center py-2"><span class="text-xs font-medium" style="color: var(--passkey-muted);">登录时间</span><span class="text-sm" style="color: var(--passkey-text);">' + log.login_time + '</span></div>';
                mobileHtml += '<div class="flex justify-between items-center py-2"><span class="text-xs font-medium" style="color: var(--passkey-muted);">设备/浏览器</span><span class="text-xs text-right max-w-48 truncate" style="color: var(--passkey-text);">' + (log.user_agent || 'Unknown') + '</span></div>';
                mobileHtml += '<div class="flex justify-between items-center py-2"><span class="text-xs font-medium" style="color: var(--passkey-muted);">IP 地址</span><code class="text-xs px-2 py-1" style="background: var(--passkey-surface-2); color: var(--passkey-text);">' + log.ip_address + '</code></div>';
                mobileHtml += '<div class="flex justify-between items-center py-2"><span class="text-xs font-medium" style="color: var(--passkey-muted);">状态</span><span class="inline-flex items-center gap-1.5 px-2 py-1 text-xs font-medium" style="' + statusBg + statusColor + '">' + statusIcon + ' ' + log.status + '</span></div>';
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