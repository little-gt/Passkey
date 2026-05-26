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

require __DIR__ . '/WebAuthn.php';

/**
 * Passkey Action 处理类 - 安全加固版
 */
class Action extends Widget implements ActionInterface
{
    private $db;
    private $prefix;
    
    // 默认安全常量（当配置不可用时使用）
    const DEFAULT_MAX_ATTEMPTS_PER_HOUR = 20;       // 每小时每个用户的最大尝试次数
    const DEFAULT_MAX_ATTEMPTS_PER_IP = 10;         // 每小时每个 IP 的最大尝试次数
    const DEFAULT_SESSION_TIMEOUT = 300;            // 会话超时时间（秒）
    const DEFAULT_MAX_CREDENTIAL_ID_LENGTH = 512;   // 凭证 ID 最大长度（Base64 编码后的长度，实际二进制长度更短）
    
    // 错误代码常量
    const ERR_VALIDATION = 'ERR_VALIDATION';           // 输入验证错误
    const ERR_AUTH_FAILED = 'ERR_AUTH_FAILED';         // 认证失败
    const ERR_ORIGIN_MISMATCH = 'ERR_ORIGIN_MISMATCH'; // Origin 不匹配
    const ERR_CREDENTIAL_LENGTH = 'ERR_CREDENTIAL_LENGTH'; // 凭证长度超限
    const ERR_DUPLICATE = 'ERR_DUPLICATE';             // 重复凭证
    const ERR_RATE_LIMIT = 'ERR_RATE_LIMIT';          // 速率限制
    const ERR_SESSION = 'ERR_SESSION';                 // 会话错误
    const ERR_NETWORK = 'ERR_NETWORK';                 // 网络错误
    const ERR_UNKNOWN = 'ERR_UNKNOWN';                 // 未知错误
    
    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        $this->db = \Typecho\Db::get();
        $this->prefix = $this->db->getPrefix();
    }
    
    /**
     * 获取安全配置参数
     */
    private function getSecurityConfig($key)
    {
        try {
            $options = Options::alloc();
            $plugin = $options->plugin('Passkey');
            
            if ($plugin && isset($plugin->$key)) {
                return (int)$plugin->$key;
            }
        } catch (\Exception $e) {
            // 配置不可用，使用默认值
        }
        
        // 返回默认值
        $defaults = array(
            'maxAttemptsPerHour' => self::DEFAULT_MAX_ATTEMPTS_PER_HOUR,
            'maxAttemptsPerIp' => self::DEFAULT_MAX_ATTEMPTS_PER_IP,
            'sessionTimeout' => self::DEFAULT_SESSION_TIMEOUT,
            'maxCredentialIdLength' => self::DEFAULT_MAX_CREDENTIAL_ID_LENGTH
        );
        
        return isset($defaults[$key]) ? $defaults[$key] : 0;
    }
    
    /**
     * 执行函数
     */
    public function action()
    {
        try {
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
                error_log('[Passkey][WARNING] Invalid action requested: ' . $action . ' - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('无效的操作', self::ERR_VALIDATION);
                return;
            }
            
            // 速率限制检查（针对敏感操作）
            $sensitiveActions = array('register-verify', 'login-verify', 'register-options', 'login-options');
            if (in_array($action, $sensitiveActions, true)) {
                if (!$this->checkRateLimit()) {
                    error_log('[Passkey][WARNING] Rate limit exceeded - IP: ' . $this->validateIpAddress($this->request->getIp()) . 
                             ' - ErrCode: ' . self::ERR_RATE_LIMIT);
                    $this->error('请求过于频繁，请稍后再试', self::ERR_RATE_LIMIT);
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
                    error_log('[Passkey][ERROR] Unhandled action in switch: ' . $action);
                    $this->error('未知的操作', self::ERR_UNKNOWN);
            }
        } catch (\Exception $e) {
            // 捕获所有未处理的异常，使用统一的错误处理
            $errorCode = $this->getErrorCode($e->getMessage());
            error_log('[Passkey][ERROR] Action exception: ' . $e->getMessage() . 
                     ' - ErrCode: ' . $errorCode);
            error_log('[Passkey][ERROR] Exception trace: ' . $e->getTraceAsString());
            
            // 返回通用错误信息，避免泄露系统细节
            $this->response->throwJson(array(
                'success' => false,
                'error' => '操作失败，请重试',
                'errorCode' => defined('__TYPECHO_DEBUG__') && __TYPECHO_DEBUG__ ? $errorCode : null
            ));
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
                error_log('[Passkey][INFO] Registration blocked - Global registration disabled');
                $this->error('注册功能已关闭，请先登录', self::ERR_VALIDATION);
                return;
            }
            
            if (!$pluginAllowRegister) {
                error_log('[Passkey][INFO] Registration blocked - Plugin registration disabled');
                $this->error('Passkey 注册功能未启用', self::ERR_VALIDATION);
                return;
            }
            
            // 获取POST数据（用户提供的注册信息）
            $postData = json_decode(file_get_contents('php://input'), true);
            
            // 验证 JSON 解析是否成功
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('[Passkey][ERROR] JSON parse error: ' . json_last_error_msg() . ' - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('请求数据格式错误', self::ERR_VALIDATION);
                return;
            }
            
            // 验证是否是数组
            if (!is_array($postData)) {
                error_log('[Passkey][ERROR] Post data is not array - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('请求数据格式错误', self::ERR_VALIDATION);
                return;
            }
            
            $userName = isset($postData['username']) ? trim($postData['username']) : '';
            $userEmail = isset($postData['email']) ? trim($postData['email']) : '';
            $displayName = isset($postData['screenName']) ? trim($postData['screenName']) : '';
            
            // 基本验证
            if (empty($userName) || empty($userEmail)) {
                error_log('[Passkey][ERROR] Username or email empty - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('用户名和邮箱不能为空', self::ERR_VALIDATION);
                return;
            }
            
            // 验证用户名长度（与前端保持一致）
            if (strlen($userName) < 3 || strlen($userName) > 32) {
                error_log('[Passkey][ERROR] Invalid username length - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('用户名长度必须在 3-32 个字符之间', self::ERR_VALIDATION);
                return;
            }
            
            // 验证用户名格式（只允许字母数字下划线，且必须以字母开头）
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,31}$/', $userName)) {
                error_log('[Passkey][ERROR] Invalid username format - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('用户名格式不正确', self::ERR_VALIDATION);
                return;
            }
            
            // 验证邮箱格式（使用更严格的验证）
            if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                error_log('[Passkey][ERROR] Invalid email format - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('邮箱格式不正确', self::ERR_VALIDATION);
                return;
            }
            
            // 验证邮箱长度和格式
            if (strlen($userEmail) > 200 || strlen($userEmail) < 5) {
                error_log('[Passkey][ERROR] Invalid email length - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('邮箱长度不合理', self::ERR_VALIDATION);
                return;
            }
            
            // 验证邮箱域名部分
            $emailParts = explode('@', $userEmail);
            if (count($emailParts) !== 2 || empty($emailParts[0]) || empty($emailParts[1])) {
                error_log('[Passkey][ERROR] Invalid email domain - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('邮箱格式不正确', self::ERR_VALIDATION);
                return;
            }
            
            // 验证昵称长度
            if (strlen($displayName) > 100) {
                error_log('[Passkey][ERROR] Display name too long - ErrCode: ' . self::ERR_VALIDATION);
                $this->error('昵称过长', self::ERR_VALIDATION);
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
                
                // 再次验证长度
                if (strlen($displayName) > 100) {
                    error_log('[Passkey][ERROR] Display name too long after sanitization - ErrCode: ' . self::ERR_VALIDATION);
                    $this->error('昵称过长', self::ERR_VALIDATION);
                    return;
                }
            }
            
            // 检查用户名或邮箱是否已存在（使用时序安全的方式）
            // 分开查询以避免信息泄露
            $existingUser = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('name = ?', $userName)
                ->limit(1));
            
            $existingEmail = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('mail = ?', $userEmail)
                ->limit(1));
            
            // 使用统一错误信息，防止用户名枚举政击
            if ($existingUser || $existingEmail) {
                // 记录尝试使用已占用的用户名/邮箱的行为
                error_log('[Passkey][WARNING] Registration attempt with existing username or email - ' .
                         'IP: ' . $this->request->getIp() . 
                         ', Username: ' . $userName . 
                         ', Email: ' . substr($userEmail, 0, 3) . '***');
                
                // 使用统一错误信息，避免用户名枚举攻击
                $this->error('该用户名或邮箱不可用', self::ERR_DUPLICATE);
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
        $rpId = $this->getSafeRpId();
        
        // 验证 rpId 格式
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $rpId)) {
            error_log('[Passkey][ERROR] Invalid RP ID format: ' . $rpId . ' - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('RP ID 格式错误', self::ERR_VALIDATION);
            return;
        }
        
        // 生成 challenge
        $challenge = $this->generateChallenge();
        
        // 保存 challenge 和用户信息到 session
        $this->startSession();
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
        $this->startSession();
        
        if (!isset($_SESSION['passkey_register_challenge'])) {
            error_log('[Passkey][ERROR] Registration session not found - ErrCode: ' . self::ERR_SESSION);
            $this->error('会话已过期，请重新开始注册', self::ERR_SESSION);
            return;
        }
        
        // 检查 session 超时
        if (!$this->checkSessionTimeout('passkey_register_challenge')) {
            error_log('[Passkey][WARNING] Registration session timeout - ErrCode: ' . self::ERR_SESSION);
            $this->error('会话已超时，请重新开始注册', self::ERR_SESSION);
            return;
        }
        
        $challenge = $_SESSION['passkey_register_challenge'];
        $isNewUser = isset($_SESSION['passkey_register_is_new_user']) && $_SESSION['passkey_register_is_new_user'];
        
        // 如果不是新用户，检查登录状态
        if (!$isNewUser) {
            $user = \Widget\User::alloc();
            if (!$user->hasLogin()) {
                error_log('[Passkey][ERROR] User not logged in - ErrCode: ' . self::ERR_SESSION);
                $this->error('请先登录', self::ERR_SESSION);
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
            error_log('[Passkey][ERROR] JSON parse error in registerVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('请求数据格式错误', self::ERR_VALIDATION);
            return;
        }
        
        if (!$data || !isset($data['id']) || !isset($data['rawId']) || !isset($data['response'])) {
            error_log('[Passkey][ERROR] Missing required fields in registerVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('请求数据不完整', self::ERR_VALIDATION);
            return;
        }
        
        // 验证数据类型
        if (!is_string($data['id']) || !is_string($data['rawId']) || !is_array($data['response'])) {
            error_log('[Passkey][ERROR] Invalid data types in registerVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('请求数据类型错误', self::ERR_VALIDATION);
            return;
        }
        
        // 验证 rawId 长度（防止缓冲区溢出）
        if (strlen($data['rawId']) > 2048) {
            error_log('[Passkey][ERROR] RawId too long - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('凭证 ID 过长', self::ERR_VALIDATION);
            return;
        }
        
        // 验证 response 结构完整性
        if (!isset($data['response']['clientDataJSON']) || !isset($data['response']['attestationObject'])) {
            error_log('[Passkey][ERROR] Missing response fields - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('响应数据不完整', self::ERR_VALIDATION);
            return;
        }
        
        // 验证 clientDataJSON 和 attestationObject 的类型和长度
        if (!is_string($data['response']['clientDataJSON']) || strlen($data['response']['clientDataJSON']) === 0) {
            error_log('[Passkey][ERROR] Invalid clientDataJSON - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('clientDataJSON 格式错误', self::ERR_VALIDATION);
            return;
        }
        
        if (!is_string($data['response']['attestationObject']) || strlen($data['response']['attestationObject']) === 0) {
            error_log('[Passkey][ERROR] Invalid attestationObject - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('attestationObject 格式错误', self::ERR_VALIDATION);
            return;
        }
        
        // 获取配置
        $options = Options::alloc();
        $plugin = $options->plugin('Passkey');
        $rpId = $this->getSafeRpId();
        
        // 验证 RP ID 格式（严格的域名格式检查）
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $rpId)) {
            error_log('[Passkey][ERROR] Invalid RP ID format in registerVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('RP ID 格式错误', self::ERR_VALIDATION);
            return;
        }
        
        // 验证 rpId 不是IP地址（WebAuthn规范要求使用域名）
        if (filter_var($rpId, FILTER_VALIDATE_IP)) {
            error_log('[Passkey][ERROR] RP ID is IP address - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('RP ID 必须是域名', self::ERR_VALIDATION);
            return;
        }
        
        // 构造 origin（从站点配置安全获取）
        $origin = $this->getSafeOrigin();
        
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
            
            // 验证凭证 ID 格式
            if (!$this->validateCredentialId($credentialId)) {
                throw new \Exception('Invalid credential ID format');
            }
            
            // 额外的安全检查：验证返回的数据
            if (!isset($verificationResult['userVerified'])) {
                throw new \Exception('Missing userVerified flag');
            }
            
            // 验证 counter 值的合理性
            if (!is_int($counter) || $counter < 0) {
                throw new \Exception('Invalid counter value');
            }
            
            // 检查凭证ID长度（MySQL VARCHAR(512) 限制）
            $credentialIdDecoded = base64_decode($credentialId, true);
            if ($credentialIdDecoded === false) {
                error_log('[Passkey][ERROR] Invalid credential ID encoding - ErrCode: ' . self::ERR_VALIDATION);
                throw new \Exception('Invalid credential ID encoding');
            }
            
            // Base64 编码后的长度检查（预防 MySQL 截断）
            $maxCredentialIdLength = $this->getSecurityConfig('maxCredentialIdLength');
            if (strlen($credentialId) > $maxCredentialIdLength) {
                error_log('[Passkey][ERROR] Credential ID exceeds maximum length (' . strlen($credentialId) . ' > ' . 
                         $maxCredentialIdLength . ') - ErrCode: ' . self::ERR_CREDENTIAL_LENGTH);
                throw new \Exception('Credential ID too long');
            }
            
            // 检查凭证ID是否已被使用（防止凭证重用攻击）
            $existingCred = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'passkey_credentials')
                ->where('credential_id = ?', $credentialId)
                ->limit(1));
            
            if ($existingCred) {
                error_log('[Passkey][ERROR] Credential ID already exists - ErrCode: ' . self::ERR_DUPLICATE);
                throw new \Exception('Credential ID already exists');
            }
            
        } catch (\Exception $e) {
            // 记录详细的错误信息用于调试
            error_log('[Passkey][ERROR] Registration verification failed: ' . $e->getMessage());
            error_log('[Passkey][ERROR] Registration trace: ' . $e->getTraceAsString());
            
            // 返回通用错误信息，避免泄露敏感配置
            $errorCode = $this->getErrorCode($e->getMessage());
            $this->error('验证失败，请重试', $errorCode);
            return;
        }
        
        // 如果是新用户，先创建账户
        if ($isNewUser) {
            // 从 session 读取用户信息
            $username = isset($_SESSION['passkey_register_username']) ? $_SESSION['passkey_register_username'] : '';
            $email = isset($_SESSION['passkey_register_email']) ? $_SESSION['passkey_register_email'] : '';
            $screenName = isset($_SESSION['passkey_register_screenname']) ? $_SESSION['passkey_register_screenname'] : '';
            
            if (empty($username) || empty($email)) {
                error_log('[Passkey][ERROR] Registration info lost - ErrCode: ' . self::ERR_SESSION);
                $this->error('注册信息丢失，请重试', self::ERR_SESSION);
                return;
            }
            
            $password = md5(uniqid(mt_rand(), true)); // 随机密码
            
            // 使用数据库事务确保原子性
            try {
                // 开始事务（如果数据库支持）
                if (method_exists($this->db, 'beginTransaction')) {
                    $this->db->beginTransaction();
                }
                
                // 再次检查用户名和邮箱是否被占用（防止竞态条件）
                $checkUser = $this->db->fetchRow($this->db->select()
                    ->from($this->prefix . 'users')
                    ->where('name = ? OR mail = ?', $username, $email)
                    ->limit(1));
                
                if ($checkUser) {
                    if (method_exists($this->db, 'rollback')) {
                        $this->db->rollback();
                    }
                    error_log('[Passkey][ERROR] Username or email already taken (race condition) - ErrCode: ' . self::ERR_DUPLICATE);
                    $this->error('用户名或邮箱已被使用', self::ERR_DUPLICATE);
                    return;
                }
                
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
                    if (method_exists($this->db, 'rollback')) {
                        $this->db->rollback();
                    }
                    error_log('[Passkey][ERROR] Failed to insert user - ErrCode: ' . self::ERR_UNKNOWN);
                    $this->error('创建用户失败', self::ERR_UNKNOWN);
                    return;
                }
                
                // 提交事务
                if (method_exists($this->db, 'commit')) {
                    $this->db->commit();
                }
                
            } catch (\Exception $e) {
                if (method_exists($this->db, 'rollback')) {
                    $this->db->rollback();
                }
                error_log('[Passkey][ERROR] Failed to create user: ' . $e->getMessage() . ' - ErrCode: ' . self::ERR_UNKNOWN);
                $this->error('创建用户失败', self::ERR_UNKNOWN);
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
                // 重新生成 session ID 防止会话固定攻击
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                
                // 使用 Typecho 标准的 simpleLogin 方法
                $userWidget = \Widget\User::alloc();
                $expire = 30 * 24 * 3600;
                
                if ($userWidget->simpleLogin($userId, false, $expire)) {
                    // 登录成功后再次重新生成 session ID
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                    }
                    
                    // 记录注册事件
                    error_log('[Passkey][INFO] New user registered successfully - User ID: ' . $userId . ', IP: ' . $this->request->getIp());
                    
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
            $errorCode = self::ERR_UNKNOWN;
            
            if (strpos($errorMessage, 'Duplicate') !== false || 
                strpos($errorMessage, 'unique') !== false ||
                strpos($errorMessage, 'UNIQUE') !== false) {
                error_log('[Passkey][ERROR] Duplicate credential detected - ErrCode: ' . self::ERR_DUPLICATE);
                $this->error('此凭证已经注册过', self::ERR_DUPLICATE);
            } else if (strpos($errorMessage, 'too long') !== false || 
                       strpos($errorMessage, 'Data too long') !== false) {
                // MySQL 数据过长错误（可能是 credential_id 超长）
                error_log('[Passkey][ERROR] Data too long for database - ErrCode: ' . self::ERR_CREDENTIAL_LENGTH);
                $this->error('凭证数据过长，注册失败', self::ERR_CREDENTIAL_LENGTH);
            } else {
                error_log('[Passkey][ERROR] Failed to save credential: ' . $errorMessage . ' - ErrCode: ' . self::ERR_UNKNOWN);
                $this->error('保存凭证失败，请重试', self::ERR_UNKNOWN);
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
        
        $rpId = $this->getSafeRpId();
        
        // 验证 RP ID
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $rpId)) {
            error_log('[Passkey][ERROR] Invalid RP ID in loginOptions - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('RP ID 格式错误', self::ERR_VALIDATION);
            return;
        }
        
        // 生成 challenge
        $challenge = $this->generateChallenge();
        
        // 保存 challenge 到 session
        $this->startSession();
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
        $this->startSession();
        
        if (!isset($_SESSION['passkey_login_challenge'])) {
            error_log('[Passkey][ERROR] Login session not found - ErrCode: ' . self::ERR_SESSION);
            $this->error('会话已过期，请重试', self::ERR_SESSION);
            return;
        }
        
        // 检查 session 超时
        if (!$this->checkSessionTimeout('passkey_login_challenge')) {
            error_log('[Passkey][WARNING] Login session timeout - ErrCode: ' . self::ERR_SESSION);
            $this->error('会话已过期，请重新登录', self::ERR_SESSION);
            return;
        }
        
        $challenge = $_SESSION['passkey_login_challenge'];
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // 验证 JSON 解析是否成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Passkey][ERROR] JSON parse error in loginVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('请求数据格式错误', self::ERR_VALIDATION);
            return;
        }
        
        if (!$data || !isset($data['id']) || !isset($data['rawId']) || !isset($data['response'])) {
            error_log('[Passkey][ERROR] Missing required fields in loginVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('请求数据不完整', self::ERR_VALIDATION);
            return;
        }
        
        // 验证数据类型
        if (!is_string($data['id']) || !is_string($data['rawId']) || !is_array($data['response'])) {
            error_log('[Passkey][ERROR] Invalid data types in loginVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('请求数据类型错误', self::ERR_VALIDATION);
            return;
        }

        // 验证 rawId 长度（防止缓冲区溢出）
        if (strlen($data['rawId']) > 2048) {
            error_log('[Passkey][ERROR] RawId too long in loginVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('凭证 ID 过长', self::ERR_VALIDATION);
            return;
        }
        
        // 验证 response 结构完整性
        if (!isset($data['response']['clientDataJSON']) || 
            !isset($data['response']['authenticatorData']) || 
            !isset($data['response']['signature'])) {
            error_log('[Passkey][ERROR] Missing response fields in loginVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('响应数据不完整', self::ERR_VALIDATION);
            return;
        }
        
        // 验证响应字段类型
        if (!is_string($data['response']['clientDataJSON']) || 
            !is_string($data['response']['authenticatorData']) || 
            !is_string($data['response']['signature'])) {
            error_log('[Passkey][ERROR] Invalid response field types - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('响应数据类型错误', self::ERR_VALIDATION);
            return;
        }
        
        // rawId 是 base64url 编码，需要转换为标准 base64
        $credentialId = $this->base64url_to_base64($data['rawId']);
        
        // 验证凭证 ID
        if (!$this->validateCredentialId($credentialId)) {
            error_log('[Passkey][ERROR] Invalid credential ID format in loginVerify - ErrCode: ' . self::ERR_VALIDATION);
            $this->error('凭证 ID 格式错误', self::ERR_VALIDATION);
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
                
                $this->error('凭证不存在。请先登录后台在"Passkey 管理"中添加凭证。', self::ERR_AUTH_FAILED);
                return;
            }
            
            // 获取配置
            $options = Options::alloc();
            $plugin = $options->plugin('Passkey');
            $rpId = $this->getSafeRpId();
            
            // 验证 rpId 格式
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $rpId)) {
                throw new \Exception('Invalid RP ID format');
            }
            
            // 验证 rpId 不是IP地址
            if (filter_var($rpId, FILTER_VALIDATE_IP)) {
                throw new \Exception('RP ID cannot be an IP address');
            }
            
            // 构造 origin（从站点配置安全获取）
            $origin = $this->getSafeOrigin();
            
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
                
                // 额外验证：检查返回的标志位
                if (!isset($verificationResult['userPresent']) || !$verificationResult['userPresent']) {
                    throw new \Exception('User presence not confirmed');
                }
                
                // 验证 counter 值的合理性
                if (!is_int($newCounter) || $newCounter < 0) {
                    throw new \Exception('Invalid counter value');
                }
                
            } catch (\Exception $e) {
                // 记录失败的登录尝试（详细日志）
                $errorCode = $this->getErrorCode($e->getMessage());
                error_log('[Passkey][ERROR] Login verification failed for user ' . $credential['user_id'] . 
                         ': ' . $e->getMessage() . 
                         ' | ErrCode: ' . $errorCode . 
                         ' | IP: ' . $this->validateIpAddress($this->request->getIp()) . 
                         ' | UA: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'));
                
                $this->logLoginActivity($credential['user_id'], $credential['id'], $challenge, 'failed');
                // 返回通用错误信息
                $this->error('身份验证失败，请重试', $errorCode);
                return;
            }
            
            // 获取用户信息
            $user = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('uid = ?', $credential['user_id']));
            
            if (!$user) {
                error_log('[Passkey][ERROR] User not found for credential - ErrCode: ' . self::ERR_AUTH_FAILED);
                $this->error('用户不存在', self::ERR_AUTH_FAILED);
                return;
            }
            
            // 使用 Typecho 标准的 simpleLogin 方法
            $userWidget = \Widget\User::alloc();
            $expire = 30 * 24 * 3600;
            
            if (!$userWidget->simpleLogin($user['uid'], false, $expire)) {
                error_log('[Passkey][ERROR] Failed to set login state for user: ' . $user['uid'] . ' - ErrCode: ' . self::ERR_UNKNOWN);
                $this->error('登录失败，请重试', self::ERR_UNKNOWN);
                return;
            }
            
            // 登录成功后重新生成 session ID 防止会话固定攻击
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            
            // 更新凭证计数器和最后使用时间
            // WHERE 条件包含 counter 检查，提供原子性保护防止并发更新导致的 counter 回退
            try {
                $this->db->query(
                    $this->db->update($this->prefix . 'passkey_credentials')
                        ->rows(array(
                            'counter' => $newCounter,
                            'last_used' => time()
                        ))
                        ->where('id = ? AND counter <= ?', $credential['id'], $newCounter)
                );
            } catch (\Exception $e) {
                error_log('[Passkey][ERROR] Failed to update credential counter: ' . $e->getMessage());
                // 更新失败不影响登录流程，但记录错误
            }
            
            // 记录登录日志
            $this->logLoginActivity($credential['user_id'], $credential['id'], $challenge);
            
            // 清除登录挑战（防止重放攻击）
            unset($_SESSION['passkey_login_challenge']);
            unset($_SESSION['passkey_login_challenge_time']);
            
            // 记录成功登录事件
            error_log('[Passkey][INFO] User logged in successfully - User ID: ' . $user['uid'] . 
                     ', IP: ' . $this->request->getIp() . 
                     ', Credential ID: ' . substr($credentialId, 0, 20) . '...');
            
            $this->success(array(
                'message' => '登录成功',
                'redirect' => Options::alloc()->adminUrl,
                'user' => array(
                    'name' => $user['name'],
                    'screenName' => $user['screenName']
                )
            ));
        } catch (\Exception $e) {
            error_log('[Passkey][ERROR] Login error: ' . $e->getMessage() . ' - ErrCode: ' . self::ERR_UNKNOWN);
            $this->error('登录失败，请重试', self::ERR_UNKNOWN);
        }
    }
    
    /**
     * 记录登录活动
     */
    private function logLoginActivity($userId, $credentialId, $challenge, $status = 'success')
    {
        try {
            // 验证并清理 IP 地址
            $ipAddress = $this->validateIpAddress($this->request->getIp());
            
            $this->db->query($this->db->insert($this->prefix . 'passkey_login_logs')
                ->rows(array(
                    'user_id' => $userId,
                    'credential_id' => $credentialId,
                    'challenge' => $challenge,
                    'ip_address' => $ipAddress,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'login_time' => time(),
                    'status' => $status
                )));
        } catch (\Exception $e) {
            // 记录失败不影响登录流程
            error_log('[Passkey][ERROR] Failed to log login activity: ' . $e->getMessage());
        }
    }
    
    /**
     * 验证并清理 IP 地址
     * 
     * @param string $ip 待验证的 IP 地址
     * @return string 验证后的 IP 地址
     */
    private function validateIpAddress($ip)
    {
        if (empty($ip) || !is_string($ip)) {
            return '0.0.0.0';
        }
        
        // 移除可能的端口号
        $ip = preg_replace('/:[0-9]+$/', '', $ip);
        
        // IPv6 地址可能被方括号包围
        $ip = trim($ip, '[]');
        
        // 验证 IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        
        // 验证 IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }
        
        // 无效 IP，记录警告并返回安全值
        error_log('[Passkey][WARNING] Invalid IP address detected: ' . substr($ip, 0, 50));
        return '0.0.0.0';
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
            error_log('[Passkey][ERROR] Get credentials list error: ' . $e->getMessage() . ' - ErrCode: ' . self::ERR_UNKNOWN);
            $this->error('获取凭证列表失败，请重试', self::ERR_UNKNOWN);
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
            error_log('[Passkey][ERROR] Delete credential error: ' . $e->getMessage() . ' - ErrCode: ' . self::ERR_UNKNOWN);
            $this->error('删除凭证失败，请重试', self::ERR_UNKNOWN);
        }
    }
    
    /**
     * 获取登录日志
     */
    private function getLoginLogs()
    {
        error_log('[Passkey][DEBUG] getLoginLogs() called');
        
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            error_log('[Passkey][DEBUG] User not logged in');
            $this->error('请先登录');
        }
        
        error_log('[Passkey][DEBUG] User ID: ' . $user->uid);
        
        $limit = (int)$this->request->get('limit', 10);
        if ($limit > 100) $limit = 100;
        if ($limit < 1) $limit = 10;
        
        try {
            // 检查表是否存在
            $tableExists = $this->db->fetchRow(
                $this->db->query("SHOW TABLES LIKE '{$this->prefix}passkey_login_logs'")
            );
            
            if (!$tableExists) {
                error_log('[Passkey][DEBUG] login_logs table does not exist');
                $this->success(array()); // 返回空数组而不是错误
                return;
            }
            
            error_log('[Passkey][DEBUG] Fetching logs for user ' . $user->uid);
            
            $logs = $this->db->fetchAll(
                $this->db->select('l.*, c.credential_id')
                    ->from($this->prefix . 'passkey_login_logs AS l')
                    ->join($this->prefix . 'passkey_credentials AS c', 'l.credential_id = c.id', \Typecho\Db::LEFT_JOIN)
                    ->where('l.user_id = ?', $user->uid)
                    ->order('l.login_time', \Typecho\Db::SORT_DESC)
                    ->limit($limit)
            );
            
            error_log('[Passkey][DEBUG] Found ' . count($logs) . ' logs');
            
            $result = array();
            foreach ($logs as $index => $log) {
                error_log('[Passkey][DEBUG] Processing log #' . $index . ': ' . json_encode($log));
                
                try {
                    // 直接使用 base64 编码的凭证 ID，不解码（避免二进制数据导致 UTF-8 错误）
                    $credentialId = 'N/A';
                    if (isset($log['credential_id']) && !empty($log['credential_id'])) {
                        // 截取前20个字符的 base64 字符串
                        $credentialId = substr($log['credential_id'], 0, 20) . '...';
                    }
                    
                    $item = array(
                        'id' => $log['id'],
                        'credential_id' => $credentialId,
                        'ip_address' => $log['ip_address'],
                        'user_agent' => $this->parseUserAgent($log['user_agent']),
                        'login_time' => date('Y-m-d H:i:s', $log['login_time']),
                        'status' => $log['status']
                    );
                    
                    error_log('[Passkey][DEBUG] Processed item: ' . json_encode($item));
                    $result[] = $item;
                } catch (\Exception $e) {
                    error_log('[Passkey][DEBUG] Error processing log #' . $index . ': ' . $e->getMessage());
                }
            }
            
            error_log('[Passkey][DEBUG] Result array count: ' . count($result));
            error_log('[Passkey][DEBUG] Result array JSON: ' . json_encode($result));
            error_log('[Passkey][DEBUG] Returning success with ' . count($result) . ' results');
            $this->success($result);
        } catch (\Exception $e) {
            error_log('[Passkey][ERROR] getLoginLogs exception: ' . $e->getMessage() . ' - ErrCode: ' . self::ERR_UNKNOWN);
            $this->error('获取登录日志失败，请重试', self::ERR_UNKNOWN);
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
        $this->startSession();
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
        
        $maxAttemptsPerHour = $this->getSecurityConfig('maxAttemptsPerHour');
        if ($globalCount >= $maxAttemptsPerHour) {
            error_log('[Passkey][WARNING] Rate limit exceeded (global)');
            return false;
        }
        
        $maxAttemptsPerIp = $this->getSecurityConfig('maxAttemptsPerIp');
        if ($ipCount >= $maxAttemptsPerIp) {
            error_log('[Passkey][WARNING] Rate limit exceeded for IP: ' . $ip);
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
        $sessionTimeout = $this->getSecurityConfig('sessionTimeout');
        if ($elapsed > $sessionTimeout) {
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
     * 
     * @param string $credentialId Base64 编码的凭证 ID
     * @return bool 是否有效
     */
    private function validateCredentialId($credentialId) {
        // 类型检查
        if (!is_string($credentialId)) {
            return false;
        }
        
        // 长度检查
        $maxCredentialIdLength = $this->getSecurityConfig('maxCredentialIdLength');
        if (strlen($credentialId) === 0 || strlen($credentialId) > $maxCredentialIdLength) {
            return false;
        }
        
        // Base64 编码检查（允许标准 base64 和 base64url）
        if (!preg_match('/^[A-Za-z0-9+\/=_-]+$/', $credentialId)) {
            return false;
        }
        
        // 尝试解码以验证有效性
        $decoded = base64_decode($credentialId, true);
        if ($decoded === false) {
            return false;
        }
        
        // 验证解码后的长度合理性
        $decodedLength = strlen($decoded);
        if ($decodedLength < 16 || $decodedLength > 1024) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 安全地启动 session（如果尚未启动）
     */
    private function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * 生成随机 challenge
     * 使用安全的随机数生成器
     * 
     * @return string Base64URL 编码的 challenge
     * @throws \Exception 当随机数生成失败时
     */
    private function generateChallenge()
    {
        try {
            // 生成32字节（256位）的强随机数
            $bytes = random_bytes(32);
            
            // 转换为 base64url 编码
            return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        } catch (\Exception $e) {
            // 如果 random_bytes 失败，记录错误并抛出异常
            error_log('[Passkey][ERROR] Failed to generate challenge: ' . $e->getMessage());
            throw new \Exception('Failed to generate secure challenge');
        }
    }
    
    /**
     * 返回成功响应（使用 Typecho 标准方法）
     */
    private function success($data)
    {
        $this->response->throwJson(array(
            'success' => true,
            'data' => $data
        ));
    }
    
    /**
     * 从站点URL安全地获取 RP ID（域名）
     * 
     * @return string 域名（不含协议和路径）
     */
    private function getSafeRpId()
    {
        $options = Options::alloc();
        $plugin = $options->plugin('Passkey');
        
        // 优先使用配置的 rpId
        if (!empty($plugin->rpId)) {
            return $plugin->rpId;
        }
        
        // 从站点 URL 中提取域名
        $host = parse_url($options->siteUrl, PHP_URL_HOST);
        if ($host) {
            return $host;
        }
        
        // 降级方案：使用 localhost（仅开发环境）
        error_log('[Passkey][WARNING] Unable to extract host from siteUrl, using localhost');
        return 'localhost';
    }
    
    /**
     * 从站点URL安全地获取 Origin
     * 
     * @return string 完整的 origin（包含协议和域名）
     */
    private function getSafeOrigin()
    {
        $options = Options::alloc();
        $siteUrl = rtrim($options->siteUrl, '/');
        
        // 确保 URL 包含协议
        if (!preg_match('/^https?:\/\//i', $siteUrl)) {
            error_log('[Passkey][WARNING] siteUrl missing protocol, adding https://');
            $siteUrl = 'https://' . $siteUrl;
        }
        
        // 提取协议和主机（不含路径）
        $parsed = parse_url($siteUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'localhost';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        
        return $scheme . '://' . $host . $port;
    }
    
    /**
     * 返回错误响应（使用 Typecho 标准方法）
     * 
     * @param string $message 用户友好的错误信息
     * @param string $errorCode 错误代码（可选，用于日志追踪）
     */
    private function error($message, $errorCode = null)
    {
        $response = array(
            'success' => false,
            'error' => $message
        );
        
        // 在开发环境下可以返回错误代码
        if ($errorCode && defined('__TYPECHO_DEBUG__') && __TYPECHO_DEBUG__) {
            $response['errorCode'] = $errorCode;
        }
        
        $this->response->throwJson($response);
    }
    
    /**
     * 根据错误信息获取错误代码
     * 
     * @param string $errorMessage 错误信息
     * @return string 错误代码
     */
    private function getErrorCode($errorMessage)
    {
        $message = strtolower($errorMessage);
        
        if (strpos($message, 'origin') !== false) {
            return self::ERR_ORIGIN_MISMATCH;
        }
        if (strpos($message, 'credential') !== false && strpos($message, 'length') !== false) {
            return self::ERR_CREDENTIAL_LENGTH;
        }
        if (strpos($message, 'duplicate') !== false || strpos($message, 'exists') !== false) {
            return self::ERR_DUPLICATE;
        }
        if (strpos($message, 'challenge') !== false) {
            return self::ERR_AUTH_FAILED;
        }
        if (strpos($message, 'signature') !== false) {
            return self::ERR_AUTH_FAILED;
        }
        if (strpos($message, 'counter') !== false) {
            return self::ERR_AUTH_FAILED;
        }
        
        return self::ERR_UNKNOWN;
    }
}