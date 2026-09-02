<?php
if (!defined('ABSPATH')) { exit; }

function pnk_product_request_path(): string {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $home_path = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    $path = trim($path, '/');

    if ($home_path !== '' && ($path === $home_path || strpos($path, $home_path . '/') === 0)) {
        $path = trim(substr($path, strlen($home_path)), '/');
    }

    return $path;
}

function pnk_product_route(): ?array {
    $path = pnk_product_request_path();
    $routes = [
        'login'                 => ['key' => 'login',         'title' => 'Вход в Presentonica', 'active' => ''],
        'cabinet'               => ['key' => 'dashboard',     'title' => 'Дашборд', 'active' => 'dashboard'],
        'cabinet/presentations' => ['key' => 'presentations', 'title' => 'Презентации', 'active' => 'presentations'],
        'cabinet/templates'     => ['key' => 'templates',     'title' => 'Библиотека шаблонов', 'active' => 'templates'],
        'cabinet/account'       => ['key' => 'account',       'title' => 'Аккаунт', 'active' => 'account'],
        'payment'               => ['key' => 'payment',       'title' => 'Тариф и оплата', 'active' => 'payment'],
        'createpres'            => ['key' => 'create',        'title' => 'Новая презентация', 'active' => 'create'],
        'plan'                  => ['key' => 'plan',          'title' => 'Структура презентации', 'active' => 'create'],
        'waiting'               => ['key' => 'waiting',       'title' => 'Генерация презентации', 'active' => 'presentations'],
        'presentation'          => ['key' => 'presentation',  'title' => 'Готовая презентация', 'active' => 'presentations', 'presentation_id' => 0],
    ];

    if (isset($routes[$path])) return $routes[$path];

    if (preg_match('#^presentation/(\d+)$#', $path, $match)) {
        return [
            'key' => 'presentation',
            'title' => 'Готовая презентация',
            'active' => 'presentations',
            'presentation_id' => (int) $match[1],
        ];
    }

    return null;
}

function pnk_product_url(string $path): string {
    return home_url('/' . trim($path, '/') . '/');
}

function pnk_product_logo(): string {
    $src = PRESENTONIKA_UI_URL . 'assets/demo/logo-mark.svg';
    return '<span class="pnk-product-logo__mark"><img src="' . esc_url($src) . '" alt="" width="35" height="35"></span><span>Presentonica</span>';
}

function pnk_product_icon(string $name, int $size = 18): string {
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'document' => '<path d="M8 3h8l4 4v14a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M12 12v5M9.5 14.5h5"/>',
        'screen' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 21h8M12 16v5"/>',
        'library' => '<rect x="3" y="4" width="7" height="7" rx="1.5"/><rect x="14" y="4" width="7" height="7" rx="1.5"/><rect x="3" y="15" width="7" height="7" rx="1.5"/><rect x="14" y="15" width="7" height="7" rx="1.5"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 21c.7-4 3-6 7-6s6.3 2 7 6"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 0 1 4.6 1c0 2-2.4 2.2-2.4 4M12 18h.01"/>',
        'wallet' => '<path d="M4 7.5h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-12a2 2 0 0 1 2-2h11"/><path d="M15 12h5v4h-5a2 2 0 0 1 0-4z"/>',
        'lock' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
        'open' => '<path d="M7 17L17 7M8 7h9v9"/>',
        'check' => '<path d="M4 12l5 5 11-11"/>',
        'shield' => '<path d="M12 3l7 3v5c0 4.5-2.8 8-7 10-4.2-2-7-5.5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
    ];
    $body = $icons[$name] ?? $icons['document'];
    return '<svg aria-hidden="true" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

function pnk_product_nav_link(string $path, string $label, string $key, string $active): string {
    $icons = ['dashboard' => 'dashboard', 'create' => 'document', 'presentations' => 'screen', 'templates' => 'library', 'account' => 'user', 'payment' => 'wallet'];
    $class = 'pnk-product-nav__item' . ($key === $active ? ' is-active' : '');
    $current = $key === $active ? ' aria-current="page"' : '';
    return '<a class="' . esc_attr($class) . '"' . $current . ' href="' . esc_url(pnk_product_url($path)) . '"><span class="pnk-product-nav__icon">' . pnk_product_icon($icons[$key] ?? 'document') . '</span><span>' . esc_html($label) . '</span></a>';
}

function pnk_product_nav_coming_soon(string $label, string $icon): string {
    return '<span class="pnk-product-nav__item is-disabled" aria-disabled="true"><span class="pnk-product-nav__icon">' . pnk_product_icon($icon) . '</span><span class="pnk-product-nav__copy"><span>' . esc_html($label) . '</span><small>В разработке</small></span></span>';
}

