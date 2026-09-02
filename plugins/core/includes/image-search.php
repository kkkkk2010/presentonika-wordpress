<?php
if (!defined('ABSPATH')) { exit; }

const PNK_IMAGE_SEARCH_BASIC_QUOTA = 8;
const PNK_IMAGE_SEARCH_PREMIUM_QUOTA = 12;
const PNK_IMAGE_SEARCH_EXTRA_COST = 1;

function pnk_image_search_plan_from_meta(int $user_id): string {
    foreach (['_pnk_active_plan', '_pnk_plan', 'pnk_plan', 'subscription_plan'] as $key) {
        $value = sanitize_key((string) get_user_meta($user_id, $key, true));
        if (in_array($value, ['optimal', 'professional', 'premium'], true)) return 'premium';
        if ($value === 'basic') return 'basic';
    }
    return '';
}

function pnk_image_search_plan_from_orders(int $user_id): string {
    if (!function_exists('wc_get_orders')) return '';

    try {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['processing', 'completed'],
            'limit' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_created' => '>' . (time() - 32 * DAY_IN_SECONDS),
            'return' => 'objects',
        ]);
    } catch (Throwable $error) {
        pnk_log_exception('image_search.plan_lookup_failed', $error, ['uid' => $user_id]);
        return '';
    }

    $saw_basic = false;
    foreach ($orders as $order) {
        if (!is_object($order) || !method_exists($order, 'get_items')) continue;
        foreach ($order->get_items() as $item) {
            $parts = [method_exists($item, 'get_name') ? (string) $item->get_name() : ''];
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if ($product) {
                if (method_exists($product, 'get_slug')) $parts[] = (string) $product->get_slug();
                if (method_exists($product, 'get_sku')) $parts[] = (string) $product->get_sku();
            }
            $haystack = function_exists('mb_strtolower')
                ? mb_strtolower(implode(' ', $parts), 'UTF-8')
                : strtolower(implode(' ', $parts));
            if (
                strpos($haystack, 'optimal') !== false ||
                strpos($haystack, 'professional') !== false ||
                strpos($haystack, 'premium') !== false ||
                strpos($haystack, 'оптималь') !== false ||
                strpos($haystack, 'профессиональ') !== false ||
                strpos($haystack, 'премиум') !== false
            ) {
                return 'premium';
            }
            if (strpos($haystack, 'basic') !== false || strpos($haystack, 'базов') !== false) {
                $saw_basic = true;
            }
        }
    }
    return $saw_basic ? 'basic' : '';
}

function pnk_get_image_search_plan(int $user_id): string {
    $plan = pnk_image_search_plan_from_meta($user_id);
    if ($plan !== '') return $plan;
    $plan = pnk_image_search_plan_from_orders($user_id);
    return $plan !== '' ? $plan : 'basic';
}

function pnk_image_search_usage_option_key(int $presentation_id, string $placeholder_key): string {
    return 'pnk_img_search_' . substr(hash('sha256', $presentation_id . "\0" . $placeholder_key), 0, 40);
}

function pnk_image_search_usage_payload(
    bool $allowed,
    bool $requires_confirmation,
    bool $charged,
    int $quota,
    int $used,
    int $points_balance,
    string $plan,
    string $message = ''
): array {
    return [
        'allowed' => $allowed,
        'requiresConfirmation' => $requires_confirmation,
        'charged' => $charged,
        'cost' => PNK_IMAGE_SEARCH_EXTRA_COST,
        'quota' => $quota,
        'used' => $used,
        'remaining' => max(0, $quota - $used),
        'pointsBalance' => max(0, $points_balance),
        'plan' => $plan,
        'message' => $message,
    ];
}

