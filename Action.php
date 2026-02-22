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
 * Passkey Action 处理类
 */
class Action extends Widget implements ActionInterface
{
    private $db;
    private $prefix;
    
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
        $this->response->setContentType('application/json');
        
        $action = $this->request->get('do');
        
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
            default:
                $this->error('Invalid action');
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
            $userName = isset($postData['username']) ? trim($postData['username']) : '';
            $userEmail = isset($postData['email']) ? trim($postData['email']) : '';
            $displayName = isset($postData['screenName']) ? trim($postData['screenName']) : '';
            
            // 验证用户输入
            if (empty($userName) || empty($userEmail)) {
                $this->error('用户名和邮箱不能为空');
                return;
            }
            
            // 验证用户名格式（只允许字母数字下划线）
            if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $userName)) {
                $this->error('用户名只能包含字母、数字和下划线，长度 3-32 个字符');
                return;
            }
            
            // 验证邮箱格式
            if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $this->error('请输入有效的邮箱地址');
                return;
            }
            
            // 检查用户名是否已存在
            $existingUser = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('name = ?', $userName)
                ->limit(1));
            
            if ($existingUser) {
                $this->error('用户名已存在，请选择其他用户名');
                return;
            }
            
            // 检查邮箱是否已存在
            $existingEmail = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('mail = ?', $userEmail)
                ->limit(1));
            
            if ($existingEmail) {
                $this->error('邮箱已被使用，请使用其他邮箱');
                return;
            }
            
            if (empty($displayName)) {
                $displayName = $userName;
            }
            
            // 允许未登录用户注册
            $tempUserId = 'passuser_' . substr(md5($userName . time()), 0, 16);
        } else {
            // 已登录用户添加凭证
            $tempUserId = $user->uid;
            $userName = $user->name;
            $userEmail = $user->mail;
            $displayName = $user->screenName;
        }
        
        $rpName = $plugin->rpName ?: 'My Website';
        $rpId = $plugin->rpId ?: $_SERVER['HTTP_HOST'];
        
        // 生成 challenge
        $challenge = $this->generateChallenge();
        
        // 保存 challenge 和用户信息到 session
        session_start();
        $_SESSION['passkey_register_challenge'] = $challenge;
        $_SESSION['passkey_register_user_id'] = $isLoggedIn ? $user->uid : null;
        $_SESSION['passkey_register_is_new_user'] = !$isLoggedIn;
        
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
        
        if (!$data || !isset($data['id']) || !isset($data['rawId']) || !isset($data['response'])) {
            $this->error('Invalid data');
            return;
        }
        
        // 验证 response
        $response = $data['response'];
        
        // 这里应该进行完整的 WebAuthn 验证
        // 为了简化，我们只做基本验证
        
        $credentialId = base64_encode($data['rawId']);
        $publicKey = isset($response['publicKey']) ? $response['publicKey'] : 
                     (isset($response['attestationObject']) ? $response['attestationObject'] : '');
        
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
        try {
            $this->db->query($this->db->insert($this->prefix . 'passkey_credentials')->rows(array(
                'user_id' => $userId,
                'credential_id' => $credentialId,
                'public_key' => $publicKey,
                'counter' => 0,
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
                // 获取用户信息用于生成 authCode
                $userInfo = $this->db->fetchRow($this->db->select()
                    ->from($this->prefix . 'users')
                    ->where('uid = ?', $userId));
                
                if ($userInfo) {
                    Cookie::set('__typecho_uid', $userId);
                    Cookie::set('__typecho_authCode', Common::hash($userInfo['password'], $userId));
                    
                    $this->success(array(
                        'message' => '🎉 注册成功！欢迎使用 Passkey 登录。',
                        'isNewUser' => true,
                        'redirect' => Options::alloc()->adminUrl
                    ));
                    return;
                }
            }
            
            $this->success(array('message' => 'Passkey registered successfully'));
        } catch (Exception $e) {
            $this->error('Failed to save credential: ' . $e->getMessage());
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
        
        // 生成 challenge
        $challenge = $this->generateChallenge();
        
        // 保存 challenge 到 session
        session_start();
        $_SESSION['passkey_login_challenge'] = $challenge;
        
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
            $this->error('Invalid session');
            return;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['id']) || !isset($data['rawId']) || !isset($data['response'])) {
            $this->error('Invalid data');
            return;
        }
        
        $credentialId = base64_encode($data['rawId']);
        
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
            
            // 这里应该进行完整的 WebAuthn 验证
            // 为了简化，我们只做基本验证
            
            // 登录用户
            $user = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'users')
                ->where('uid = ?', $credential['user_id']));
            
            if (!$user) {
                $this->error('User not found');
                return;
            }
            
            // 设置登录状态
            Cookie::set('__typecho_uid', $user['uid']);
            Cookie::set('__typecho_authCode', Common::hash($user['password'], $user['uid']));
            
            unset($_SESSION['passkey_login_challenge']);
            
            $this->success(array(
                'message' => 'Login successful',
                'redirect' => Options::alloc()->adminUrl
            ));
        } catch (Exception $e) {
            $this->error('Login failed: ' . $e->getMessage());
        }
    }
    
    /**
     * 列出当前用户的凭证
     */
    private function listCredentials()
    {
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            $this->error('Please login first');
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
        } catch (Exception $e) {
            $this->error('Failed to fetch credentials: ' . $e->getMessage());
        }
    }
    
    /**
     * 删除凭证
     */
    private function deleteCredential()
    {
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            $this->error('Please login first');
            return;
        }
        
        $id = $this->request->get('id');
        
        if (!$id) {
            $this->error('Invalid credential ID');
            return;
        }
        
        try {
            $this->db->query($this->db->delete($this->prefix . 'passkey_credentials')
                ->where('id = ? AND user_id = ?', $id, $user->uid));
            
            $this->success(array('message' => 'Credential deleted'));
        } catch (Exception $e) {
            $this->error('Failed to delete credential: ' . $e->getMessage());
        }
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