function pnk_product_sidebar(string $active): string {
    $user = wp_get_current_user();
    $name = $user->display_name ?: $user->user_login;
    $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));

    ob_start(); ?>
    <aside class="pnk-product-sidebar" id="pnk-productSidebar" aria-label="Рабочее пространство">
        <a class="pnk-product-logo" href="<?php echo esc_url(pnk_product_url('/demo/')); ?>">
            <?php echo pnk_product_logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </a>

        <nav class="pnk-product-nav" aria-label="Навигация кабинета">
            <span class="pnk-product-nav__label">Рабочее пространство</span>
            <?php echo pnk_product_nav_link('/cabinet/', 'Дашборд', 'dashboard', $active); ?>
            <?php echo pnk_product_nav_link('/createpres/', 'Новая презентация', 'create', $active); ?>
            <?php echo pnk_product_nav_link('/cabinet/presentations/', 'Презентации', 'presentations', $active); ?>
            <?php echo pnk_product_nav_coming_soon('Планы уроков', 'document'); ?>
            <?php echo pnk_product_nav_coming_soon('Библиотека шаблонов', 'library'); ?>
            <span class="pnk-product-nav__label">Ещё</span>
            <?php echo pnk_product_nav_link('/cabinet/account/', 'Аккаунт', 'account', $active); ?>
            <?php echo pnk_product_nav_link('/payment/', 'Тариф и оплата', 'payment', $active); ?>
            <a class="pnk-product-nav__item" href="<?php echo esc_url(pnk_product_url('/demo/podderzhka/')); ?>"><span class="pnk-product-nav__icon"><?php echo pnk_product_icon('help'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span>Помощь и поддержка</span></a>
        </nav>

        <div class="pnk-product-sidebar__bottom">
            <a class="pnk-product-user" href="<?php echo esc_url(pnk_product_url('/cabinet/account/')); ?>">
                <span class="pnk-product-avatar"><?php echo esc_html($initial ?: 'P'); ?></span>
                <span><strong><?php echo esc_html($name); ?></strong><small><?php echo esc_html($user->user_email); ?></small></span>
            </a>
        </div>
    </aside>
    <?php return ob_get_clean();
}

function pnk_product_mobile_bar(): string {
    ob_start(); ?>
    <div class="pnk-product-mobilebar">
        <a class="pnk-product-logo" href="<?php echo esc_url(pnk_product_url('/cabinet/')); ?>">
            <?php echo pnk_product_logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </a>
        <button class="pnk-product-menu" type="button" data-pnk-sidebar-toggle aria-label="Открыть меню" aria-expanded="false"><span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span></button>
    </div>
    <?php return ob_get_clean();
}

function pnk_product_trust_strip(): string {
    return '<div class="pnk-product-trust"><span>Российская разработка</span><i></i><span>Соответствует ФГОС</span><i></i><span>Работает в браузере без установки</span></div>';
}

function pnk_product_topbar(string $title, string $subtitle = '', string $actions = ''): string {
    ob_start(); ?>
    <header class="pnk-product-topbar">
        <div>
            <h1><?php echo esc_html($title); ?></h1>
            <?php if ($subtitle !== ''): ?><p><?php echo esc_html($subtitle); ?></p><?php endif; ?>
        </div>
        <?php if ($actions !== ''): ?><div class="pnk-product-topbar__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php endif; ?>
    </header>
    <?php return ob_get_clean();
}

function pnk_product_stats(): array {
    $defaults = ['total' => 0, 'done' => 0, 'active' => 0];
    if (!is_user_logged_in() || !function_exists('pnk_table_name')) return $defaults;

    global $wpdb;
    $table = pnk_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if (!$exists) return $defaults;

    $user_id = get_current_user_id();
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT status, COUNT(*) AS amount FROM {$table} WHERE userid = %d GROUP BY status",
            $user_id
        )
    );

    foreach ($rows as $row) {
        $amount = (int) $row->amount;
        $defaults['total'] += $amount;
        if ((string) $row->status === 'done') $defaults['done'] += $amount;
        if (in_array((string) $row->status, ['pending', 'processing'], true)) $defaults['active'] += $amount;
    }

    return $defaults;
}

function pnk_product_points(): int {
    return function_exists('pnk_get_user_points_balance')
        ? (int) pnk_get_user_points_balance((int) get_current_user_id())
        : 0;
}

function pnk_product_material_badge(string $title): string {
    $lower = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
    $subjects = [
        'Био' => ['биолог', 'фотосинт', 'клетк', 'организм', 'генет'],
        'Ист' => ['истор', 'войн', 'революц', 'импер', 'древн'],
        'Мат' => ['математ', 'процент', 'дроб', 'уравнен', 'геометр'],
        'Физ' => ['физик', 'ньютон', 'механик', 'электр', 'сила'],
        'Лит' => ['литератур', 'роман', 'пушкин', 'поэз', 'герой нашего'],
        'Гео' => ['географ', 'климат', 'материк', 'океан', 'природн'],
    ];
    foreach ($subjects as $badge => $needles) {
        foreach ($needles as $needle) {
            if (strpos($lower, $needle) !== false) return $badge;
        }
    }
    $clean = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title));
    if ($clean === '') return 'През';
    return function_exists('mb_substr') ? mb_substr($clean, 0, 3) : substr($clean, 0, 3);
}

