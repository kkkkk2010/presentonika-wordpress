<?php
/**
 * Plugin Name: VK ID Auth
 * Description: Авторизация через VK ID OneTap по OAuth 2.1 с PKCE.
 * Version: 1.2.1
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/account-linking.php';

class VKID_Auth_Shortcodes_Logged {

    const VK_TOKEN_URL    = 'https://id.vk.ru/oauth2/auth';
    const VK_USERINFO_URL = 'https://id.vk.ru/oauth2/user_info';

    const META_VKID_ID            = 'vk_id';
    const META_VKID_ACCESS_TOKEN  = 'vkid_access_token';
    const META_VKID_REFRESH_TOKEN = 'vkid_refresh_token';
    const META_VKID_EXPIRES_AT    = 'vkid_expires_at';

    public function __construct() {
        add_action('template_redirect', [$this, 'handle_oauth_callback'], 1);
        add_action('wp_ajax_vk_id_auth',        [$this, 'ajax_vk_id_auth']);
        add_action('wp_ajax_nopriv_vk_id_auth', [$this, 'ajax_vk_id_auth']);
        add_action('wp_ajax_vkid_oauth_transaction',        [$this, 'ajax_oauth_transaction']);
        add_action('wp_ajax_nopriv_vkid_oauth_transaction', [$this, 'ajax_oauth_transaction']);
        add_action('wp_ajax_vkid_link_transaction', [$this, 'ajax_link_transaction']);
        add_action('wp_ajax_vkid_link_account', [$this, 'ajax_link_account']);
        add_action('wp_ajax_vkid_unlink_account', [$this, 'ajax_unlink_account']);

        add_shortcode('vkid_login',    [$this, 'sc_login']);
        add_shortcode('cabinet_guard', [$this, 'sc_cabinet_guard']);
        add_shortcode('vkid_cabinet',  [$this, 'sc_cabinet']);
        add_shortcode('vkid_account_link', [$this, 'sc_account_link']);
        add_shortcode('vkid_logout',   [$this, 'sc_logout_button']);
        add_shortcode('vkid_debug',    [$this, 'sc_debug']);
    }

    /* ---------------------------
     * Logging
     * --------------------------- */

    private function log($tag, array $data = []) {
        if (function_exists('pnk_log')) {
            pnk_log('vkid.' . strtolower((string)$tag), $data, 'info');
            return;
        }
        error_log('[VKID][' . sanitize_key((string)$tag) . ']');
    }

    private function mask($s, $keep_left = 4, $keep_right = 4) {
        $s = (string)$s;
        $n = strlen($s);
        if ($n <= ($keep_left + $keep_right + 3)) return str_repeat('*', $n);
        return substr($s, 0, $keep_left) . '...' . substr($s, -$keep_right);
    }

    /**
     * Lightweight rate-limit for admin-ajax action (best-effort).
     * NOTE: This is not a replacement for nginx rate limiting.
     */
    private function rate_limit_or_die(string $bucket, int $maxRequests, int $windowSeconds): void {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (function_exists('pnk_rate_limit_allow')) {
            [$allowed, $retry_after] = pnk_rate_limit_allow(
                'vkid:' . $bucket . ':' . $ip . ':' . hash('sha256', $ua),
                $maxRequests,
                $windowSeconds
            );
            if ($allowed) return;

            $this->log('RATE_LIMIT', [
                'bucket' => $bucket,
                'ip' => $ip,
                'max' => $maxRequests,
                'window' => $windowSeconds,
                'retry_after' => $retry_after,
            ]);
            wp_send_json_error('Too many requests. Try again in a minute.', 429);
        }

        $key = 'vkid_rl_' . $bucket . '_' . md5($ip . '|' . $ua);
        $now = time();

        $data = get_transient($key);
        if (!is_array($data) || !isset($data['t']) || !isset($data['n']) || ($now - (int)$data['t']) >= $windowSeconds) {
            set_transient($key, ['t' => $now, 'n' => 1], $windowSeconds);
            return;
        }

        $n = (int)$data['n'] + 1;
        // keep remaining TTL roughly aligned with the original window start
        $ttl = max(1, $windowSeconds - ($now - (int)$data['t']));

        if ($n > $maxRequests) {
            $this->log('RATE_LIMIT', [
                'bucket' => $bucket,
                'ip' => $ip,
                'max' => $maxRequests,
                'window' => $windowSeconds,
                'n' => $n,
            ]);
            wp_send_json_error('Too many requests. Try again in a minute.', 429);
        }

        $data['n'] = $n;
        set_transient($key, $data, $ttl);
    }

    /* ---------------------------
     * Helpers
     * --------------------------- */

    private function cfg($key) {
        return (defined($key) && constant($key)) ? constant($key) : null;
    }

    private function cfg_snapshot() {
        $cid = $this->cfg('VKID_CLIENT_ID');
        $sec = $this->cfg('VKID_CLIENT_SECRET');
        $ru  = $this->cfg('VKID_REDIRECT_URI');

        return [
            'client_id'     => $cid,
            'redirect_uri'  => $ru,
            'has_secret'    => (bool)$sec,
            'secret_len'    => $sec ? strlen((string)$sec) : 0,
            'site_url'      => site_url(),
            'home_url'      => home_url(),
        ];
    }

    private function require_cfg_or_die_json() {
        $cid = $this->cfg('VKID_CLIENT_ID');
        $sec = $this->cfg('VKID_CLIENT_SECRET');
        $ru  = $this->cfg('VKID_REDIRECT_URI');

        $this->log('CFG_CHECK', $this->cfg_snapshot());

        if (!$cid || !$sec || !$ru) {
            wp_send_json_error('VKID config missing (VKID_CLIENT_ID / VKID_CLIENT_SECRET / VKID_REDIRECT_URI).', 500);
        }
        return [$cid, $sec, $ru];
    }

    /* ---------------------------
     * OAuth state helpers (anti-CSRF)
     * --------------------------- */

    private function issue_oauth_transaction(string $purpose = 'login', int $user_id = 0) {
        try {
            $state = bin2hex(random_bytes(24));
            $code_verifier = bin2hex(random_bytes(32));
        } catch (Throwable $error) {
            $this->log('STATE_RANDOM_FAILED', []);
            return new WP_Error('vkid_state_random_failed', 'Не удалось начать защищенную сессию входа.');
        }
        $key = 'vkid_state_' . hash('sha256', $state);

        $stored = set_transient($key, [
            'code_verifier' => $code_verifier,
            'created_at' => time(),
            'purpose' => $purpose,
            'user_id' => $user_id,
        ], 10 * MINUTE_IN_SECONDS);
        if (!$stored) {
            $this->log('STATE_STORE_FAILED', []);
            return new WP_Error('vkid_state_store_failed', 'Не удалось начать защищенную сессию входа.');
        }

        if (headers_sent() || !setcookie('vkid_oauth_state', $state, [
            'expires'  => time() + 10 * MINUTE_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ])) {
            delete_transient($key);
            $this->log('STATE_COOKIE_FAILED', []);
            return new WP_Error('vkid_state_cookie_failed', 'Не удалось начать защищенную сессию входа.');
        }

        return [
            'state' => $state,
            'code_verifier' => $code_verifier,
        ];
    }

    private function consume_oauth_transaction(string $state, string $expected_purpose = 'login', int $expected_user_id = 0) {
        $state = trim((string)$state);
        if ($state === '') return false;

        $cookie = isset($_COOKIE['vkid_oauth_state']) ? (string)$_COOKIE['vkid_oauth_state'] : '';
        if ($cookie === '' || !hash_equals($cookie, $state)) return false;

        $key = 'vkid_state_' . hash('sha256', $state);
        $lock_name = 'vkid_state_' . substr(hash('sha256', $state), 0, 24);
        if (!function_exists('pnk_try_lock') || !pnk_try_lock($lock_name, 30)) return false;

        try {
            $transaction = get_transient($key);
            delete_transient($key);

            if (!headers_sent()) {
                setcookie('vkid_oauth_state', '', [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            if (!is_array($transaction) || empty($transaction['code_verifier'])) return false;
            if (empty($transaction['created_at']) || (time() - (int)$transaction['created_at']) > 10 * MINUTE_IN_SECONDS) return false;
            $purpose = (string)($transaction['purpose'] ?? 'login');
            if (!hash_equals($expected_purpose, $purpose)) return false;
            if ($expected_user_id > 0 && (int)($transaction['user_id'] ?? 0) !== $expected_user_id) return false;
            return $transaction;
        } finally {
            pnk_release_lock($lock_name);
        }
    }


    private function http_post_form($url, array $fields, $log_tag = 'HTTP_POST') {
        $safe = $fields;

        foreach (['client_secret', 'access_token', 'refresh_token', 'code', 'id_token'] as $k) {
            if (isset($safe[$k])) $safe[$k] = $this->mask($safe[$k]);
        }
        if (isset($safe['code_verifier'])) $safe['code_verifier'] = '[len:' . strlen((string)$safe['code_verifier']) . ']';
        if (isset($safe['device_id']))     $safe['device_id'] = $this->mask($safe['device_id']);

        $this->log($log_tag . '_REQUEST', [
            'url' => $url,
            'field_names' => array_keys($safe),
        ]);

        $resp = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => $fields,
        ]);

        if (is_wp_error($resp)) {
            $this->log($log_tag . '_WP_ERROR', [
                'code' => $resp->get_error_code(),
            ]);
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $this->log($log_tag . '_BAD_JSON', [
                'http_code' => $code,
            ]);
            return new WP_Error('vkid_bad_json', 'VKID returned non-JSON', ['http_code' => $code]);
        }

        $this->log($log_tag . '_RESPONSE', [
            'http_code' => $code,
            'response_keys' => array_keys($json),
            'has_error' => !empty($json['error']),
            'error_code' => sanitize_key((string)($json['error'] ?? '')),
        ]);

        $json['_http_code'] = $code;
        return $json;
    }

    private function exchange_code_for_token($code, $device_id, $code_verifier) {
        [$client_id, $client_secret, $redirect_uri] = $this->require_cfg_or_die_json();

        $this->log('TOKEN_PAYLOAD', [
            'client_id'      => $client_id,
            'redirect_uri'   => $redirect_uri,
            'has_secret'     => (bool)$client_secret,
            'secret_len'     => $client_secret ? strlen((string)$client_secret) : 0,
            'code_len'       => strlen((string)$code),
            'device_id_mask' => $this->mask($device_id),
            'verifier_len'   => strlen((string)$code_verifier),
        ]);

        $res = $this->http_post_form(self::VK_TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri'  => $redirect_uri,
            'code'          => $code,
            'device_id'     => $device_id,
            'code_verifier' => $code_verifier,
        ], 'VK_TOKEN');

        if (is_wp_error($res)) return $res;
        if (!empty($res['error'])) {
            return new WP_Error('vkid_token_error', $res['error_description'] ?? $res['error'], $res);
        }
        return $res;
    }

    /**
     * FIX: VK user_info требует client_id (и access_token).
     */
    private function get_user_info($access_token) {
        // Берём client_id из конфига (secret/redirect тут не нужны, но проверку конфига можно сохранить)
        [$client_id] = $this->require_cfg_or_die_json();

        $this->log('USERINFO_PAYLOAD', [
            'client_id' => $client_id,
            'access_token_mask' => $this->mask($access_token),
        ]);

        $res = $this->http_post_form(self::VK_USERINFO_URL, [
            'access_token' => $access_token,
            'client_id'    => $client_id,
        ], 'VK_USERINFO');

        if (is_wp_error($res)) return $res;

        if (!empty($res['error'])) {
            return new WP_Error('vkid_userinfo_error', $res['error_description'] ?? $res['error'], $res);
        }

        return $res;
    }

    private function upsert_wp_user($vk_id, $first_name, $last_name, $email = null) {
        global $wpdb;

        $vk_id = (string)$vk_id;
        $first_name = (string)$first_name;
        $last_name  = (string)$last_name;

        if ($vk_id === '' || $first_name === '') {
            return new WP_Error('vkid_bad_user', 'Missing vk_id/first_name');
        }

        $lock_name = 'vkid_login_' . substr(hash('sha256', $vk_id), 0, 32);
        if (!function_exists('pnk_try_lock') || !pnk_try_lock($lock_name, 30)) {
            return new WP_Error('vkid_login_busy', 'Вход уже выполняется. Повторите попытку.');
        }

        try {

        if (!$email) $email = 'vk_' . $vk_id . '@presentonika.ru';
        $display_name = trim($first_name . ' ' . $last_name);

        $existing_user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
            self::META_VKID_ID, $vk_id
        ));

        if ($existing_user_id) {
            $res = wp_update_user([
                'ID' => (int)$existing_user_id,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'display_name' => $display_name ?: $first_name,
            ]);
            if (is_wp_error($res)) return $res;

            $this->log('USER_UPSERT_EXISTING', [
                'wp_user_id' => (int)$existing_user_id,
                'vk_id' => $vk_id,
                'email' => $email,
            ]);
            return (int)$existing_user_id;
        }

        $u = get_user_by('email', $email);
        if ($u) {
            $this->log('USER_EMAIL_CONFLICT', [
                'wp_user_id' => (int)$u->ID,
            ]);
            return new WP_Error(
                'vkid_email_in_use',
                'Учетная запись с таким email уже существует. Автоматическая привязка VK ID запрещена.'
            );
        }

        $user_id = wp_insert_user([
            'user_login' => 'vk_user_' . $vk_id,
            'user_email' => $email,
            'user_pass'  => wp_generate_password(20, true),
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'display_name' => $display_name ?: $first_name,
            'role' => 'subscriber',
        ]);

        if (is_wp_error($user_id)) return $user_id;

        update_user_meta($user_id, self::META_VKID_ID, $vk_id);

        $this->log('USER_CREATED', [
            'wp_user_id' => (int)$user_id,
            'vk_id' => $vk_id,
            'email' => $email,
        ]);

        return (int)$user_id;
        } finally {
            pnk_release_lock($lock_name);
        }
    }

    private function save_tokens($user_id, array $token) {
        // Access and refresh tokens are intentionally not persisted.
        if (!empty($token['expires_in'])) {
            update_user_meta($user_id, self::META_VKID_EXPIRES_AT, time() + (int)$token['expires_in']);
        }

        $this->log('TOKENS_SAVED', [
            'wp_user_id' => (int)$user_id,
            'has_access_token' => !empty($token['access_token']),
            'has_refresh_token' => !empty($token['refresh_token']),
            'expires_in' => (int)($token['expires_in'] ?? 0),
        ]);
    }

    private function login_wp_user($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) return;
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true, is_ssl());
        do_action('wp_login', $user->user_login, $user);
        $this->log('WP_LOGIN_OK', ['wp_user_id' => (int)$user_id]);
    }

    private function complete_oauth_login(string $code, string $device_id, string $code_verifier) {
        if ($code === '' || $device_id === '' || $code_verifier === '') {
            return new WP_Error('vkid_missing_params', 'VK ID did not return all required authorization parameters.');
        }

        $token = $this->exchange_code_for_token($code, $device_id, $code_verifier);
        if (is_wp_error($token)) {
            return $token;
        }

        $access_token = $token['access_token'] ?? '';
        if ($access_token === '') {
            return new WP_Error('vkid_missing_token', 'VK ID did not return an access token.');
        }

        $info = $this->get_user_info($access_token);
        if (is_wp_error($info)) {
            return $info;
        }

        $user_data = $info['user'] ?? ($info['data']['user'] ?? ($info['data'] ?? null));
        if (!is_array($user_data)) {
            return new WP_Error('vkid_user_shape', 'VK ID returned an unexpected user profile response.');
        }

        $vk_id = $user_data['user_id'] ?? ($user_data['id'] ?? ($user_data['vk_id'] ?? null));
        $first_name = $user_data['first_name'] ?? '';
        $last_name = $user_data['last_name'] ?? '';
        $email = $user_data['email'] ?? null;

        $this->log('USERINFO_OK', [
            'vk_id' => (string)$vk_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'has_email' => (bool)$email,
        ]);

        if (!$vk_id || $first_name === '') {
            return new WP_Error('vkid_incomplete_profile', 'VK ID profile is missing the user ID or first name.');
        }

        $user_id = $this->upsert_wp_user($vk_id, $first_name, $last_name, $email);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $this->save_tokens($user_id, $token);
        $this->login_wp_user($user_id);

        return [
            'wp_user_id' => (int)$user_id,
            'vk_id' => (string)$vk_id,
            'first_name' => (string)$first_name,
            'last_name' => (string)$last_name,
        ];
    }

    public function handle_oauth_callback() {
        if (is_user_logged_in()) {
            return;
        }

        $request_path = untrailingslashit((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $login_path = untrailingslashit((string)parse_url(home_url('/login/'), PHP_URL_PATH));
        if ($request_path !== $login_path) {
            return;
        }

        if (!empty($_GET['error']) && empty($_GET['vkid_error'])) {
            wp_safe_redirect(add_query_arg('vkid_error', 'cancelled', home_url('/login/')));
            exit;
        }

        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if ($code === '') {
            return;
        }

        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $device_id = sanitize_text_field(wp_unslash($_GET['device_id'] ?? ''));
        $transaction = $this->consume_oauth_transaction($state);

        if ($transaction === false) {
            $this->log('REDIRECT_BAD_STATE', ['state_len' => strlen($state)]);
            wp_safe_redirect(add_query_arg('vkid_error', 'state', home_url('/login/')));
            exit;
        }

        $result = $this->complete_oauth_login($code, $device_id, (string)$transaction['code_verifier']);
        if (is_wp_error($result)) {
            $this->log('REDIRECT_AUTH_ERROR', [
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ]);
            wp_safe_redirect(add_query_arg('vkid_error', 'auth', home_url('/login/')));
            exit;
        }

        wp_safe_redirect(home_url('/cabinet/'));
        exit;
    }

    /* ---------------------------
     * AJAX compatibility endpoint
     * --------------------------- */

    public function ajax_oauth_transaction() {
        $this->rate_limit_or_die('oauth_transaction', 10, 60);

        $nonce = (string)($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'vkid_auth_nonce')) {
            $this->log('TRANSACTION_BAD_NONCE', []);
            wp_send_json_error(['message' => 'Сессия страницы входа устарела.'], 403);
        }

        $client_id = $this->cfg('VKID_CLIENT_ID');
        $client_secret = $this->cfg('VKID_CLIENT_SECRET');
        $redirect_uri = $this->cfg('VKID_REDIRECT_URI');
        if (!$client_id || !$client_secret || !$redirect_uri) {
            $this->log('TRANSACTION_CONFIG_MISSING', []);
            wp_send_json_error(['message' => 'VK ID временно недоступен.'], 503);
        }

        $transaction = $this->issue_oauth_transaction();
        if (is_wp_error($transaction)) {
            wp_send_json_error(['message' => $transaction->get_error_message()], 500);
        }

        wp_send_json_success([
            'state' => (string)$transaction['state'],
            'codeVerifier' => (string)$transaction['code_verifier'],
            'expiresIn' => 10 * MINUTE_IN_SECONDS,
        ]);
    }

    public function ajax_link_transaction() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Сначала войдите в личный кабинет.'], 401);
        }
        $this->rate_limit_or_die('link_transaction', 6, 60);
        $nonce = (string)($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'vkid_link_nonce')) {
            wp_send_json_error(['message' => 'Сессия кабинета устарела. Обновите страницу.'], 403);
        }
        if (!$this->cfg('VKID_CLIENT_ID') || !$this->cfg('VKID_CLIENT_SECRET') || !$this->cfg('VKID_REDIRECT_URI')) {
            wp_send_json_error(['message' => 'VK ID временно недоступен.'], 503);
        }

        $transaction = $this->issue_oauth_transaction('link', get_current_user_id());
        if (is_wp_error($transaction)) {
            wp_send_json_error(['message' => $transaction->get_error_message()], 500);
        }
        wp_send_json_success([
            'state' => (string)$transaction['state'],
            'codeVerifier' => (string)$transaction['code_verifier'],
            'expiresIn' => 10 * MINUTE_IN_SECONDS,
        ]);
    }

    private function complete_oauth_link(string $code, string $device_id, string $code_verifier, int $user_id) {
        if ($code === '' || $device_id === '' || $code_verifier === '' || $user_id <= 0) {
            return new WP_Error('vkid_link_missing_params', 'VK ID did not return all required authorization parameters.');
        }
        $token = $this->exchange_code_for_token($code, $device_id, $code_verifier);
        if (is_wp_error($token)) return $token;
        $access_token = (string)($token['access_token'] ?? '');
        if ($access_token === '') return new WP_Error('vkid_link_missing_token', 'VK ID did not return an access token.');

        $info = $this->get_user_info($access_token);
        if (is_wp_error($info)) return $info;
        $user_data = $info['user'] ?? ($info['data']['user'] ?? ($info['data'] ?? null));
        if (!is_array($user_data)) return new WP_Error('vkid_link_profile', 'VK ID returned an unexpected profile response.');
        $vk_id = (string)($user_data['user_id'] ?? ($user_data['id'] ?? ($user_data['vk_id'] ?? '')));
        $linked = pnk_vkid_link_account($user_id, $vk_id);
        if (is_wp_error($linked)) return $linked;
        $this->save_tokens($user_id, $token);
        $this->log('LINK_OK', ['wp_user_id' => $user_id]);
        return ['vk_id' => $vk_id];
    }

    public function ajax_link_account() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Сначала войдите в личный кабинет.'], 401);
        }
        $this->rate_limit_or_die('link_account', 8, 60);
        $nonce = (string)($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'vkid_link_nonce')) {
            wp_send_json_error(['message' => 'Сессия кабинета устарела. Обновите страницу.'], 403);
        }

        $user_id = get_current_user_id();
        $state = sanitize_text_field(wp_unslash($_POST['state'] ?? ''));
        $transaction = $this->consume_oauth_transaction($state, 'link', $user_id);
        if ($transaction === false) {
            wp_send_json_error(['message' => 'Сессия привязки устарела или уже использована.'], 403);
        }
        $code_verifier = sanitize_text_field(wp_unslash($_POST['code_verifier'] ?? ''));
        if (!hash_equals((string)$transaction['code_verifier'], $code_verifier)) {
            wp_send_json_error(['message' => 'Проверка защищённой сессии не пройдена.'], 403);
        }
        $result = $this->complete_oauth_link(
            sanitize_text_field(wp_unslash($_POST['code'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['device_id'] ?? '')),
            $code_verifier,
            $user_id
        );
        if (is_wp_error($result)) {
            $this->log('LINK_FAILED', ['wp_user_id' => $user_id, 'error_code' => $result->get_error_code()]);
            wp_send_json_error(['message' => $result->get_error_message()], 409);
        }
        wp_send_json_success($result);
    }

    public function ajax_unlink_account() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Сначала войдите в личный кабинет.'], 401);
        }
        $this->rate_limit_or_die('unlink_account', 5, 60);
        $nonce = (string)($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'vkid_link_nonce')) {
            wp_send_json_error(['message' => 'Сессия кабинета устарела. Обновите страницу.'], 403);
        }
        $user_id = get_current_user_id();
        $password = (string)wp_unslash($_POST['current_password'] ?? '');
        $result = pnk_vkid_unlink_account($user_id, $password);
        if (is_wp_error($result)) {
            $this->log('UNLINK_DENIED', ['wp_user_id' => $user_id, 'error_code' => $result->get_error_code()]);
            wp_send_json_error(['message' => $result->get_error_message()], 409);
        }
        $this->log('UNLINK_OK', ['wp_user_id' => $user_id]);
        wp_send_json_success(['message' => 'VK ID отвязан.']);
    }

    public function ajax_vk_id_auth() {
        $this->rate_limit_or_die('vk_id_auth', 20, 60);

        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'vkid_auth_nonce')) {
            $this->log('AJAX_BAD_NONCE', []);
            wp_send_json_error('Bad nonce', 403);
        }


        $state = sanitize_text_field(wp_unslash($_POST['state'] ?? ''));
        $transaction = $this->consume_oauth_transaction($state);
        if ($transaction === false) {
            $this->log('AJAX_BAD_STATE', ['state_len' => strlen((string)$state)]);
            wp_send_json_error('Bad OAuth state', 403);
        }

        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        $device_id = sanitize_text_field(wp_unslash($_POST['device_id'] ?? ''));
        $code_verifier = sanitize_text_field(wp_unslash($_POST['code_verifier'] ?? ''));

        if (!hash_equals((string)$transaction['code_verifier'], $code_verifier)) {
            $this->log('AJAX_BAD_VERIFIER', ['verifier_len' => strlen($code_verifier)]);
            wp_send_json_error('Bad PKCE verifier', 403);
        }

        $result = $this->complete_oauth_login($code, $device_id, $code_verifier);
        if (is_wp_error($result)) {
            $this->log('AJAX_AUTH_ERROR', [
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ]);
            wp_send_json_error($result->get_error_message(), 401);
        }

        wp_send_json_success($result);
    }

    /* ---------------------------
     * UI styles (visual only)
     * --------------------------- */

    private function ui_styles_once() {
        static $printed = false;

        if ($printed) {
            return '';
        }

        $printed = true;

        return '<style>
'
            . '.vkid-shell{max-width:720px;margin:24px auto;padding:0 14px;box-sizing:border-box;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111;}
'
            . '.vkid-card{box-sizing:border-box;background:#fff;border:1px solid #e6e6e6;border-radius:20px;box-shadow:0 18px 44px rgba(0,0,0,.06);padding:22px;overflow:hidden;}
'
            . '.vkid-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px;}
'
            . '.vkid-title{margin:0;font-size:24px;line-height:1.1;letter-spacing:-.02em;color:#111;font-weight:700;}
'
            . '.vkid-sub{margin:8px 0 0;font-size:14px;line-height:1.5;color:#666;}
'
            . '.vkid-badge{display:inline-flex;align-items:center;gap:8px;border:1px solid #e6e6e6;border-radius:999px;padding:8px 12px;background:#f7f7f7;color:#111;font-size:12px;font-weight:600;white-space:nowrap;}
'
            . '.vkid-badge span{color:#666;font-weight:500;}
'
            . '.vkid-widget-wrap{border:1px solid #ececec;border-radius:18px;padding:18px;background:linear-gradient(180deg,#fafafa 0%,#fff 100%);box-sizing:border-box;}
'
            . '.vkid-widget-wrap #VkIdSdkOneTap{min-height:48px;}
'
            . '.vkid-error{margin-top:12px;padding:10px 12px;border:1px solid #f2c5ca;border-radius:8px;color:#8f2632;background:#fff4f5;font-size:13px;line-height:1.45;}
'
            . '.vkid-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:18px;}
'
            . '.vkid-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:46px;padding:0 18px;border-radius:14px;border:1px solid #111;background:#111;color:#fff;text-decoration:none;font-size:14px;font-weight:600;line-height:1;transition:transform .15s ease,box-shadow .15s ease,background .15s ease,color .15s ease,border-color .15s ease;box-sizing:border-box;}
'
            . '.vkid-btn:hover{transform:translateY(-1px);box-shadow:0 10px 24px rgba(0,0,0,.08);text-decoration:none;}
'
            . '.vkid-btn--ghost{background:#fff;color:#111;border-color:#dcdcdc;}
'
            . '.vkid-grid{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:center;}
'
            . '.vkid-user{min-width:0;}
'
            . '.vkid-name{margin:0;font-size:22px;line-height:1.15;font-weight:700;letter-spacing:-.02em;color:#111;}
'
            . '.vkid-meta{margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
'
            . '.vkid-note{margin:8px 0 0;font-size:14px;line-height:1.5;color:#666;}
'
            . '@media (max-width:640px){.vkid-shell{padding:0 10px;}.vkid-card{padding:18px;border-radius:18px;}.vkid-grid{grid-template-columns:1fr;align-items:flex-start;}.vkid-title{font-size:22px;}.vkid-name{font-size:20px;}.vkid-btn{width:100%;}}
'
            . '</style>';
    }

    /* ---------------------------
     * Shortcodes
     * --------------------------- */

    private function account_link_control(string $vk_id): string {
        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('vkid_link_nonce');
        $sdk_url = plugins_url('assets/js/vkid-sdk-2.6.1.js', __FILE__);
        ob_start(); ?>
        <div class="vkid-widget-wrap" style="margin-top:18px;">
          <h4 style="margin:0 0 8px;font-size:16px;">Безопасность входа</h4>
          <?php if ($vk_id !== ''): ?>
            <p class="vkid-note">VK ID привязан. Для отвязки подтвердите действующий пароль — так аккаунт не останется без способа входа.</p>
            <form id="vkidUnlinkForm" class="vkid-actions" autocomplete="off">
              <input id="vkidCurrentPassword" type="password" autocomplete="current-password" placeholder="Текущий пароль" required style="min-height:44px;padding:0 12px;border:1px solid #dcdcdc;border-radius:12px;box-sizing:border-box;">
              <button class="vkid-btn vkid-btn--ghost" type="submit">Отвязать VK ID</button>
            </form>
          <?php else: ?>
            <p class="vkid-note">Привяжите VK ID из текущего кабинета. Совпадение email никогда не используется для автоматической привязки.</p>
            <div class="vkid-actions"><button id="vkidLinkStart" class="vkid-btn" type="button">Привязать VK ID</button></div>
            <div id="vkidLinkWidget" style="margin-top:14px;" hidden></div>
          <?php endif; ?>
          <div id="vkidLinkMessage" class="vkid-error" role="status" hidden></div>
        </div>
        <script>
        (function(){
          const ajaxUrl = <?php echo wp_json_encode($ajax); ?>;
          const nonce = <?php echo wp_json_encode($nonce); ?>;
          const message = document.getElementById('vkidLinkMessage');
          function show(text, ok) {
            if (!message) return;
            message.textContent = text;
            message.hidden = false;
            message.style.borderColor = ok ? '#b9dfc4' : '#f2c5ca';
            message.style.background = ok ? '#f2fff5' : '#fff4f5';
            message.style.color = ok ? '#216b35' : '#8f2632';
          }
          async function post(action, values) {
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', nonce);
            Object.entries(values || {}).forEach(([key, value]) => body.append(key, String(value)));
            const response = await fetch(ajaxUrl, {method:'POST', body, credentials:'same-origin', cache:'no-store'});
            const result = await response.json();
            if (!response.ok || !result.success) {
              throw new Error(result && result.data && result.data.message ? result.data.message : 'Операция не выполнена.');
            }
            return result.data;
          }
          const unlink = document.getElementById('vkidUnlinkForm');
          if (unlink) unlink.addEventListener('submit', async (event) => {
            event.preventDefault();
            const password = document.getElementById('vkidCurrentPassword');
            try {
              await post('vkid_unlink_account', {current_password: password ? password.value : ''});
              show('VK ID отвязан. Страница обновляется…', true);
              window.setTimeout(() => window.location.reload(), 500);
            } catch (error) { show(error.message || 'Не удалось отвязать VK ID.', false); }
          });
          const start = document.getElementById('vkidLinkStart');
          if (!start) return;
          start.addEventListener('click', async () => {
            start.disabled = true;
            try {
              if (!window.VKIDSDK) {
                await new Promise((resolve, reject) => {
                  const script = document.createElement('script');
                  script.src = <?php echo wp_json_encode($sdk_url); ?>;
                  script.onload = resolve;
                  script.onerror = () => reject(new Error('Не удалось загрузить локальный VK ID SDK.'));
                  document.head.appendChild(script);
                });
              }
              const transaction = await post('vkid_link_transaction');
              const VKID = window.VKIDSDK;
              const container = document.getElementById('vkidLinkWidget');
              container.hidden = false;
              container.replaceChildren();
              VKID.Config.init({
                app: <?php echo (int)($this->cfg('VKID_CLIENT_ID') ?: 0); ?>,
                redirectUrl: <?php echo wp_json_encode((string)($this->cfg('VKID_REDIRECT_URI') ?: home_url('/login/'))); ?>,
                state: transaction.state,
                codeVerifier: transaction.codeVerifier,
                scope: 'email',
                responseMode: VKID.ConfigResponseMode.Callback,
              });
              new VKID.OneTap().render({container, styles:{width:Math.min(360, container.clientWidth || 360), height:44, borderRadius:8}})
                .on(VKID.WidgetEvents.ERROR, () => show('VK ID не завершил привязку.', false))
                .on(VKID.OneTapInternalEvents.LOGIN_SUCCESS, async (payload) => {
                  try {
                    await post('vkid_link_account', {
                      state: transaction.state,
                      code: payload.code || '',
                      device_id: payload.device_id || '',
                      code_verifier: transaction.codeVerifier,
                    });
                    show('VK ID привязан. Страница обновляется…', true);
                    window.setTimeout(() => window.location.reload(), 500);
                  } catch (error) { show(error.message || 'Не удалось привязать VK ID.', false); }
                });
            } catch (error) {
              show(error.message || 'Не удалось начать привязку VK ID.', false);
              start.disabled = false;
            }
          });
        })();
        </script>
        <?php
        return (string)ob_get_clean();
    }

    public function sc_login($atts = []) {

        if (is_user_logged_in()) {
            return '<script>location.href=' . json_encode(home_url('/cabinet/')) . ';</script>';
        }

        $app_id   = (int)($this->cfg('VKID_CLIENT_ID') ?: 0);
        $redirect = (string)($this->cfg('VKID_REDIRECT_URI') ?: home_url('/login/'));
        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('vkid_auth_nonce');
        $sdk_urls = [plugins_url('assets/js/vkid-sdk-2.6.1.js', __FILE__)];
        $requested_redirect = sanitize_url(wp_unslash($_GET['redirect_to'] ?? ''));
        $after_login = wp_validate_redirect($requested_redirect, home_url('/cabinet/'));

        $error_key = sanitize_key(wp_unslash($_GET['vkid_error'] ?? ''));
        $error_messages = [
            'cancelled' => 'Вход отменён. Попробуйте ещё раз, когда будете готовы.',
            'state' => 'Сессия входа устарела. Обновите страницу и повторите попытку.',
            'auth' => 'VK ID не завершил вход. Попробуйте ещё раз через несколько секунд.',
        ];
        $error_message = $error_messages[$error_key] ?? '';

        $this->log('SC_LOGIN_RENDER', [
            'app_id' => $app_id,
            'redirect' => $redirect,
        ]);

        ob_start(); ?>
        <?php echo $this->ui_styles_once(); ?>
        <section class="vkid-shell">
            <div class="vkid-card">
                <div class="vkid-head">
                    <div>
                        <h2 class="vkid-title">Вход в личный кабинет</h2>
                        <p class="vkid-sub">Авторизуйтесь через VK ID, чтобы открыть кабинет, историю генераций и управление презентациями.</p>
                    </div>
                    <div class="vkid-badge"><span>Способ входа</span> VK ID</div>
                </div>

                <div class="vkid-widget-wrap">
                    <div id="VkIdSdkOneTap"></div>
                </div>
                <?php if ($error_message !== ''): ?><div class="vkid-error" role="alert"><?php echo esc_html($error_message); ?></div><?php endif; ?>
                <div class="vkid-error" id="vkidClientError" role="alert" hidden>Не удалось загрузить VK ID. Проверьте соединение и обновите страницу.</div>
            </div>
        </section>

        <script>
        (function(){
          function loadScript(src, timeoutMs) {
            return new Promise((resolve, reject) => {
              const s = document.createElement('script');
              const timeout = window.setTimeout(() => {
                s.remove();
                reject(new Error('VK ID SDK load timed out'));
              }, timeoutMs);
              s.src = src;
              s.async = true;
              s.onload = () => {
                window.clearTimeout(timeout);
                resolve();
              };
              s.onerror = () => {
                window.clearTimeout(timeout);
                s.remove();
                reject(new Error('VK ID SDK load failed'));
              };
              document.head.appendChild(s);
            });
          }

          async function loadVKIDSDK() {
            if (window.VKIDSDK) return;

            const sources = <?php echo wp_json_encode($sdk_urls, JSON_UNESCAPED_SLASHES); ?>;
            let lastError = null;
            for (const source of sources) {
              try {
                await loadScript(source, 8000);
                if (window.VKIDSDK) return;
                lastError = new Error('VK ID SDK did not expose its API');
              } catch (error) {
                lastError = error;
              }
            }
            throw lastError || new Error('VK ID SDK is unavailable');
          }

          function showClientError(error) {
            console.error('VK ID initialization failed', error);
            const message = document.getElementById('vkidClientError');
            if (message) {
              if (typeof error === 'string' && error) message.textContent = error;
              message.hidden = false;
            }
          }

          async function initVKID() {
            try {
              if (!window.VKIDSDK) {
                await loadVKIDSDK();
              }

              const transactionForm = new FormData();
              transactionForm.append('action', 'vkid_oauth_transaction');
              transactionForm.append('nonce', <?php echo json_encode($nonce); ?>);
              const transactionResponse = await fetch(<?php echo json_encode($ajax); ?>, {
                method: 'POST',
                body: transactionForm,
                credentials: 'same-origin',
                cache: 'no-store',
              });
              const transactionResult = await transactionResponse.json();
              if (!transactionResponse.ok || !transactionResult.success || !transactionResult.data) {
                const message = transactionResult && transactionResult.data && transactionResult.data.message
                  ? transactionResult.data.message
                  : 'Не удалось начать защищенную сессию входа.';
                throw new Error(message);
              }
              const state = String(transactionResult.data.state || '');
              const codeVerifier = String(transactionResult.data.codeVerifier || '');
              if (!state || !codeVerifier) throw new Error('Сервер вернул неполные параметры входа.');

              const VKID = window.VKIDSDK;
              const container = document.getElementById('VkIdSdkOneTap');
              if (!VKID || !container) throw new Error('VK ID SDK or container is unavailable');

              VKID.Config.init({
                app: <?php echo (int)$app_id; ?>,
                redirectUrl: <?php echo json_encode($redirect); ?>,
                state,
                codeVerifier,
                scope: 'email',
                responseMode: VKID.ConfigResponseMode.Callback,
              });

              const oneTap = new VKID.OneTap();
              oneTap.render({
                container,
                styles: { width: Math.min(360, container.clientWidth || 360), height: 44, borderRadius: 8 },
              })
                .on(VKID.WidgetEvents.ERROR, showClientError)
                .on(VKID.OneTapInternalEvents.LOGIN_SUCCESS, async (payload) => {
                  try {
                    const formData = new FormData();
                    formData.append('action', 'vk_id_auth');
                    formData.append('nonce', <?php echo json_encode($nonce); ?>);
                    formData.append('state', state);
                    formData.append('code', payload.code || '');
                    formData.append('device_id', payload.device_id || '');
                    formData.append('code_verifier', codeVerifier);

                    const response = await fetch(<?php echo json_encode($ajax); ?>, {
                      method: 'POST',
                      body: formData,
                      credentials: 'same-origin',
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                      const detail = typeof result.data === 'string'
                        ? result.data
                        : (result.data && result.data.message) || 'Не удалось завершить вход через VK ID.';
                      throw new Error(detail);
                    }
                    window.location.assign(<?php echo json_encode($after_login); ?>);
                  } catch (error) {
                    showClientError(error && error.message ? error.message : 'Не удалось завершить вход через VK ID.');
                  }
                });
            } catch (error) {
              showClientError(error);
            }
          }

          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initVKID);
          } else {
            initVKID();
          }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function sc_cabinet_guard() {
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/'));
            exit;
        }
        return '';
    }

    public function sc_cabinet() {
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/'));
            exit;
        }

        $u = wp_get_current_user();
        $vk_id = get_user_meta($u->ID, self::META_VKID_ID, true);

        $out  = $this->ui_styles_once();
        $out .= '<section class="vkid-shell">';
        $out .= '<div class="vkid-card">';
        $out .= '<div class="vkid-grid">';
        $out .= '<div class="vkid-user">';
        $out .= '<div class="vkid-head" style="margin-bottom:0;">';
        $out .= '<div>';
        $out .= '<h3 class="vkid-name">Добро пожаловать, ' . esc_html($u->display_name) . '!</h3>';
        $out .= '<p class="vkid-note">Ты авторизован через VK ID. Здесь начинается работа с кабинетом и презентациями.</p>';
        $out .= '</div>';
        if ($vk_id) {
            $out .= '<div class="vkid-badge"><span>ID VK</span> ' . esc_html($vk_id) . '</div>';
        }
        $out .= '</div>';
        $out .= '</div>';
        $out .= '<div class="vkid-actions">';
        $out .= do_shortcode('[vkid_logout]');
        $out .= '</div>';
        $out .= '</div>';
        $out .= $this->account_link_control((string)$vk_id);
        $out .= '</div>';
        $out .= '</section>';

        return $out;
    }

    public function sc_account_link(): string {
        if (!is_user_logged_in()) return '';
        $user = wp_get_current_user();
        $vk_id = (string)get_user_meta($user->ID, self::META_VKID_ID, true);
        return $this->ui_styles_once() . $this->account_link_control($vk_id);
    }

    public function sc_logout_button() {
        if (!is_user_logged_in()) return '';
        $url = wp_logout_url(home_url('/login/'));
        return $this->ui_styles_once() . '<a class="vkid-btn vkid-btn--ghost" href="' . esc_url($url) . '">Выйти</a>';
    }

    public function sc_debug() {
        if (!current_user_can('manage_options')) return '';
        $snap = $this->cfg_snapshot();

        $html  = '<div style="padding:12px;border:1px solid #ddd;border-radius:8px;margin:12px 0;">';
        $html .= '<h4 style="margin:0 0 8px;">VKID Debug</h4>';
        $html .= '<pre style="white-space:pre-wrap;margin:0;">' . esc_html(wp_json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
        $html .= '</div>';

        return $html;
    }
}

new VKID_Auth_Shortcodes_Logged();
