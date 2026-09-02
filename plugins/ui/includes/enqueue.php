<?php
if (!defined('ABSPATH')) { exit; }

function pnk_ui_should_enqueue(): bool {
    if (is_admin()) return false;

    // Cabinet page slug used in your current setup.
    $path = function_exists('pnk_product_request_path')
        ? pnk_product_request_path()
        : (isset($_SERVER['REQUEST_URI'])
            ? trim((string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')
            : '');

    $product_paths = [
        'login',
        'cabinet',
        'cabinet/presentations',
        'cabinet/templates',
        'cabinet/account',
        'payment',
        'createpres',
        'plan',
        'waiting',
        'presentation',
    ];

    if (in_array($path, $product_paths, true) || preg_match('#^presentation/\d+$#', $path)) return true;
    if (is_page('cabinet') || is_page('waiting') || is_page('plan')) return true;

    global $post;
    if (!$post || empty($post->post_content)) return false;

    $c = (string)$post->post_content;
    $shortcodes = ['presentation_form','presentation_plan','presentation_waiting','presentation_open_editor','user_points','presentonika_cabinet','presentonika_cabinet_page'];

    foreach ($shortcodes as $s) {
        if (has_shortcode($c, $s)) return true;
    }

    return false;
}

add_action('wp_enqueue_scripts', function () {
    if (!pnk_ui_should_enqueue()) return;

    wp_enqueue_style(
        'presentonika-ui',
        PRESENTONIKA_UI_URL . 'assets/css/presentonika-ui.css',
        [],
        PRESENTONIKA_UI_VERSION
    );

    wp_enqueue_script(
        'presentonika-ui',
        PRESENTONIKA_UI_URL . 'assets/js/presentonika-ui.js',
        [],
        PRESENTONIKA_UI_VERSION,
        true
    );

    $orchThemesRaw = defined('PRESENTONIKA_ORCHESTRATOR_THEMES') ? (string)PRESENTONIKA_ORCHESTRATOR_THEMES : 'teacher-dark,teacher-light,teacher-bright';
    $orchThemes = array_values(array_filter(array_map('trim', explode(',', $orchThemesRaw))));
    if (!$orchThemes) $orchThemes = ['teacher-dark','teacher-light','teacher-bright'];

    $orchThemeOptions = array_map(function($t){
        // make labels nicer
        $label = $t;
        if (strpos($t, 'teacher-') === 0) {
            $names = [
                'dark' => 'Учительская · тёмная',
                'light' => 'Учительская · светлая',
                'bright' => 'Учительская · яркая',
            ];
            $variant = substr($t, 8);
            $label = $names[$variant] ?? ('Учительская · ' . $variant);
        }
        return ['value' => $t, 'label' => $label];
    }, $orchThemes);

    $gammaThemeOptions = [
        ['value' => 'default', 'label' => 'Классическая'],
        ['value' => 'dark',    'label' => 'Тёмная'],
        ['value' => 'light',   'label' => 'Светлая'],
    ];

    wp_localize_script('presentonika-ui', 'pnkUi', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('presentation_nonce'),
        'waitingUrl' => home_url('/waiting/'),
        'planUrl'    => home_url('/plan/'),
        'loginUrl'   => home_url('/login'),
        'editorBase' => defined('PRESENTONIKA_EDITOR_BASE') ? rtrim((string)PRESENTONIKA_EDITOR_BASE, '/') : '',
        'debugPlan'  => defined('PRESENTONIKA_PLAN_DEBUG_UI') && PRESENTONIKA_PLAN_DEBUG_UI,
        'themes' => [
            'deepseek' => $orchThemeOptions,
            'gamma'    => $gammaThemeOptions,
        ],
    ]);
});
