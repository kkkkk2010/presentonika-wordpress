<?php
if (!defined('ABSPATH')) { exit; }

function pnk_ui_status_label(string $status): array {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'done': return ['Готово', 'ok'];
        case 'processing': return ['Генерируется', 'work'];
        case 'pending': return ['В очереди', 'work'];
        case 'failed': return ['Ошибка', 'bad'];
        default: return [$status ?: '—', 'muted'];
    }
}

function pnk_ui_render_cabinet_list(int $limit = 12, bool $show_header = true): string {
    if (!is_user_logged_in()) {
        return '<div class="pnk pnk-center"><div class="pnk-card">Пожалуйста, войдите в аккаунт.</div></div>';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table = function_exists('pnk_table_name') ? pnk_table_name() : 'wkwa_presentations';

    // Fail soft if table missing
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$table_exists) {
        return '<div class="pnk pnk-center"><div class="pnk-card">Ошибка: таблица презентаций не найдена.</div></div>';
    }

    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT presentationID, presentationname, created_at, status, path, error_message
             FROM {$table}
             WHERE userid = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $user_id,
            max(1, $limit)
        )
    );

    $nonce = wp_create_nonce('presentation_nonce');

    ob_start(); ?>
    <div class="pnk pnk-cabinet" id="pnk-cabinet" data-nonce="<?php echo esc_attr($nonce); ?>">
        <?php if ($show_header): ?>
            <div class="pnk-cabinet__header">
                <div>
                    <div class="pnk-title">История генераций</div>
                    <div class="pnk-muted">Последние <?php echo (int)max(1,$limit); ?> презентаций</div>
                </div>
                <div class="pnk-actions" style="margin-top:0;">
                    <a class="pnk-btn pnk-btn--primary" href="<?php echo esc_url(home_url('/createpres/')); ?>">Сгенерировать</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($items)): ?>
            <div class="pnk-card">
                <div class="pnk-muted">Пока нет презентаций. Сгенерируйте первую — она появится здесь.</div>
            </div>
        <?php else: ?>
            <div class="pnk-grid">
                <?php foreach ($items as $it):
                    $pid = (int)$it->presentationID;
                    [$label, $kind] = pnk_ui_status_label((string)$it->status);
                    $date = !empty($it->created_at) ? date_i18n('d.m.Y', strtotime((string)$it->created_at)) : '';
                    $can_open = ((string)$it->status === 'done' && !empty($it->path));
                    $open_url = home_url('/presentation/' . $pid . '/');
                    $waiting_url = home_url('/waiting/?presentation_id=' . $pid);
                    ?>
                    <article class="pnk-card pnk-card--item">
                        <div class="pnk-card__top">
                            <a class="pnk-card__title" href="<?php echo esc_url($open_url); ?>">
                                <?php echo esc_html($it->presentationname ?: ('Презентация #' . $pid)); ?>
                            </a>
                            <span class="pnk-badge pnk-badge--<?php echo esc_attr($kind); ?>"><?php echo esc_html($label); ?></span>
                        </div>

                        <div class="pnk-card__meta">
                            <span class="pnk-muted">#<?php echo (int)$pid; ?></span>
                            <?php if ($date): ?><span class="pnk-dot">•</span><span class="pnk-muted"><?php echo esc_html($date); ?></span><?php endif; ?>
                        </div>

                        <?php if ((string)$it->status === 'failed' && !empty($it->error_message)): ?>
                            <div class="pnk-error"><?php echo esc_html(mb_substr((string)$it->error_message, 0, 180)); ?></div>
                        <?php endif; ?>

                        <div class="pnk-actions">
                            <button type="button"
                                    class="pnk-btn pnk-btn--primary pnk-open-btn"
                                    data-presentation-id="<?php echo esc_attr((string)$pid); ?>"
                                    data-can-open="<?php echo $can_open ? '1' : '0'; ?>"
                                    data-waiting-url="<?php echo esc_url($waiting_url); ?>">
                                Открыть
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

/**
 * Full cabinet page layout (matches your page structure, but pretty)
 * [presentonika_cabinet_page]
 */
add_shortcode('presentonika_cabinet_page', function($atts){
    $atts = shortcode_atts([
        'limit' => '12',
    ], $atts);

    if (!is_user_logged_in()) {
        $login = esc_url(home_url('/login/'));
        return '<div class="pnk pnk-center"><div class="pnk-card"><div class="pnk-title">Нужно войти</div><div class="pnk-muted" style="margin-top:8px;">Перейдите на страницу входа и вернитесь в кабинет.</div><div class="pnk-actions"><a class="pnk-btn pnk-btn--primary" href="' . $login . '">Войти</a></div></div></div>';
    }

    $u = wp_get_current_user();
    $vk_id = (string)get_user_meta($u->ID, 'vk_id', true);
    $logout = wp_logout_url(home_url('/login/'));

    ob_start(); ?>
    <div class="pnk pnk-dashboard">
        <div class="pnk-dashboard__top">
            <div class="pnk-card" style="padding:18px; flex: 1 1 520px;">
                <div class="pnk-title">Личный кабинет</div>
                <div class="pnk-dashboard__meta">Привет, <strong><?php echo esc_html($u->display_name ?: $u->user_login); ?></strong></div>

                <div class="pnk-kv">
                    <?php if ($vk_id !== ''): ?>
                        <div class="pnk-kv__item">
                            <div class="pnk-kv__k">VK ID</div>
                            <div class="pnk-kv__v"><?php echo esc_html($vk_id); ?></div>
                        </div>
                    <?php endif; ?>
</div>

                <div class="pnk-actions" style="margin-top:14px;">
                    <a class="pnk-btn pnk-btn--primary" href="<?php echo esc_url(home_url('/createpres/')); ?>">Сгенерировать презентацию</a>
                    <a class="pnk-btn pnk-btn--ghost" href="<?php echo esc_url($logout); ?>">Выйти</a>
                </div>
            </div>

            <div class="pnk-dashboard__actions">
                <?php echo do_shortcode('[user_points]'); ?>
            </div>
        </div>

        <?php echo pnk_ui_render_cabinet_list((int)$atts['limit'], true); ?>

        <?php if (shortcode_exists('vkid_debug') && current_user_can('manage_options')): ?>
            <div style="max-width:920px;margin:14px auto 0;">
                <?php echo do_shortcode('[vkid_debug]'); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
});

/**
 * Shortcode for cabinet list (recommended)
 * [presentonika_cabinet]
 */
add_shortcode('presentonika_cabinet', function($atts) {
    $atts = shortcode_atts(['limit' => '12'], $atts);
    return pnk_ui_render_cabinet_list((int)$atts['limit'], true);
});

/**
 * Backward compatible: replace placeholder on /cabinet page:
 * <div id="account-presentations"></div>
 */
add_filter('the_content', function($content) {
    if (!is_page('cabinet')) return $content;
    if (strpos($content, 'id="account-presentations"') === false) return $content;

    return str_replace('<div id="account-presentations"></div>', pnk_ui_render_cabinet_list(12, true), $content);
}, 20);