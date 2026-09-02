<?php
if (!defined('ABSPATH')) { exit; }

/**
 * [user_points]
 */
add_shortcode('user_points', function () {
    if (!is_user_logged_in()) return '';

    $user_id = get_current_user_id();
    $points = function_exists('pnk_get_user_points_balance')
        ? pnk_get_user_points_balance((int)$user_id)
        : 0;

    ob_start(); ?>
    <div class="pnk-points" aria-label="Баллы">
        <div class="pnk-points__label">Ваши баллы</div>
        <div class="pnk-points__value"><?php echo (int)$points; ?></div>
    </div>
    <?php return ob_get_clean();
});

/**
 * [presentation_form]
 */
add_shortcode('presentation_form', function () {
    $nonce = wp_create_nonce('presentation_nonce');

    ob_start(); ?>
    <div class="pnk pnk-form-wrap">
        <form class="pnk-form" id="pnk-presentationForm" autocomplete="off">
            <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
            <div class="pnk-form__main">
                <section class="pnk-form-section">
                    <div class="pnk-form-section__head"><span>1</span><div><h3>Тема и задача</h3><p>Опишите, о чём должна быть презентация и что важно объяснить аудитории.</p></div></div>
                    <div class="pnk-form__field">
                        <label class="pnk-label" for="pnk-text">Тема / запрос</label>
                        <textarea class="pnk-textarea" id="pnk-text" name="presentation_text" rows="8" minlength="10" required placeholder="Например: «Клеточное дыхание для 8 класса: этапы процесса и роль митохондрий»"></textarea>
                        <div class="pnk-help"><span class="pnk-muted">Минимум 10 символов</span><span class="pnk-muted"><span id="pnk-count">0</span> символов</span></div>
                    </div>
                </section>

                <section class="pnk-form-section">
                    <div class="pnk-form-section__head"><span>2</span><div><h3>Содержание</h3><p>Уточните аудиторию и логику подачи материала.</p></div></div>
                    <div class="pnk-form__row pnk-form__row--compact">
                        <div class="pnk-form__field"><label class="pnk-label" for="pnk-subject">Предмет</label><input class="pnk-input" id="pnk-subject" name="subject" type="text" placeholder="Например, биология"></div>
                        <div class="pnk-form__field"><label class="pnk-label" for="pnk-grade">Класс</label><input class="pnk-input" id="pnk-grade" name="grade" type="text" placeholder="8"></div>
                        <div class="pnk-form__field"><label class="pnk-label" for="pnk-slideCount">Слайдов</label><input class="pnk-input" id="pnk-slideCount" name="slide_count" type="number" min="1" max="20" value="10"></div>
                    </div>
                    <div class="pnk-form__field">
                        <label class="pnk-label" for="pnk-presentationType">Тип объяснения</label>
                        <span class="pnk-select-wrap"><select class="pnk-select" name="presentation_type" id="pnk-presentationType"><option value="auto">Подобрать автоматически</option><option value="historical_overview">Исторический обзор</option><option value="causes_consequences">Причины и последствия</option><option value="biography_contribution">Биография и вклад</option><option value="literary_analysis">Литературный разбор</option><option value="process">Пошаговый процесс</option><option value="comparison">Сравнение</option><option value="lesson">Учебное объяснение</option></select><span class="pnk-select-wrap__arrow" aria-hidden="true"></span></span>
                    </div>
                </section>

                <section class="pnk-form-section">
                    <div class="pnk-form-section__head"><span>3</span><div><h3>Оформление</h3><p>Выберите способ генерации и визуальный характер слайдов.</p></div></div>
                    <div class="pnk-form__row">
                        <div class="pnk-form__field"><div class="pnk-label">Движок</div><div class="pnk-segment" role="radiogroup" aria-label="Движок генерации"><label class="pnk-segment__item"><input type="radio" name="engine" value="deepseek" checked><span><strong>DeepSeek</strong><small>Структура и редактор</small></span></label><label class="pnk-segment__item"><input type="radio" name="engine" value="gamma"><span><strong>Gamma</strong><small>Быстрая сборка</small></span></label></div></div>
                        <div class="pnk-form__field"><label class="pnk-label" for="pnk-theme">Цветовая гамма</label><span class="pnk-select-wrap pnk-select-wrap--theme"><span class="pnk-theme-swatch" id="pnk-themeSwatch" aria-hidden="true"></span><select class="pnk-select" name="theme" id="pnk-theme"></select><span class="pnk-select-wrap__arrow" aria-hidden="true"></span></span></div>
                    </div>
                </section>
            </div>

            <aside class="pnk-form__aside">
                <span class="pnk-product-kicker">Перед запуском</span>
                <h3>Проверьте параметры</h3>
                <p>Система сначала соберёт структуру, чтобы вы могли поправить её до генерации слайдов.</p>
                <dl class="pnk-create-summary"><div><dt>Движок</dt><dd id="pnk-summaryEngine">DeepSeek</dd></div><div><dt>Оформление</dt><dd id="pnk-summaryTheme">Учительская · тёмная</dd></div><div><dt>Объём</dt><dd id="pnk-summarySlides">10 слайдов</dd></div><div><dt>Подача</dt><dd id="pnk-summaryType">Автоматически</dd></div></dl>
                <button type="submit" class="pnk-btn pnk-btn--primary" id="pnk-submit" aria-label="Создать презентацию"><span class="pnk-btn__text">Создать презентацию</span></button>
                <a class="pnk-form__back" href="/cabinet/">Вернуться в кабинет</a>
                <div class="pnk-status" id="pnk-status" hidden><div class="pnk-spinner" aria-hidden="true"></div><div class="pnk-status__text" id="pnk-statusText">Ждём запрос…</div></div>
            </aside>
        </form>
    </div>
    <?php return ob_get_clean();
});