function pnk_product_render_pagination(int $current_page, int $total_pages, int $total_items, int $per_page): string {
    if ($total_pages <= 1) return '';

    $base_url = pnk_product_url('/cabinet/presentations/');
    $page_url = static function (int $page) use ($base_url): string {
        return $page <= 1 ? $base_url : add_query_arg('pnk_page', $page, $base_url);
    };

    $pages = [1, $total_pages];
    for ($page = max(1, $current_page - 2); $page <= min($total_pages, $current_page + 2); $page++) {
        $pages[] = $page;
    }
    $pages = array_values(array_unique($pages));
    sort($pages, SORT_NUMERIC);

    $first_item = (($current_page - 1) * $per_page) + 1;
    $last_item = min($total_items, $current_page * $per_page);
    $previous_page = null;

    ob_start(); ?>
    <nav class="pnk-product-pagination" aria-label="Страницы презентаций">
        <span class="pnk-product-pagination__summary">Материалы <?php echo esc_html((string) $first_item); ?>–<?php echo esc_html((string) $last_item); ?> из <?php echo esc_html((string) $total_items); ?></span>
        <div class="pnk-product-pagination__pages">
            <?php if ($current_page > 1): ?>
                <a class="pnk-product-pagination__link pnk-product-pagination__link--wide" rel="prev" href="<?php echo esc_url($page_url($current_page - 1)); ?>">Назад</a>
            <?php endif; ?>
            <?php foreach ($pages as $page): ?>
                <?php if ($previous_page !== null && $page > $previous_page + 1): ?><span class="pnk-product-pagination__ellipsis" aria-hidden="true">…</span><?php endif; ?>
                <?php if ($page === $current_page): ?>
                    <span class="pnk-product-pagination__current" aria-current="page"><?php echo esc_html((string) $page); ?></span>
                <?php else: ?>
                    <a class="pnk-product-pagination__link" href="<?php echo esc_url($page_url($page)); ?>" aria-label="Страница <?php echo esc_attr((string) $page); ?>"><?php echo esc_html((string) $page); ?></a>
                <?php endif; ?>
                <?php $previous_page = $page; ?>
            <?php endforeach; ?>
            <?php if ($current_page < $total_pages): ?>
                <a class="pnk-product-pagination__link pnk-product-pagination__link--wide" rel="next" href="<?php echo esc_url($page_url($current_page + 1)); ?>">Далее</a>
            <?php endif; ?>
        </div>
    </nav>
    <?php return ob_get_clean();
}

function pnk_product_render_materials(int $limit = 4, int $page = 1, bool $paginate = false): string {
    if (!is_user_logged_in() || !function_exists('pnk_table_name')) return '';

    global $wpdb;
    $table = pnk_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if (!$exists) {
        return '<div class="pnk-product-lessons-empty"><strong>Материалы пока недоступны</strong><span>Таблица презентаций не найдена. Проверьте активацию Presentonica Core.</span></div>';
    }

    $limit = max(1, min(100, $limit));
    $page = max(1, $page);
    $total_items = 0;
    $total_pages = 1;

    if ($paginate) {
        $total_items = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE userid = %d", get_current_user_id())
        );
        $total_pages = max(1, (int) ceil($total_items / $limit));
        $page = min($page, $total_pages);
    }

    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT presentationID, presentationname, created_at, status, path, error_message
             FROM {$table}
             WHERE userid = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            get_current_user_id(),
            $limit,
            ($page - 1) * $limit
        )
    );

    if (empty($items)) {
        return '<div class="pnk-product-lessons-empty"><span class="pnk-product-lessons-empty__icon">' . pnk_product_icon('screen', 22) . '</span><strong>Здесь появятся ваши презентации</strong><span>После первой генерации презентация и её статус будут сохранены в этом списке.</span><a class="pnk-btn pnk-btn--primary" href="' . esc_url(pnk_product_url('/createpres/')) . '">Создать презентацию</a></div>';
    }

    ob_start(); ?>
    <div class="pnk-product-lessons" id="pnk-cabinet">
        <?php foreach ($items as $index => $item):
            $pid = (int) $item->presentationID;
            $title = trim((string) $item->presentationname) ?: ('Презентация #' . $pid);
            $status = strtolower(trim((string) $item->status));
            [$status_label, $status_kind] = function_exists('pnk_ui_status_label') ? pnk_ui_status_label($status) : [$status ?: '—', 'muted'];
            $date = !empty($item->created_at) ? date_i18n('d.m.Y', strtotime((string) $item->created_at)) : '';
            $target = $status === 'done' && !empty($item->path)
                ? pnk_product_url('/presentation/' . $pid . '/')
                : add_query_arg('presentation_id', $pid, pnk_product_url('/waiting/'));
            if ($status === 'failed') $target = pnk_product_url('/createpres/');
            ?>
            <article class="pnk-product-lesson-row">
                <span class="pnk-product-subject-badge pnk-product-subject-badge--<?php echo esc_attr((string) (($index % 4) + 1)); ?>"><?php echo esc_html(pnk_product_material_badge($title)); ?></span>
                <div class="pnk-product-lesson-main">
                    <a href="<?php echo esc_url($target); ?>"><?php echo esc_html($title); ?></a>
                    <span>Презентация #<?php echo esc_html((string) $pid); ?><?php if ($date !== ''): ?> · обновлено <?php echo esc_html($date); ?><?php endif; ?></span>
                </div>
                <span class="pnk-product-lesson-status pnk-product-lesson-status--<?php echo esc_attr($status_kind); ?>"><?php if ($status_kind === 'ok') echo pnk_product_icon('check', 12); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html($status_label); ?></span>
                <a class="pnk-product-icon-btn" href="<?php echo esc_url($target); ?>" title="Открыть" aria-label="Открыть <?php echo esc_attr($title); ?>"><?php echo pnk_product_icon('open', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if ($paginate) echo pnk_product_render_pagination($page, $total_pages, $total_items, $limit); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php return ob_get_clean();
}

