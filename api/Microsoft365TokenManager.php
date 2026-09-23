<?php
/*********************************************************************
 * Microsoft 365 delegated OAuth token manager for osTicket
 *
 * Stores one encrypted token set per osTicket staff member.
 * Requires PHP cURL and OpenSSL extensions.
 *********************************************************************/

class Microsoft365TokenManager
{
    private $tenantId;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $table;

    private $scopes = array(
        'openid',
        'profile',
        'offline_access',
        'https://graph.microsoft.com/Sites.Read.All',
        'https://graph.microsoft.com/Mail.Read',
        'https://graph.microsoft.com/People.Read.All',
        'https://graph.microsoft.com/OnlineMeetingTranscript.Read.All',
        'https://graph.microsoft.com/Chat.Read',
        'https://graph.microsoft.com/ChannelMessage.Read.All',
        'https://graph.microsoft.com/ExternalItem.Read.All'
    );

    public function __construct($tenantId, $clientId, $clientSecret, $redirectUri)
    {
        $this->tenantId = trim((string)$tenantId);
        $this->clientId = trim((string)$clientId);
        $this->clientSecret = (string)$clientSecret;
        $this->redirectUri = trim((string)$redirectUri);
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'ost_';
        $this->table = $prefix . 'plugin_m365_copilot_token';

        if (!$this->tenantId || !$this->clientId || !$this->clientSecret || !$this->redirectUri) {
            throw new InvalidArgumentException('Incomplete Microsoft Entra configuration.');
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('The PHP OpenSSL extension is required.');
        }

        $this->ensureTable();
    }

    public function getAuthorizationUrl($staffId)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $state = bin2hex(random_bytes(32));
        $_SESSION['m365_copilot_oauth_state'] = $state;
        $_SESSION['m365_copilot_staff_id'] = (int)$staffId;

        $query = http_build_query(array(
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'response_mode' => 'query',
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
            'prompt' => 'select_account'
        ), '', '&', PHP_QUERY_RFC3986);