/**
 * [presentation_plan]
 */
add_shortcode('presentation_plan', function () {
    if (!is_user_logged_in()) {
        return '<div class="pnk pnk-center"><div class="pnk-card"><h2>Нужно войти в аккаунт</h2><p><a class="pnk-link" href="' . esc_url(home_url('/login')) . '">Перейти к входу</a></p></div></div>';
    }

    $nonce = wp_create_nonce('presentation_nonce');

    ob_start(); ?>
    <div class="pnk pnk-plan-page" id="pnk-planPage" data-nonce="<?php echo esc_attr($nonce); ?>">
        <section class="pnk-plan pnk-plan--page">
            <div class="pnk-plan__top">
                <div class="pnk-plan__top-copy">
                    <div class="pnk-kicker">Черновик сценария</div>
                    <div class="pnk-title" id="pnk-planTyping">Структура готова к проверке</div>
                    <div class="pnk-muted pnk-plan__subtitle" id="pnk-planSubtitle">Проверьте ключевую мысль и последовательность слайдов.</div>
                </div>
                <div class="pnk-actions pnk-actions--tight">
                    <a class="pnk-btn pnk-btn--ghost" href="<?php echo esc_url(home_url('/createpres/')); ?>">Назад к настройкам</a>
                    <button type="button" class="pnk-btn pnk-btn--secondary" id="pnk-planRebuild">Пересобрать</button>
                </div>
            </div>

            <div class="pnk-plan__empty" id="pnk-planEmpty" hidden>
                <span class="pnk-product-kicker">Нет черновика</span>
                <div class="pnk-title">Сначала задайте тему презентации</div>
                <div class="pnk-muted">После настройки система подготовит структуру, которую можно будет проверить здесь.</div>
                <a class="pnk-btn pnk-btn--primary" href="<?php echo esc_url(home_url('/createpres/')); ?>">Перейти к созданию</a>
            </div>

            <form class="pnk-plan__editor" id="pnk-planEditor" hidden autocomplete="off">
                <div class="pnk-plan__summary">
                    <div class="pnk-plan-section__head">
                        <div>
                            <h3>Основа презентации</h3>
                            <p>Эти формулировки задают общую логику всему материалу.</p>
                        </div>
                    </div>
                    <div class="pnk-form__field pnk-plan__question">
                        <label class="pnk-label" for="pnk-planQuestion">Главный вопрос</label>
                        <input class="pnk-input" id="pnk-planQuestion" type="text">
                    </div>
                    <div class="pnk-form__field pnk-plan__thesis">
                        <label class="pnk-label" for="pnk-planThesis">Тезис</label>
                        <textarea class="pnk-textarea pnk-textarea--small" id="pnk-planThesis" rows="3"></textarea>
                    </div>
                </div>

                <div class="pnk-plan__sequence">
                    <div class="pnk-plan-section__head">
                        <div>
                            <h3>Сценарий по слайдам</h3>
                            <p>Проверьте задачу каждого слайда и уберите лишние ограничения.</p>
                        </div>
                        <span class="pnk-plan__count" id="pnk-planCount"></span>
                    </div>
                    <div class="pnk-plan__slides" id="pnk-planSlides"></div>
                </div>

                <div class="pnk-status" id="pnk-planStatus" hidden aria-live="polite">
                    <div class="pnk-spinner" aria-hidden="true"></div>
                    <div class="pnk-status__text" id="pnk-planStatusText"></div>
                </div>

                <div class="pnk-actions pnk-plan__actions">
                    <button type="button" class="pnk-btn pnk-btn--secondary" id="pnk-planSaveDraft">Сохранить черновик</button>
                    <button type="submit" class="pnk-btn pnk-btn--primary" id="pnk-planStart">Запустить генерацию</button>
                </div>
            </form>
        </section>
    </div>
    <?php return ob_get_clean();
});

