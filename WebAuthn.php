<?php

namespace TypechoPlugin\Passkey;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * WebAuthn 验证类
 * 实现完整的 WebAuthn 注册和认证验证
 * 严格限制只支持 PHP OpenSSL 原生支持的算法
 */
class WebAuthn
{
    // 默认安全常量（当配置不可用时使用）
    const DEFAULT_MAX_CHALLENGE_LENGTH = 1024;             //Challenge 最大长度
    const DEFAULT_MAX_CLIENT_DATA_LENGTH = 8192;           //ClientDataJSON 最大长度
    const DEFAULT_MAX_ATTESTATION_LENGTH = 65536;          //AttestationObject 最大长度
    const DEFAULT_MAX_AUTHENTICATOR_DATA_LENGTH = 65536;   //AuthenticatorData 最大长度
    const DEFAULT_MAX_SIGNATURE_LENGTH = 1024;             //Signature 最大长度
    const DEFAULT_MAX_PUBLIC_KEY_LENGTH = 8192;            //PublicKey 最大长度
    const DEFAULT_MAX_CBOR_DEPTH = 10;                     //CBOR 解码最大深度，防止递归攻击 
    
    // 支持的算法白名单（只支持 PHP OpenSSL 原生支持的）
    const SUPPORTED_ALGORITHMS = array(
        -7 => 'ES256',   // ECDSA with SHA-256
        -257 => 'RS256'  // RSA with SHA-256
    );
    
    // 支持的曲线白名单
    const SUPPORTED_CURVES = array(
        1 => 'P-256'
    );
    
    /**
     * 获取安全配置参数
     * 从插件配置中读取，如果不存在则使用默认值
     */
    private static function getSecurityConfig($key)
    {
        try {
            $options = \Widget\Options::alloc();
            $plugin = $options->plugin('Passkey');
            
            if ($plugin && isset($plugin->$key)) {
                return (int)$plugin->$key;
            }
        } catch (\Exception $e) {
            // 配置不可用，使用默认值
        }
        
        // 返回默认值
        $defaults = array(
            'maxChallengeLength' => self::DEFAULT_MAX_CHALLENGE_LENGTH,
            'maxClientDataLength' => self::DEFAULT_MAX_CLIENT_DATA_LENGTH,
            'maxAttestationLength' => self::DEFAULT_MAX_ATTESTATION_LENGTH,
            'maxAuthenticatorDataLength' => self::DEFAULT_MAX_AUTHENTICATOR_DATA_LENGTH,
            'maxSignatureLength' => self::DEFAULT_MAX_SIGNATURE_LENGTH,
            'maxPublicKeyLength' => self::DEFAULT_MAX_PUBLIC_KEY_LENGTH,
            'maxCborDepth' => self::DEFAULT_MAX_CBOR_DEPTH
        );
        
        return isset($defaults[$key]) ? $defaults[$key] : 0;
    }
    
