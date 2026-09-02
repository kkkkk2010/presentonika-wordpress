<?php
defined('ABSPATH') || exit;

/**
 * Standalone demo routes built from the approved design mockups.
 * Product actions still lead into the existing Presentonica UI.
 */

function pnk_demo_routes(): array {
    return [
        'landing'       => ['path' => 'demo',              'title' => 'Presentonica'],
        'instrumenty'  => ['path' => 'demo/instrumenty',  'title' => 'Инструменты — Presentonica'],
        'tarify'        => ['path' => 'demo/tarify',       'title' => 'Тарифы — Presentonica'],
        'podderzhka'    => ['path' => 'demo/podderzhka',   'title' => 'Помощь — Presentonica'],
        'registration' => ['path' => 'demo/registration', 'title' => 'Регистрация — Presentonica'],
        'app'           => ['path' => 'demo/app',          'title' => 'Кабинет — Presentonica'],
    ];
}

function pnk_demo_request_path(): string {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);
    $home_path = trim($home_path, '/');
    $path = trim($path, '/');

    if ($home_path !== '' && ($path === $home_path || strpos($path, $home_path . '/') === 0)) {
        $path = trim(substr($path, strlen($home_path)), '/');
    }

    return $path;
}

function pnk_demo_current_route(): ?string {
    $path = pnk_demo_request_path();

    foreach (pnk_demo_routes() as $key => $route) {
        if ($path === $route['path']) {
            return $key;
        }
    }

    if (defined('PRESENTONIKA_DEMO_HOME') && PRESENTONIKA_DEMO_HOME && ($path === '' || $path === 'index.php')) {
        return 'landing';
    }

    return null;
}

function pnk_demo_route_url(string $key): string {
    $routes = pnk_demo_routes();
    $path = $routes[$key]['path'] ?? $routes['landing']['path'];
    return home_url('/' . trim($path, '/') . '/');
}

function pnk_demo_product_url(string $path): string {
    return home_url('/' . trim($path, '/') . '/');
}

function pnk_demo_template_path(string $page): string {
    if (!array_key_exists($page, pnk_demo_routes())) {
        $page = 'landing';
    }

    return dirname(__DIR__) . '/assets/demo/pages/' . $page . '.html';
}

function pnk_demo_register_newsletter_post_type(): void {
    register_post_type('pnk_newsletter', [
        'labels' => [
            'name'          => 'Подписки на новости',
            'singular_name' => 'Подписка на новости',
            'menu_name'     => 'Подписки на новости',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-email-alt',
        'supports'            => ['title'],
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'exclude_from_search' => true,
    ]);
}
add_action('init', 'pnk_demo_register_newsletter_post_type');

function pnk_demo_newsletter_redirect(string $status): void {
    $url = add_query_arg('newsletter', sanitize_key($status), pnk_demo_route_url('instrumenty')) . '#updates';
    wp_safe_redirect($url);
    exit;
}

function pnk_demo_handle_newsletter_signup(): void {
    if (!isset($_POST['pnk_newsletter_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pnk_newsletter_nonce'])), 'pnk_newsletter_signup')) {
        pnk_demo_newsletter_redirect('invalid');
    }

    // A filled honeypot is treated as a successful request without storing data.
    if (!empty($_POST['website'])) {
        pnk_demo_newsletter_redirect('success');
    }

    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $has_consents = !empty($_POST['privacy_consent']) && !empty($_POST['updates_consent']);
    if (!is_email($email) || !$has_consents) {
        pnk_demo_newsletter_redirect('invalid');
    }

    $existing = get_posts([
        'post_type'      => 'pnk_newsletter',
        'post_status'    => ['private', 'publish'],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_pnk_newsletter_email',
        'meta_value'     => $email,
        'no_found_rows'  => true,
    ]);

    if (!$existing) {
        $post_id = wp_insert_post([
            'post_type'   => 'pnk_newsletter',
            'post_status' => 'private',
            'post_title'  => $email,
        ], true);

        if (is_wp_error($post_id)) {
            pnk_demo_newsletter_redirect('error');
        }

        update_post_meta($post_id, '_pnk_newsletter_email', $email);
        update_post_meta($post_id, '_pnk_newsletter_source', 'demo-instrumenty');
        update_post_meta($post_id, '_pnk_newsletter_user_id', get_current_user_id());
    }

    pnk_demo_newsletter_redirect('success');
}
add_action('admin_post_pnk_newsletter_signup', 'pnk_demo_handle_newsletter_signup');
add_action('admin_post_nopriv_pnk_newsletter_signup', 'pnk_demo_handle_newsletter_signup');