add_action('template_redirect', function () {
    if (is_admin()) return;

    $path = isset($_SERVER['REQUEST_URI'])
        ? trim((string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')
        : '';

    if ($path !== 'plan') return;

    status_header(200);
    nocache_headers();
    get_header();
    echo do_shortcode('[presentation_plan]');
    get_footer();
    exit;
});

/**
 * [presentation_waiting]
 * expects ?presentation_id=123
 */
add_shortcode('presentation_waiting', function () {
    if (!is_user_logged_in()) {
        return '<div class="pnk pnk-center"><div class="pnk-card"><h2>Нужно войти в аккаунт</h2><p><a class="pnk-link" href="' . esc_url(home_url('/login')) . '">Перейти к входу</a></p></div></div>';
    }

    $pid = function_exists('pnk_detect_presentation_id_from_request') ? pnk_detect_presentation_id_from_request() : 0;
    // Also allow shortcode attribute (optional)
    $nonce = wp_create_nonce('presentation_nonce');

    ob_start(); ?>
    <div class="pnk pnk-center" id="pnk-waiting" data-nonce="<?php echo esc_attr($nonce); ?>" data-pid="<?php echo esc_attr((string)$pid); ?>">
        <div class="pnk-card pnk-card--wide">
            <div class="pnk-row">
                <div class="pnk-spinner pnk-spinner--lg" aria-hidden="true"></div>
                <div>
                    <div class="pnk-title">Генерация запущена</div>
                    <div class="pnk-muted" id="pnk-waitingStatus">Проверяем статус…</div>
                </div>
            </div>
            <div class="pnk-divider"></div>
            <div class="pnk-muted">Обычно это занимает 30–120 секунд. Страницу можно не обновлять — всё сделаем сами.</div>
        </div>
    </div>
    <?php return ob_get_clean();
});

/**
 * [presentation_open_editor id="18" auto="1"]
 */
add_shortcode('presentation_open_editor', function ($atts) {
    if (!is_user_logged_in()) {
        return '<div class="pnk pnk-center"><div class="pnk-card"><h2>Нужно войти в аккаунт</h2><p><a class="pnk-link" href="' . esc_url(home_url('/login')) . '">Перейти к входу</a></p></div></div>';
    }

    $atts = shortcode_atts([
        'id' => '0',
        'auto' => '0',
        'minimal' => '0',
    ], $atts);

    $pid = (int)$atts['id'];
    if ($pid <= 0 && function_exists('pnk_detect_presentation_id_from_request')) $pid = pnk_detect_presentation_id_from_request();
    if ($pid <= 0) {
        return '<div class="pnk pnk-center"><div class="pnk-card">Не удалось определить <code>presentation_id</code>.</div></div>';
    }

    $nonce = wp_create_nonce('presentation_nonce');
    $auto  = ((string)$atts['auto'] === '1');
    $minimal = ((string)$atts['minimal'] === '1');

    ob_start(); ?>
    <div class="pnk pnk-center<?php echo $minimal ? ' pnk-open-editor--minimal' : ''; ?>" id="pnk-openEditor" data-nonce="<?php echo esc_attr($nonce); ?>" data-pid="<?php echo esc_attr((string)$pid); ?>" data-auto="<?php echo $auto ? '1' : '0'; ?>">
        <?php if ($minimal): ?>
        <div class="pnk-editor-redirect" role="status"><div class="pnk-spinner pnk-spinner--lg" aria-hidden="true"></div><strong>Открываем редактор…</strong><span id="pnk-openStatus">Подготавливаем рабочую область</span><button class="pnk-editor-redirect__retry" id="pnk-openBtn" type="button" disabled>Повторить</button></div>
        <?php else: ?>
        <div class="pnk-card pnk-card--wide">
            <div class="pnk-title">Презентация #<?php echo (int)$pid; ?></div>
            <div class="pnk-muted" id="pnk-openStatus">Готово к открытию</div>
            <div class="pnk-actions">
                <button class="pnk-btn pnk-btn--primary" id="pnk-openBtn" type="button">Открыть в редакторе</button>
                <a class="pnk-btn pnk-btn--ghost" href="<?php echo esc_url(home_url('/cabinet')); ?>">В кабинет</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
});