    /**
     * 验证注册响应
     * 
     * @param array $response 客户端响应数据
     * @param string $challenge Base64URL 编码的 challenge
     * @param string $rpId Relying Party ID
     * @param string $origin 预期的 origin
     * @return array 返回公钥和其他验证信息
     * @throws \Exception 验证失败时抛出异常
     */
    public static function verifyRegistration($response, $challenge, $rpId, $origin)
    {
        // 输入验证
        if (!is_array($response)) {
            throw new \Exception('Invalid response type');
        }
        
        if (!isset($response['clientDataJSON']) || !isset($response['attestationObject'])) {
            throw new \Exception('Missing required fields in response');
        }
        
        // 验证 challenge 长度
        $maxChallengeLength = self::getSecurityConfig('maxChallengeLength');
        if (strlen($challenge) > $maxChallengeLength) {
            throw new \Exception('Challenge too long');
        }
        
        // 验证 rpId 格式
        if (!self::isValidDomain($rpId)) {
            throw new \Exception('Invalid RP ID format');
        }
        
        // 验证 origin 格式
        if (!self::isValidOrigin($origin)) {
            throw new \Exception('Invalid origin format');
        }
        
        // 1. 解码 clientDataJSON（添加长度限制）
        $clientDataJSONBase64 = $response['clientDataJSON'];
        $maxClientDataLength = self::getSecurityConfig('maxClientDataLength');
        if (strlen($clientDataJSONBase64) > $maxClientDataLength * 2) {
            throw new \Exception('ClientDataJSON too long');
        }
        
        $clientDataJSON = base64_decode($clientDataJSONBase64, true);
        if ($clientDataJSON === false) {
            throw new \Exception('Failed to decode clientDataJSON');
        }
        
        if (strlen($clientDataJSON) > $maxClientDataLength) {
            throw new \Exception('ClientDataJSON too long');
        }
        
        $clientData = json_decode($clientDataJSON, true);
        
        if (!$clientData || !is_array($clientData)) {
            throw new \Exception('Invalid clientDataJSON');
        }
        
        // 2. 验证 type（严格匹配）
        if (!isset($clientData['type']) || $clientData['type'] !== 'webauthn.create') {
            throw new \Exception('Invalid type in clientData');
        }
        
        // 3. 验证 challenge（使用时序安全的比较）
        if (!isset($clientData['challenge']) || !is_string($clientData['challenge'])) {
            throw new \Exception('Missing or invalid challenge in clientData');
        }
        
        // 使用 hash_equals() 防止时序攻击
        if (!hash_equals($challenge, $clientData['challenge'])) {
            throw new \Exception('Challenge mismatch');
        }
        
        // 4. 验证 origin（严格验证）
        if (!isset($clientData['origin']) || !is_string($clientData['origin'])) {
            throw new \Exception('Missing or invalid origin in clientData');
        }
        
        if (!self::verifyOrigin($clientData['origin'], $origin)) {
            // 详细信息记录日志，避免向客户端泄露配置信息
            error_log('[Passkey][SECURITY] Origin mismatch - Expected: ' . $origin . 
                     ', Got: ' . $clientData['origin'] . 
                     ' | Timestamp: ' . date('Y-m-d H:i:s'));
            throw new \Exception('Origin verification failed');
        }
        
        // 5. 解码 attestationObject（添加长度限制）
        $attestationObjectBase64 = $response['attestationObject'];
        $maxAttestationLength = self::getSecurityConfig('maxAttestationLength');
        if (strlen($attestationObjectBase64) > $maxAttestationLength * 2) {
            throw new \Exception('AttestationObject too long');
        }
        
        $attestationObject = base64_decode($attestationObjectBase64, true);
        if ($attestationObject === false) {
            throw new \Exception('Failed to decode attestationObject');
        }
        
        if (strlen($attestationObject) > $maxAttestationLength) {
            throw new \Exception('AttestationObject too long');
        }
        
        // 解码 CBOR（添加深度限制）
        $attestation = self::decodeCBOR($attestationObject, 0);
        
        if (!$attestation || !is_array($attestation)) {
            throw new \Exception('Failed to decode attestationObject');
        }
        
        // 验证必需字段
        if (!isset($attestation['authData']) || !isset($attestation['fmt'])) {
            throw new \Exception('Missing required fields in attestation');
        }
        
        $authData = $attestation['authData'];
        
        // 验证 authData 长度
        if (strlen($authData) < 37) {
            throw new \Exception('AuthData too short');
        }
        
        $maxAuthenticatorDataLength = self::getSecurityConfig('maxAuthenticatorDataLength');
        if (strlen($authData) > $maxAuthenticatorDataLength) {
            throw new \Exception('AuthData too long');
        }
        
        
        // 6. 验证 RPID hash（SHA-256哈希必须是32字节）
        $rpIdHash = substr($authData, 0, 32);
        $expectedRpIdHash = hash('sha256', $rpId, true);
        
        // 使用时序安全比较防止时序攻击
        if (!hash_equals($rpIdHash, $expectedRpIdHash)) {
            throw new \Exception('RP ID hash mismatch');
        }
        
        // 7. 验证 flags（详细解析所有标志位）
        $flags = ord($authData[32]);
        $userPresent = ($flags & 0x01) !== 0;      // UP: User Present
        $userVerified = ($flags & 0x04) !== 0;     // UV: User Verified
        $backupEligible = ($flags & 0x08) !== 0;   // BE: Backup Eligible
        $backupState = ($flags & 0x10) !== 0;      // BS: Backup State
        $attestedCredentialData = ($flags & 0x40) !== 0; // AT: Attested Credential Data
        $extensionData = ($flags & 0x80) !== 0;    // ED: Extension Data
        
        // User Present 标志是必需的（WebAuthn规范要求）
        if (!$userPresent) {
            throw new \Exception('User not present');
        }
        
        // 注册时必须包含凭证数据
        if (!$attestedCredentialData) {
            throw new \Exception('Attested credential data flag not set');
        }
        
        // 8. 提取并验证 counter（签名计数器，防止克隆攻击）
        $counterBytes = substr($authData, 33, 4);
        if (strlen($counterBytes) !== 4) {
            throw new \Exception('Invalid counter data');
        }
        
        $counter = unpack('N', $counterBytes)[1];
        
        // Counter在注册时通常为0，但也可能不为0
        // 验证范围（32位无符号整数）
        if ($counter < 0 || $counter > 0xFFFFFFFF) {
            throw new \Exception('Invalid counter value');
        }
        
        // 9. 验证 authenticatorData 基本结构完整性
        // rpIdHash(32) + flags(1) + counter(4) = 37 字节是最小长度
        // 如果有 AT 标志，还需要 AAGUID(16) + credIdLen(2) + credId + publicKey
        $minExpectedLength = 37;
        if ($attestedCredentialData) {
            $minExpectedLength += 18; // AAGUID(16) + credIdLen(2) 的最小长度
        }
        
        if (strlen($authData) < $minExpectedLength) {
            throw new \Exception('Authenticator data too short for declared flags');
        }
        
        // 10. 提取公钥和凭证ID
        $publicKeyData = self::extractPublicKey($authData);
        
        // 11. 验证公钥数据完整性
        if (!$publicKeyData || !isset($publicKeyData['publicKey']) || !isset($publicKeyData['credentialId'])) {
            throw new \Exception('Failed to extract public key');
        }
        
        // 12. 验证凭证ID长度合理性（防止异常大的ID）
        $credentialIdDecoded = base64_decode($publicKeyData['credentialId'], true);
        if ($credentialIdDecoded === false) {
            throw new \Exception('Invalid credential ID encoding');
        }
        
        $credIdLength = strlen($credentialIdDecoded);
        if ($credIdLength < 16 || $credIdLength > 1024) {
            throw new \Exception('Credential ID length out of acceptable range');
        }
        
        // 13. 验证公钥格式（确保是有效的COSE key）
        $publicKeyDecoded = base64_decode($publicKeyData['publicKey'], true);
        if ($publicKeyDecoded === false || strlen($publicKeyDecoded) < 32) {
            throw new \Exception('Invalid public key encoding');
        }
        
        // 14. 验证 AAGUID 格式（16字节的认证器标识符）
        if (isset($publicKeyData['aaguid']) && strlen($publicKeyData['aaguid']) !== 32) {
            // AAGUID是16字节，hex编码后应该是32字符
            throw new \Exception('Invalid AAGUID format');
        }
        
        return array(
            'publicKey' => $publicKeyData['publicKey'],
            'credentialId' => $publicKeyData['credentialId'],
            'counter' => $counter,
            'userVerified' => $userVerified,
            'backupEligible' => $backupEligible,
            'backupState' => $backupState,
            'aaguid' => isset($publicKeyData['aaguid']) ? $publicKeyData['aaguid'] : null
        );
    }
    