function pnk_demo_newsletter_notice(): string {
    $status = isset($_GET['newsletter']) ? sanitize_key(wp_unslash($_GET['newsletter'])) : '';
    if ($status === 'success') {
        return '<div class="newsletter-notice is-success" role="status">Готово. Сообщим вам, как только новые инструменты станут доступны.</div>';
    }
    if ($status === 'invalid') {
        return '<div class="newsletter-notice is-error" role="alert">Проверьте email и подтвердите обязательные согласия.</div>';
    }
    if ($status === 'error') {
        return '<div class="newsletter-notice is-error" role="alert">Не удалось сохранить подписку. Попробуйте ещё раз или напишите нам.</div>';
    }
    return '';
}

function pnk_demo_footer_html(): string {
    $logo = esc_url(PRESENTONIKA_UI_URL . 'assets/demo/logo-mark.svg');
    $home = esc_url(pnk_demo_route_url('landing'));
    $help = esc_url(pnk_demo_route_url('podderzhka'));

    return '<footer class="pnk-demo-footer"><div class="pnk-demo-footer__inner"><div class="pnk-demo-footer__top"><a class="pnk-demo-footer__brand" href="' . $home . '"><img src="' . $logo . '" alt="" width="34" height="34"><span>Presentonica</span></a><nav class="pnk-demo-footer__links" aria-label="Документы и поддержка"><a href="' . $help . '">Сведения об организации</a><a href="' . $help . '">Пользовательское соглашение</a><a href="' . $help . '">Политика конфиденциальности</a><a href="' . $help . '">Правила оказания платных услуг</a><a href="' . $help . '">Политика использования файлов cookie</a><a href="' . $help . '">Родителям и законным представителям</a></nav></div><div class="pnk-demo-footer__legal"><span>© ООО «Цифровые коммуникации» 2025</span><span>Материалы создаются для образовательного процесса под ответственность пользователя. По вопросам — <a href="mailto:support@presentonica.ru">support@presentonica.ru</a></span></div></div></footer>';
}