function pnk_authorize_image_search_usage(
    int $user_id,
    int $presentation_id,
    string $placeholder_key,
    string $usage_key,
    bool $confirm_token_charge
): array {
    $plan = pnk_get_image_search_plan($user_id);
    $quota = $plan === 'premium' ? PNK_IMAGE_SEARCH_PREMIUM_QUOTA : PNK_IMAGE_SEARCH_BASIC_QUOTA;
    $option_key = pnk_image_search_usage_option_key($presentation_id, $placeholder_key);
    $lock_name = 'img_search_' . substr(hash('sha256', $option_key), 0, 32);

    if (!pnk_try_lock($lock_name, 20)) {
        return pnk_image_search_usage_payload(false, false, false, $quota, 0, pnk_get_user_points_balance($user_id), $plan, 'Поиск уже выполняется. Попробуйте ещё раз.');
    }

    try {
        $ledger = get_option($option_key, []);
        if (!is_array($ledger) || (int) ($ledger['userId'] ?? 0) !== $user_id || (int) ($ledger['presentationId'] ?? 0) !== $presentation_id) {
            $ledger = [
                'version' => 1,
                'userId' => $user_id,
                'presentationId' => $presentation_id,
                'placeholderKey' => $placeholder_key,
                'items' => [],
            ];
        }
        $items = is_array($ledger['items'] ?? null) ? $ledger['items'] : [];
        $used = count($items);
        $balance = pnk_get_user_points_balance($user_id);

        if (isset($items[$usage_key])) {
            $was_charged = !empty($items[$usage_key]['charged']);
            return pnk_image_search_usage_payload(true, false, $was_charged, $quota, $used, $balance, $plan);
        }

        if ($used < $quota) {
            $items[$usage_key] = ['charged' => false, 'createdAt' => time()];
            $ledger['items'] = $items;
            $ledger['updatedAt'] = time();
            update_option($option_key, $ledger, false);
            return pnk_image_search_usage_payload(true, false, false, $quota, $used + 1, $balance, $plan);
        }

        if (!$confirm_token_charge) {
            return pnk_image_search_usage_payload(false, true, false, $quota, $used, $balance, $plan, 'Требуется подтверждение списания 1 балла.');
        }

        if ($balance < PNK_IMAGE_SEARCH_EXTRA_COST || !function_exists('mycred')) {
            return pnk_image_search_usage_payload(false, false, false, $quota, $used, $balance, $plan, 'Недостаточно баллов для дополнительного поиска.');
        }

        $reference = 'image_search_extra';
        $reference_id = (int) hexdec(substr(hash('sha256', $presentation_id . "\0" . $placeholder_key . "\0" . $usage_key), 0, 7));
        $mycred = mycred('points');
        $already_charged = method_exists($mycred, 'has_entry')
            && $mycred->has_entry($reference, $reference_id, $user_id, null, 'points');
        if (!$already_charged) {
            $applied = $mycred->add_creds(
                $reference,
                $user_id,
                -PNK_IMAGE_SEARCH_EXTRA_COST,
                'Дополнительный поиск изображения для презентации #' . $presentation_id,
                $reference_id,
                ['presentation_id' => $presentation_id, 'placeholder_key' => $placeholder_key],
                'points'
            );
            if (!$applied) {
                return pnk_image_search_usage_payload(false, false, false, $quota, $used, $balance, $plan, 'Не удалось списать балл. Попробуйте ещё раз.');
            }
        }

        $items[$usage_key] = ['charged' => true, 'createdAt' => time(), 'referenceId' => $reference_id];
        $ledger['items'] = $items;
        $ledger['updatedAt'] = time();
        update_option($option_key, $ledger, false);

        return pnk_image_search_usage_payload(
            true,
            false,
            true,
            $quota,
            $used + 1,
            pnk_get_user_points_balance($user_id),
            $plan
        );
    } catch (Throwable $error) {
        pnk_log_exception('image_search.authorization_failed', $error, [
            'uid' => $user_id,
            'pid' => $presentation_id,
        ]);
        return pnk_image_search_usage_payload(false, false, false, $quota, 0, pnk_get_user_points_balance($user_id), $plan, 'Не удалось проверить лимит поиска.');
    } finally {
        pnk_release_lock($lock_name);
    }
}

function pnk_rest_authorize_image_search(WP_REST_Request $request) {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        return new WP_REST_Response(['ok' => true], 200);
    }
    if (!pnk_check_validate_bearer()) {
        return new WP_REST_Response(['ok' => false], 401);
    }
    $ip = pnk_get_client_ip();
    if ($rate_limited = pnk_rest_rate_limit_or_429('authorize-image-search:' . $ip, 120, 60)) {
        return $rate_limited;
    }

    $body = $request->get_json_params();
    $body = is_array($body) ? $body : [];
    $presentation_id = (int) ($body['presentationId'] ?? 0);
    $save_token = (string) ($body['saveToken'] ?? '');
    $placeholder_key = sanitize_text_field((string) ($body['placeholderKey'] ?? ''));
    $usage_key = strtolower(sanitize_text_field((string) ($body['usageKey'] ?? '')));
    $confirm_token_charge = !empty($body['confirmTokenCharge']);

    if (
        $presentation_id <= 0 ||
        $save_token === '' ||
        !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/', $placeholder_key) ||
        !preg_match('/^[a-f0-9]{64}$/', $usage_key)
    ) {
        return new WP_REST_Response(['ok' => false], 400);
    }

    [$error, $validated] = pnk_validate_save_token($presentation_id, $save_token);
    if ($error) return new WP_REST_Response(['ok' => false], 200);

    $user_id = (int) $validated['user_id'];
    $usage = pnk_authorize_image_search_usage(
        $user_id,
        $presentation_id,
        $placeholder_key,
        $usage_key,
        $confirm_token_charge
    );

    return new WP_REST_Response([
        'ok' => true,
        'presentationId' => (string) $presentation_id,
        'userId' => (string) $user_id,
        'imageSearch' => $usage,
    ], 200);
}

add_action('rest_api_init', function () {
    register_rest_route('presentonika/v1', '/authorize-image-search', [
        'methods' => ['POST', 'OPTIONS'],
        'permission_callback' => '__return_true',
        'callback' => 'pnk_rest_authorize_image_search',
    ]);
});
