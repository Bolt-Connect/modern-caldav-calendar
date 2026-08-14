<?php
/*
Plugin Name: Modern CalDAV Calendar
Description: Moderne CalDAV kalender plugin met styling, herhalingen en veiligheid. Credentials beheerd via admin-instellingen. Optionele meertaligheid via DeepL.
Version: 2.6.4
Author: Bolt Connect
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: modern-caldav-calendar
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

define('CALDAV_PLUGIN_VERSION', '2.6.4');
define('CALDAV_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CALDAV_PLUGIN_URL', plugin_dir_url(__FILE__));

// === HOOKS ===

register_activation_hook(__FILE__, 'caldav_plugin_activate');
register_deactivation_hook(__FILE__, 'caldav_plugin_deactivate');
register_uninstall_hook(__FILE__, 'caldav_plugin_uninstall');

function caldav_plugin_activate() {
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Modern CalDAV Calendar vereist PHP 7.4 of hoger.');
    }

    if (!function_exists('curl_init')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Modern CalDAV Calendar vereist de PHP cURL extensie.');
    }

    add_option('caldav_plugin_version', CALDAV_PLUGIN_VERSION);
    add_option('caldav_cache_enabled', true);
    add_option('caldav_cache_duration', 300);
    add_option('caldav_connections', []);
    add_option('caldav_deepl_api_key', '');
    add_option('caldav_show_activation_notice', true);
}

function caldav_plugin_deactivate() {
    caldav_clear_all_cache();
}

function caldav_plugin_uninstall() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'caldav_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_caldav_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_caldav_%'");
}

// === CONNECTIONS API ===

function caldav_get_connections() {
    $connections = get_option('caldav_connections', []);
    return is_array($connections) ? $connections : [];
}

function caldav_get_connection($slug) {
    $connections = caldav_get_connections();
    return $connections[$slug] ?? null;
}

function caldav_save_connection($slug, $data) {
    $connections = caldav_get_connections();
    $connections[$slug] = $data;
    update_option('caldav_connections', $connections);
}

function caldav_delete_connection($slug) {
    $connections = caldav_get_connections();
    unset($connections[$slug]);
    update_option('caldav_connections', $connections);
    // Laat geen verweesde terugval-/statusopties achter in wp_options.
    caldav_delete_connection_state($slug);
}

function caldav_sanitize_slug($slug) {
    return sanitize_title($slug);
}

// === SECURITY & CACHING ===

function caldav_sanitize_url($url) {
    $url = str_replace('%40', '@', $url);
    $url = sanitize_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    return $url;
}

function caldav_get_cache_key($slug, $options = []) {
    $key_data = [
        'slug' => $slug,
        'count' => $options['count'] ?? 10,
        'limitdays' => $options['limitdays'] ?? 365,
        'pastdays' => $options['pastdays'] ?? 0
    ];
    return 'caldav_cache_' . md5(serialize($key_data));
}

function caldav_get_cached_events($cache_key) {
    if (!get_option('caldav_cache_enabled', true)) {
        return false;
    }

    $cached = get_transient($cache_key);
    if ($cached && isset($cached['events']) && isset($cached['timestamp'])) {
        $cache_duration = get_option('caldav_cache_duration', 300);
        if ((time() - $cached['timestamp']) < $cache_duration) {
            return $cached['events'];
        }
    }

    return false;
}

function caldav_cache_events($cache_key, $events) {
    if (get_option('caldav_cache_enabled', true)) {
        $cache_data = [
            'events' => $events,
            'timestamp' => time()
        ];
        set_transient($cache_key, $cache_data, get_option('caldav_cache_duration', 300));
    }
}

function caldav_clear_all_cache() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_caldav_cache_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_caldav_cache_%'");
}

// "Laatst bekende goede" agenda per verbinding. Wordt bij elke geslaagde fetch
// opgeslagen en gebruikt als terugval als de CalDAV-server later even faalt,
// zodat de kalender nooit leeg/wit wordt. Bewaard als (niet-autoloaded) option,
// dus blijft staan ook als de transient-cache wordt geleegd.
function caldav_store_lastgood($slug, $events) {
    if (empty($slug) || empty($events)) {
        return;
    }
    update_option('caldav_lastgood_' . $slug, $events, false);
    update_option('caldav_lastgood_time_' . $slug, time(), false);
}

function caldav_get_lastgood($slug) {
    if (empty($slug)) {
        return [];
    }
    $events = get_option('caldav_lastgood_' . $slug, []);
    return is_array($events) ? $events : [];
}

// Tijdstip van de laatste geslaagde ophaalactie (0 = onbekend).
function caldav_get_lastgood_time($slug) {
    if (empty($slug)) {
        return 0;
    }
    return (int) get_option('caldav_lastgood_time_' . $slug, 0);
}

// === HEALTH / STATUS (v2.6.4) ===
//
// De terugval op de "laatst bekende goede agenda" (v2.6.3) voorkomt een lege
// pagina, maar verbergt daarmee ook een kapotte verbinding: bezoekers zien een
// nette agenda terwijl die in werkelijkheid bevroren is. Daarom houden we per
// verbinding de laatste fout bij en waarschuwen we de beheerder in wp-admin.

function caldav_get_fetch_error($slug) {
    if (empty($slug)) {
        return null;
    }
    $status = get_option('caldav_status_' . $slug, null);
    return (is_array($status) && !empty($status['message'])) ? $status : null;
}

// Leg een mislukte ophaalactie vast. Schrijft hooguit eens per 5 minuten naar
// de database (of direct bij een nieuwe foutmelding), zodat een kapotte
// verbinding op een drukke pagina geen schrijfactie per paginaweergave geeft.
function caldav_record_fetch_error($slug, $message) {
    if (empty($slug) || $message === '') {
        return;
    }

    $existing = get_option('caldav_status_' . $slug, null);
    if (is_array($existing)
        && ($existing['message'] ?? '') === $message
        && (time() - (int) ($existing['time'] ?? 0)) < 5 * MINUTE_IN_SECONDS) {
        return;
    }

    update_option('caldav_status_' . $slug, [
        'message' => $message,
        'time'    => time(),
        // Eerste keer dat deze storing optrad; blijft staan zolang de fout duurt.
        'since'   => (is_array($existing) && !empty($existing['since'])) ? (int) $existing['since'] : time(),
    ], false);
}

// Wis de foutstatus na een geslaagde ophaalactie.
function caldav_clear_fetch_error($slug) {
    if (empty($slug)) {
        return;
    }
    if (get_option('caldav_status_' . $slug, null) !== null) {
        delete_option('caldav_status_' . $slug);
    }
}

// Verwijder alle opgeslagen status/terugval-data van één verbinding.
function caldav_delete_connection_state($slug) {
    if (empty($slug)) {
        return;
    }
    delete_option('caldav_lastgood_' . $slug);
    delete_option('caldav_lastgood_time_' . $slug);
    delete_option('caldav_status_' . $slug);
}

function caldav_log_error($message, $context = []) {
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $log_message = 'CalDAV Calendar: ' . $message;
        if (!empty($context)) {
            $log_message .= ' Context: ' . json_encode($context);
        }
        error_log($log_message);
    }
}

function caldav_check_rate_limit($url) {
    $rate_limit_key = 'caldav_rate_limit_' . md5($url);
    $requests = get_transient($rate_limit_key);

    if (!$requests) {
        $requests = 0;
    }

    if ($requests >= 10) {
        return false;
    }

    set_transient($rate_limit_key, $requests + 1, 60);
    return true;
}

// === TRANSLATION (DeepL) ===

// Talen die de plugin als doeltaal accepteert in de shortcode.
// 'nl' is de brontaal en wordt nooit vertaald.
function caldav_supported_languages() {
    return ['nl', 'en', 'de', 'fr', 'es'];
}

// Cache-sleutel voor één vertaalde bron-tekst.
function caldav_tr_cache_key($target, $is_html, $text) {
    return 'caldav_tr_' . md5($target . '|' . ($is_html ? 'h|' : 't|') . $text);
}

// Haal een eerder gecachete vertaling op (of false).
function caldav_tr_cached($target, $is_html, $text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    return get_transient(caldav_tr_cache_key($target, $is_html, $text));
}

// Vertaal een set unieke teksten in zo min mogelijk DeepL-verzoeken (batch).
// Elke geslaagde vertaling wordt 30 dagen gecachet. Bij een rate-limit (429),
// quota-fout (456), netwerk- of HTTP-fout wordt een korte cooldown gezet zodat
// de plugin DeepL niet bij elke paginaweergave blijft bestoken; de teksten
// blijven dan tijdelijk Nederlands en worden later alsnog vertaald.
function caldav_deepl_translate_batch($texts, $target, $is_html, $api_key) {
    $texts = array_values(array_unique(array_filter(array_map('trim', $texts), 'strlen')));
    if (empty($texts)) {
        return;
    }

    // Een gratis DeepL-key eindigt op ':fx' en gebruikt een ander endpoint.
    $endpoint = (substr($api_key, -3) === ':fx')
        ? 'https://api-free.deepl.com/v2/translate'
        : 'https://api.deepl.com/v2/translate';

    // Max 40 teksten per verzoek (DeepL staat er 50 toe — veilige marge).
    foreach (array_chunk($texts, 40) as $chunk) {
        $payload = [
            'text'        => $chunk,
            'source_lang' => 'NL',
            'target_lang' => strtoupper($target),
        ];
        if ($is_html) {
            // Behoudt <br> en andere tags in de beschrijving.
            $payload['tag_handling'] = 'html';
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 8,
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            caldav_log_error('DeepL request failed', ['error' => $response->get_error_message()]);
            set_transient('caldav_tr_cooldown', 1, 2 * MINUTE_IN_SECONDS);
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 429 || $code === 456 || $code === 529) {
            // Te veel verzoeken / quota op → langer terugtrekken.
            caldav_log_error('DeepL rate/quota limit', ['code' => $code]);
            set_transient('caldav_tr_cooldown', 1, 5 * MINUTE_IN_SECONDS);
            return;
        }
        if ($code !== 200) {
            caldav_log_error('DeepL HTTP error', ['code' => $code]);
            set_transient('caldav_tr_cooldown', 1, 2 * MINUTE_IN_SECONDS);
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $translations = $data['translations'] ?? [];
        foreach ($chunk as $i => $src) {
            $translated = $translations[$i]['text'] ?? null;
            if ($translated !== null && $translated !== '') {
                set_transient(caldav_tr_cache_key($target, $is_html, $src), $translated, 30 * DAY_IN_SECONDS);
            }
        }
    }
}

// Vertaal titel + beschrijving van de zichtbare events (tot 'count').
// Werkwijze: eerst de cache vullen voor alle nog-onvertaalde unieke teksten
// (gebatcht, max 2 DeepL-verzoeken per render), daarna uit de cache toepassen.
// Alleen nieuwe/gewijzigde teksten kosten dus een vertaling.
// $incomplete (per referentie) wordt true als minstens één zichtbare tekst
// niet vertaald kon worden en dus in het Nederlands wordt getoond.
function caldav_translate_events($events, $options, &$incomplete = false) {
    $target = strtolower($options['lang'] ?? 'nl');
    if ($target === 'nl') {
        return $events;
    }

    $api_key = trim(get_option('caldav_deepl_api_key', ''));
    if (empty($api_key)) {
        $incomplete = true; // geen key → niets vertaald, blijft Nederlands
        return $events;
    }

    $limit = max(1, intval($options['count']));

    // 1. Verzamel de unieke teksten die nog niet in de cache staan.
    $need_titles = [];
    $need_descs  = [];
    $n = 0;
    foreach ($events as $event) {
        if ($n++ >= $limit) {
            break;
        }
        $summary = trim($event['summary'] ?? '');
        if ($summary !== '' && caldav_tr_cached($target, false, $summary) === false) {
            $need_titles[$summary] = true;
        }
        $desc = trim($event['description'] ?? '');
        if ($desc !== '' && caldav_tr_cached($target, true, $desc) === false) {
            $need_descs[$desc] = true;
        }
    }

    // 2. Vul de cache (tenzij we net een rate-limit raakten → cooldown actief).
    if ((!empty($need_titles) || !empty($need_descs)) && !get_transient('caldav_tr_cooldown')) {
        if (!empty($need_titles)) {
            caldav_deepl_translate_batch(array_keys($need_titles), $target, false, $api_key);
        }
        if (!empty($need_descs)) {
            caldav_deepl_translate_batch(array_keys($need_descs), $target, true, $api_key);
        }
    }

    // 3. Pas toe uit de cache; niet-gevonden teksten blijven Nederlands en
    //    markeren de vertaling als onvolledig.
    $n = 0;
    foreach ($events as &$event) {
        if ($n++ >= $limit) {
            break;
        }
        $summary = trim($event['summary'] ?? '');
        if ($summary !== '') {
            $cached = caldav_tr_cached($target, false, $summary);
            if ($cached !== false && $cached !== '') {
                $event['summary'] = $cached;
            } else {
                $incomplete = true;
            }
        }
        $desc = trim($event['description'] ?? '');
        if ($desc !== '') {
            $cached = caldav_tr_cached($target, true, $desc);
            if ($cached !== false && $cached !== '') {
                $event['description'] = $cached;
            } else {
                $incomplete = true;
            }
        }
    }
    unset($event);

    return $events;
}

// === ADMIN ===

function caldav_admin_menu() {
    if (!current_user_can('manage_options')) {
        return;
    }

    add_options_page(
        'CalDAV Calendar Instellingen',
        'CalDAV Calendar',
        'manage_options',
        'caldav-calendar',
        'caldav_admin_page'
    );
}
add_action('admin_menu', 'caldav_admin_menu');

function caldav_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Je hebt niet voldoende rechten om deze pagina te bekijken.');
    }

    caldav_handle_admin_actions();

    $allowed_tabs = ['connections', 'settings', 'help'];
    $active_tab = isset($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs) ? $_GET['tab'] : 'connections';
    $base_url = admin_url('options-general.php?page=caldav-calendar');
    ?>
    <div class="wrap">
        <h1>CalDAV Calendar <span style="font-size:0.6em;color:#666;">v<?php echo esc_html(CALDAV_PLUGIN_VERSION); ?></span></h1>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url($base_url . '&tab=connections'); ?>" class="nav-tab <?php echo $active_tab === 'connections' ? 'nav-tab-active' : ''; ?>">Verbindingen</a>
            <a href="<?php echo esc_url($base_url . '&tab=settings'); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Instellingen</a>
            <a href="<?php echo esc_url($base_url . '&tab=help'); ?>" class="nav-tab <?php echo $active_tab === 'help' ? 'nav-tab-active' : ''; ?>">Hulp</a>
        </h2>

        <?php
        switch ($active_tab) {
            case 'settings':
                caldav_render_settings_tab();
                break;
            case 'help':
                caldav_render_help_tab();
                break;
            case 'connections':
            default:
                caldav_render_connections_tab();
                break;
        }
        ?>
    </div>
    <?php
}

function caldav_handle_admin_actions() {
    // Cache settings update
    if (isset($_POST['caldav_action']) && $_POST['caldav_action'] === 'update_settings'
        && wp_verify_nonce($_POST['caldav_nonce'] ?? '', 'caldav_update_settings')) {
        $cache_enabled = isset($_POST['cache_enabled']);
        $cache_duration = max(60, min(3600, intval($_POST['cache_duration'] ?? 300)));
        $deepl_key = sanitize_text_field($_POST['deepl_api_key'] ?? '');
        update_option('caldav_cache_enabled', $cache_enabled);
        update_option('caldav_cache_duration', $cache_duration);
        update_option('caldav_deepl_api_key', $deepl_key);
        add_settings_error('caldav', 'saved', 'Instellingen opgeslagen.', 'updated');
    }

    // Clear cache
    if (isset($_POST['caldav_action']) && $_POST['caldav_action'] === 'clear_cache'
        && wp_verify_nonce($_POST['caldav_nonce'] ?? '', 'caldav_clear_cache')) {
        caldav_clear_all_cache();
        add_settings_error('caldav', 'cache_cleared', 'Cache geleegd.', 'updated');
    }

    // Save connection (add or edit)
    if (isset($_POST['caldav_action']) && $_POST['caldav_action'] === 'save_connection'
        && wp_verify_nonce($_POST['caldav_nonce'] ?? '', 'caldav_save_connection')) {
        $original_slug = caldav_sanitize_slug($_POST['original_slug'] ?? '');
        $slug = caldav_sanitize_slug($_POST['slug'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $url = caldav_sanitize_url($_POST['url'] ?? '');
        $user = sanitize_text_field($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';

        if (empty($slug) || empty($name) || empty($url)) {
            add_settings_error('caldav', 'invalid', 'Naam, slug en URL zijn verplicht. URL moet geldig zijn.', 'error');
        } else {
            $existing = caldav_get_connection($original_slug);

            // If password left empty during edit, keep the existing one
            if ($existing && empty($pass)) {
                $pass = $existing['pass'] ?? '';
            }

            // If slug changed during edit, remove the old entry
            if (!empty($original_slug) && $original_slug !== $slug) {
                caldav_delete_connection($original_slug);
            }

            // Check for collision when adding a new connection
            if (empty($original_slug) && caldav_get_connection($slug) !== null) {
                add_settings_error('caldav', 'duplicate', 'Slug "' . esc_html($slug) . '" bestaat al.', 'error');
            } else {
                caldav_save_connection($slug, [
                    'name' => $name,
                    'url' => $url,
                    'user' => $user,
                    'pass' => $pass,
                ]);
                add_settings_error('caldav', 'saved', 'Verbinding "' . esc_html($name) . '" opgeslagen.', 'updated');
            }
        }
    }

    // Test connection (v2.6.4)
    if (isset($_POST['caldav_action']) && $_POST['caldav_action'] === 'test_connection'
        && wp_verify_nonce($_POST['caldav_nonce'] ?? '', 'caldav_test_connection')) {
        $slug = caldav_sanitize_slug($_POST['slug'] ?? '');
        $test = caldav_run_connection_test($slug);
        add_settings_error('caldav', 'test', $test['message'], $test['ok'] ? 'updated' : 'error');
    }

    // Delete connection
    if (isset($_POST['caldav_action']) && $_POST['caldav_action'] === 'delete_connection'
        && wp_verify_nonce($_POST['caldav_nonce'] ?? '', 'caldav_delete_connection')) {
        $slug = caldav_sanitize_slug($_POST['slug'] ?? '');
        if (!empty($slug)) {
            caldav_delete_connection($slug);
            add_settings_error('caldav', 'deleted', 'Verbinding "' . esc_html($slug) . '" verwijderd.', 'updated');
        }
    }
}

// Voer een live test uit op één verbinding: haalt de agenda echt op, telt de
// events en werkt de statusregistratie bij. Bij succes wordt de cache geleegd,
// zodat de site meteen de verse agenda toont in plaats van de terugval.
function caldav_run_connection_test($slug) {
    if (empty($slug)) {
        return ['ok' => false, 'message' => 'Geen verbinding opgegeven.'];
    }

    $connection = caldav_get_connection($slug);
    if (!$connection) {
        return ['ok' => false, 'message' => 'Verbinding "' . esc_html($slug) . '" niet gevonden.'];
    }

    $url = caldav_sanitize_url($connection['url'] ?? '');
    if (!$url) {
        caldav_record_fetch_error($slug, 'Ongeldige CalDAV-URL in de verbinding.');
        return ['ok' => false, 'message' => 'De CalDAV-URL van "' . esc_html($slug) . '" is ongeldig.'];
    }

    $started = microtime(true);
    $fetch = caldav_fetch_ical($url, sanitize_text_field($connection['user'] ?? ''), $connection['pass'] ?? '');
    $duration = number_format_i18n(microtime(true) - $started, 1);

    if (!$fetch['ok']) {
        caldav_record_fetch_error($slug, $fetch['error']);
        return [
            'ok' => false,
            'message' => '<strong>Test mislukt voor "' . esc_html($slug) . '"</strong> (na ' . $duration . ' s): '
                       . esc_html($fetch['error'])
                       . '<br>De kalender op de site toont zolang de laatst bekende agenda.',
        ];
    }

    // Ruim venster, zodat de test niet faalt door een toevallig lege week.
    $events = caldav_parse_events($fetch['ical'], ['limitdays' => 365, 'pastdays' => 0]);
    caldav_clear_fetch_error($slug);
    caldav_clear_all_cache();

    $message = '<strong>Verbinding "' . esc_html($slug) . '" werkt.</strong> '
             . 'HTTP ' . intval($fetch['http']) . ', ' . intval($fetch['items']) . ' agenda-items opgehaald in ' . $duration . ' s. ';

    if (empty($events)) {
        $message .= 'Let op: geen enkel event in de komende 365 dagen — de agenda zelf is leeg.';
    } else {
        usort($events, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });
        $first = $events[0];
        $message .= count($events) . ' event(s) in het komende jaar, eerstvolgende: '
                  . esc_html($first['summary']) . ' op ' . esc_html($first['start']->format('d-m-Y H:i')) . '.';
    }

    $message .= '<br>De cache is geleegd, dus de site toont vanaf nu weer verse gegevens.';

    return ['ok' => true, 'message' => $message];
}

function caldav_render_connections_tab() {
    settings_errors('caldav');

    $base_url = admin_url('options-general.php?page=caldav-calendar&tab=connections');

    // Edit or add mode
    $is_adding = isset($_GET['add']);
    $editing_slug = isset($_GET['edit']) ? caldav_sanitize_slug($_GET['edit']) : '';

    if ($is_adding || $editing_slug) {
        $connection = $editing_slug ? caldav_get_connection($editing_slug) : null;
        $form_title = $editing_slug ? 'Verbinding bewerken' : 'Nieuwe verbinding';
        ?>
        <h2><?php echo esc_html($form_title); ?></h2>
        <form method="post">
            <?php wp_nonce_field('caldav_save_connection', 'caldav_nonce'); ?>
            <input type="hidden" name="caldav_action" value="save_connection">
            <input type="hidden" name="original_slug" value="<?php echo esc_attr($editing_slug); ?>">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="caldav-name">Naam</label></th>
                    <td>
                        <input id="caldav-name" type="text" name="name" class="regular-text" required
                               value="<?php echo esc_attr($connection['name'] ?? ''); ?>">
                        <p class="description">Vrije omschrijving voor in het admin-overzicht.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="caldav-slug">Slug</label></th>
                    <td>
                        <input id="caldav-slug" type="text" name="slug" class="regular-text" required
                               pattern="[a-z0-9-]+"
                               value="<?php echo esc_attr($editing_slug); ?>">
                        <p class="description">Technische ID, alleen kleine letters, cijfers en streepjes. Gebruikt in de shortcode: <code>connection="slug"</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="caldav-url">CalDAV URL</label></th>
                    <td>
                        <input id="caldav-url" type="url" name="url" class="regular-text code" required
                               value="<?php echo esc_attr($connection['url'] ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="caldav-user">Gebruikersnaam</label></th>
                    <td>
                        <input id="caldav-user" type="text" name="user" class="regular-text" autocomplete="off"
                               value="<?php echo esc_attr($connection['user'] ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="caldav-pass">Wachtwoord</label></th>
                    <td>
                        <input id="caldav-pass" type="password" name="pass" class="regular-text" autocomplete="new-password">
                        <?php if ($editing_slug): ?>
                            <p class="description">Laat leeg om het huidige wachtwoord te behouden.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button-primary" value="Opslaan">
                <a href="<?php echo esc_url($base_url); ?>" class="button">Annuleren</a>
            </p>
        </form>
        <?php
        return;
    }

    // List view
    $connections = caldav_get_connections();
    ?>
    <h2>Verbindingen</h2>
    <p>Beheer hier de CalDAV-verbindingen. Verwijs in je shortcode naar de slug: <code>[caldav_calendar connection="slug"]</code>.</p>

    <p>
        <a href="<?php echo esc_url($base_url . '&add=1'); ?>" class="button button-primary">Verbinding toevoegen</a>
    </p>

    <?php if (empty($connections)): ?>
        <p><em>Nog geen verbindingen aangemaakt.</em></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Naam</th>
                    <th>Slug</th>
                    <th>URL</th>
                    <th>Gebruiker</th>
                    <th style="width:230px;">Status</th>
                    <th style="width:220px;">Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($connections as $slug => $conn): ?>
                    <?php
                    $status = caldav_get_fetch_error($slug);
                    $last_ok = caldav_get_lastgood_time($slug);
                    ?>
                    <tr>
                        <td><?php echo esc_html($conn['name'] ?? '(naamloos)'); ?></td>
                        <td><code><?php echo esc_html($slug); ?></code></td>
                        <td><?php echo esc_html($conn['url'] ?? ''); ?></td>
                        <td><?php echo esc_html($conn['user'] ?? ''); ?></td>
                        <td>
                            <?php if ($status): ?>
                                <span style="color:#b32d2e;font-weight:600;">&#10007; Fout</span><br>
                                <span style="color:#b32d2e;"><?php echo esc_html($status['message']); ?></span><br>
                                <small>Al mis sinds <?php echo esc_html(human_time_diff((int) $status['since'], time())); ?>.</small>
                                <?php if ($last_ok): ?>
                                    <br><small>Toont de agenda van <?php echo esc_html(human_time_diff($last_ok, time())); ?> geleden.</small>
                                <?php endif; ?>
                            <?php elseif ($last_ok): ?>
                                <span style="color:#207b3f;font-weight:600;">&#10003; OK</span><br>
                                <small>Laatst opgehaald <?php echo esc_html(human_time_diff($last_ok, time())); ?> geleden.</small>
                            <?php else: ?>
                                <span style="color:#666;">Nog niet opgehaald.</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('caldav_test_connection', 'caldav_nonce'); ?>
                                <input type="hidden" name="caldav_action" value="test_connection">
                                <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                <button type="submit" class="button">Testen</button>
                            </form>
                            <a href="<?php echo esc_url($base_url . '&edit=' . urlencode($slug)); ?>">Bewerken</a>
                            |
                            <form method="post" style="display:inline;" onsubmit="return confirm('Verbinding &quot;<?php echo esc_js($slug); ?>&quot; verwijderen?');">
                                <?php wp_nonce_field('caldav_delete_connection', 'caldav_nonce'); ?>
                                <input type="hidden" name="caldav_action" value="delete_connection">
                                <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
                                <button type="submit" class="button-link" style="color:#b32d2e;">Verwijderen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

function caldav_render_settings_tab() {
    settings_errors('caldav');

    $cache_enabled = get_option('caldav_cache_enabled', true);
    $cache_duration = get_option('caldav_cache_duration', 300);
    $deepl_key = get_option('caldav_deepl_api_key', '');
    ?>
    <h2>Cache & instellingen</h2>

    <form method="post">
        <?php wp_nonce_field('caldav_update_settings', 'caldav_nonce'); ?>
        <input type="hidden" name="caldav_action" value="update_settings">

        <table class="form-table">
            <tr>
                <th scope="row"><label for="caldav-deepl-key">DeepL API-key</label></th>
                <td>
                    <input id="caldav-deepl-key" type="password" name="deepl_api_key" class="regular-text"
                           autocomplete="off" value="<?php echo esc_attr($deepl_key); ?>">
                    <p class="description">
                        Optioneel. Nodig voor automatische vertaling van titel en beschrijving
                        bij <code>lang="en"</code> (of een andere taal) in de shortcode. Zonder key
                        blijven titel/beschrijving in het Nederlands; alleen datums/weekdagen worden
                        dan nog vertaald. Een gratis key (eindigt op <code>:fx</code>) werkt tot
                        500.000 tekens/maand. Aanvragen via
                        <a href="https://www.deepl.com/pro-api" target="_blank" rel="noopener">deepl.com/pro-api</a>.
                        Vertalingen worden 30 dagen gecachet.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">Cache inschakelen</th>
                <td>
                    <label>
                        <input type="checkbox" name="cache_enabled" <?php checked($cache_enabled); ?>>
                        Cache kalenderdata om prestaties te verbeteren
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="caldav-cache-duration">Cache-duur (seconden)</label></th>
                <td>
                    <input id="caldav-cache-duration" type="number" name="cache_duration" min="60" max="3600"
                           value="<?php echo esc_attr($cache_duration); ?>">
                    <p class="description">Tussen 60 en 3600 seconden (1 minuut tot 1 uur).</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" class="button-primary" value="Instellingen opslaan">
        </p>
    </form>

    <hr>

    <h2>Cache beheer</h2>
    <form method="post">
        <?php wp_nonce_field('caldav_clear_cache', 'caldav_nonce'); ?>
        <input type="hidden" name="caldav_action" value="clear_cache">
        <p>
            <input type="submit" class="button" value="Cache legen">
            <span class="description">Leeg alle opgeslagen kalenderdata om verse data op te halen.</span>
        </p>
    </form>
    <?php
}

function caldav_render_help_tab() {
    ?>
    <h2>Hoe te gebruiken</h2>
    <p>Maak eerst een verbinding aan onder het tabblad <strong>Verbindingen</strong>. Gebruik daarna de slug van die verbinding in de shortcode.</p>

    <h3>Basis gebruik</h3>
    <code>[caldav_calendar connection="mijn-agenda"]</code>

    <h3>Alle beschikbare parameters</h3>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Parameter</th>
                <th>Beschrijving</th>
                <th>Standaard</th>
                <th>Opties</th>
            </tr>
        </thead>
        <tbody>
            <tr><td><code>connection</code></td><td>Slug van de verbinding (verplicht)</td><td>-</td><td>Slug uit het tabblad Verbindingen</td></tr>
            <tr><td><code>view</code></td><td>Weergave</td><td>old</td><td>old, modern, list, card</td></tr>
            <tr><td><code>theme</code></td><td>Kleurthema</td><td>default</td><td>default, dark, blue</td></tr>
            <tr><td><code>count</code></td><td>Aantal evenementen</td><td>10</td><td>1-100</td></tr>
            <tr><td><code>limitdays</code></td><td>Dagen vooruit kijken</td><td>365</td><td>1-3650</td></tr>
            <tr><td><code>pastdays</code></td><td>Dagen terug kijken</td><td>0</td><td>0-365</td></tr>
            <tr><td><code>nomonthheaders</code></td><td>Maandkoppen verbergen</td><td>false</td><td>true, false</td></tr>
            <tr><td><code>show_time</code></td><td>Tijd weergeven</td><td>yes</td><td>yes, no</td></tr>
            <tr><td><code>show_description</code></td><td>Beschrijving weergeven</td><td>yes</td><td>yes, no</td></tr>
            <tr><td><code>lang</code></td><td>Taal van de weergave</td><td>nl</td><td>nl, en, de, fr, es</td></tr>
            <tr><td><code>translation_notice</code></td><td>Vertaal-melding (oranje box) tonen bij niet-NL</td><td>yes</td><td>yes, no</td></tr>
        </tbody>
    </table>

    <div style="background:#e7f4ff;border:1px solid #b8daff;padding:15px;border-radius:5px;margin-top:20px;">
        <h4>Meertaligheid (vanaf v2.6)</h4>
        <p>De agenda wordt altijd in het Nederlands ingevoerd. Met de <code>lang</code>-parameter
        toon je dezelfde agenda automatisch in een andere taal:</p>
        <ul style="list-style:disc;margin-left:20px;">
            <li><strong>Datums en weekdagen</strong> worden altijd vertaald (gratis, geen API nodig).</li>
            <li><strong>Titel en beschrijving</strong> worden vertaald via DeepL — vul daarvoor een
            API-key in onder het tabblad <strong>Instellingen</strong>. Zonder key blijven titel en
            beschrijving Nederlands.</li>
        </ul>
        <p>Plaats op je Engelse pagina (bijv. <code>/en</code>) simpelweg de shortcode met
        <code>lang="en"</code>. Vertalingen worden 30 dagen gecachet, dus terugkerende diensten
        kosten na de eerste keer niets meer.</p>
    </div>

    <h3>Voorbeelden</h3>
    <h4>Volgende evenement zonder maandkop</h4>
    <code>[caldav_calendar connection="mijn-agenda" count="1" nomonthheaders="true"]</code>

    <h4>5 evenementen zonder maandkoppen</h4>
    <code>[caldav_calendar connection="mijn-agenda" count="5" nomonthheaders="true"]</code>

    <h4>Moderne kaart weergave</h4>
    <code>[caldav_calendar connection="mijn-agenda" view="card" theme="dark" count="15"]</code>

    <h4>Engelse versie (voor /en-pagina)</h4>
    <code>[caldav_calendar connection="mijn-agenda" lang="en"]</code>

    <h4>Engels, één los event zonder vertaal-melding (widget)</h4>
    <code>[caldav_calendar connection="mijn-agenda" lang="en" count="1" translation_notice="no"]</code>

    <div style="background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:5px;margin-top:20px;">
        <h4>Beveiligingsnotitie</h4>
        <p>Vanaf v2.5 worden credentials uitsluitend in admin-instellingen opgeslagen, niet meer in shortcodes. Dit voorkomt dat wachtwoorden in post-content/database-backups zichtbaar worden.</p>
    </div>
    <?php
}

// === RECURRING EVENTS ===

function caldav_expand_recurring_events($vevent, $dtstart, $dtend, $options) {
    if (!preg_match('/RRULE:(.*?)(\r\n|$)/s', $vevent, $m)) {
        return null;
    }

    $rrule_string = trim($m[1]);
    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $pastLimit = $now->sub(new DateInterval('P' . intval($options['pastdays']) . 'D'));
    $futureLimit = $now->add(new DateInterval('P' . intval($options['limitdays']) . 'D'));

    $rrule = array();
    foreach (explode(';', $rrule_string) as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) {
            $rrule[strtoupper(trim($kv[0]))] = trim($kv[1]);
        }
    }

    $freq = strtoupper($rrule['FREQ'] ?? 'DAILY');
    $count = isset($rrule['COUNT']) ? intval($rrule['COUNT']) : 0;
    $until = $rrule['UNTIL'] ?? null;
    $interval = isset($rrule['INTERVAL']) ? max(1, intval($rrule['INTERVAL'])) : 1;
    $byday = $rrule['BYDAY'] ?? null;
    $bymonthday = $rrule['BYMONTHDAY'] ?? null;

    $end_for_expansion = $futureLimit;

    if ($until) {
        $until_date = null;
        if (strlen($until) === 8) {
            $until_date = DateTimeImmutable::createFromFormat('Ymd', $until, new DateTimeZone(date_default_timezone_get()));
        } elseif (substr($until, -1) === 'Z') {
            $until_date = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $until, new DateTimeZone('UTC'));
            if ($until_date) {
                $until_date = $until_date->setTimezone(new DateTimeZone(date_default_timezone_get()));
            }
        } else {
            $until_date = DateTimeImmutable::createFromFormat('Ymd\THis', $until, new DateTimeZone(date_default_timezone_get()));
        }

        if ($until_date) {
            $end_for_expansion = min($end_for_expansion, $until_date);
        }
    }

    $expanded_events = array();
    $current = $dtstart;
    $generated = 0;
    $max_iterations = 10000;
    $iterations = 0;

    $day_map = array(
        'MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday',
        'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'
    );

    while ($iterations < $max_iterations && $current <= $end_for_expansion) {
        $iterations++;

        if ($count > 0 && $generated >= $count) {
            break;
        }

        $include_event = true;

        if ($byday && in_array($freq, ['WEEKLY', 'MONTHLY', 'YEARLY'])) {
            $include_event = false;
            $current_day = $current->format('l');
            foreach (explode(',', $byday) as $bd) {
                $bd = strtoupper(trim($bd));
                if (isset($day_map[$bd]) && $day_map[$bd] === $current_day) {
                    $include_event = true;
                    break;
                }
            }
        }

        if ($bymonthday && in_array($freq, ['MONTHLY', 'YEARLY'])) {
            $include_event = false;
            $current_monthday = intval($current->format('j'));
            foreach (explode(',', $bymonthday) as $md) {
                if (intval(trim($md)) === $current_monthday) {
                    $include_event = true;
                    break;
                }
            }
        }

        if ($include_event && $current >= $pastLimit && $current <= $end_for_expansion) {
            $occurrence_end = $dtend;
            if ($dtend && $dtstart !== $dtend) {
                $diff = $dtstart->diff($dtend);
                $occurrence_end = $current->add($diff);
            }
            $expanded_events[] = array('start' => $current, 'end' => $occurrence_end);
            $generated++;
        }

        try {
            switch ($freq) {
                case 'DAILY':
                    $current = $current->add(new DateInterval('P' . $interval . 'D'));
                    break;
                case 'WEEKLY':
                    $current = $current->add(new DateInterval('P' . ($interval * 7) . 'D'));
                    break;
                case 'MONTHLY':
                    $current = $current->add(new DateInterval('P' . $interval . 'M'));
                    break;
                case 'YEARLY':
                    $current = $current->add(new DateInterval('P' . $interval . 'Y'));
                    break;
                default:
                    caldav_log_error('Unknown FREQ type', ['freq' => $freq]);
                    return null;
            }
        } catch (Exception $e) {
            caldav_log_error('Error in date interval', ['error' => $e->getMessage()]);
            break;
        }
    }

    return !empty($expanded_events) ? $expanded_events : null;
}

// === MAIN SHORTCODE ===

// Publieke shortcode-handler met vangnet: vangt elke onverwachte fout
// (Throwable) af zodat de pagina nooit wit wordt. Bij een fout proberen we
// nog de laatst bekende goede agenda in het Nederlands te tonen.
// === CALDAV FETCH (v2.6.4: gedeeld door de shortcode en de testknop) ===
//
// Doet één CalDAV REPORT en levert de ruwe iCalendar-data terug. Retourneert
// altijd een array met:
//   ok      — bool, of er bruikbare kalenderdata is opgehaald
//   ical    — de (ontvouwen) iCalendar-tekst bij ok = true
//   http    — de HTTP-statuscode (0 als er geen antwoord kwam)
//   error   — korte technische reden voor de beheerder (leeg bij ok)
//   visitor — vriendelijke NL melding voor bezoekers (leeg bij ok)
//   items   — aantal gevonden calendar-data blokken
function caldav_fetch_ical($url, $user, $pass) {
    $result = ['ok' => false, 'ical' => '', 'http' => 0, 'error' => '', 'visitor' => '', 'items' => 0];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
           '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">' . "\n" .
           '    <D:prop>' . "\n" .
           '        <D:getetag/>' . "\n" .
           '        <C:calendar-data/>' . "\n" .
           '    </D:prop>' . "\n" .
           '    <C:filter>' . "\n" .
           '        <C:comp-filter name="VCALENDAR">' . "\n" .
           '            <C:comp-filter name="VEVENT"/>' . "\n" .
           '        </C:comp-filter>' . "\n" .
           '    </C:filter>' . "\n" .
           '</C:calendar-query>';

    $ch = curl_init();
    if (!$ch) {
        caldav_log_error('Failed to initialize cURL');
        $result['error'] = 'Kon cURL niet initialiseren.';
        $result['visitor'] = 'Kon verbinding niet initialiseren.';
        return $result;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'REPORT',
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8',
            'User-Agent: WordPress CalDAV Plugin/' . CALDAV_PLUGIN_VERSION
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    if (!empty($user) || !empty($pass)) {
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    }

    $resp = curl_exec($ch);
    $result['http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($resp === false || !empty($curl_error)) {
        caldav_log_error('cURL error', ['error' => $curl_error, 'url' => $url]);
        $result['error'] = 'Netwerkfout: ' . $curl_error;
        $result['visitor'] = 'Netwerkfout bij ophalen kalender: ' . $curl_error;
        return $result;
    }

    if ($result['http'] >= 400) {
        caldav_log_error('HTTP error', ['code' => $result['http'], 'url' => $url]);
        $error_messages = [
            401 => 'Authenticatie gefaald. Controleer gebruikersnaam en wachtwoord.',
            403 => 'Toegang geweigerd.',
            404 => 'Kalender niet gevonden.',
            500 => 'Server fout.'
        ];
        $msg = $error_messages[$result['http']] ?? 'HTTP fout ' . $result['http'];
        $result['error'] = 'HTTP ' . $result['http'] . ' — ' . $msg;
        $result['visitor'] = $msg;
        return $result;
    }

    if (!preg_match_all('/<([a-zA-Z0-9]+:)?calendar-data[^>]*>(.*?)<\/([a-zA-Z0-9]+:)?calendar-data>/s', $resp, $matches)
        || empty($matches[2])) {
        caldav_log_error('No calendar data found', ['url' => $url]);
        $result['error'] = 'Server antwoordde zonder kalenderdata (klopt de agenda-URL?).';
        $result['visitor'] = 'Geen kalenderdata gevonden.';
        return $result;
    }

    $ical_data = implode("\n", $matches[2]);

    $result['ok'] = true;
    $result['items'] = count($matches[2]);
    // Ontvouw gevouwen iCalendar-regels (CRLF + spatie/tab, en kale LF).
    $result['ical'] = preg_replace("/\r?\n[ \t]/", '', $ical_data);

    return $result;
}

function caldav_calendar_shortcode($atts) {
    try {
        return caldav_calendar_shortcode_impl($atts);
    } catch (\Throwable $e) {
        caldav_log_error('Shortcode exception', ['error' => $e->getMessage()]);
        $a = shortcode_atts(['connection' => ''], $atts, 'caldav_calendar');
        $slug = caldav_sanitize_slug($a['connection'] ?? '');
        caldav_record_fetch_error($slug, 'PHP-fout tijdens opbouwen kalender: ' . $e->getMessage());
        $lastgood = caldav_get_lastgood($slug);
        if (!empty($lastgood)) {
            usort($lastgood, function ($x, $y) {
                return $x['start'] <=> $y['start'];
            });
            // Bewust in het NL renderen: geen nieuwe DeepL-calls die opnieuw
            // zouden kunnen falen.
            return caldav_generate_output($lastgood, [
                'count' => 10, 'view' => 'old', 'theme' => 'default',
                'show_time' => 'yes', 'show_description' => 'yes',
                'htmltagmonth' => 'h4', 'htmltagdate' => 'h5',
                'date_format' => 'd-m-Y H:i', 'nomonthheaders' => 'false',
                'lang' => 'nl', 'translation_notice' => 'no',
            ]);
        }
        return '';
    }
}

function caldav_calendar_shortcode_impl($atts) {
    $a = shortcode_atts(array(
        'connection' => '',
        'count' => 10,
        'limitdays' => 365,
        'date_format' => 'd-m-Y H:i',
        'view' => 'old',
        'htmltagmonth' => 'h4',
        'htmltagdate' => 'h5',
        'pastdays' => 0,
        'show_time' => 'yes',
        'show_description' => 'yes',
        'theme' => 'default',
        'nomonthheaders' => 'false',
        'lang' => 'nl',
        'translation_notice' => 'yes'
    ), $atts);

    $lang = in_array(strtolower($a['lang']), caldav_supported_languages()) ? strtolower($a['lang']) : 'nl';

    // Op een vertaalde (niet-NL) pagina tonen we geen Nederlandse foutmeldingen
    // aan bezoekers: daar valt alles stil terug (lege output) i.p.v. een rode
    // melding. Fouten gaan nog wel naar het debug-log. Op de NL-pagina (de
    // primaire weergave) blijven de meldingen gewoon zichtbaar.
    $err = function ($dutch_error_html) use ($lang) {
        return $lang === 'nl' ? $dutch_error_html : '';
    };

    // Resolve connection
    $slug = caldav_sanitize_slug($a['connection']);
    if (empty($slug)) {
        return $err('<div class="caldav-error">Geen verbinding opgegeven. Gebruik <code>connection="slug"</code> en maak eerst een verbinding aan onder Instellingen &gt; CalDAV Calendar.</div>');
    }

    $connection = caldav_get_connection($slug);
    if (!$connection) {
        caldav_log_error('Connection not found', ['slug' => $slug]);
        return $err('<div class="caldav-error">Verbinding "' . esc_html($slug) . '" niet gevonden.</div>');
    }

    $url = caldav_sanitize_url($connection['url'] ?? '');
    if (!$url) {
        caldav_log_error('Invalid URL in connection', ['slug' => $slug]);
        return $err('<div class="caldav-error">Verbinding "' . esc_html($slug) . '" heeft een ongeldige URL.</div>');
    }

    $user = sanitize_text_field($connection['user'] ?? '');
    $pass = $connection['pass'] ?? '';

    // Sanitize remaining shortcode inputs
    $count = max(1, min(100, intval($a['count'])));
    $limitdays = max(1, min(3650, intval($a['limitdays'])));
    $pastdays = max(0, min(365, intval($a['pastdays'])));

    $allowed_views = ['old', 'simple', 'modern', 'list', 'card'];
    $view = in_array($a['view'], $allowed_views) ? $a['view'] : 'old';

    $allowed_themes = ['default', 'dark', 'blue'];
    $theme = in_array($a['theme'], $allowed_themes) ? $a['theme'] : 'default';

    $show_time = in_array($a['show_time'], ['yes', 'no']) ? $a['show_time'] : 'yes';
    $show_description = in_array($a['show_description'], ['yes', 'no']) ? $a['show_description'] : 'yes';
    $nomonthheaders = in_array($a['nomonthheaders'], ['true', 'false']) ? $a['nomonthheaders'] : 'false';
    $translation_notice = in_array($a['translation_notice'], ['yes', 'no']) ? $a['translation_notice'] : 'yes';

    // Rate limiting
    if (!caldav_check_rate_limit($url)) {
        caldav_log_error('Rate limit exceeded', ['url' => $url]);
        return $err('<div class="caldav-error">Te veel verzoeken. Probeer over een minuut opnieuw.</div>');
    }

    // Check cache
    $options_for_cache = [
        'count' => $count,
        'limitdays' => $limitdays,
        'pastdays' => $pastdays
    ];
    $cache_key = caldav_get_cache_key($slug, $options_for_cache);
    $cached_events = caldav_get_cached_events($cache_key);

    $clean_options = [
        'count' => $count,
        'view' => $view,
        'theme' => $theme,
        'show_time' => $show_time,
        'show_description' => $show_description,
        'htmltagmonth' => sanitize_key($a['htmltagmonth']),
        'htmltagdate' => sanitize_key($a['htmltagdate']),
        'date_format' => sanitize_text_field($a['date_format']),
        'nomonthheaders' => $nomonthheaders,
        'lang' => $lang,
        'translation_notice' => $translation_notice
    ];

    if ($cached_events !== false) {
        return caldav_generate_output($cached_events, $clean_options);
    }

    // Terugval bij een ophaal-/serverfout: toon de laatst bekende goede agenda
    // i.p.v. een lege/foutpagina. Pas als die er ook niet is, val terug op de
    // (op niet-NL onderdrukte) foutmelding.
    // $reason wordt (beknopt, zonder credentials) vastgelegd zodat de beheerder
    // in wp-admin ziet dat de kalender op de terugval draait.
    $fallback = function ($dutch_error_html, $reason = '') use ($slug, $clean_options, $err) {
        caldav_record_fetch_error($slug, $reason !== '' ? $reason : 'Onbekende ophaalfout.');

        $lastgood = caldav_get_lastgood($slug);
        if (!empty($lastgood)) {
            usort($lastgood, function ($a, $b) {
                return $a['start'] <=> $b['start'];
            });
            return caldav_generate_output($lastgood, $clean_options);
        }
        return $err($dutch_error_html);
    };

    $fetch = caldav_fetch_ical($url, $user, $pass);

    if (!$fetch['ok']) {
        return $fallback('<div class="caldav-error">' . esc_html($fetch['visitor']) . '</div>', $fetch['error']);
    }

    $ical = $fetch['ical'];

    $parse_options = [
        'limitdays' => $limitdays,
        'pastdays' => $pastdays
    ];
    $events = caldav_parse_events($ical, $parse_options);

    if (empty($events)) {
        // Ophalen slaagde wél — de agenda is simpelweg leeg binnen het venster.
        caldav_clear_fetch_error($slug);
        $no_events = $lang === 'nl' ? 'Geen komende evenementen gevonden.' : 'No upcoming events found.';
        return '<div class="caldav-no-events">' . esc_html($no_events) . '</div>';
    }

    caldav_clear_fetch_error($slug);
    caldav_cache_events($cache_key, $events);

    usort($events, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    // Bewaar als laatst bekende goede agenda voor terugval bij latere fouten.
    caldav_store_lastgood($slug, $events);

    return caldav_generate_output($events, $clean_options);
}

function caldav_parse_events($ical, $options) {
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical, $events_raw);
    $events = array();

    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $pastLimit = $now->sub(new DateInterval('P' . intval($options['pastdays']) . 'D'));
    $futureLimit = $now->add(new DateInterval('P' . intval($options['limitdays']) . 'D'));

    foreach ($events_raw[1] as $vevent) {
        $summary = '';
        if (preg_match('/SUMMARY:(.*?)(\r\n|$)/s', $vevent, $m)) {
            $summary = trim($m[1]);
            $summary = str_replace(['\,', '\;', '\n'], [',', ';', ' '], $summary);
        }

        $description = '';
        if (preg_match('/DESCRIPTION:(.*?)(\r\n|$)/s', $vevent, $m)) {
            $description = trim($m[1]);
            $description = str_replace('\n', "<br>", $description);
            $description = str_replace(['\,', '\;'], [',', ';'], $description);
        }

        $location = '';
        if (preg_match('/LOCATION:(.*?)(\r\n|$)/s', $vevent, $m)) {
            $location = trim($m[1]);
            $location = str_replace(['\,', '\;'], [',', ';'], $location);
        }

        $dtstart = null;
        $start_raw = '';
        if (preg_match('/DTSTART(?:;TZID=[^:]+)?:([0-9TzZ]+)(\r\n|$)/', $vevent, $m)) {
            $start_raw = trim($m[1]);
            if (preg_match('/^\d{8}$/', $start_raw)) {
                $dtstart = DateTimeImmutable::createFromFormat('Ymd', $start_raw, new DateTimeZone(date_default_timezone_get()));
            } elseif (substr($start_raw, -1) === 'Z') {
                $dtstart = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $start_raw, new DateTimeZone('UTC'));
                if ($dtstart) {
                    $dtstart = $dtstart->setTimezone(new DateTimeZone(date_default_timezone_get()));
                }
            } else {
                $dtstart = DateTimeImmutable::createFromFormat('Ymd\THis', $start_raw, new DateTimeZone(date_default_timezone_get()));
                if (!$dtstart) {
                    $dtstart = DateTimeImmutable::createFromFormat('Ymd\THi', $start_raw, new DateTimeZone(date_default_timezone_get()));
                }
            }
        }

        $dtend = null;
        if (preg_match('/DTEND(?:;TZID=[^:]+)?:([0-9TzZ]+)(\r\n|$)/', $vevent, $m)) {
            $raw = trim($m[1]);
            if (preg_match('/^\d{8}$/', $raw)) {
                $dtend = DateTimeImmutable::createFromFormat('Ymd', $raw, new DateTimeZone(date_default_timezone_get()));
            } elseif (substr($raw, -1) === 'Z') {
                $dtend = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $raw, new DateTimeZone('UTC'));
                if ($dtend) {
                    $dtend = $dtend->setTimezone(new DateTimeZone(date_default_timezone_get()));
                }
            } else {
                $dtend = DateTimeImmutable::createFromFormat('Ymd\THis', $raw, new DateTimeZone(date_default_timezone_get()));
                if (!$dtend) {
                    $dtend = DateTimeImmutable::createFromFormat('Ymd\THi', $raw, new DateTimeZone(date_default_timezone_get()));
                }
            }
        }

        if (!$dtstart) {
            continue;
        }

        $is_all_day = preg_match('/^\d{8}$/', $start_raw);

        $recurring_dates = caldav_expand_recurring_events($vevent, $dtstart, $dtend, $options);

        if ($recurring_dates !== null) {
            foreach ($recurring_dates as $occurrence) {
                if ($occurrence['start'] >= $pastLimit && $occurrence['start'] <= $futureLimit) {
                    $events[] = array(
                        'summary' => $summary,
                        'description' => $description,
                        'location' => $location,
                        'start' => $occurrence['start'],
                        'end' => $occurrence['end'],
                        'is_all_day' => $is_all_day
                    );
                }
            }
        } else {
            if ($dtstart >= $pastLimit && $dtstart <= $futureLimit) {
                $events[] = array(
                    'summary' => $summary,
                    'description' => $description,
                    'location' => $location,
                    'start' => $dtstart,
                    'end' => $dtend,
                    'is_all_day' => $is_all_day
                );
            }
        }
    }

    return $events;
}

// === OUTPUT GENERATORS ===

// Waarschuwingsbox boven de kalender bij een andere taal dan NL: de agenda
// wordt automatisch vertaald en kan deels Nederlands blijven (fallback).
// Styling staat inline zodat de box altijd goed rendert, ook als de externe
// CSS nog gecachet is.
function caldav_translation_notice($lang) {
    $lang = strtolower($lang);
    if ($lang === 'nl') {
        return '';
    }

    $messages = [
        'en' => 'This calendar is automatically translated from Dutch. Some details may still appear in Dutch.',
        'de' => 'Dieser Kalender wird automatisch aus dem Niederländischen übersetzt. Einige Angaben können noch auf Niederländisch erscheinen.',
        'fr' => 'Ce calendrier est traduit automatiquement du néerlandais. Certains détails peuvent encore apparaître en néerlandais.',
        'es' => 'Este calendario se traduce automáticamente del neerlandés. Algunos detalles pueden seguir apareciendo en neerlandés.',
    ];
    $msg = $messages[$lang] ?? $messages['en'];

    $box  = 'display:flex;align-items:flex-start;gap:10px;'
          . 'margin:0 0 1.25rem 0;padding:12px 14px;'
          . 'background:#fff7e6;border:1px solid #f0c36d;'
          . 'border-left:4px solid #e69500;border-radius:8px;'
          . 'color:#6b4b00;font-size:.88rem;line-height:1.45;';
    $icon = 'flex:none;color:#e69500;font-size:1.2rem;line-height:1.2;';

    return '<div class="caldav-translation-notice" role="note" style="' . $box . '">' .
           '<span aria-hidden="true" style="' . $icon . '">&#9888;</span>' .
           '<span style="flex:1;">' . esc_html($msg) . '</span>' .
           '</div>';
}

function caldav_generate_output($events, $options) {
    $lang = strtolower($options['lang'] ?? 'nl');

    // Titel + beschrijving vertalen indien een andere taal dan NL is gevraagd.
    // $incomplete = true als minstens één zichtbare tekst Nederlands bleef.
    $incomplete = false;
    if ($lang !== 'nl') {
        $events = caldav_translate_events($events, $options, $incomplete);
    }

    // Info-box boven de kalender. Tonen bij een andere taal dan NL wanneer:
    //  - translation_notice="yes" (standaard), OF
    //  - de vertaling (deels) mislukte en de inhoud in het Nederlands valt —
    //    dan tonen we de box óók bij translation_notice="no", zodat de bezoeker
    //    begrijpt waarom hij Nederlands ziet i.p.v. de gevraagde taal.
    $want_notice = ($options['translation_notice'] ?? 'yes') !== 'no';
    $show_notice = ($lang !== 'nl') && ($want_notice || $incomplete);
    $notice = $show_notice ? caldav_translation_notice($lang) : '';

    if ($options['view'] === 'old' || $options['view'] === 'simple') {
        return $notice . caldav_generate_old_view($events, $options);
    }

    $theme_class = 'caldav-theme-' . $options['theme'];
    $view_class = 'caldav-view-' . $options['view'];

    $out = '<div class="caldav-calendar ' . $theme_class . ' ' . $view_class . '">';
    $out .= $notice;

    switch ($options['view']) {
        case 'card':
            $out .= caldav_generate_card_view($events, $options);
            break;
        case 'modern':
            $out .= caldav_generate_modern_view($events, $options);
            break;
        default:
            $out .= caldav_generate_list_view($events, $options);
            break;
    }

    $out .= '</div>';
    return $out;
}

function caldav_generate_modern_view($events, $options) {
    $out = '<div class="caldav-modern-grid">';
    $currentMonth = '';
    $i = 0;

    foreach ($events as $event) {
        $month = $event['start']->format('F Y');

        if ($month !== $currentMonth && $options['nomonthheaders'] !== 'true') {
            if ($currentMonth !== '') {
                $out .= '</div>';
            }
            $out .= '<div class="caldav-month-section">';
            $out .= '<' . $options['htmltagmonth'] . ' class="caldav-month-header">' . esc_html($month) . '</' . $options['htmltagmonth'] . '>';
            $currentMonth = $month;
        } elseif ($options['nomonthheaders'] === 'true' && $currentMonth === '') {
            $out .= '<div class="caldav-month-section caldav-no-header">';
            $currentMonth = $month;
        }

        $out .= caldav_generate_event_card($event, $options);

        $i++;
        if ($i >= intval($options['count'])) break;
    }

    if ($currentMonth !== '') {
        $out .= '</div>';
    }

    $out .= '</div>';
    return $out;
}

function caldav_generate_card_view($events, $options) {
    $out = '<div class="caldav-card-grid">';
    $i = 0;

    foreach ($events as $event) {
        $out .= caldav_generate_event_card($event, $options, true);
        $i++;
        if ($i >= intval($options['count'])) break;
    }

    $out .= '</div>';
    return $out;
}

function caldav_generate_event_card($event, $options, $standalone = false) {
    $day = $event['start']->format('d');
    $month_short = $event['start']->format('M');

    $card_class = $standalone ? 'caldav-card-standalone' : 'caldav-event-card';

    $out = '<div class="' . $card_class . '">';
    $out .= '<div class="caldav-date-badge">';
    $out .= '<span class="caldav-day">' . $day . '</span>';
    $out .= '<span class="caldav-month">' . $month_short . '</span>';
    $out .= '</div>';

    $out .= '<div class="caldav-event-content">';
    $out .= '<h4 class="caldav-event-title">' . esc_html($event['summary']) . '</h4>';

    if ($options['show_time'] === 'yes' && !$event['is_all_day']) {
        $time_display = $event['start']->format('H:i');
        if ($event['end']) {
            $time_display .= ' - ' . $event['end']->format('H:i');
        }
        $out .= '<div class="caldav-event-time">' . esc_html($time_display) . '</div>';
    }

    if (!empty($event['location'])) {
        $out .= '<div class="caldav-event-location">' . esc_html($event['location']) . '</div>';
    }

    if ($options['show_description'] === 'yes' && !empty($event['description'])) {
        $out .= '<div class="caldav-event-description">' . wp_kses_post($event['description']) . '</div>';
    }

    $out .= '</div></div>';
    return $out;
}

function caldav_generate_list_view($events, $options) {
    $out = '<ul class="caldav-events-list">';
    $currentMonth = '';
    $i = 0;

    foreach ($events as $event) {
        $month = $event['start']->format('F Y');
        $date = $event['start']->format('d-m-Y');

        if ($month !== $currentMonth && $options['nomonthheaders'] !== 'true') {
            $out .= '<li class="caldav-month-divider">';
            $out .= '<' . $options['htmltagmonth'] . '>' . esc_html($month) . '</' . $options['htmltagmonth'] . '>';
            $out .= '</li>';
            $currentMonth = $month;
        }

        $out .= '<li class="caldav-event-item">';
        $out .= '<' . $options['htmltagdate'] . ' class="caldav-event-date">' . esc_html($date) . '</' . $options['htmltagdate'] . '>';
        $out .= '<div class="caldav-event-details">';
        $out .= '<strong class="caldav-event-title">' . esc_html($event['summary']) . '</strong>';

        if ($options['show_description'] === 'yes' && !empty($event['description'])) {
            $out .= '<div class="caldav-event-description">' . wp_kses_post($event['description']) . '</div>';
        }

        $out .= '<div class="caldav-event-datetime"><small>' . esc_html($event['start']->format($options['date_format'])) . '</small></div>';
        $out .= '</div></li>';

        $i++;
        if ($i >= intval($options['count'])) break;
    }

    $out .= '</ul>';
    return $out;
}

function caldav_generate_old_view($events, $options) {
    $out = '<ul class="caldav-events">';
    $currentMonth = '';
    $i = 0;

    $months = array(
        'January' => 'januari', 'February' => 'februari', 'March' => 'maart',
        'April' => 'april', 'May' => 'mei', 'June' => 'juni',
        'July' => 'juli', 'August' => 'augustus', 'September' => 'september',
        'October' => 'oktober', 'November' => 'november', 'December' => 'december'
    );

    $days = array(
        'Monday' => 'maandag', 'Tuesday' => 'dinsdag', 'Wednesday' => 'woensdag',
        'Thursday' => 'donderdag', 'Friday' => 'vrijdag', 'Saturday' => 'zaterdag',
        'Sunday' => 'zondag'
    );

    // Alleen bij NL de eigen vertaaltabel gebruiken; andere talen tonen de
    // Engelse namen die PHP's date()-format al levert (Friday, October, …).
    $useDutch = strtolower($options['lang'] ?? 'nl') === 'nl';

    foreach ($events as $event) {
        $month = $event['start']->format('F Y');

        $dayName = $event['start']->format('l');
        $dayNumber = $event['start']->format('j');
        $monthName = $event['start']->format('F');

        $dayLabel = $useDutch
            ? (isset($days[$dayName]) ? ucfirst($days[$dayName]) : ucfirst(strtolower($dayName)))
            : $dayName;
        $monthLabel = $useDutch
            ? (isset($months[$monthName]) ? $months[$monthName] : strtolower($monthName))
            : $monthName;

        $dateReadable = $dayLabel . ' ' . $dayNumber . ' ' . $monthLabel;

        if ($month !== $currentMonth && $options['nomonthheaders'] !== 'true') {
            $monthOnly = $event['start']->format('F');
            $year = $event['start']->format('Y');
            $monthHeadLabel = $useDutch
                ? (isset($months[$monthOnly]) ? $months[$monthOnly] : strtolower($monthOnly))
                : $monthOnly;
            $monthFormatted = ucfirst($monthHeadLabel) . ' ' . $year;
            $out .= '<li class="caldav-month"><' . $options['htmltagmonth'] . '>' . esc_html($monthFormatted) . '</' . $options['htmltagmonth'] . '></li>';
            $currentMonth = $month;
        }

        $out .= '<li class="caldav-event">';
        $out .= '<' . $options['htmltagdate'] . '><strong>' . esc_html($dateReadable) . '</strong></' . $options['htmltagdate'] . '>';

        $timeDisplay = '';
        if (!$event['is_all_day'] && $options['show_time'] === 'yes') {
            $timeDisplay = '<strong>' . $event['start']->format('H:i') . '</strong>';
            if ($event['end']) {
                $timeDisplay .= ' – ' . $event['end']->format('H:i');
            }
        }

        $out .= '<p class="caldav-summary"><strong>' . esc_html($event['summary']) . '</strong></p>';

        if (!empty($timeDisplay)) {
            $out .= '<p class="caldav-time">' . $timeDisplay . '</p>';
        }

        if ($options['show_description'] === 'yes' && !empty($event['description'])) {
            $out .= '<p class="caldav-description">' . wp_kses_post($event['description']) . '</p>';
        }

        $out .= '</li>';

        $i++;
        if ($i >= intval($options['count'])) break;
    }

    $out .= '</ul>';
    return $out;
}

add_shortcode('caldav_calendar', 'caldav_calendar_shortcode');

// === ACTIVATION NOTICE ===

function caldav_show_activation_notice() {
    if (get_option('caldav_show_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Modern CalDAV Calendar v<?php echo esc_html(CALDAV_PLUGIN_VERSION); ?> geactiveerd!</strong>
            Maak een verbinding aan onder <a href="<?php echo esc_url(admin_url('options-general.php?page=caldav-calendar')); ?>">Instellingen &gt; CalDAV Calendar</a> en verwijs ernaar vanuit je shortcode.</p>
        </div>
        <?php
        delete_option('caldav_show_activation_notice');
    }
}
add_action('admin_notices', 'caldav_show_activation_notice');

// === HEALTH NOTICE (v2.6.4) ===
//
// Waarschuw de beheerder wanneer een kalender op de terugval draait. Zonder
// deze melding blijft een kapotte verbinding onzichtbaar: bezoekers zien een
// keurige maar bevroren agenda en nieuwe afspraken verschijnen nooit.
function caldav_health_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Niet dubbelop tonen: de Verbindingen-tab heeft al een statuskolom.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->id === 'settings_page_caldav-calendar') {
        return;
    }

    $broken = [];
    foreach (caldav_get_connections() as $slug => $conn) {
        $status = caldav_get_fetch_error($slug);
        if ($status) {
            $broken[$slug] = $status;
        }
    }

    if (empty($broken)) {
        return;
    }

    $settings_url = admin_url('options-general.php?page=caldav-calendar&tab=connections');
    ?>
    <div class="notice notice-error">
        <p><strong>Modern CalDAV Calendar: de agenda is niet bijgewerkt.</strong></p>
        <ul style="margin-left:1.5em;list-style:disc;">
            <?php foreach ($broken as $slug => $status): ?>
                <?php $last_ok = caldav_get_lastgood_time($slug); ?>
                <li>
                    <code><?php echo esc_html($slug); ?></code>: <?php echo esc_html($status['message']); ?>
                    <?php if (!empty($status['since'])): ?>
                        (al <?php echo esc_html(human_time_diff((int) $status['since'], time())); ?> mis<?php
                            if ($last_ok) {
                                echo '; de site toont de agenda van ' . esc_html(human_time_diff($last_ok, time())) . ' geleden';
                            }
                        ?>)
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p>Bezoekers zien nu de laatst bekende agenda, dus nieuwe afspraken verschijnen niet.
           <a href="<?php echo esc_url($settings_url); ?>">Verbinding testen en herstellen</a>.</p>
    </div>
    <?php
}
add_action('admin_notices', 'caldav_health_notice');

// === STYLES ===

function caldav_enqueue_styles() {
    wp_enqueue_style(
        'caldav-style',
        CALDAV_PLUGIN_URL . 'caldav-style.css',
        [],
        CALDAV_PLUGIN_VERSION
    );
}
add_action('wp_enqueue_scripts', 'caldav_enqueue_styles');
