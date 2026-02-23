<?php

namespace TypechoPlugin\Passkey;

use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;
use Typecho\Cookie;
use Typecho\Common;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Passkey Action 处理类 - 安全加固版
 */
class Action extends Widget implements ActionInterface
{
    private $db;
    private $prefix;
    
    // 安全常量
    const MAX_ATTEMPTS_PER_HOUR = 20;      // 每小时最大尝试次数
    const MAX_ATTEMPTS_PER_IP = 10;        // 每个 IP 每小时最大尝试次数
    const SESSION_TIMEOUT = 300;            // Session 超时时间（5分钟）
    const MAX_CREDENTIAL_ID_LENGTH = 512;  // 凭证 ID 最大长度
    
    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        $this->db = \Typecho\Db::get();
        $this->prefix = $this->db->getPrefix();
    }
    
    /**
     * 执行函数
     */
    public function action()
    {
        // 捕获所有输出，确保只返回 JSON
        ob_start();
        
        // 设置错误处理，防止 PHP 警告影响 JSON 输出
        $previousErrorHandler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
            // 记录错误但不输出
            error_log("Passkey Error [$errno]: $errstr in $errfile on line $errline");
            return true; // 不执行 PHP 内部错误处理
        });
        
        try {
            $this->response->setContentType('application/json');
            
            $action = $this->request->get('do');
        
        // 验证 action 参数（白名单）
        $allowedActions = array(
            'register-options',
            'register-verify',
            'login-options',
            'login-verify',
            'list',
            'delete',
            'login-logs'
        );
        
        if (!in_array($action, $allowedActions, true)) {
            $this->error('Invalid action');
            return;
        }
        
        // 速率限制检查（针对敏感操作）
        $sensitiveActions = array('register-verify', 'login-verify', 'register-options', 'login-options');
        if (in_array($action, $sensitiveActions, true)) {
            if (!$this->checkRateLimit()) {
                $this->error('请求过于频繁，请稍后再试');
                return;
            }
        }
        
        switch ($action) {
            case 'register-options':
                $this->registerOptions();
                break;
            case 'register-verify':
                $this->registerVerify();
                break;
            case 'login-options':
                $this->loginOptions();
                break;
            case 'login-verify':
                $this->loginVerify();
                break;
            case 'list':
                $this->listCredentials();
                break;
            case 'delete':
                $this->deleteCredential();
                break;
            case 'login-logs':
                $this->getLoginLogs();
                break;
            default:
                $this->error('Invalid action');
        }
        } catch (\Exception $e) {
            // 捕获所有未处理的异常
            ob_clean(); // 清空输出缓冲区
            error_log('Passkey Action Exception: ' . $e->getMessage());
            $this->error('系统错误: ' . $e->getMessage());
        } finally {
            // 恢复错误处理器
            if (isset($previousErrorHandler)) {
                set_error_handler($previousErrorHandler);
            }
            // 清理并丢弃可能的警告输出
            $unwantedOutput = ob_get_clean();
            if (!empty($unwantedOutput)) {
                error_log('Passkey: Unwanted output captured: ' . substr($unwantedOutput, 0, 200));
            }
        }
    }
    
    /**
     * 生成注册选项
     */
    private function registerOptions()
    {
        $options = Options::alloc();
        $plugin = $options->plugin('Passkey');
        
        // 检查用户是否登录
        $user = \Widget\User::alloc();
        $isLoggedIn = $user->hasLogin();
        
        // 如果未登录，检查是否允许注册
        if (!$isLoggedIn) {
            // 优先检查全局注册设置
            $globalAllowRegister = $options->allowRegister ? true : false;
            $pluginAllowRegister = (isset($plugin->enableRegister) && $plugin->enableRegister == '1') ? true : false;
            
            if (!$globalAllowRegister) {
                $this->error('全局注册已关闭。请先登录，或联系管理员开启注册功能。');
                return;
            }
            
            if (!$pluginAllowRegister) {
                $this->error('Passkey 注册功能未启用。请先登录，或联系管理员。');
                return;
            }
            
            // 获取POST数据（用户提供的注册信息）
            $postData = json_decode(file_get_contents('php://input'), true);
            
            // 验证 JSON 解析是否成功
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('无效的请求数据');
                return;
            }
            
            $userName = isset($postData['username']) ? trim($postData['username']) : '';
            $userEmail = isset($postData['email']) ? trim($postData['email']) : '';
            $displayName = isset($postData['screenName']) ? trim($postData['screenName']) : '';
            
            // 验证用户输入
            if (empty($userName) || empty($userEmail)) {
                $this->error('用户名和邮箱不能为空');
                return;
            }
            
            // 验证用户名长度
            if (strlen($userName) < 3 || strlen($userName) > 32) {
                $this->error('用户名长度必须在 3-32 个字符之间');
                return;
            }
            
            // 验证用户名格式（只允许字母数字下划线，且不能以数字开头）
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,31}$/', $userName)) {
                $this->error('用户名只能包含字母、数字和下划线，必须以字母开头');
                return;
            }
            
            // 验证邮箱格式
            if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $this->error('请输入有效的邮箱地址');
                return;
            }
            
            // 验证邮箱长度
            if (strlen($userEmail) > 200) {
                $this->error('邮箱地址过长');
                return;
            }
            
            // 验证昵称长度
            if (strlen($displayName) > 100) {
                $this->error('昵称过长');
                return;
            }
            
            // 过滤昵称中的危险字符（防止 XSS 和数据库注入）
            if (!empty($displayName)) {
                // 移除控制字符和不可见字符
                $displayName = preg_replace('/[\x00-\x1F\x7F]/u', '', $displayName);
                // 移除 HTML 标签
                $displayName = strip_tags($displayName);
                // 重新 trim 以移除可能产生的空格
                $displayName = trim($displayName);
            }
            
            // 检查用户名或邮箱是否已存在
            $existingUser = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('name = ?', $userName)
                ->limit(1));
            
            $existingEmail = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('mail = ?', $userEmail)
                ->limit(1));
            
            if ($existingUser || $existingEmail) {
                // 使用统一错误信息
                $this->error('该用户名或邮箱不可用，请选择其他用户名或邮箱。');
                return;
            }
            
            if (empty($displayName)) {
                $displayName = $userName;
            }
            
            // 允许未登录用户注册
            $tempUserId = 'passuser_' . bin2hex(random_bytes(8));
        } else {
            // 已登录用户添加凭证
            $tempUserId = $user->uid;
            $userName = $user->name;
            $userEmail = $user->mail;
            $displayName = $user->screenName;
        }
        
        $rpName = $plugin->rpName ?: 'My Website';
        $rpId = $plugin->rpId ?: $_SERVER['HTTP_HOST'];
        
        // 验证 rpId 格式
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $rpId)) {
            $this->error('无效的 RP ID 格式');
            return;
        }
        
        // 生成 challenge
        $challenge = $this->generateChallenge();
        
        // 保存 challenge 和用户信息到 session
        session_start();
        $_SESSION['passkey_register_challenge'] = $challenge;
        $_SESSION['passkey_register_user_id'] = $isLoggedIn ? $user->uid : null;
        $_SESSION['passkey_register_is_new_user'] = !$isLoggedIn;
        $this->setSessionTimestamp('passkey_register_challenge');
        
        // 如果是新用户，保存注册信息
        if (!$isLoggedIn) {
            $_SESSION['passkey_register_username'] = $userName;
            $_SESSION['passkey_register_email'] = $userEmail;
            $_SESSION['passkey_register_screenname'] = $displayName;
        }
        
        $publicKey = array(
            'challenge' => $challenge,
            'rp' => array(
                'name' => $rpName,
                'id' => $rpId
            ),
            'user' => array(
                'id' => base64_encode($tempUserId),
                'name' => $userName,
                'displayName' => $displayName
            ),
            'pubKeyCredParams' => array(
                array('type' => 'public-key', 'alg' => -7),  // ES256
                array('type' => 'public-key', 'alg' => -257) // RS256
            ),
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => array(
                'authenticatorAttachment' => 'platform',
                'requireResidentKey' => false,
                'userVerification' => 'preferred'
            )
        );
        
        $this->success($publicKey);
    }
    
    /**
     * 验证注册
     */
    private function registerVerify()
    {
        session_start();
        
        if (!isset($_SESSION['passkey_register_challenge'])) {
            $this->error('Invalid session');
            return;
        }
        
        // 检查 session 超时
        if (!$this->checkSessionTimeout('passkey_register_challenge')) {
            $this->error('Session 已超时，请重新开始注册');
            return;
        }
        
        $challenge = $_SESSION['passkey_register_challenge'];
        $isNewUser = isset($_SESSION['passkey_register_is_new_user']) && $_SESSION['passkey_register_is_new_user'];
        
        // 如果不是新用户，检查登录状态
        if (!$isNewUser) {
            $user = \Widget\User::alloc();
            if (!$user->hasLogin()) {
                $this->error('Please login first');
                return;
            }
            $userId = $user->uid;
        } else {
            // 新用户注册，需要创建账户
            $userId = null;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // 验证 JSON 解析是否成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('无效的请求数据');
            return;
        }
        
        if (!$data || !isset($data['id']) || !isset($data['rawId']) || !isset($data['response'])) {
            $this->error('Invalid data');
            return;
        }
        
        // 验证数据类型
        if (!is_string($data['id']) || !is_string($data['rawId']) || !is_array($data['response'])) {
            $this->error('Invalid data type');
            return;
        }
        
        // 验证 rawId 长度（防止缓冲区溢出）
        if (strlen($data['rawId']) > 2048) {
            $this->error('Invalid credential ID length');
            return;
        }
        
        // 获取配置
        $options = Options::alloc();
        $plugin = $options->plugin('Passkey');
        $rpId = $plugin->rpId ?: $_SERVER['HTTP_HOST'];
        
        // 验证 RP ID
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $rpId)) {
            $this->error('无效的 RP ID');
            return;
        }
        
        // 构造 origin
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $origin = $protocol . '://' . $_SERVER['HTTP_HOST'];
        
        // 使用 WebAuthn 类进行完整验证
        try {
            $verificationResult = WebAuthn::verifyRegistration(
                $data['response'],
                $challenge,
                $rpId,
                $origin
            );
            
            $credentialId = $verificationResult['credentialId'];
            $publicKey = $verificationResult['publicKey'];
            $counter = $verificationResult['counter'];
            
            // 验证凭证 ID
            if (!$this->validateCredentialId($credentialId)) {
                throw new \Exception('Invalid credential ID format');
            }
            
        } catch (\Exception $e) {
            $this->error('WebAuthn 验证失败: ' . $e->getMessage());
            return;
        }
        
        // 如果是新用户，先创建账户
        if ($isNewUser) {
            // 从 session 读取用户信息
            $username = isset($_SESSION['passkey_register_username']) ? $_SESSION['passkey_register_username'] : '';
            $email = isset($_SESSION['passkey_register_email']) ? $_SESSION['passkey_register_email'] : '';
            $screenName = isset($_SESSION['passkey_register_screenname']) ? $_SESSION['passkey_register_screenname'] : '';
            
            if (empty($username) || empty($email)) {
                $this->error('注册信息丢失，请重试');
                return;
            }
            
            $password = md5(uniqid(mt_rand(), true)); // 随机密码
            
            // 插入新用户
            $insertData = array(
                'name' => $username,
                'password' => Common::hash($password),
                'mail' => $email,
                'url' => '',
                'screenName' => $screenName,
                'created' => time(),
                'activated' => time(),
                'logged' => time(),
                'group' => 'subscriber'
            );
            
            $userId = $this->db->query($this->db->insert($this->prefix . 'users')->rows($insertData));
            
            if (!$userId) {
                $this->error('创建用户失败');
                return;
            }
        }
        
        // 保存凭证
        // 直接插入，依赖数据库 UNIQUE 约束防止竞态条件
        // 不再手动检查凭证是否存在，让数据库原子性地处理
        try {
            $this->db->query($this->db->insert($this->prefix . 'passkey_credentials')->rows(array(
                'user_id' => $userId,
                'credential_id' => $credentialId,
                'public_key' => $publicKey,
                'counter' => $counter,
                'created_at' => time()
            )));
            
            unset($_SESSION['passkey_register_challenge']);
            unset($_SESSION['passkey_register_user_id']);
            unset($_SESSION['passkey_register_is_new_user']);
            unset($_SESSION['passkey_register_username']);
            unset($_SESSION['passkey_register_email']);
            unset($_SESSION['passkey_register_screenname']);
            
            // 如果是新用户，自动登录
            if ($isNewUser) {
                // 使用 Typecho 标准的 simpleLogin 方法
                $userWidget = \Widget\User::alloc();
                $expire = 30 * 24 * 3600;
                
                if ($userWidget->simpleLogin($userId, false, $expire)) {
                    $this->success(array(
                        'message' => '注册成功！欢迎使用 Passkey 登录',
                        'isNewUser' => true,
                        'redirect' => Options::alloc()->adminUrl
                    ));
                    return;
                }
            }
            
            $this->success(array('message' => 'Passkey registered successfully'));
        } catch (\Exception $e) {
            // 捕获数据库唯一键冲突异常（竞态条件导致的重复插入）
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Duplicate') !== false || 
                strpos($errorMessage, 'unique') !== false ||
                strpos($errorMessage, 'UNIQUE') !== false) {
                $this->error('此凭证已经注册过');
            } else {
                $this->error('Failed to save credential: ' . $errorMessage);
            }
        }
    }
    
    /**
     * 生成登录选项
     */
    private function loginOptions()
    {
        $options = Options::alloc();
        $plugin = $options->plugin('Passkey');
        
        $rpId = $plugin->rpId ?: $_SERVER['HTTP_HOST'];
        
        // 验证 RP ID
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $rpId)) {
            $this->error('无效的 RP ID');
            return;
        }
        
        // 生成 challenge
        $challenge = $this->generateChallenge();
        
        // 保存 challenge 到 session
        session_start();
        $_SESSION['passkey_login_challenge'] = $challenge;
        $this->setSessionTimestamp('passkey_login_challenge');
        
        $publicKey = array(
            'challenge' => $challenge,
            'timeout' => 60000,
            'rpId' => $rpId,
            'userVerification' => 'preferred'
        );
        
        $this->success($publicKey);
    }
    
    /**
     * 验证登录
     */
    private function loginVerify()
    {
        session_start();
        
        if (!isset($_SESSION['passkey_login_challenge'])) {
            $this->error('会话已过期，请重试');
            return;
        }
        
        // 检查 session 超时
        if (!$this->checkSessionTimeout('passkey_login_challenge')) {
            $this->error('Session 已超时，请重新开始登录');
            return;
        }
        
        $challenge = $_SESSION['passkey_login_challenge'];
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // 验证 JSON 解析是否成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('无效的请求数据');
            return;
        }
        
        if (!$data || !isset($data['id']) || !isset($data['rawId']) || !isset($data['response'])) {
            $this->error('数据格式错误');
            return;
        }
        
        // 验证数据类型
        if (!is_string($data['id']) || !is_string($data['rawId']) || !is_array($data['response'])) {
            $this->error('Invalid data type');
            return;
        }

        // 验证 rawId 长度（防止缓冲区溢出）
        if (strlen($data['rawId']) > 2048) {
            $this->error('Invalid credential ID length');
            return;
        }
        
        // rawId 是 base64url 编码，需要转换为标准 base64
        $credentialId = $this->base64url_to_base64($data['rawId']);
        
        // 验证凭证 ID
        if (!$this->validateCredentialId($credentialId)) {
            $this->error('Invalid credential ID format');
            return;
        }
        
        // 查找凭证
        try {
            $credential = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'passkey_credentials')
                ->where('credential_id = ?', $credentialId));
            
            if (!$credential) {
                // 凭证不存在，检查是否允许注册
                $options = Options::alloc();
                $globalAllowRegister = $options->allowRegister ? true : false;
                $plugin = $options->plugin('Passkey');
                $pluginAllowRegister = (isset($plugin->enableRegister) && $plugin->enableRegister == '1') ? true : false;
                
                if ($globalAllowRegister && $pluginAllowRegister) {
                    // 允许注册，返回特殊错误码
                    $this->response->throwJson(array(
                        'success' => false,
                        'needRegister' => true,
                        'error' => '此设备尚未注册 Passkey'
                    ));
                    return;
                }
                
                $this->error('凭证不存在。请先登录后台在"Passkey 管理"中添加凭证。');
                return;
            }
            
            // 获取配置
            $options = Options::alloc();
            $plugin = $options->plugin('Passkey');
            $rpId = $plugin->rpId ?: $_SERVER['HTTP_HOST'];
            
            // 构造 origin
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $origin = $protocol . '://' . $_SERVER['HTTP_HOST'];
            
            // 使用 WebAuthn 类进行完整验证
            try {
                $verificationResult = WebAuthn::verifyAuthentication(
                    $data['response'],
                    $challenge,
                    $rpId,
                    $origin,
                    $credential['public_key'],
                    (int)$credential['counter']
                );
                
                $newCounter = $verificationResult['counter'];
                
            } catch (\Exception $e) {
                // 记录失败的登录尝试
                $this->logLoginActivity($credential['user_id'], $credential['id'], $challenge, 'failed');
                $this->error('WebAuthn 验证失败: ' . $e->getMessage());
                return;
            }
            
            // 获取用户信息
            $user = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('uid = ?', $credential['user_id']));
            
            if (!$user) {
                $this->error('用户不存在');
                return;
            }
            
            // 使用 Typecho 标准的 simpleLogin 方法
            $userWidget = \Widget\User::alloc();
            $expire = 30 * 24 * 3600;
            
            if (!$userWidget->simpleLogin($user['uid'], false, $expire)) {
                $this->error('登录失败：无法设置登录状态');
                return;
            }
            
            // 更新凭证计数器和最后使用时间
            try {
                $updateData = array('counter' => $newCounter);
                
                // 尝试更新 last_used 字段（兼容旧版本）
                try {
                    $updateData['last_used'] = time();
                } catch (\Exception $e) {
                    // 字段不存在，忽略
                }
                
                $this->db->query($this->db->update($this->prefix . 'passkey_credentials')
                    ->rows($updateData)
                    ->where('id = ?', $credential['id']));
            } catch (\Exception $e) {
                error_log('Failed to update credential: ' . $e->getMessage());
            }
            
            // 记录登录日志
            $this->logLoginActivity($credential['user_id'], $credential['id'], $challenge);
            
            // 清除挑战（但保留在日志中）
            unset($_SESSION['passkey_login_challenge']);
            
            $this->success(array(
                'message' => '登录成功',
                'redirect' => Options::alloc()->adminUrl,
                'user' => array(
                    'name' => $user['name'],
                    'screenName' => $user['screenName']
                )
            ));
        } catch (\Exception $e) {
            $this->error('登录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 记录登录活动
     */
    private function logLoginActivity($userId, $credentialId, $challenge, $status = 'success')
    {
        try {
            $this->db->query($this->db->insert($this->prefix . 'passkey_login_logs')
                ->rows(array(
                    'user_id' => $userId,
                    'credential_id' => $credentialId,
                    'challenge' => $challenge,
                    'ip_address' => $this->request->getIp(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'login_time' => time(),
                    'status' => $status
                )));
        } catch (\Exception $e) {
            // 记录失败不影响登录流程
            error_log('Failed to log Passkey login activity: ' . $e->getMessage());
        }
    }
    
    /**
     * 列出当前用户的凭证
     */
    private function listCredentials()
    {
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            $this->error('请先登录');
            return;
        }
        
        try {
            $credentials = $this->db->fetchAll($this->db->select()
                ->from($this->prefix . 'passkey_credentials')
                ->where('user_id = ?', $user->uid));
            
            $result = array();
            foreach ($credentials as $cred) {
                $result[] = array(
                    'id' => $cred['id'],
                    'credential_id' => substr($cred['credential_id'], 0, 20) . '...',
                    'created_at' => date('Y-m-d H:i:s', $cred['created_at'])
                );
            }
            
            $this->success($result);
        } catch (\Exception $e) {
            $this->error('获取凭证列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 删除凭证
     */
    private function deleteCredential()
    {
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            $this->error('请先登录');
            return;
        }
        
        // 从 JSON body 中读取 ID
        $data = json_decode(file_get_contents('php://input'), true);
        $id = isset($data['id']) ? $data['id'] : $this->request->get('id');
        
        if (!$id) {
            $this->error('无效的凭证 ID');
            return;
        }
        
        try {
            $this->db->query($this->db->delete($this->prefix . 'passkey_credentials')
                ->where('id = ? AND user_id = ?', $id, $user->uid));
            
            $this->success(array('message' => '凭证已删除'));
        } catch (\Exception $e) {
            $this->error('删除失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取登录日志
     */
    private function getLoginLogs()
    {
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            $this->error('请先登录');
            return;
        }
        
        $limit = (int)$this->request->get('limit', 10);
        if ($limit > 100) $limit = 100;
        if ($limit < 1) $limit = 10;
        
        try {
            $logs = $this->db->fetchAll(
                $this->db->select('l.*, c.credential_id')
                    ->from($this->prefix . 'passkey_login_logs AS l')
                    ->join($this->prefix . 'passkey_credentials AS c', 'l.credential_id = c.id', \Typecho\Db::LEFT_JOIN)
                    ->where('l.user_id = ?', $user->uid)
                    ->order('l.login_time', \Typecho\Db::SORT_DESC)
                    ->limit($limit)
            );
            
            $result = array();
            foreach ($logs as $log) {
                $result[] = array(
                    'id' => $log['id'],
                    'credential_id' => isset($log['credential_id']) ? substr(base64_decode($log['credential_id']), 0, 16) . '...' : 'N/A',
                    'ip_address' => $log['ip_address'],
                    'user_agent' => $this->parseUserAgent($log['user_agent']),
                    'login_time' => date('Y-m-d H:i:s', $log['login_time']),
                    'status' => $log['status']
                );
            }
            
            $this->success($result);
        } catch (\Exception $e) {
            $this->error('获取登录日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 解析 User Agent
     */
    private function parseUserAgent($ua)
    {
        if (empty($ua)) return '未知设备';
        
        // 简单的 UA 解析
        $browser = '未知浏览器';
        $os = '未知系统';
        
        // 检测浏览器
        if (strpos($ua, 'Edg') !== false) $browser = 'Edge';
        elseif (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
        elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
        elseif (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
        
        // 检测操作系统
        if (strpos($ua, 'Windows') !== false) $os = 'Windows';
        elseif (strpos($ua, 'Mac OS') !== false) $os = 'macOS';
        elseif (strpos($ua, 'Linux') !== false) $os = 'Linux';
        elseif (strpos($ua, 'Android') !== false) $os = 'Android';
        elseif (strpos($ua, 'iOS') !== false || strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) $os = 'iOS';
        
        return $browser . ' / ' . $os;
    }
    
    /**
     * 检查速率限制
     */
    private function checkRateLimit()
    {
        session_start();
        $now = time();
        $ip = $this->request->getIp();
        
        // 清理过期的记录
        if (!isset($_SESSION['passkey_rate_limit'])) {
            $_SESSION['passkey_rate_limit'] = array();
        }
        
        $rateLimit = &$_SESSION['passkey_rate_limit'];
        
        // 清理1小时前的记录
        foreach ($rateLimit as $key => $data) {
            if ($data['time'] < $now - 3600) {
                unset($rateLimit[$key]);
            }
        }
        
        // 检查全局限制
        $globalCount = 0;
        $ipCount = 0;
        
        foreach ($rateLimit as $data) {
            $globalCount++;
            if ($data['ip'] === $ip) {
                $ipCount++;
            }
        }
        
        if ($globalCount >= self::MAX_ATTEMPTS_PER_HOUR) {
            error_log('Passkey: Rate limit exceeded (global)');
            return false;
        }
        
        if ($ipCount >= self::MAX_ATTEMPTS_PER_IP) {
            error_log('Passkey: Rate limit exceeded for IP: ' . $ip);
            return false;
        }
        
        // 记录本次请求
        $rateLimit[] = array(
            'time' => $now,
            'ip' => $ip
        );
        
        return true;
    }
    
    /**
     * 验证 session 超时
     */
    private function checkSessionTimeout($sessionKey) {
        if (!isset($_SESSION[$sessionKey . '_time'])) {
            return false;
        }
        
        $elapsed = time() - $_SESSION[$sessionKey . '_time'];
        if ($elapsed > self::SESSION_TIMEOUT) {
            unset($_SESSION[$sessionKey]);
            unset($_SESSION[$sessionKey . '_time']);
            return false;
        }
        
        return true;
    }
    
    /**
     * 设置 session 时间戳
     */
    private function setSessionTimestamp($sessionKey) {
        $_SESSION[$sessionKey . '_time'] = time();
    }
    
    /**
     * 转换 base64url 为标准 base64
     */
    private function base64url_to_base64($base64url) {
        $base64 = strtr($base64url, '-_', '+/');
        // 添加填充
        $padding = strlen($base64) % 4;
        if ($padding) {
            $base64 .= str_repeat('=', 4 - $padding);
        }
        return $base64;
    }
    
    /**
     * 验证凭证 ID 格式
     */
    private function validateCredentialId($credentialId) {
        if (!is_string($credentialId)) {
            return false;
        }
        
        if (strlen($credentialId) === 0 || strlen($credentialId) > self::MAX_CREDENTIAL_ID_LENGTH) {
            return false;
        }
        
        // Base64 编码检查
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $credentialId)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 生成随机 challenge
     */
    private function generateChallenge()
    {
        $bytes = random_bytes(32);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
    
    /**
     * 返回成功响应
     */
    private function success($data)
    {
        echo json_encode(array(
            'success' => true,
            'data' => $data
        ));
        exit;
    }
    
    /**
     * 返回错误响应
     */
    private function error($message)
    {
        echo json_encode(array(
            'success' => false,
            'error' => $message
        ));
        exit;
    }
}