    /**
     * 验证 origin
     */
    private static function verifyOrigin($clientOrigin, $expectedOrigin)
    {
        // 严格验证 origin 格式
        if (!self::isValidOrigin($clientOrigin) || !self::isValidOrigin($expectedOrigin)) {
            return false;
        }
        
        // 解析并比较
        $clientParsed = parse_url($clientOrigin);
        $expectedParsed = parse_url($expectedOrigin);
        
        if (!$clientParsed || !$expectedParsed) {
            return false;
        }
        
        // 必须有相同的协议和主机
        if (!isset($clientParsed['scheme']) || !isset($clientParsed['host']) ||
            !isset($expectedParsed['scheme']) || !isset($expectedParsed['host'])) {
            return false;
        }
        
        // 协议必须匹配（严格）
        if ($clientParsed['scheme'] !== $expectedParsed['scheme']) {
            return false;
        }
        
        // 主机必须匹配（大小写不敏感）
        if (strtolower($clientParsed['host']) !== strtolower($expectedParsed['host'])) {
            return false;
        }
        
        // 如果指定了端口，端口也必须匹配
        $clientPort = isset($clientParsed['port']) ? $clientParsed['port'] : self::getDefaultPort($clientParsed['scheme']);
        $expectedPort = isset($expectedParsed['port']) ? $expectedParsed['port'] : self::getDefaultPort($expectedParsed['scheme']);
        
        if ($clientPort !== $expectedPort) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取协议默认端口
     */
    private static function getDefaultPort($scheme)
    {
        $scheme = strtolower($scheme);
        if ($scheme === 'https') {
            return 443;
        } elseif ($scheme === 'http') {
            return 80;
        }
        return null;
    }
    
    /**
     * 验证域名格式
     */
    private static function isValidDomain($domain)
    {
        if (!is_string($domain) || strlen($domain) === 0 || strlen($domain) > 253) {
            return false;
        }
        
        // 基本的域名格式验证
        return preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $domain) === 1;
    }
    
    /**
     * 验证 origin 格式
     */
    private static function isValidOrigin($origin)
    {
        if (!is_string($origin) || strlen($origin) === 0 || strlen($origin) > 2048) {
            return false;
        }
        
        // 必须是 https 或 http (localhost 可以用 http)
        if (!preg_match('/^https?:\/\/[a-zA-Z0-9.-]+(:[0-9]+)?$/', $origin)) {
            return false;
        }
        
        $parsed = parse_url($origin);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }
        
        // 只允许 http 和 https
        if (!in_array($parsed['scheme'], array('http', 'https'), true)) {
            return false;
        }
        
        // 非 localhost 必须使用 https
        if ($parsed['scheme'] === 'http' && 
            $parsed['host'] !== 'localhost' && 
            $parsed['host'] !== '127.0.0.1' &&
            $parsed['host'] !== '[::1]') {
            return false;
        }
        