        return $this->authorizeEndpoint() . '?' . $query;
    }

    public function validateState($state)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $expected = isset($_SESSION['m365_copilot_oauth_state'])
            ? (string)$_SESSION['m365_copilot_oauth_state'] : '';
        unset($_SESSION['m365_copilot_oauth_state']);

        return $expected !== '' && is_string($state) && hash_equals($expected, $state);
    }

    public function getPendingStaffId()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $staffId = isset($_SESSION['m365_copilot_staff_id'])
            ? (int)$_SESSION['m365_copilot_staff_id'] : 0;
        unset($_SESSION['m365_copilot_staff_id']);
        return $staffId;
    }

    public function exchangeAuthorizationCode($staffId, $code)
    {
        $tokens = $this->tokenRequest(array(
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'code' => (string)$code,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $this->scopes)
        ));

        $this->saveTokens((int)$staffId, $tokens);
        return $tokens;
    }

    public function getAccessToken($staffId)
    {
        $stored = $this->loadTokens((int)$staffId);
        if (!$stored) {
            return null;
        }

        if (!empty($stored['access_token']) && (int)$stored['expires_at'] > time() + 120) {
            return (string)$stored['access_token'];
        }

        if (empty($stored['refresh_token'])) {
            $this->deleteTokens((int)$staffId);
            return null;
        }

        try {
            $tokens = $this->tokenRequest(array(
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $stored['refresh_token'],
                'scope' => implode(' ', $this->scopes)
            ));

            if (empty($tokens['refresh_token'])) {
                $tokens['refresh_token'] = $stored['refresh_token'];
            }
            $this->saveTokens((int)$staffId, $tokens);
            return isset($tokens['access_token']) ? (string)$tokens['access_token'] : null;
        } catch (Exception $e) {
            $this->deleteTokens((int)$staffId);
            return null;
        }
    }

    public function deleteTokens($staffId)
    {
        db_query('DELETE FROM `' . $this->table . '` WHERE `staff_id`=' . (int)$staffId);
    }

    private function saveTokens($staffId, array $tokens)
    {
        if (empty($tokens['access_token'])) {
            throw new RuntimeException('Microsoft token response contained no access token.');
        }

        $expiresAt = time() + max(60, (int)(isset($tokens['expires_in']) ? $tokens['expires_in'] : 3600));
        $access = db_input($this->encrypt((string)$tokens['access_token']));
        $refresh = db_input($this->encrypt((string)(isset($tokens['refresh_token']) ? $tokens['refresh_token'] : '')));
        $scope = db_input((string)(isset($tokens['scope']) ? $tokens['scope'] : ''));

        $sql = 'INSERT INTO `' . $this->table . '` '
            . '(`staff_id`,`access_token`,`refresh_token`,`expires_at`,`scope`,`updated`) VALUES ('
            . (int)$staffId . ',' . $access . ',' . $refresh . ',' . (int)$expiresAt . ',' . $scope . ',NOW()) '
            . 'ON DUPLICATE KEY UPDATE `access_token`=VALUES(`access_token`),'
            . '`refresh_token`=VALUES(`refresh_token`),`expires_at`=VALUES(`expires_at`),'
            . '`scope`=VALUES(`scope`),`updated`=NOW()';

        if (!db_query($sql)) {
            throw new RuntimeException('Unable to store Microsoft 365 tokens in osTicket.');
        }
    }

    private function loadTokens($staffId)
    {
        $result = db_query('SELECT * FROM `' . $this->table . '` WHERE `staff_id`=' . (int)$staffId . ' LIMIT 1');
        if (!$result || !($row = db_fetch_array($result))) {
            return null;
        }

        return array(
            'access_token' => $this->decrypt($row['access_token']),
            'refresh_token' => $this->decrypt($row['refresh_token']),
            'expires_at' => (int)$row['expires_at'],
            'scope' => isset($row['scope']) ? $row['scope'] : ''
        );
    }

    private function ensureTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $this->table . '` ('
            . '`staff_id` INT UNSIGNED NOT NULL,'
            . '`access_token` MEDIUMTEXT NOT NULL,'
            . '`refresh_token` MEDIUMTEXT NULL,'
            . '`expires_at` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`scope` TEXT NULL,'
            . '`updated` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`staff_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        if (!db_query($sql)) {
            throw new RuntimeException('Unable to create Microsoft 365 token table.');
        }
    }

    private function tokenRequest(array $fields)
    {
        $ch = curl_init($this->tokenEndpoint());
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60
        ));

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Microsoft token cURL error: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid JSON from Microsoft token endpoint.');
        }
        if ($status < 200 || $status >= 300 || isset($json['error'])) {
            $message = isset($json['error_description']) ? $json['error_description']
                : (isset($json['error']) ? $json['error'] : $body);
            throw new RuntimeException('Microsoft token error HTTP ' . $status . ': ' . $message);
        }
        return $json;
    }

    private function encrypt($plainText)
    {
        if ($plainText === '') {
            return '';
        }
        $key = hash('sha256', $this->encryptionSecret(), true);
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new RuntimeException('Unable to encrypt Microsoft token.');
        }
        return base64_encode($iv . $tag . $cipherText);
    }

    private function decrypt($encoded)
    {
        if (!$encoded) {
            return '';
        }
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 29) {
            throw new RuntimeException('Stored Microsoft token is invalid.');
        }
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $cipherText = substr($data, 28);
        $plainText = openssl_decrypt(
            $cipherText, 'aes-256-gcm', hash('sha256', $this->encryptionSecret(), true),
            OPENSSL_RAW_DATA, $iv, $tag
        );
        if ($plainText === false) {
            throw new RuntimeException('Unable to decrypt stored Microsoft token.');
        }
        return $plainText;
    }

    private function encryptionSecret()
    {
        if (defined('SECRET_SALT') && SECRET_SALT) {
            return SECRET_SALT . '|' . $this->clientId;
        }
        throw new RuntimeException('osTicket SECRET_SALT is unavailable.');
    }

    private function authorizeEndpoint()
    {
        return 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/authorize';
    }

    private function tokenEndpoint()
    {
        return 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
    }
}