function pnk_product_dashboard_page(): string {
    $user = wp_get_current_user();
    $name = $user->display_name ?: $user->user_login;
    $first_name = trim((string) get_user_meta($user->ID, 'first_name', true));
    if ($first_name !== '') $name = $first_name;
    $stats = pnk_product_stats();
    $hours = max(0, (int) round($stats['done'] * 0.6));

    ob_start(); ?>
    <section class="pnk-product-dashboard-head">
        <div class="pnk-product-dashboard-copy">
            <span class="pnk-product-kicker">Рабочее пространство</span>
            <h2>Здравствуйте, <?php echo esc_html($name); ?>!</h2>
            <p>Подготовьте новую презентацию или продолжите работу с материалами из кабинета.</p>
            <a class="pnk-btn pnk-btn--primary pnk-product-dashboard-cta" href="<?php echo esc_url(pnk_product_url('/createpres/')); ?>"><?php echo pnk_product_icon('screen', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>Создать презентацию</a>
        </div>
        <div class="pnk-product-dashboard-aside">
            <div class="pnk-product-savedtime"><?php echo pnk_product_icon('clock', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><div><strong>≈<?php echo esc_html((string) $hours); ?> часов</strong><span>сэкономлено в этом месяце</span></div></div>
            <div class="pnk-product-roadmap" aria-label="Будущие инструменты">
                <div><span><?php echo pnk_product_icon('document', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><strong>Планы уроков</strong><small>В разработке</small></div>
                <div><span><?php echo pnk_product_icon('library', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><strong>Шаблоны</strong><small>В разработке</small></div>
            </div>
        </div>
    </section>

    <section class="pnk-product-section">
        <div class="pnk-product-section__head"><h2>Ваши презентации</h2><a href="<?php echo esc_url(pnk_product_url('/cabinet/presentations/')); ?>">Показать все →</a></div>
        <?php echo pnk_product_render_materials(4); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </section>
    <?php return ob_get_clean();
}

function pnk_product_presentations_page(): string {
    $page = isset($_GET['pnk_page']) ? max(1, absint(wp_unslash($_GET['pnk_page']))) : 1;

    ob_start(); ?>
    <section class="pnk-product-page-intro">
        <span class="pnk-product-kicker">Ваши материалы</span>
        <h2>Презентации и генерации</h2>
        <p>Здесь сохраняются готовые работы, текущие задачи и материалы, которые можно снова открыть в редакторе.</p>
    </section>
    <?php echo pnk_product_render_materials(10, $page, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php return ob_get_clean();
}

function pnk_product_templates_page(): string {
    ob_start(); ?>
    <section class="pnk-product-coming-page">
        <span class="pnk-product-coming-page__icon"><?php echo pnk_product_icon('library', 28); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        <span class="pnk-product-coming-badge">В разработке</span>
        <h2>Библиотека шаблонов скоро появится</h2>
        <p>Пока все доступные варианты оформления можно выбрать прямо при создании презентации.</p>
        <a class="pnk-btn pnk-btn--primary" href="<?php echo esc_url(pnk_product_url('/createpres/')); ?>">Создать презентацию</a>
    </section>
    <?php return ob_get_clean();
}

function pnk_product_account_page(): string {
    $user = wp_get_current_user();
    $vk_id = (string) get_user_meta($user->ID, 'vk_id', true);
    $logout = wp_logout_url(pnk_product_url('/login/'));
    $account_name = $user->display_name ?: $user->user_login;
    $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($account_name, 0, 1)) : strtoupper(substr($account_name, 0, 1));

    ob_start(); ?>
    <section class="pnk-product-page-intro">
        <span class="pnk-product-kicker">Профиль</span>
        <h2>Аккаунт и доступ</h2>
        <p>Основные данные профиля и состояние подписки.</p>
    </section>
    <div class="pnk-product-accountgrid">
        <article class="pnk-product-profile">
            <div class="pnk-product-profile__avatar"><?php echo esc_html($initial ?: 'P'); ?></div>
            <div><h3><?php echo esc_html($user->display_name ?: $user->user_login); ?></h3><p><?php echo esc_html($user->user_email); ?></p></div>
        </article>
        <article class="pnk-product-accountcard"><span>Баланс</span><strong><?php echo esc_html((string) pnk_product_points()); ?> баллов</strong><a href="<?php echo esc_url(pnk_product_url('/payment/')); ?>">Выбрать тариф</a></article>
        <article class="pnk-product-accountcard"><span>Способ входа</span><strong>VK ID</strong><small><?php echo $vk_id !== '' ? 'ID ' . esc_html($vk_id) : 'Аккаунт подключён'; ?></small></article>
        <article class="pnk-product-accountcard"><span>Безопасность</span><strong>Сессия защищена</strong><small>Персональные данные обрабатываются на стороне WordPress и VK ID.</small></article>
    </div>
    <?php echo do_shortcode('[vkid_account_link]'); ?>
    <div class="pnk-product-account-actions"><a class="pnk-btn pnk-btn--ghost" href="<?php echo esc_url($logout); ?>">Выйти из аккаунта</a></div>
    <?php return ob_get_clean();
}

function pnk_product_create_page(): string {
    ob_start(); ?>
    <section class="pnk-product-page-intro pnk-product-page-intro--compact">
        <span class="pnk-product-kicker">Новая работа</span>
        <h2>Настройте будущую презентацию</h2>
        <p>Сформулируйте тему и уточните параметры. Перед запуском вы сможете проверить структуру слайдов.</p>
    </section>
    <?php echo do_shortcode('[presentation_form]'); ?>
    <?php return ob_get_clean();
}

function pnk_product_plan_page(): string {
    ob_start(); ?>
    <section class="pnk-product-page-intro pnk-product-page-intro--compact">
        <span class="pnk-product-kicker">Шаг 2 из 3</span>
        <h2>Проверьте структуру</h2>
        <p>Отредактируйте логику подачи и содержание каждого слайда до запуска генерации.</p>
    </section>
    <?php echo do_shortcode('[presentation_plan]'); ?>
    <?php return ob_get_clean();
}

function pnk_product_waiting_page(): string {
    ob_start(); ?>
    <section class="pnk-product-process">
        <span class="pnk-product-kicker">Шаг 3 из 3</span>
        <h2>Собираем презентацию</h2>
        <p>Структура уже готова. Сейчас формируем тексты, иллюстрации и оформление слайдов.</p>
        <div class="pnk-product-process__track"><span></span></div>
        <?php echo do_shortcode('[presentation_waiting]'); ?>
    </section>
    <?php return ob_get_clean();
}

function pnk_product_presentation_page(int $presentation_id): string {
    return do_shortcode('[presentation_open_editor id="' . (int) $presentation_id . '" auto="1" minimal="1"]');
}

function pnk_product_plans(): array {
    return [
        'basic' => [
            'name' => 'Базовый',
            'price' => 720,
            'points' => 100,
            'presentations' => 'до 10 презентаций',
            'product_names' => ['Базовый', 'Стандартный'],
        ],
        'optimal' => [
            'name' => 'Оптимальный',
            'price' => 1300,
            'points' => 250,
            'presentations' => 'до 25 презентаций',
            'product_names' => ['Оптимальный'],
            'popular' => true,
        ],
        'professional' => [
            'name' => 'Профессиональный',
            'price' => 1890,
            'points' => 450,
            'presentations' => 'до 45 презентаций',
            'product_names' => ['Профессиональный'],
        ],
    ];
}

function pnk_product_find_wc_product_id(array $plan): int {
    if (!function_exists('wc_get_products')) return 0;
    $products = wc_get_products(['status' => 'publish', 'limit' => -1]);
    foreach ($products as $product) {
        if (!is_object($product) || !method_exists($product, 'get_name')) continue;
        if (in_array((string) $product->get_name(), $plan['product_names'], true)) {
            return (int) $product->get_id();
        }
    }
    return 0;
}

function pnk_product_payment_page(): string {
    $plans = pnk_product_plans();
    $selected_key = sanitize_key(wp_unslash($_GET['plan'] ?? 'optimal'));
    if (!isset($plans[$selected_key])) $selected_key = 'optimal';
    $selected = $plans[$selected_key];
    $product_id = pnk_product_find_wc_product_id($selected);
    $checkout_url = wp_nonce_url(
        add_query_arg(['pnk_checkout' => '1', 'plan' => $selected_key], pnk_product_url('/payment/')),
        'pnk_checkout_' . $selected_key
    );
    $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));

    ob_start(); ?>
    <section class="pnk-payment-intro">
        <span class="pnk-product-kicker">Подписка Presentonica</span>
        <h2>Выберите подходящий объём</h2>
        <p>Оплата проходит на защищённой стороне ЮKassa. Данные банковской карты не передаются Presentonica.</p>
    </section>
    <?php if ($status === 'unavailable'): ?><div class="pnk-payment-notice" role="alert">Этот тариф ещё не связан с товаром WooCommerce. Выберите другой вариант или вернитесь позже.</div><?php endif; ?>
    <div class="pnk-payment-layout">
        <div class="pnk-payment-plans" aria-label="Тарифы">
            <?php foreach ($plans as $key => $plan):
                $is_selected = $key === $selected_key;
                $href = add_query_arg('plan', $key, pnk_product_url('/payment/'));
                ?>
                <a class="pnk-payment-plan<?php echo $is_selected ? ' is-selected' : ''; ?>" href="<?php echo esc_url($href); ?>"<?php echo $is_selected ? ' aria-current="true"' : ''; ?>>
                    <span class="pnk-payment-plan__radio" aria-hidden="true"></span>
                    <span class="pnk-payment-plan__copy"><strong><?php echo esc_html($plan['name']); ?></strong><small><?php echo esc_html((string) $plan['points']); ?> баллов · <?php echo esc_html($plan['presentations']); ?></small></span>
                    <span class="pnk-payment-plan__price"><?php echo esc_html(number_format_i18n((int) $plan['price'], 0)); ?> ₽<small>/ месяц</small></span>
                    <?php if (!empty($plan['popular'])): ?><span class="pnk-payment-plan__badge">Популярный</span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <aside class="pnk-payment-summary">
            <span class="pnk-payment-summary__label">К оплате</span>
            <h3><?php echo esc_html($selected['name']); ?></h3>
            <div class="pnk-payment-summary__price"><?php echo esc_html(number_format_i18n((int) $selected['price'], 0)); ?> ₽<small>за 30 дней</small></div>
            <ul><li><?php echo pnk_product_icon('check', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html((string) $selected['points']); ?> баллов после оплаты</li><li><?php echo pnk_product_icon('check', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>Материалы сохраняются в кабинете</li><li><?php echo pnk_product_icon('check', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>Оплата через официальный модуль ЮKassa</li></ul>
            <?php if ($product_id > 0): ?>
                <a class="pnk-btn pnk-btn--primary pnk-payment-submit" href="<?php echo esc_url($checkout_url); ?>"><?php echo pnk_product_icon('lock', 17); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>Перейти к оплате</a>
            <?php else: ?>
                <span class="pnk-btn pnk-btn--disabled pnk-payment-submit" aria-disabled="true">Тариф подключается</span>
            <?php endif; ?>
            <p class="pnk-payment-summary__fineprint">Нажимая кнопку, вы переходите к оформлению заказа в WooCommerce и выбору способа оплаты ЮKassa.</p>
        </aside>
    </div>
    <div class="pnk-payment-security"><?php echo pnk_product_icon('shield', 22); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><div><strong>Безопасная оплата</strong><span>Платёжные данные обрабатывает ЮKassa по стандарту PCI DSS. Presentonica не хранит реквизиты карты.</span></div></div>
    <?php return ob_get_clean();
}

function pnk_product_login_page(): string {
    $widget = shortcode_exists('vkid_login')
        ? do_shortcode('[vkid_login]')
        : '<div class="pnk-product-auth-error">VK ID временно недоступен.</div>';

    ob_start(); ?>
    <div class="pnk-registration">
        <header class="pnk-registration__header">
            <a class="pnk-product-logo" href="<?php echo esc_url(pnk_product_url('/demo/')); ?>"><?php echo pnk_product_logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
            <div>Уже есть аккаунт? <a href="#pnk-vk-login">Войти через ВКонтакте</a></div>
        </header>
        <main class="pnk-registration__wrap">
            <div class="pnk-registration__hero">
                <section class="pnk-registration__intro">
                    <h1>Добро пожаловать в мир быстрых презентаций</h1>
                    <p class="pnk-registration__lead">Создавайте профессиональные материалы к урокам за 2 минуты — без паролей и лишних форм.</p>
                    <div class="pnk-registration__benefits">
                        <div><?php echo pnk_product_icon('check'); ?>Мгновенный вход без паролей</div>
                        <div><?php echo pnk_product_icon('check'); ?>Не нужно запоминать новый логин</div>
                        <div><?php echo pnk_product_icon('check'); ?>Ваши данные защищены ВКонтакте</div>
                        <div><?php echo pnk_product_icon('check'); ?>Автоматическое заполнение профиля</div>
                    </div>
                    <div class="pnk-registration__photo"><img src="https://images.unsplash.com/photo-1758685848142-06e158cf64bc?fm=jpg&amp;q=80&amp;w=900&amp;auto=format&amp;fit=crop&amp;ixlib=rb-4.1.0" alt="Учитель готовится к уроку с Presentonica"></div>
                    <div class="pnk-registration__trust"><?php echo pnk_product_icon('shield'); ?>Российская разработка · данные хранятся на серверах в РФ</div>
                </section>

                <section class="pnk-registration__card" id="pnk-vk-login">
                    <div class="pnk-registration__vk"><?php echo $widget; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    <div class="pnk-registration__vk-note">Мы используем ВК ID — официальную систему аутентификации ВКонтакте</div>

                    <div class="pnk-registration__data">
                        <div><strong>Мы получим</strong><span>Имя и фамилию</span><span>Электронную почту</span><span>ID ВКонтакте</span></div>
                        <div class="is-muted"><strong>Мы не получим</strong><span>Пароль от ВКонтакте</span><span>Личные сообщения</span><span>Список друзей</span></div>
                    </div>

                    <div class="pnk-registration__consents">
                        <strong class="pnk-registration__label">Обязательно</strong>
                        <label><input type="checkbox" checked><span>Я прочитал(а) и принимаю <a href="#">Пользовательское соглашение</a><b> *</b><small>Регистрируясь на сайте, вы заключаете договор с ООО «Цифровые коммуникации».</small></span></label>
                        <label><input type="checkbox" checked><span>Согласен(на) на обработку персональных данных по <a href="#">Политике конфиденциальности</a><b> *</b></span></label>
                        <label><input type="checkbox" checked><span>Мне есть 18 лет, либо есть согласие законного представителя<b> *</b></span></label>
                    </div>

                    <div class="pnk-registration__consents">
                        <strong class="pnk-registration__label">Необязательно</strong>
                        <label><input type="checkbox"><span>Хочу получать новости о новых возможностях и акциях</span></label>
                        <label><input type="checkbox"><span>Согласен(на) на обезличенную статистику для улучшения сервиса</span></label>
                        <label><input type="checkbox"><span>Я учитель/преподаватель — присылайте специальные материалы</span></label>
                    </div>

                    <details class="pnk-registration__minor"><summary>Если вам меньше 18 лет</summary><ol><li>Получите согласие родителя или опекуна</li><li>Покажите им Пользовательское соглашение и Политику конфиденциальности</li><li>Регистрируйтесь только после их подтверждения</li></ol></details>
                    <div class="pnk-registration__security"><div><?php echo pnk_product_icon('check'); ?>Авторизация — через защищённый сервис ВК ID</div><div><?php echo pnk_product_icon('check'); ?>Платёжные данные обрабатывает ЮKassa, мы их не храним</div></div>
                </section>
            </div>

            <section class="pnk-registration__faq">
                <h2>Частые вопросы при регистрации</h2>
                <details open><summary>Почему только через ВКонтакте?</summary><p>Мы начинаем с самого популярного в России способа авторизации. В будущем добавим другие варианты.</p></details>
                <details><summary>Что делать, если у меня нет аккаунта ВКонтакте?</summary><p>Создайте аккаунт на vk.com — это бесплатно и займёт несколько минут.</p></details>
                <details><summary>Можно ли отвязать аккаунт Presentonica от ВКонтакте?</summary><p>Да, это можно сделать в настройках безопасности ВКонтакте или написав нам на support@presentonica.ru.</p></details>
                <details><summary>Какой email будет использоваться для уведомлений?</summary><p>Тот, который указан в вашем профиле ВКонтакте. Изменить его можно в настройках аккаунта Presentonica после регистрации.</p></details>
                <details><summary>Мой ребёнок хочет использовать сервис. Что делать?</summary><p>Пользователям от 14 до 18 лет потребуется согласие законного представителя. Детям до 14 лет регистрация запрещена.</p></details>
            </section>

            <section class="pnk-registration__help"><h3>Нужна помощь?</h3><p>Если у вас возникли проблемы с регистрацией — напишите нам, отвечаем в течение 24 часов.</p><a class="pnk-btn pnk-btn--primary" href="mailto:support@presentonica.ru">support@presentonica.ru</a></section>
        </main>
    </div>
    <?php return ob_get_clean();
}

function pnk_product_route_content(array $route): string {
    switch ($route['key']) {
        case 'dashboard': return pnk_product_dashboard_page();
        case 'presentations': return pnk_product_presentations_page();
        case 'templates': return pnk_product_templates_page();
        case 'account': return pnk_product_account_page();
        case 'payment': return pnk_product_payment_page();
        case 'create': return pnk_product_create_page();
        case 'plan': return pnk_product_plan_page();
        case 'waiting': return pnk_product_waiting_page();
        case 'presentation': return pnk_product_presentation_page((int) ($route['presentation_id'] ?? 0));
        case 'login': return pnk_product_login_page();
    }

    return '';
}

function pnk_product_route_actions(array $route): string {
    if ($route['key'] === 'presentations') {
        return '<a class="pnk-btn pnk-btn--primary" href="' . esc_url(pnk_product_url('/createpres/')) . '">Новая презентация</a>';
    }
    if (in_array($route['key'], ['create', 'plan', 'waiting', 'presentation'], true)) {
        return '<a class="pnk-btn pnk-btn--ghost" href="' . esc_url(pnk_product_url('/cabinet/')) . '">В кабинет</a>';
    }
    return '';
}

function pnk_product_route_subtitle(array $route): string {
    $subtitles = [
        'dashboard' => 'Ваши презентации и текущие генерации',
        'presentations' => 'Готовые материалы, черновики и текущие генерации',
        'templates' => 'Раздел готовится к запуску',
        'account' => 'Профиль, баланс и параметры доступа',
        'payment' => 'Выбор тарифа и переход к защищённой оплате',
        'create' => 'Тема и класс — первые слайды уже через несколько минут',
        'plan' => 'Проверьте структуру перед финальной генерацией',
    ];
    return $subtitles[$route['key']] ?? '';
}

function pnk_product_render_document(array $route): string {
    $GLOBALS['pnk_product_current_route'] = $route;
    show_admin_bar(false);
    ob_start(); ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php wp_head(); ?>
    </head>
    <body <?php body_class('presentonika-product-page'); ?>>
    <?php if (function_exists('wp_body_open')) wp_body_open(); ?>
    <?php if ($route['key'] === 'login'): ?>
        <?php echo pnk_product_route_content($route); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php else: ?>
        <div class="pnk-product-layout" data-pnk-product-route="<?php echo esc_attr($route['key']); ?>">
            <?php echo pnk_product_sidebar((string) $route['active']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="pnk-product-sidebar-backdrop" data-pnk-sidebar-toggle></div>
            <div class="pnk-product-main">
                <?php echo pnk_product_mobile_bar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo pnk_product_topbar((string) $route['title'], pnk_product_route_subtitle($route), pnk_product_route_actions($route)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <main class="pnk-product-content">
                    <?php echo pnk_product_route_content($route); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </main>
            </div>
        </div>
    <?php endif; ?>
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php return ob_get_clean();
}

add_filter('pre_get_document_title', function ($title) {
    $route = $GLOBALS['pnk_product_current_route'] ?? null;
    if (!is_array($route)) return $title;
    return (string) $route['title'] . ' — Presentonica';
});

add_action('template_redirect', function () {
    $route = pnk_product_route();
    if ($route === null) return;

    if ($route['key'] === 'login' && is_user_logged_in()) {
        wp_safe_redirect(pnk_product_url('/cabinet/'));
        exit;
    }

    if ($route['key'] !== 'login' && !is_user_logged_in()) {
        $login = add_query_arg('redirect_to', home_url($_SERVER['REQUEST_URI'] ?? '/cabinet/'), pnk_product_url('/login/'));
        wp_safe_redirect($login);
        exit;
    }

    if ($route['key'] === 'payment' && isset($_GET['pnk_checkout'])) {
        $plan_key = sanitize_key(wp_unslash($_GET['plan'] ?? ''));
        $plans = pnk_product_plans();
        if (!isset($plans[$plan_key]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'pnk_checkout_' . $plan_key)) {
            wp_safe_redirect(add_query_arg('status', 'invalid', pnk_product_url('/payment/')));
            exit;
        }

        $product_id = pnk_product_find_wc_product_id($plans[$plan_key]);
        if ($product_id <= 0 || !function_exists('WC')) {
            wp_safe_redirect(add_query_arg(['plan' => $plan_key, 'status' => 'unavailable'], pnk_product_url('/payment/')));
            exit;
        }

        if (function_exists('wc_load_cart') && (!WC()->cart || !WC()->session)) wc_load_cart();
        if (WC()->cart) {
            WC()->cart->empty_cart();
            WC()->cart->add_to_cart($product_id, 1);
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }

    status_header(200);
    nocache_headers();
    echo pnk_product_render_document($route); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}, -90);