        return true;
    }
    
    /**
     * 验证认证响应
     * 
     * @param array $response 客户端响应数据
     * @param string $challenge Base64URL 编码的 challenge
     * @param string $rpId Relying Party ID
     * @param string $origin 预期的 origin
     * @param string $publicKey 公钥（COSE 格式，Base64 编码）
     * @param int $storedCounter 存储的 counter 值
     * @return array 返回验证结果
     * @throws \Exception 验证失败时抛出异常
     */
    public static function verifyAuthentication($response, $challenge, $rpId, $origin, $publicKey, $storedCounter)
    {
        // 输入验证
        if (!is_array($response)) {
            throw new \Exception('Invalid response type');
        }
        
        if (!isset($response['clientDataJSON']) || !isset($response['authenticatorData']) || !isset($response['signature'])) {
            throw new \Exception('Missing required fields in response');
        }
        
        if (!is_string($publicKey) || strlen($publicKey) === 0) {
            throw new \Exception('Invalid public key');
        }
        
        $maxPublicKeyLength = self::getSecurityConfig('maxPublicKeyLength');
        if (strlen($publicKey) > $maxPublicKeyLength) {
            throw new \Exception('Public key too long');
        }
        
        if (!is_int($storedCounter) || $storedCounter < 0) {
            throw new \Exception('Invalid stored counter');
        }
        
        // 验证 challenge 长度
        $maxChallengeLength = self::getSecurityConfig('maxChallengeLength');
        if (strlen($challenge) > $maxChallengeLength) {
            throw new \Exception('Challenge too long');
        }
        
        // 验证 rpId 和 origin 格式
        if (!self::isValidDomain($rpId)) {
            throw new \Exception('Invalid RP ID format');
        }
        
        if (!self::isValidOrigin($origin)) {
            throw new \Exception('Invalid origin format');
        }
        
        // 1. 解码 clientDataJSON（添加长度限制）
        $clientDataJSONBase64 = $response['clientDataJSON'];
        $maxClientDataLength = self::getSecurityConfig('maxClientDataLength');
        if (strlen($clientDataJSONBase64) > $maxClientDataLength * 2) {
            throw new \Exception('ClientDataJSON too long');
        }
        
        $clientDataJSON = base64_decode($clientDataJSONBase64, true);
        if ($clientDataJSON === false) {
            throw new \Exception('Failed to decode clientDataJSON');
        }
        
        if (strlen($clientDataJSON) > $maxClientDataLength) {
            throw new \Exception('ClientDataJSON too long');
        }
        
        $clientData = json_decode($clientDataJSON, true);
        
        if (!$clientData || !is_array($clientData)) {
            throw new \Exception('Invalid clientDataJSON');
        }
        
        // 2. 验证 type（严格匹配）
        if (!isset($clientData['type']) || $clientData['type'] !== 'webauthn.get') {
            throw new \Exception('Invalid type in clientData');
        }
        
        // 3. 验证 challenge（使用时序安全的比较）
        if (!isset($clientData['challenge']) || !is_string($clientData['challenge'])) {
            throw new \Exception('Missing or invalid challenge in clientData');
        }
        
        // 使用 hash_equals() 防止时序攻击
        if (!hash_equals($challenge, $clientData['challenge'])) {
            throw new \Exception('Challenge mismatch');
        }
        
        // 4. 验证 origin（严格验证）
        if (!isset($clientData['origin']) || !is_string($clientData['origin'])) {
            throw new \Exception('Missing or invalid origin in clientData');
        }
        
        if (!self::verifyOrigin($clientData['origin'], $origin)) {
            // 详细信息记录日志
            error_log('[Passkey][SECURITY] Origin verification failed during authentication - ' .
                     'Expected: ' . $origin . ', Got: ' . $clientData['origin'] . 
                     ' | Timestamp: ' . date('Y-m-d H:i:s'));
            throw new \Exception('Origin verification failed');
        }
        
        // 5. 解码 authenticatorData（添加长度限制）
        $authenticatorDataBase64 = $response['authenticatorData'];
        $maxAuthenticatorDataLength = self::getSecurityConfig('maxAuthenticatorDataLength');
        if (strlen($authenticatorDataBase64) > $maxAuthenticatorDataLength * 2) {
            throw new \Exception('AuthenticatorData too long');
        }
        
        $authenticatorData = base64_decode($authenticatorDataBase64, true);
        if ($authenticatorData === false) {
            throw new \Exception('Failed to decode authenticatorData');
        }
        
        if (strlen($authenticatorData) < 37) {
            throw new \Exception('AuthenticatorData too short');
        }
        
        if (strlen($authenticatorData) > $maxAuthenticatorDataLength) {
            throw new \Exception('AuthenticatorData too long');
        }
        
        // 6. 验证 RPID hash
        $rpIdHash = substr($authenticatorData, 0, 32);
        $expectedRpIdHash = hash('sha256', $rpId, true);
        
        if (!hash_equals($rpIdHash, $expectedRpIdHash)) {
            throw new \Exception('RP ID hash mismatch');
        }
        
        // 7. 验证 flags
        $flags = ord($authenticatorData[32]);
        $userPresent = ($flags & 0x01) !== 0;
        
        if (!$userPresent) {
            throw new \Exception('User not present');
        }
        
        // 8. 提取和验证 counter
        $counter = unpack('N', substr($authenticatorData, 33, 4))[1];
        
        // 验证 counter 范围
        if ($counter < 0 || $counter > 0xFFFFFFFF) {
            throw new \Exception('Invalid counter value');
        }
        
        // Counter 防克隆检测（严格）
        if ($counter !== 0 && $storedCounter !== 0 && $counter <= $storedCounter) {
            throw new \Exception('Counter did not increase (possible cloned authenticator)');
        }
        
        // 9. 验证签名（添加长度限制）
        $signatureBase64 = $response['signature'];
        $maxSignatureLength = self::getSecurityConfig('maxSignatureLength');
        if (strlen($signatureBase64) > $maxSignatureLength * 2) {
            throw new \Exception('Signature too long');
        }
        
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false) {
            throw new \Exception('Failed to decode signature');
        }
        
        if (strlen($signature) > $maxSignatureLength) {
            throw new \Exception('Signature too long');
        }
        
        // 构造签名验证数据：authenticatorData + hash(clientDataJSON)
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedData = $authenticatorData . $clientDataHash;
        
        // 验证签名
        if (!self::verifySignature($signedData, $signature, $publicKey)) {
            throw new \Exception('Signature verification failed');
        }
        
        return array(
            'counter' => $counter,
            'userPresent' => $userPresent,
            'userVerified' => ($flags & 0x04) !== 0
        );
    }
    
    /**
     * 从 authenticatorData 中提取公钥
     * 
     * @param string $authData 认证器数据
     * @return array 包含公钥信息的数组
     * @throws \Exception 当数据无效时
     */
    private static function extractPublicKey($authData)
    {
        // authenticatorData 结构：
        // rpIdHash: 32 bytes
        // flags: 1 byte
        // counter: 4 bytes
        // aaguid: 16 bytes
        // credentialIdLength: 2 bytes
        // credentialId: credentialIdLength bytes
        // credentialPublicKey: COSE 格式
        
        // 验证最小长度（37 + 16 + 2 = 55字节）
        if (strlen($authData) < 55) {
            throw new \Exception('AuthData too short for credential data');
        }
        
        $offset = 37; // 跳过 rpIdHash + flags + counter
        
        // 提取 AAGUID (16 bytes)
        $aaguid = substr($authData, $offset, 16);
        if (strlen($aaguid) !== 16) {
            throw new \Exception('Failed to extract AAGUID');
        }
        $offset += 16;
        
        // 提取 credentialId 长度
        if ($offset + 2 > strlen($authData)) {
            throw new \Exception('AuthData too short for credential ID length');
        }
        
        $credIdLengthBytes = substr($authData, $offset, 2);
        if (strlen($credIdLengthBytes) !== 2) {
            throw new \Exception('Failed to extract credential ID length');
        }
        
        $credIdLength = unpack('n', $credIdLengthBytes)[1];
        $offset += 2;
        
        // 验证 credentialId 长度合理性（防止整数溢出攻击）
        if ($credIdLength === 0) {
            throw new \Exception('Credential ID length cannot be zero');
        }
        
        if ($credIdLength > 1024) {
            throw new \Exception('Invalid credential ID length: too large');
        }
        
        // 验证剩余数据足够
        if ($offset + $credIdLength > strlen($authData)) {
            throw new \Exception('AuthData too short for credential ID');
        }
        // 提取 credentialId
        $credentialId = substr($authData, $offset, $credIdLength);
        if (strlen($credentialId) !== $credIdLength) {
            throw new \Exception('Failed to extract credential ID');
        }
        $offset += $credIdLength;
        
        // 提取公钥 (COSE 格式)
        if ($offset >= strlen($authData)) {
            throw new \Exception('AuthData too short for public key');
        }
        
        $publicKeyData = substr($authData, $offset);
        
        // 验证公钥长度（COSE公钥至少需要32字节）
        if (strlen($publicKeyData) < 32) {
            throw new \Exception('Public key data too short');
        }
        
        if (strlen($publicKeyData) > 8192) {
            throw new \Exception('Invalid public key length: too large');
        }
        
        // 尝试解析COSE公钥以验证其格式
        try {
            $parsedKey = self::decodeCOSEKey($publicKeyData);
            if (!$parsedKey || !is_array($parsedKey)) {
                throw new \Exception('Invalid COSE key format');
            }
        } catch (\Exception $e) {
            error_log('[Passkey][ERROR] Failed to parse COSE key: ' . $e->getMessage());
            throw new \Exception('Failed to parse public key');
        }
        
        return array(
            'credentialId' => base64_encode($credentialId),
            'publicKey' => base64_encode($publicKeyData),
            'aaguid' => bin2hex($aaguid)
        );
    }
    
    /**
     * 验证签名
     */
    private static function verifySignature($data, $signature, $publicKeyBase64)
    {
        try {
            $publicKeyData = base64_decode($publicKeyBase64, true);
            if ($publicKeyData === false) {
                throw new \Exception('Failed to decode public key');
            }
            
            $maxPublicKeyLength = self::getSecurityConfig('maxPublicKeyLength');
            if (strlen($publicKeyData) === 0 || strlen($publicKeyData) > $maxPublicKeyLength) {
                throw new \Exception('Invalid public key length');
            }
            
            $publicKey = self::decodeCOSEKey($publicKeyData);
            
            if (!$publicKey || !is_array($publicKey)) {
                throw new \Exception('Failed to decode COSE key');
            }
            
            // 验证算法是否在白名单中
            if (!isset($publicKey['alg']) || !isset(self::SUPPORTED_ALGORITHMS[$publicKey['alg']])) {
                throw new \Exception('Unsupported or missing algorithm');
            }
            
            // 根据算法类型验证签名
            switch ($publicKey['alg']) {
                case -7: // ES256
                    return self::verifyES256($data, $signature, $publicKey);
                case -257: // RS256
                    return self::verifyRS256($data, $signature, $publicKey);
                default:
                    error_log('[Passkey][ERROR] Unsupported algorithm: ' . $publicKey['alg']);
                    throw new \Exception('Unsupported signature algorithm');
            }
        } catch (\Exception $e) {
            error_log('[Passkey][ERROR] Signature verification error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 验证 ES256 签名（ECDSA with SHA-256）
     */
    private static function verifyES256($data, $signature, $publicKey)
    {
        if (!isset($publicKey['x']) || !isset($publicKey['y'])) {
            throw new \Exception('Missing x or y coordinate in EC public key');
        }
        
        // 验证坐标长度（P-256 曲线坐标长度应为 32 字节）
        if (strlen($publicKey['x']) !== 32 || strlen($publicKey['y']) !== 32) {
            throw new \Exception('Invalid EC key coordinate length');
        }
        
        // 验证曲线类型
        if (isset($publicKey['crv']) && $publicKey['crv'] !== 1) {
            // crv = 1 表示 P-256
            throw new \Exception('Unsupported curve');
        }
        
        // WebAuthn 签名可能是 IEEE P1363 格式（r || s，每个 32 字节）或 DER 格式
        // 检测并转换为 DER 格式供 openssl_verify 使用
        if (strlen($signature) === 64) {
            // 标准的 IEEE P1363 格式
            $signature = self::ieee1363ToDer($signature);
        } elseif (strlen($signature) > 64 && ord($signature[0]) === 0x30) {
            // 可能已经是 DER 格式（SEQUENCE tag）
            // 直接使用
        } else {
            throw new \Exception('Invalid ES256 signature format');
        }
        
        // 构造 PEM 格式的公钥
        $x = $publicKey['x'];
        $y = $publicKey['y'];
        
        // 未压缩点格式: 0x04 + x + y
        $uncompressedPoint = "\x04" . $x . $y;
        
        // 构造 DER 编码的公钥
        $der = self::encodeECPublicKey($uncompressedPoint);
        
        // 转换为 PEM 格式
        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";
        
        // 验证签名（使用 PHP OpenSSL）
        $result = openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256);
        
        if ($result === -1) {
            error_log('[Passkey][ERROR] OpenSSL ES256 verification error: ' . openssl_error_string());
            throw new \Exception('Signature verification failed');
        }
        
        return $result === 1;
    }
    
    /**
     * 验证 RS256 签名（RSA with SHA-256）
     */
    private static function verifyRS256($data, $signature, $publicKey)
    {
        if (!isset($publicKey['n']) || !isset($publicKey['e'])) {
            throw new \Exception('Missing n or e in RSA public key');
        }
        
        // 验证 RSA 密钥长度（至少 2048 位）
        $keyBits = strlen($publicKey['n']) * 8;
        if ($keyBits < 2048) {
            throw new \Exception('RSA key too short (minimum 2048 bits)');
        }
        
        if ($keyBits > 8192) {
            throw new \Exception('RSA key too long (maximum 8192 bits)');
        }
        
        // 构造 PEM 格式的公钥
        $der = self::encodeRSAPublicKey($publicKey['n'], $publicKey['e']);
        
        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";
        
        // 验证签名（使用 PHP OpenSSL）
        $result = openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256);
        
        if ($result === -1) {
            error_log('[Passkey][ERROR] OpenSSL RS256 verification error: ' . openssl_error_string());
            throw new \Exception('Signature verification failed');
        }
        
        return $result === 1;
    }
    
    /**
     * 编码 EC 公钥为 DER 格式
     */
    private static function encodeECPublicKey($point)
    {
        // EC Public Key DER 结构:
        // SEQUENCE {
        //   SEQUENCE {
        //     OBJECT IDENTIFIER ecPublicKey (1.2.840.10045.2.1)
        //     OBJECT IDENTIFIER prime256v1 (1.2.840.10045.3.1.7)
        //   }
        //   BIT STRING (uncompressed point)
        // }
        
        // OID: ecPublicKey (1.2.840.10045.2.1)
        $oidEcPublicKey = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        
        // OID: prime256v1/secp256r1 (1.2.840.10045.3.1.7)
        $oidPrime256v1 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        
        // Algorithm SEQUENCE
        $algorithm = "\x30" . chr(strlen($oidEcPublicKey) + strlen($oidPrime256v1)) . 
                     $oidEcPublicKey . $oidPrime256v1;
        
        // BIT STRING (0x00 表示没有 unused bits)
        $bitString = "\x03" . chr(strlen($point) + 1) . "\x00" . $point;
        
        // 外层 SEQUENCE
        $sequence = $algorithm . $bitString;
        
        return "\x30" . chr(strlen($sequence)) . $sequence;
    }
    
    /**
     * 编码 RSA 公钥为 DER 格式
     */
    private static function encodeRSAPublicKey($n, $e)
    {
        // 编码模数和指数
        $nEncoded = self::encodeDERInteger($n);
        $eEncoded = self::encodeDERInteger($e);
        
        $sequence = $nEncoded . $eEncoded;
        $sequence = "\x30" . self::encodeDERLength(strlen($sequence)) . $sequence;
        
        $bitString = "\x03" . self::encodeDERLength(strlen($sequence) + 1) . "\x00" . $sequence;
        
        // RSA 加密算法标识符
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        
        $publicKey = $algorithm . $bitString;
        
        return "\x30" . self::encodeDERLength(strlen($publicKey)) . $publicKey;
    }
    
    /**
     * 编码 DER 整数
     * 
     * @param string $value 二进制字符串形式的整数
     * @return string DER 编码的整数
     * @throws \Exception 当输入无效时
     */
    private static function encodeDERInteger($value)
    {
        // 验证输入
        if (!is_string($value)) {
            throw new \Exception('DER integer value must be a string');
        }
        
        if (strlen($value) === 0) {
            throw new \Exception('DER integer value cannot be empty');
        }
        
        // 移除前导零，但保留一个如果最高位是 1
        $value = ltrim($value, "\x00");
        
        // 如果所有字节都被移除了，表示值为0
        if (strlen($value) === 0) {
            $value = "\x00";
        } elseif (ord($value[0]) & 0x80) {
            // 如果最高位是1，需要添加一个前导零（保证是正数）
            $value = "\x00" . $value;
        }
        
        return "\x02" . self::encodeDERLength(strlen($value)) . $value;
    }
    
    /**
     * 将 IEEE P1363 格式签名转换为 DER 格式
     * IEEE P1363: r || s (每个 32 字节，共 64 字节)
     * DER: SEQUENCE { INTEGER r, INTEGER s }
     */
    private static function ieee1363ToDer($signature)
    {
        if (strlen($signature) !== 64) {
            throw new \Exception('Invalid IEEE P1363 signature length');
        }
        
        // 分离 r 和 s
        $r = substr($signature, 0, 32);
        $s = substr($signature, 32, 32);
        
        // 编码 r 和 s 为 DER INTEGER
        $rDer = self::encodeDERInteger($r);
        $sDer = self::encodeDERInteger($s);
        
        // 构造 SEQUENCE
        $sequence = $rDer . $sDer;
        
        return "\x30" . self::encodeDERLength(strlen($sequence)) . $sequence;
    }
    
    /**
     * 编码 DER 长度
     * 
     * @param int $length 长度值
     * @return string DER 编码的长度
     * @throws \Exception 当长度无效时
     */
    private static function encodeDERLength($length)
    {
        // 验证输入
        if (!is_int($length) || $length < 0) {
            throw new \Exception('DER length must be a non-negative integer');
        }
        
        // DER 规范：长度字段的最大值
        // 根据 X.690 标准，长度的长度字段最多 127 字节，但实际实现中通常限制为 4 字节
        // 这意味着最大可编码长度为 2^32-1 (约 4GB)
        if ($length > 0xFFFFFFFF) {
            throw new \Exception('DER length exceeds maximum (2^32-1)');
        }
        
        // 短形式：0-127
        if ($length < 128) {
            return chr($length);
        }
        
        // 长形式：需要多个字节表示
        $encoded = '';
        $temp = $length;
        
        while ($temp > 0) {
            $encoded = chr($temp & 0xff) . $encoded;
            $temp >>= 8;
        }
        
        $encodedLength = strlen($encoded);
        
        // DER 规范：长度的长度字段必须是最小编码
        // 第一个字节不能是 0x00（除非只有一个字节）
        if ($encodedLength > 1 && ord($encoded[0]) === 0x00) {
            throw new \Exception('DER length encoding is not minimal');
        }
        
        // 实际限制：长度字段最多 4 字节（支持到 2^32-1）
        if ($encodedLength > 4) {
            throw new \Exception('DER length encoding too long (maximum 4 bytes)');
        }
        
        // 长形式第一个字节：0x80 | 长度的字节数
        // 根据 X.690，0x80-0xFF 表示长形式，低 7 位表示后续字节数
        if ($encodedLength > 127) {
            throw new \Exception('DER length of length exceeds maximum (127)');
        }
        
        return chr(0x80 | $encodedLength) . $encoded;
    }
    
    /**
     * 解码 COSE 密钥
     */
    private static function decodeCOSEKey($data)
    {
        $key = self::decodeCBOR($data, 0);
        
        if (!$key || !is_array($key)) {
            return null;
        }
        
        $result = array();
        
        // kty (1): 密钥类型（必需）
        if (!isset($key[1])) {
            throw new \Exception('Missing key type (kty) in COSE key');
        }
        $result['kty'] = $key[1];
        
        // 只支持 EC2 (2) 和 RSA (3)
        if ($result['kty'] !== 2 && $result['kty'] !== 3) {
            error_log('[Passkey][ERROR] Unsupported key type: ' . $result['kty']);
            throw new \Exception('Unsupported key type');
        }
        
        // alg (3): 算法（必需）
        if (!isset($key[3])) {
            throw new \Exception('Missing algorithm (alg) in COSE key');
        }
        $result['alg'] = $key[3];
        
        // 验证算法是否在白名单中
        if (!isset(self::SUPPORTED_ALGORITHMS[$result['alg']])) {
            error_log('[Passkey][ERROR] Unsupported COSE algorithm: ' . $result['alg']);
            throw new \Exception('Unsupported algorithm');
        }
        
        // EC2 密钥参数
        if ($result['kty'] === 2) {
            // crv (-1): 曲线
            if (!isset($key[-1])) {
                throw new \Exception('Missing curve (crv) in EC key');
            }
            $result['crv'] = $key[-1];
            
            // 验证曲线是否在白名单中
            if (!isset(self::SUPPORTED_CURVES[$result['crv']])) {
                throw new \Exception('Unsupported curve: ' . $result['crv']);
            }
            
            // x (-2): x 坐标
            if (!isset($key[-2]) || !is_string($key[-2])) {
                throw new \Exception('Missing or invalid x coordinate in EC key');
            }
            $result['x'] = $key[-2];
            
            // y (-3): y 坐标
            if (!isset($key[-3]) || !is_string($key[-3])) {
                throw new \Exception('Missing or invalid y coordinate in EC key');
            }
            $result['y'] = $key[-3];
        }
        
        // RSA 密钥参数
        if ($result['kty'] === 3) {
            // n (-1): 模数
            if (!isset($key[-1]) || !is_string($key[-1])) {
                throw new \Exception('Missing or invalid n (modulus) in RSA key');
            }
            $result['n'] = $key[-1];
            
            // e (-2): 指数
            if (!isset($key[-2]) || !is_string($key[-2])) {
                throw new \Exception('Missing or invalid e (exponent) in RSA key');
            }
            $result['e'] = $key[-2];
        }
        
        return $result;
    }
    
    /**
     * 简化的 CBOR 解码器（添加深度限制）
     * 仅支持 WebAuthn 所需的基本类型
     */
    private static function decodeCBOR($data, $depth = 0)
    {
        // 防止深度过大导致的栈溢出
        $maxCborDepth = self::getSecurityConfig('maxCborDepth');
        if ($depth > $maxCborDepth) {
            throw new \Exception('CBOR nesting too deep');
        }
        
        $offset = 0;
        return self::decodeCBORValue($data, $offset, $depth);
    }
    
    /**
     * 递归解码 CBOR 值（添加深度参数）
     */
    private static function decodeCBORValue($data, &$offset, $depth)
    {
        if ($offset >= strlen($data)) {
            throw new \Exception('Unexpected end of CBOR data');
        }
        
        $initialByte = ord($data[$offset++]);
        $majorType = $initialByte >> 5;
        $additionalInfo = $initialByte & 0x1f;
        
        // 获取值
        $value = null;
        if ($additionalInfo < 24) {
            $value = $additionalInfo;
        } elseif ($additionalInfo === 24) {
            if ($offset >= strlen($data)) {
                throw new \Exception('Unexpected end of CBOR data');
            }
            $value = ord($data[$offset++]);
        } elseif ($additionalInfo === 25) {
            if ($offset + 2 > strlen($data)) {
                throw new \Exception('Unexpected end of CBOR data');
            }
            $value = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($additionalInfo === 26) {
            if ($offset + 4 > strlen($data)) {
                throw new \Exception('Unexpected end of CBOR data');
            }
            $value = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
        } elseif ($additionalInfo === 27) {
            if ($offset + 8 > strlen($data)) {
                throw new \Exception('Unexpected end of CBOR data');
            }
            $high = unpack('N', substr($data, $offset, 4))[1];
            $low = unpack('N', substr($data, $offset + 4, 4))[1];
            
            // 安全处理64位整数，防止32位系统溢出
            // 检查是否超出PHP_INT_MAX
            if (PHP_INT_SIZE === 8) {
                // 64位系统，直接计算
                $value = ($high << 32) | $low;
                // 检查是否溢出到负数（超过PHP_INT_MAX）
                if ($value < 0) {
                    throw new \Exception('CBOR integer too large for PHP integer');
                }
            } else {
                // 32位系统，只接受小于2^32的值
                if ($high > 0) {
                    throw new \Exception('CBOR 64-bit integer not supported on 32-bit PHP');
                }
                $value = $low;
            }
            $offset += 8;
        } else {
            throw new \Exception('Unsupported CBOR additional info: ' . $additionalInfo);
        }
        
        switch ($majorType) {
            case 0: // unsigned integer
                return $value;
            
            case 1: // negative integer
                return -1 - $value;
            
            case 2: // byte string
                $length = $value;
                if ($length > 1048576) { // 1MB 限制
                    throw new \Exception('CBOR byte string too long');
                }
                if ($offset + $length > strlen($data)) {
                    throw new \Exception('Unexpected end of CBOR data');
                }
                $result = substr($data, $offset, $length);
                $offset += $length;
                return $result;
            
            case 3: // text string
                $length = $value;
                if ($length > 1048576) { // 1MB 限制
                    throw new \Exception('CBOR text string too long');
                }
                if ($offset + $length > strlen($data)) {
                    throw new \Exception('Unexpected end of CBOR data');
                }
                $result = substr($data, $offset, $length);
                $offset += $length;
                return $result;
            
            case 4: // array
                $length = $value;
                if ($length > 10000) { // 数组大小限制
                    throw new \Exception('CBOR array too large');
                }
                $result = array();
                for ($i = 0; $i < $length; $i++) {
                    $result[] = self::decodeCBORValue($data, $offset, $depth + 1);
                }
                return $result;
            
            case 5: // map
                $length = $value;
                if ($length > 10000) { // Map 大小限制
                    throw new \Exception('CBOR map too large');
                }
                $result = array();
                for ($i = 0; $i < $length; $i++) {
                    $key = self::decodeCBORValue($data, $offset, $depth + 1);
                    $val = self::decodeCBORValue($data, $offset, $depth + 1);
                    $result[$key] = $val;
                }
                return $result;
            
            case 7: // simple values
                if ($additionalInfo === 20) return false;
                if ($additionalInfo === 21) return true;
                if ($additionalInfo === 22) return null;
                return $value;
            
            default:
                throw new \Exception('Unsupported CBOR major type: ' . $majorType);
        }
    }
}