function pnk_demo_integration_css(): string {
    return <<<'CSS'
<style id="presentonika-demo-integration">
  html { color-scheme: light; }
  body { letter-spacing: 0; }
  header {
    position: sticky !important;
    top: 0;
    z-index: 100 !important;
    background: rgba(255, 255, 255, .96) !important;
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
  }
  section[id], div[id] { scroll-margin-top: 96px; }
  a:focus-visible, button:focus-visible, input:focus-visible {
    outline: 3px solid rgba(68, 114, 217, .35) !important;
    outline-offset: 3px !important;
  }
  body[data-pnk-demo-page="registration"] .devbar { display: none !important; }
  body[data-pnk-demo-page="registration"] .view { display: none !important; }
  body[data-pnk-demo-page="registration"] #mode-desktop { display: block !important; }
  .pnk-demo-menu-toggle,
  .pnk-demo-mobile-nav { display: none; }
  .pnk-demo-account {
    display: inline-flex;
    min-height: 46px;
    align-items: center;
    gap: 10px;
    padding: 5px 10px 5px 6px;
    border: 1px solid #dfe4ee;
    border-radius: 8px;
    color: #1b2340;
    background: #fff;
    transition: border-color .18s ease, background-color .18s ease;
  }
  .pnk-demo-account:hover { border-color: #b9c8ec; background: #f7f9ff; }
  .pnk-demo-account__avatar {
    display: grid;
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    place-items: center;
    border-radius: 7px;
    color: #294fae;
    background: #eaf0ff;
    font-family: Manrope, Inter, sans-serif;
    font-size: 13px;
    font-weight: 800;
  }
  .pnk-demo-account__copy { display: grid; min-width: 0; gap: 1px; text-align: left; }
  .pnk-demo-account__copy small { color: #7b8497; font-size: 9px; font-weight: 600; line-height: 1.1; }
  .pnk-demo-account__copy strong { max-width: 126px; overflow: hidden; font-size: 12px; line-height: 1.2; text-overflow: ellipsis; white-space: nowrap; }
  .pnk-demo-footer {
    padding: 44px 32px 30px !important;
    border-top: 1px solid #e4e8f1 !important;
    color: #6b7280 !important;
    background: #fff !important;
    font-family: Inter, system-ui, sans-serif !important;
    text-align: left !important;
  }
  .pnk-demo-footer__inner { width: min(100%, 1180px); margin: 0 auto; }
  .pnk-demo-footer__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 32px; padding-bottom: 28px; }
  .pnk-demo-footer__brand { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 10px; color: #1b2340; font-family: Manrope, Inter, sans-serif; font-size: 17px; font-weight: 800; }
  .pnk-demo-footer__brand img { display: block; width: 34px; height: 34px; object-fit: contain; }
  .pnk-demo-footer__links { display: grid; grid-template-columns: repeat(2, minmax(210px, 1fr)); gap: 10px 42px; }
  .pnk-demo-footer__links a { color: #6b7280; font-size: 13px; line-height: 1.4; }
  .pnk-demo-footer__links a:hover { color: #2c3d93; }
  .pnk-demo-footer__legal { display: flex; justify-content: space-between; gap: 24px; padding-top: 20px; border-top: 1px solid #e4e8f1; font-size: 12px; line-height: 1.55; }
  .pnk-demo-footer__legal span:last-child { max-width: 660px; text-align: right; }
  .pnk-demo-footer__legal a { color: #2c3d93; font-weight: 600; }
  @media (max-width: 960px) {
    .pnk-demo-menu-toggle {
      display: grid;
      width: 40px;
      height: 40px;
      min-height: 40px;
      place-content: center;
      gap: 4px;
      padding: 0;
      border: 1px solid #d9deea;
      border-radius: 8px;
      color: #1b2340;
      background: #fff;
      box-shadow: none;
      cursor: pointer;
      appearance: none;
    }
    .pnk-demo-menu-toggle span {
      display: block;
      width: 17px;
      height: 2px;
      border-radius: 2px;
      background: currentColor;
    }
    .pnk-demo-mobile-nav {
      position: absolute;
      top: calc(100% + 8px);
      right: 16px;
      left: 16px;
      z-index: 50;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 4px;
      padding: 8px;
      border: 1px solid #e1e6ef;
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 16px 36px rgba(27, 35, 64, .14);
    }
    body.pnk-demo-menu-open .pnk-demo-mobile-nav { display: grid; }
    .pnk-demo-mobile-nav a {
      min-width: 0;
      padding: 11px 12px;
      border-radius: 6px;
      color: #29344c;
      font-size: 13px;
      font-weight: 650;
      text-align: center;
    }
    .pnk-demo-mobile-nav a:hover,
    .pnk-demo-mobile-nav a:focus-visible { color: #294fae; background: #eef3ff; }
    .pnk-demo-footer__top { flex-direction: column; }
  }
  @media (max-width: 720px) {
    .nav-row { padding-left: 16px !important; padding-right: 16px !important; }
    .nav-actions .btn-outline { display: none !important; }
    .nav-actions .btn-primary { padding: 10px 12px !important; font-size: 12px !important; }
    .logo span { font-size: 15px !important; }
    .pnk-demo-account { min-height: 40px; padding: 3px 5px; }
    .pnk-demo-account__avatar { width: 32px; height: 32px; flex-basis: 32px; }
    .pnk-demo-account__copy { display: none; }
    body[data-pnk-demo-page="app"] .lesson-row {
      display: grid !important;
      grid-template-columns: 40px minmax(0, 1fr) auto !important;
      gap: 10px 12px !important;
      padding: 14px !important;
    }
    body[data-pnk-demo-page="app"] .subject-badge { grid-column: 1; grid-row: 1 / 3; }
    body[data-pnk-demo-page="app"] .lesson-main { grid-column: 2 / 4; grid-row: 1; }
    body[data-pnk-demo-page="app"] .lesson-row > .pill { grid-column: 2; grid-row: 2; justify-self: start; }
    body[data-pnk-demo-page="app"] .row-actions { grid-column: 3; grid-row: 2; }
    body[data-pnk-demo-page="registration"] #mode-desktop { display: none !important; }
    body[data-pnk-demo-page="registration"] #mode-mobile { display: block !important; }
    .pnk-demo-footer { padding: 34px 18px 24px !important; }
    .pnk-demo-footer__links { grid-template-columns: 1fr; gap: 10px; }
    .pnk-demo-footer__legal { flex-direction: column; }
    .pnk-demo-footer__legal span:last-child { text-align: left; }
  }
  @media (max-width: 540px) {
    .nav-actions .btn-primary { display: none !important; }
    .pnk-demo-mobile-nav { grid-template-columns: 1fr; }
  }

  /* Mobile overflow fixes for narrow phones (320-430 px). */
  @media (max-width: 620px) {
    body[data-pnk-demo-page="landing"] section,
    body[data-pnk-demo-page="instrumenty"] section,
    body[data-pnk-demo-page="podderzhka"] section {
      padding-left: 16px !important;
      padding-right: 16px !important;
    }
    body[data-pnk-demo-page="landing"] .wrap,
    body[data-pnk-demo-page="podderzhka"] .wrap {
      width: 100% !important;
      max-width: 100% !important;
      padding-left: 0 !important;
      padding-right: 0 !important;
    }
    body[data-pnk-demo-page="landing"] .cta-band {
      width: 100%;
      max-width: 100%;
      padding: 32px 24px !important;
      box-sizing: border-box;
    }
    body[data-pnk-demo-page="landing"] .cta-band > * {
      width: 100%;
      min-width: 0;
      max-width: 100%;
    }
    body[data-pnk-demo-page="landing"] .cta-band .btn {
      width: 100%;
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
      justify-content: center;
      padding-left: 14px;
      padding-right: 14px;
      text-align: center;
      white-space: normal;
      overflow-wrap: anywhere;
    }
    body[data-pnk-demo-page="podderzhka"] .cat-head > * {
      min-width: 0;
    }
    body[data-pnk-demo-page="podderzhka"] .cat-head h2 {
      max-width: 100%;
      overflow-wrap: anywhere;
    }
    body[data-pnk-demo-page="instrumenty"] .contact-band,
    body[data-pnk-demo-page="podderzhka"] .contact-band {
      width: 100%;
      max-width: 100%;
      padding: 28px 24px !important;
      box-sizing: border-box;
    }
    body[data-pnk-demo-page="instrumenty"] .contact-band .btn,
    body[data-pnk-demo-page="podderzhka"] .contact-band .btn {
      display: flex;
      width: 100%;
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
      justify-content: center;
      padding-left: 12px;
      padding-right: 12px;
      text-align: center;
      white-space: normal;
      overflow-wrap: anywhere;
    }
    body[data-pnk-demo-page="tarify"] .plan-card,
    body[data-pnk-demo-page="tarify"] .info-card {
      width: 100%;
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }
    body[data-pnk-demo-page="tarify"] .plan-card > *,
    body[data-pnk-demo-page="tarify"] .info-card > *,
    body[data-pnk-demo-page="tarify"] .info-card li > * {
      min-width: 0;
      max-width: 100%;
    }
    body[data-pnk-demo-page="tarify"] .plan-badge {
      max-width: calc(100% - 24px);
      box-sizing: border-box;
      text-align: center;
      white-space: nowrap;
    }
    body[data-pnk-demo-page="tarify"] .btn-block {
      width: 100%;
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }
    body[data-pnk-demo-page="tarify"] .info-card li span {
      overflow-wrap: anywhere;
    }
  }
</style>
CSS;
}

function pnk_demo_integration_script(): string {
    $urls = [
        'landing'       => pnk_demo_route_url('landing'),
        'instrumenty'  => pnk_demo_route_url('instrumenty'),
        'tarify'        => pnk_demo_route_url('tarify'),
        'podderzhka'    => pnk_demo_route_url('podderzhka'),
        'registration' => pnk_demo_route_url('registration'),
        'app'           => pnk_demo_route_url('app'),
        'login'         => pnk_demo_product_url('/login/'),
        'create'        => pnk_demo_product_url('/createpres/'),
        'cta'           => is_user_logged_in() ? pnk_demo_product_url('/createpres/') : pnk_demo_product_url('/login/'),
        'cabinet'       => pnk_demo_product_url('/cabinet/'),
        'presentations' => pnk_demo_product_url('/cabinet/presentations/'),
        'templates'     => pnk_demo_product_url('/cabinet/templates/'),
        'account'       => pnk_demo_product_url('/cabinet/account/'),
        'payment'       => pnk_demo_product_url('/payment/'),
    ];

    $json = wp_json_encode($urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return <<<HTML
<script id="presentonika-demo-integration-script">
(() => {
  const urls = {$json};
  const clean = value => value.replace(/\s+/g, ' ').trim().toLocaleLowerCase('ru-RU');
  const links = new Map([
    ['главная', urls.landing],
    ['инструменты', urls.instrumenty],
    ['тарифы', urls.tarify],
    ['помощь', urls.podderzhka],
    ['помощь и поддержка', urls.podderzhka],
    ['войти', urls.login],
    ['войти через вконтакте', urls.login],
    ['продолжить через vk id', urls.login],
    ['продолжить', urls.login],
    ['попробовать бесплатно', urls.cta],
    ['зарегистрироваться', urls.cta],
    ['создать презентацию', urls.create],
    ['использовать', urls.create],
    ['дашборд', urls.cabinet],
    ['новая презентация', urls.create],
    ['презентации', urls.presentations],
    ['показать все →', urls.presentations],
    ['аккаунт', urls.account],
    ['история списаний →', urls.account],
    ['пополнить', urls.tarify],
    ['задать вопрос', urls.podderzhka],
  ]);

  document.querySelectorAll('a, button').forEach(element => {
    const url = links.get(clean(element.textContent || ''));
    if (!url) return;

    if (element instanceof HTMLAnchorElement) {
      element.href = url;
      return;
    }

    element.addEventListener('click', event => {
      event.preventDefault();
      window.location.assign(url);
    });
  });

  document.querySelectorAll('a.logo, header .logo').forEach(element => {
    if (element instanceof HTMLAnchorElement) element.href = urls.landing;
  });

  document.querySelectorAll('[data-pnk-plan]').forEach(element => {
    const plan = element.getAttribute('data-pnk-plan');
    if (element instanceof HTMLAnchorElement && plan) {
      element.href = urls.payment + '?plan=' + encodeURIComponent(plan);
    }
  });

  const menuButton = document.querySelector('[data-pnk-demo-menu]');
  const mobileMenu = document.querySelector('.pnk-demo-mobile-nav');
  if (menuButton && mobileMenu) {
    const setMenuOpen = open => {
      document.body.classList.toggle('pnk-demo-menu-open', open);
      menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
      menuButton.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    };
    menuButton.addEventListener('click', () => setMenuOpen(!document.body.classList.contains('pnk-demo-menu-open')));
    mobileMenu.addEventListener('click', event => {
      if (event.target.closest('a')) setMenuOpen(false);
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') setMenuOpen(false);
    });
  }
})();
</script>
HTML;
}

function pnk_demo_render_document(string $page): string {
    $template = pnk_demo_template_path($page);
    $html = is_readable($template) ? file_get_contents($template) : false;

    if ($html === false) {
        return '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Presentonica</title><body><p>Демо-макет временно недоступен.</p></body></html>';
    }

    $routes = pnk_demo_routes();
    $title = esc_html($routes[$page]['title'] ?? 'Presentonica');
    $html = preg_replace('/<title>.*?<\/title>/is', '<title>' . $title . '</title>', $html, 1);
    $html = preg_replace('/<body\b/i', '<body data-pnk-demo-page="' . esc_attr($page) . '"', $html, 1);
    $html = str_replace('__PNK_DEMO_ASSET__', esc_url(PRESENTONIKA_UI_URL . 'assets/demo'), $html);
    $html = str_replace('__PNK_NEWSLETTER_ACTION__', esc_url(admin_url('admin-post.php')), $html);
    $html = str_replace('__PNK_NEWSLETTER_NONCE__', esc_attr(wp_create_nonce('pnk_newsletter_signup')), $html);
    $html = str_replace('__PNK_NEWSLETTER_NOTICE__', pnk_demo_newsletter_notice(), $html);
    $privacy_url = get_privacy_policy_url();
    $html = str_replace('__PNK_PRIVACY_URL__', esc_url($privacy_url ?: pnk_demo_route_url('podderzhka')), $html);

    if (is_user_logged_in() && in_array($page, ['landing', 'instrumenty', 'tarify', 'podderzhka'], true)) {
        $user = wp_get_current_user();
        $name = trim((string) ($user->display_name ?: $user->user_login));
        $first_name = trim((string) get_user_meta($user->ID, 'first_name', true));
        if ($first_name !== '') $name = $first_name;
        $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));
        $account_action = '<div class="nav-actions"><a class="pnk-demo-account" href="' . esc_url(pnk_demo_product_url('/cabinet/')) . '"><span class="pnk-demo-account__avatar">' . esc_html($initial ?: 'P') . '</span><span class="pnk-demo-account__copy"><small>Мой аккаунт</small><strong>' . esc_html($name) . '</strong></span></a></div>';
        $html = preg_replace('/<div class="nav-actions">.*?<\/div>/is', $account_action, $html, 1);
    }

    if (in_array($page, ['landing', 'instrumenty', 'tarify', 'podderzhka'], true)) {
        $menu = '<button class="pnk-demo-menu-toggle" type="button" data-pnk-demo-menu aria-label="Открыть меню" aria-expanded="false"><span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span></button>';
        $html = preg_replace('/<div class="nav-actions">/i', '<div class="nav-actions">' . $menu, $html, 1);
        $account_mobile = is_user_logged_in() ? '<a href="' . esc_url(pnk_demo_product_url('/cabinet/')) . '">Мой аккаунт</a>' : '<a href="' . esc_url(pnk_demo_product_url('/login/')) . '">Войти</a>';
        $mobile_nav = '<nav class="pnk-demo-mobile-nav" aria-label="Мобильная навигация"><a href="' . esc_url(pnk_demo_route_url('landing')) . '">Главная</a><a href="' . esc_url(pnk_demo_route_url('instrumenty')) . '">Инструменты</a><a href="' . esc_url(pnk_demo_route_url('tarify')) . '">Тарифы</a><a href="' . esc_url(pnk_demo_route_url('podderzhka')) . '">Помощь</a>' . $account_mobile . '</nav>';
        $html = preg_replace('/<\/header>/i', $mobile_nav . '</header>', $html, 1);
    }
    $footer = pnk_demo_footer_html();
    if (preg_match('/<footer\b[^>]*>.*?<\/footer>/is', $html)) {
        $html = preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', $footer, $html, 1);
    } else {
        $html = str_ireplace('</body>', $footer . "\n</body>", $html);
    }
    $html = str_ireplace('</head>', pnk_demo_integration_css() . "\n</head>", $html);
    $html = str_ireplace('</body>', pnk_demo_integration_script() . "\n</body>", $html);

    return $html;
}

function pnk_demo_shortcode_frame(string $page): string {
    $url = esc_url(pnk_demo_route_url($page));
    $title = esc_attr(pnk_demo_routes()[$page]['title'] ?? 'Presentonica');

    return '<iframe src="' . $url . '" title="' . $title . '" loading="lazy" style="display:block;width:100%;min-height:900px;border:0;background:#fff"></iframe>';
}

add_shortcode('presentonika_demo_landing', function () {
    return pnk_demo_shortcode_frame('landing');
});

add_shortcode('presentonika_demo_page', function ($atts) {
    $atts = shortcode_atts(['page' => 'landing'], $atts);
    $page = sanitize_key((string) $atts['page']);
    return pnk_demo_shortcode_frame(array_key_exists($page, pnk_demo_routes()) ? $page : 'landing');
});

add_action('template_redirect', function () {
    $page = pnk_demo_current_route();
    if ($page === null) return;

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    echo pnk_demo_render_document($page); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}, -100);
