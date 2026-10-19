<?php
/*
Plugin Name: Modern CalDAV Calendar
Plugin URI: https://github.com/Bolt-Connect/modern-caldav-calendar
Description: Moderne CalDAV kalender plugin met styling, herhalingen en veiligheid. Credentials beheerd via admin-instellingen. Optionele meertaligheid via DeepL.
Version: 2.7.0
Author: Bolt Connect
Update URI: https://github.com/Bolt-Connect/modern-caldav-calendar
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

define('CALDAV_PLUGIN_VERSION', '2.7.0');
define('CALDAV_GITHUB_REPO', 'Bolt-Connect/modern-caldav-calendar');
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

// Een update via het dashboard roept de activatie-hook niet aan. Leeg daarom
// bij een nieuwe versie de cache, zodat events volgens de nieuwe regels worden
// ingelezen in plaats van tot een uur lang uit de oude cache te komen.
function caldav_maybe_upgrade() {
    if (get_option('caldav_plugin_version') !== CALDAV_PLUGIN_VERSION) {
        caldav_clear_all_cache();
        update_option('caldav_plugin_version', CALDAV_PLUGIN_VERSION);
    }
}
add_action('plugins_loaded', 'caldav_maybe_upgrade');

function caldav_plugin_deactivate() {
    caldav_clear_all_cache();
}

function caldav_plugin_uninstall() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'caldav_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_caldav_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_caldav_%'");
    delete_site_transient('caldav_github_release');
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
    $url = sanitize_url($url, ['http', 'https']);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    return $url;
}

// Toegestane HTML-tags voor htmltagmonth/htmltagdate. Zonder deze lijst kon
// elke auteur via de shortcode een willekeurige tag (iframe, textarea, …) in
// de pagina zetten.
function caldav_sanitize_tag($tag, $default) {
    $tag = strtolower(trim((string) $tag));
    $allowed = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span', 'strong'];
    return in_array($tag, $allowed, true) ? $tag : $default;
}

// cURL-opties die beide CalDAV-aanroepen delen. Alleen http(s), ook na een
// redirect.
function caldav_curl_base_options($url) {
    return [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
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

    // Deze tekst wordt in wp-admin getoond; nooit inloggegevens tonen.
    $message = caldav_scrub_url($message);

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

// Haal eventuele inloggegevens uit een URL (https://user:pass@host/... ) zodat
// ze niet in het debug-log of in een beheerdersmelding terechtkomen.
function caldav_scrub_url($url) {
    return preg_replace('#(https?://)[^/@\s]*@#i', '$1', (string) $url);
}

function caldav_log_error($message, $context = []) {
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $log_message = 'CalDAV Calendar: ' . caldav_scrub_url($message);
        if (!empty($context)) {
            foreach ($context as $key => $value) {
                if (is_string($value)) {
                    $context[$key] = caldav_scrub_url($value);
                }
            }
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

// WordPress voegt slashes toe aan $_POST. Zonder wp_unslash() raakt een
// wachtwoord met een aanhalingsteken of backslash beschadigd bij opslaan.
function caldav_post_value($key) {
    return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
}

function caldav_verify_nonce($action) {
    return (bool) wp_verify_nonce(sanitize_text_field(caldav_post_value('caldav_nonce')), $action);
}

function caldav_handle_admin_actions() {
    $action = sanitize_text_field(caldav_post_value('caldav_action'));

    // Cache settings update
    if ($action === 'update_settings' && caldav_verify_nonce('caldav_update_settings')) {
        $cache_enabled = isset($_POST['cache_enabled']);
        $cache_duration = max(60, min(3600, intval(caldav_post_value('cache_duration') ?: 300)));
        $deepl_key = sanitize_text_field(caldav_post_value('deepl_api_key'));
        update_option('caldav_cache_enabled', $cache_enabled);
        update_option('caldav_cache_duration', $cache_duration);
        // Leeg laten betekent: huidige sleutel behouden (het veld toont hem niet).
        if (isset($_POST['deepl_api_key_remove'])) {
            update_option('caldav_deepl_api_key', '');
        } elseif ($deepl_key !== '') {
            update_option('caldav_deepl_api_key', $deepl_key);
        }
        add_settings_error('caldav', 'saved', 'Instellingen opgeslagen.', 'updated');
    }

    // Clear cache
    if ($action === 'clear_cache' && caldav_verify_nonce('caldav_clear_cache')) {
        caldav_clear_all_cache();
        add_settings_error('caldav', 'cache_cleared', 'Cache geleegd.', 'updated');
    }

    // Save connection (add or edit)
    if ($action === 'save_connection' && caldav_verify_nonce('caldav_save_connection')) {
        $original_slug = caldav_sanitize_slug(caldav_post_value('original_slug'));
        $slug = caldav_sanitize_slug(caldav_post_value('slug'));
        $name = sanitize_text_field(caldav_post_value('name'));
        $url = caldav_sanitize_url(caldav_post_value('url'));
        $user = sanitize_text_field(caldav_post_value('user'));
        $pass = caldav_post_value('pass');

        if (empty($slug) || empty($name) || empty($url)) {
            add_settings_error('caldav', 'invalid', 'Naam, slug en URL zijn verplicht. URL moet geldig zijn.', 'error');
        } else {
            $existing = caldav_get_connection($original_slug);

            // If password left empty during edit, keep the existing one
            if ($existing && empty($pass)) {
                $pass = $existing['pass'] ?? '';
            }

            // Botsing controleren vóór er iets verwijderd wordt: zowel bij een
            // nieuwe verbinding als bij het hernoemen naar een bestaande slug.
            // Voorheen werd bij hernoemen eerst de oude verwijderd en daarna de
            // andere verbinding stilzwijgend overschreven.
            if ($slug !== $original_slug && caldav_get_connection($slug) !== null) {
                add_settings_error('caldav', 'duplicate', 'Slug "' . esc_html($slug) . '" bestaat al.', 'error');
            } else {
                if (!empty($original_slug) && $original_slug !== $slug) {
                    caldav_delete_connection($original_slug);
                }
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
    if ($action === 'test_connection' && caldav_verify_nonce('caldav_test_connection')) {
        $slug = caldav_sanitize_slug(caldav_post_value('slug'));
        $test = caldav_run_connection_test($slug);
        add_settings_error('caldav', 'test', $test['message'], $test['ok'] ? 'updated' : 'error');
    }

    // Delete connection
    if ($action === 'delete_connection' && caldav_verify_nonce('caldav_delete_connection')) {
        $slug = caldav_sanitize_slug(caldav_post_value('slug'));
        if (!empty($slug)) {
            caldav_delete_connection($slug);
            add_settings_error('caldav', 'deleted', 'Verbinding "' . esc_html($slug) . '" verwijderd.', 'updated');
        }
    }
}

// Zoek onder een CalDAV-URL naar échte agenda-collecties (PROPFIND, Depth 1).
// Nodig omdat een "calendar-home"-URL er identiek uitziet maar geen agenda is:
// daar geeft een calendar-query REPORT netjes HTTP 405. Retourneert een lijst
// van ['url' => ..., 'name' => ...].
function caldav_discover_calendars($url, $user, $pass) {
    $body = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">' . "\n" .
            '  <D:prop><D:resourcetype/><D:displayname/></D:prop>' . "\n" .
            '</D:propfind>';

    $ch = curl_init();
    if (!$ch) {
        return [];
    }

    curl_setopt_array($ch, caldav_curl_base_options($url) + [
        CURLOPT_CUSTOMREQUEST => 'PROPFIND',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8',
            'User-Agent: WordPress CalDAV Plugin/' . CALDAV_PLUGIN_VERSION
        ],
    ]);

    if (!empty($user) || !empty($pass)) {
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    }

    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return [];
    }

    $parts = wp_parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return [];
    }
    $origin = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');

    $found = [];
    // Namespace-prefixen verschillen per server, dus die houden we vrij.
    // De (?:\s[^>]*)? voorkomt dat <responsedescription> als <response> telt.
    if (preg_match_all('/<[a-z0-9]*:?response(?:\s[^>]*)?>(.*?)<\/[a-z0-9]*:?response>/is', $resp, $responses)) {
        foreach ($responses[1] as $block) {
            // Alleen collecties die zichzelf als calendar aanmerken.
            if (!preg_match('/<[a-z0-9]*:?calendar\s*\/>/i', $block)) {
                continue;
            }
            if (!preg_match('/<[a-z0-9]*:?href[^>]*>(.*?)<\/[a-z0-9]*:?href>/is', $block, $h)) {
                continue;
            }

            $href = trim(html_entity_decode($h[1], ENT_QUOTES, 'UTF-8'));
            if ($href === '') {
                continue;
            }

            // De href komt van de server. Een absolute href die naar een ándere
            // host wijst negeren we: die zouden we anders ophalen en (bij één
            // gevonden agenda) zelfs opslaan, wat een kwaadwillende of gekaapte
            // CalDAV-server zou kunnen misbruiken om de site interne adressen
            // te laten benaderen.
            if (preg_match('#^https?://#i', $href)) {
                if (stripos($href, $origin . '/') !== 0 && strcasecmp($href, $origin) !== 0) {
                    caldav_log_error('Discovery: href op andere host genegeerd', ['href' => $href]);
                    continue;
                }
                $full = $href;
            } else {
                if (strpos($href, '/') !== 0) {
                    continue; // geen pad dat we betrouwbaar kunnen samenstellen
                }
                $full = $origin . $href;
            }

            $name = '';
            if (preg_match('/<[a-z0-9]*:?displayname[^>]*>(.*?)<\/[a-z0-9]*:?displayname>/is', $block, $d)) {
                $name = trim(html_entity_decode($d[1], ENT_QUOTES, 'UTF-8'));
            }

            $found[] = [
                'url'  => $full,
                'name' => $name !== '' ? $name : '(naamloos)',
            ];
        }
    }

    return $found;
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

    $conn_user = sanitize_text_field($connection['user'] ?? '');
    $conn_pass = $connection['pass'] ?? '';

    $started = microtime(true);
    $fetch = caldav_fetch_ical($url, $conn_user, $conn_pass);
    $duration = number_format_i18n(microtime(true) - $started, 1);

    if ($fetch['ok']) {
        caldav_clear_fetch_error($slug);
        caldav_clear_all_cache();
        return ['ok' => true, 'message' => caldav_test_success_message($slug, $fetch, $duration)];
    }

    // Veelvoorkomende oorzaak: de ingevulde URL is de "calendar-home" en niet
    // de agenda zelf. Zoek dan meteen de bruikbare agenda-URL's op.
    $calendars = caldav_discover_calendars($url, $conn_user, $conn_pass);

    // Is er precies één agenda, dan is er niets te kiezen: die vullen we zelf
    // in. Alleen als hij daadwerkelijk werkt — anders zouden we een verbinding
    // overschrijven met een URL die het ook niet doet.
    if (count($calendars) === 1) {
        $new_url = caldav_sanitize_url($calendars[0]['url']);

        if ($new_url && $new_url !== $url) {
            $retry_started = microtime(true);
            $retry = caldav_fetch_ical($new_url, $conn_user, $conn_pass);
            $retry_duration = number_format_i18n(microtime(true) - $retry_started, 1);

            if ($retry['ok']) {
                $connection['url'] = $new_url;
                caldav_save_connection($slug, $connection);
                caldav_clear_fetch_error($slug);
                caldav_clear_all_cache();

                $prefix = '<strong>URL automatisch gecorrigeerd.</strong> De ingevulde URL was een map met agenda\'s; '
                        . 'er bleek precies één agenda in te zitten'
                        . ($calendars[0]['name'] !== '(naamloos)' ? ' ("' . esc_html($calendars[0]['name']) . '")' : '')
                        . ', en die is nu opgeslagen bij deze verbinding:<br><code>' . esc_html($new_url) . '</code><br><br>';

                return ['ok' => true, 'message' => caldav_test_success_message($slug, $retry, $retry_duration, $prefix)];
            }
        }
    }

    caldav_record_fetch_error($slug, $fetch['error']);

    $message = '<strong>Test mislukt voor "' . esc_html($slug) . '"</strong> (na ' . $duration . ' s): '
             . esc_html($fetch['error']);

    if (!empty($calendars)) {
        $message .= '<br><br><strong>Deze URL is geen agenda maar een map met agenda\'s.</strong> '
                  . 'Vul in plaats daarvan een van deze URL\'s in bij de verbinding:<br>';

        // Tel het aantal items per agenda. Een naam zegt vaak te weinig — een
        // agenda kan "Test" heten en leeg zijn terwijl de naamloze ernaast de
        // echte afspraken bevat. Max 5 om de test kort te houden.
        $probed = 0;
        foreach ($calendars as $cal) {
            $label = '';
            if ($probed < 5) {
                $probed++;
                $sub = caldav_fetch_ical($cal['url'], $conn_user, $conn_pass);
                $label = $sub['ok']
                    ? ' — <strong>' . intval($sub['items']) . ' item(s)</strong>'
                    : ' — <em>niet leesbaar</em>';
            }
            $message .= '&bull; <em>' . esc_html($cal['name']) . '</em>' . $label
                      . '<br><code>' . esc_html($cal['url']) . '</code><br>';
        }
    } else {
        $message .= '<br>De kalender op de site toont zolang de laatst bekende agenda.';
    }

    return ['ok' => false, 'message' => $message];
}

// Bouw de meldtekst van een geslaagde test. $prefix wordt ervoor gezet wanneer
// de URL onderweg automatisch is gecorrigeerd.
function caldav_test_success_message($slug, $fetch, $duration, $prefix = '') {
    // Ruim venster, zodat de test niet faalt door een toevallig lege week.
    $events = caldav_parse_events($fetch['ical'], ['limitdays' => 365, 'pastdays' => 0]);

    $message = $prefix . '<strong>Verbinding "' . esc_html($slug) . '" werkt.</strong> '
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

    $tz_warning = caldav_timezone_warning();
    if ($tz_warning !== '') {
        $message .= '<br><br>' . $tz_warning;
    }

    return $message;
}

// Tijden worden getoond in de tijdzone van de site. Staat die op "UTC+0" of
// een andere vaste afwijking in plaats van een plaats, dan loopt de agenda bij
// zomer- en wintertijd een uur mis.
function caldav_timezone_warning() {
    $tz = function_exists('wp_timezone_string') ? wp_timezone_string() : '';
    if ($tz === '' || strpos($tz, '/') !== false) {
        return '';
    }
    return '<strong>Let op de tijdzone:</strong> de site staat op <code>' . esc_html($tz) . '</code>. '
         . 'Afspraaktijden worden in die tijdzone getoond. Kies onder '
         . '<a href="' . esc_url(admin_url('options-general.php')) . '">Instellingen &gt; Algemeen</a> '
         . 'een plaats, bijvoorbeeld Amsterdam, zodat zomer- en wintertijd kloppen.';
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
                                <?php if (!empty($status['since'])): ?>
                                    <small>Al mis sinds <?php echo esc_html(human_time_diff((int) $status['since'], time())); ?>.</small>
                                <?php endif; ?>
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
    $tz_warning = caldav_timezone_warning();
    ?>
    <?php if ($tz_warning !== ''): ?>
        <div class="notice notice-warning inline"><p><?php echo $tz_warning; ?></p></div>
    <?php endif; ?>
    <h2>Cache & instellingen</h2>

    <form method="post">
        <?php wp_nonce_field('caldav_update_settings', 'caldav_nonce'); ?>
        <input type="hidden" name="caldav_action" value="update_settings">

        <table class="form-table">
            <tr>
                <th scope="row"><label for="caldav-deepl-key">DeepL API-key</label></th>
                <td>
                    <input id="caldav-deepl-key" type="password" name="deepl_api_key" class="regular-text"
                           autocomplete="off" value=""
                           placeholder="<?php echo $deepl_key !== '' ? 'Sleutel is ingesteld — laat leeg om te behouden' : 'Nog geen sleutel ingesteld'; ?>">
                    <?php if ($deepl_key !== ''): ?>
                        <br><label><input type="checkbox" name="deepl_api_key_remove"> Sleutel verwijderen</label>
                    <?php endif; ?>
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

// Reken een iCalendar DURATION (ISO 8601, bijv. PT2H30M, P1DT2H, P2W) om naar
// een eindtijd. Retourneert null bij een onleesbare waarde, zodat de eindtijd
// dan simpelweg wegvalt in plaats van een fout te veroorzaken.
function caldav_apply_duration($dtstart, $duration) {
    if (!$dtstart || $duration === '') {
        return null;
    }

    $negative = (strpos($duration, '-') === 0);
    $spec = ltrim($duration, '+-');

    if (!preg_match('/^P(?:\d+W|(?:\d+Y)?(?:\d+M)?(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+S)?)?)$/', $spec)) {
        return null;
    }

    try {
        $interval = new DateInterval($spec);
    } catch (\Exception $e) {
        caldav_log_error('Onleesbare DURATION', ['duration' => $duration]);
        return null;
    }

    return $negative ? $dtstart->sub($interval) : $dtstart->add($interval);
}

// === UITZONDERINGEN OP HERHALINGEN ===
//
// Wie in zijn agenda één keer van een herhalende afspraak afwijkt, krijgt van
// de client twee mogelijke uitkomsten:
//   * "deze keer verwijderen"  -> een EXDATE-regel in de reeks
//   * "deze keer wijzigen"     -> een los VEVENT met dezelfde UID en een
//                                 RECURRENCE-ID die de oorspronkelijke datum
//                                 aanwijst
// Zonder ondersteuning hiervoor toont de site een geschrapte keer alsnog, of
// twee keer dezelfde afspraak. We vergelijken op datum (Ymd): dat is bestand
// tegen verschillen in tijdzone-notatie tussen servers, en binnen één reeks
// valt er per dag hoogstens één keer af.

function caldav_ical_date_key($value) {
    $value = trim($value);
    return (strlen($value) >= 8 && ctype_digit(substr($value, 0, 8))) ? substr($value, 0, 8) : '';
}

// Alle EXDATE-datums uit één VEVENT. Meerdere EXDATE-regels én meerdere
// komma-gescheiden waarden per regel worden ondersteund.
function caldav_collect_exdates($vevent) {
    $dates = [];
    if (preg_match_all('/^EXDATE[^:\r\n]*:([^\r\n]+)/m', $vevent, $matches)) {
        foreach ($matches[1] as $line) {
            foreach (explode(',', $line) as $value) {
                $key = caldav_ical_date_key($value);
                if ($key !== '') {
                    $dates[$key] = true;
                }
            }
        }
    }
    return $dates;
}

// Bouw een overzicht van gewijzigde losse keren: UID => [Ymd => true].
function caldav_collect_recurrence_overrides($raw_vevents) {
    $overrides = [];
    foreach ($raw_vevents as $vevent) {
        if (!preg_match('/^RECURRENCE-ID[^:\r\n]*:([^\r\n]+)/m', $vevent, $r)) {
            continue;
        }
        if (!preg_match('/^UID:([^\r\n]+)/m', $vevent, $u)) {
            continue;
        }
        $key = caldav_ical_date_key($r[1]);
        if ($key !== '') {
            $overrides[trim($u[1])][$key] = true;
        }
    }
    return $overrides;
}

// === RECURRING EVENTS ===

function caldav_expand_recurring_events($vevent, $dtstart, $dtend, $options, $excluded = []) {
    $rrule_prop = caldav_ical_prop($vevent, 'RRULE');
    if (!$rrule_prop) {
        return null;
    }

    $rrule_string = $rrule_prop['value'];
    $event_tz = $dtstart->getTimezone();
    $now = new DateTimeImmutable('now', $event_tz);
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
            // Alleen een datum: die dag telt nog mee, tot en met 23:59:59.
            // Zonder '!' nam createFromFormat de huidige kloktijd over, waardoor
            // de laatste keer afhankelijk van het tijdstip van ophalen wegviel.
            $until_date = DateTimeImmutable::createFromFormat('!Ymd', $until, $event_tz);
            if ($until_date) {
                $until_date = $until_date->setTime(23, 59, 59);
            }
        } elseif (strtoupper(substr($until, -1)) === 'Z') {
            $until_date = DateTimeImmutable::createFromFormat('Ymd\THis\Z', strtoupper($until), new DateTimeZone('UTC'));
        } else {
            $until_date = DateTimeImmutable::createFromFormat('Ymd\THis', $until, $event_tz);
        }

        if ($until_date) {
            $end_for_expansion = min($end_for_expansion, $until_date);
        }
    }

    $bysetpos = caldav_rrule_numbers($rrule['BYSETPOS'] ?? null);
    $bymonth = caldav_rrule_numbers($rrule['BYMONTH'] ?? null);
    $byday_parts = caldav_parse_byday($byday);
    $bymonthday_parts = caldav_rrule_numbers($bymonthday);

    // Duur van het origineel; elke keer krijgt dezelfde lengte.
    $duration = ($dtend && $dtend > $dtstart) ? $dtstart->diff($dtend) : null;

    $expanded_events = array();
    $emitted = 0;   // telt vanaf het begin van de reeks, ook buiten het venster
    $stop = false;

    // Voeg één keer toe. Geeft false terug zodra de reeks klaar is (COUNT
    // bereikt of voorbij de einddatum), zodat de lus kan stoppen.
    $emit = function (DateTimeImmutable $moment) use (
        &$expanded_events, &$emitted, $count, $dtstart, $pastLimit,
        $end_for_expansion, $excluded, $duration
    ) {
        if ($moment < $dtstart) {
            return true; // vóór de reeks begon
        }
        if ($moment > $end_for_expansion) {
            return false;
        }

        // COUNT telt élke keer van de reeks, ook de keren die buiten het
        // getoonde venster vallen. Anders zou een reeks met COUNT=10 die vorig
        // jaar begon er vandaag alsnog tien tonen.
        $emitted++;

        $skip = !empty($excluded) && isset($excluded[$moment->format('Ymd')]);
        $end = $duration ? $moment->add($duration) : null;

        // Een keer die al begonnen maar nog niet afgelopen is, blijft staan.
        if (!$skip && ($end ?: $moment) >= $pastLimit) {
            $expanded_events[] = array(
                'start' => $moment,
                'end'   => $end,
            );
        }

        return !($count > 0 && $emitted >= $count);
    };

    $guard = 0;

    if ($freq === 'DAILY') {
        // Zonder COUNT hoeven we niet elke dag sinds het begin af te lopen:
        // spring naar vlak voor het venster. Anders liep een dagelijkse reeks
        // die lang geleden begon tegen de lusgrens en verdween hij helemaal.
        $d = $dtstart;
        if ($count === 0 && $dtstart < $pastLimit) {
            $skip_days = (intdiv((int) $dtstart->diff($pastLimit)->days, $interval) - 1) * $interval;
            if ($skip_days > 0) {
                $d = $dtstart->add(new DateInterval('P' . $skip_days . 'D'));
            }
        }

        // BYDAY beperkt een dagelijkse reeks tot bepaalde weekdagen.
        $days = !empty($byday_parts) ? array_column($byday_parts, 'day') : array();

        for (; $d <= $end_for_expansion && $guard++ < 10000; $d = $d->add(new DateInterval('P' . $interval . 'D'))) {
            if (!empty($days) && !in_array((int) $d->format('N'), $days, true)) {
                continue;
            }
            if (!$emit($d)) {
                break;
            }
        }
    } elseif ($freq === 'WEEKLY') {
        // Per week alle gevraagde weekdagen aflopen. De oude opzet sprong
        // telkens een hele week vooruit vanaf DTSTART en filterde daarna op
        // weekdag, waardoor bij BYDAY=MO,WE,FR alleen de maandagen overbleven.
        $days = !empty($byday_parts)
            ? array_column($byday_parts, 'day')
            : array((int) $dtstart->format('N'));

        // Bewust niet modify('monday this week'): die zet de tijd op 00:00 en
        // zou de aanvangstijd van de afspraak wissen. Aftrekken behoudt hem.
        $week_start = $dtstart->sub(new DateInterval('P' . ((int) $dtstart->format('N') - 1) . 'D'));

        while ($week_start <= $end_for_expansion && $guard++ < 5000) {
            for ($offset = 0; $offset < 7; $offset++) {
                $moment = $week_start->add(new DateInterval('P' . $offset . 'D'));
                if (!in_array((int) $moment->format('N'), $days, true)) {
                    continue;
                }
                if (!$emit($moment)) {
                    $stop = true;
                    break;
                }
            }
            if ($stop) {
                break;
            }
            $week_start = $week_start->add(new DateInterval('P' . (7 * $interval) . 'D'));
        }
    } elseif ($freq === 'MONTHLY' || $freq === 'YEARLY') {
        // Een jaarlijkse reeks met BYMONTH kan in een andere maand vallen dan
        // DTSTART, of in meerdere maanden (BYMONTH=3,10). Loop dan per maand en
        // houd INTERVAL bij op jaarniveau; anders werd alleen de maand van
        // DTSTART bekeken.
        $yearly_by_month = ($freq === 'YEARLY' && !empty($bymonth));
        $step = ($freq === 'MONTHLY') ? $interval : ($yearly_by_month ? 1 : $interval * 12);
        $cursor = $dtstart->setDate((int) $dtstart->format('Y'), (int) $dtstart->format('n'), 1);
        $first_year = (int) $dtstart->format('Y');

        while ($cursor <= $end_for_expansion && $guard++ < 3000) {
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');

            $in_period = !$yearly_by_month || (($year - $first_year) % $interval === 0);

            if ($in_period && (empty($bymonth) || in_array($month, $bymonth, true))) {
                $candidates = caldav_month_candidates($dtstart, $year, $month, $byday_parts, $bymonthday_parts);

                // BYSETPOS kiest uit de kandidaten van deze periode, bijv. -1
                // voor "de laatste" (donderdag van de maand).
                if (!empty($bysetpos) && !empty($candidates)) {
                    $picked = array();
                    foreach ($bysetpos as $pos) {
                        $index = ($pos > 0) ? $pos - 1 : count($candidates) + $pos;
                        if (isset($candidates[$index])) {
                            $picked[] = $candidates[$index];
                        }
                    }
                    $candidates = $picked;
                }

                foreach ($candidates as $moment) {
                    if (!$emit($moment)) {
                        $stop = true;
                        break;
                    }
                }
            }

            if ($stop) {
                break;
            }
            $cursor = $cursor->add(new DateInterval('P' . $step . 'M'));
        }
    } else {
        caldav_log_error('Unknown FREQ type', ['freq' => $freq]);
        return null;
    }

    return !empty($expanded_events) ? $expanded_events : null;
}

// "MO,WE,FR" of "2TU" / "-1FR" -> [['ord' => 0|n, 'day' => 1..7], ...]
function caldav_parse_byday($byday) {
    $map = array('MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7);
    $out = array();

    foreach (explode(',', (string) $byday) as $part) {
        $part = strtoupper(trim($part));
        if ($part === '' || !preg_match('/^([+-]?\d+)?([A-Z]{2})$/', $part, $m)) {
            continue;
        }
        if (!isset($map[$m[2]])) {
            continue;
        }
        $out[] = array('ord' => ($m[1] !== '') ? intval($m[1]) : 0, 'day' => $map[$m[2]]);
    }

    return $out;
}

// "1,-1,15" -> [1, -1, 15]
function caldav_rrule_numbers($value) {
    $out = array();
    foreach (explode(',', (string) $value) as $part) {
        $part = trim($part);
        if ($part !== '' && preg_match('/^[+-]?\d+$/', $part)) {
            $out[] = intval($part);
        }
    }
    return $out;
}

// Alle kandidaat-datums binnen één maand, op volgorde. $proto levert de tijd
// van de oorspronkelijke afspraak.
function caldav_month_candidates($proto, $year, $month, $byday_parts, $bymonthday_parts) {
    $days_in_month = (int) $proto->setDate($year, $month, 1)->format('t');
    $candidates = array();

    if (!empty($byday_parts)) {
        foreach ($byday_parts as $part) {
            // Alle dagen in deze maand die op de gevraagde weekdag vallen.
            $matches = array();
            for ($day = 1; $day <= $days_in_month; $day++) {
                $moment = $proto->setDate($year, $month, $day);
                if ((int) $moment->format('N') === $part['day']) {
                    $matches[] = $moment;
                }
            }

            if ($part['ord'] > 0) {
                if (isset($matches[$part['ord'] - 1])) {
                    $candidates[] = $matches[$part['ord'] - 1];
                }
            } elseif ($part['ord'] < 0) {
                $index = count($matches) + $part['ord'];
                if (isset($matches[$index])) {
                    $candidates[] = $matches[$index];
                }
            } else {
                $candidates = array_merge($candidates, $matches);
            }
        }
    } elseif (!empty($bymonthday_parts)) {
        foreach ($bymonthday_parts as $number) {
            // Negatief telt vanaf het eind: -1 is de laatste dag van de maand.
            $day = ($number > 0) ? $number : $days_in_month + $number + 1;
            if ($day >= 1 && $day <= $days_in_month) {
                $candidates[] = $proto->setDate($year, $month, $day);
            }
        }
    } else {
        // Geen BY-regels: dezelfde dag van de maand als het origineel. Een
        // reeks op de 31e slaat maanden zonder 31e over.
        $day = (int) $proto->format('j');
        if ($day <= $days_in_month) {
            $candidates[] = $proto->setDate($year, $month, $day);
        }
    }

    usort($candidates, function ($a, $b) {
        return $a <=> $b;
    });

    return $candidates;
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

    curl_setopt_array($ch, caldav_curl_base_options($url) + [
        CURLOPT_CUSTOMREQUEST => 'REPORT',
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8',
            'User-Agent: WordPress CalDAV Plugin/' . CALDAV_PLUGIN_VERSION
        ],
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

    $result['ok'] = true;
    $result['items'] = count($matches[2]);
    $result['ical'] = implode("\n", array_map('caldav_normalize_calendar_data', $matches[2]));

    return $result;
}

// De iCalendar-tekst staat als XML-tekst in het antwoord. Servers op basis van
// Sabre (Nextcloud, Baïkal) schrijven elke CR als &#13; en een & als &amp;;
// andere servers gebruiken CDATA of kale CRLF. Zonder decoderen liepen de
// regex-patronen voor SUMMARY e.d. door tot het einde van het event. Na deze
// stap is elke regel afgesloten met een kale LF en zijn gevouwen regels weer
// samengevoegd.
function caldav_normalize_calendar_data($block) {
    if (preg_match('/^\s*<!\[CDATA\[(.*)\]\]>\s*$/s', $block, $m)) {
        $text = $m[1];
    } else {
        $text = html_entity_decode($block, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return preg_replace("/\n[ \t]/", '', $text);
}

// === ICALENDAR-EIGENSCHAPPEN ===

// Lees één eigenschap uit een VEVENT, inclusief parameters, bijv.
// DTSTART;TZID=Europe/Amsterdam:20261020T100000 of
// DESCRIPTION;LANGUAGE=nl-NL:... (Outlook). Retourneert
// ['params' => ['TZID' => ...], 'value' => '...'] of null.
function caldav_ical_prop($vevent, $name) {
    $pattern = '/^' . preg_quote($name, '/') . '((?:;[^;:"=\n]+=(?:"[^"]*"|[^;:"\n]*))*):(.*)$/mi';
    if (!preg_match($pattern, $vevent, $m)) {
        return null;
    }

    $params = [];
    if ($m[1] !== '' && preg_match_all('/;([^;:"=\n]+)=("[^"]*"|[^;:"\n]*)/', $m[1], $pm, PREG_SET_ORDER)) {
        foreach ($pm as $p) {
            $params[strtoupper(trim($p[1]))] = trim($p[2], '"');
        }
    }

    return ['params' => $params, 'value' => trim($m[2])];
}

// Tekstwaarde ontsnappen volgens RFC 5545: \\ \, \; \n \N.
function caldav_ical_unescape($value) {
    return preg_replace_callback('/\\\\([\\\\;,nN])/', function ($m) {
        return ($m[1] === 'n' || $m[1] === 'N') ? "\n" : $m[1];
    }, (string) $value);
}

// === TIJDZONES ===
//
// Tijden worden gelezen in de zone die het event zelf opgeeft (TZID), of UTC
// bij een Z-tijd, en daarna getoond in de tijdzone van de site (Instellingen >
// Algemeen). Herhalingen worden in de zone van het event uitgerekend, zodat
// een dienst van 10:00 ook na de overgang naar zomertijd om 10:00 staat.

function caldav_site_timezone() {
    return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
}

// Zet een TZID om naar een PHP-tijdzone. Ondersteunt IANA-namen (ook met
// aanhalingstekens of een voorvoegsel zoals /freeassociation.sourceforge.net/)
// en de Windows-namen die Exchange/Outlook gebruiken. Onbekend → null.
function caldav_ical_timezone($tzid) {
    $tzid = trim((string) $tzid, " \"'");
    if ($tzid === '') {
        return null;
    }

    $windows = [
        'W. Europe Standard Time'       => 'Europe/Amsterdam',
        'Romance Standard Time'         => 'Europe/Paris',
        'Central Europe Standard Time'  => 'Europe/Budapest',
        'Central European Standard Time'=> 'Europe/Warsaw',
        'GMT Standard Time'             => 'Europe/London',
        'Greenwich Standard Time'       => 'Atlantic/Reykjavik',
        'UTC'                           => 'UTC',
        'Coordinated Universal Time'    => 'UTC',
    ];
    if (isset($windows[$tzid])) {
        $tzid = $windows[$tzid];
    }

    $candidates = [$tzid];
    if (preg_match('#([A-Za-z_]+/[A-Za-z_\-]+(?:/[A-Za-z_\-]+)?)$#', $tzid, $m)) {
        $candidates[] = $m[1];
    }
    foreach ($candidates as $name) {
        try {
            return new DateTimeZone($name);
        } catch (\Exception $e) {
            // volgende proberen
        }
    }
    return null;
}

// Lees een DTSTART/DTEND-achtige eigenschap. Retourneert
// ['dt' => DateTimeImmutable, 'all_day' => bool] of null.
// - 20261020                 → hele dag, middernacht in de sitezone
// - 20261020T100000Z         → UTC, omgezet naar de sitezone
// - TZID=...:20261020T100000 → in die zone
// - 20261020T100000          → "zwevend", in de sitezone
function caldav_ical_datetime($prop) {
    if (!$prop || !preg_match('/^(\d{8})(?:T(\d{4})(\d{2})?(Z)?)?$/i', $prop['value'], $m)) {
        return null;
    }

    $site_tz = caldav_site_timezone();

    if (!isset($m[2]) || $m[2] === '') {
        $dt = DateTimeImmutable::createFromFormat('!Ymd', $m[1], $site_tz);
        return $dt ? ['dt' => $dt, 'all_day' => true] : null;
    }

    $stamp = $m[1] . $m[2] . (isset($m[3]) && $m[3] !== '' ? $m[3] : '00');

    if (!empty($m[4])) {
        $dt = DateTimeImmutable::createFromFormat('!YmdHis', $stamp, new DateTimeZone('UTC'));
        return $dt ? ['dt' => $dt->setTimezone($site_tz), 'all_day' => false] : null;
    }

    $tz = !empty($prop['params']['TZID']) ? caldav_ical_timezone($prop['params']['TZID']) : null;
    $dt = DateTimeImmutable::createFromFormat('!YmdHis', $stamp, $tz ?: $site_tz);
    return $dt ? ['dt' => $dt, 'all_day' => false] : null;
}

function caldav_calendar_shortcode($atts) {
    try {
        return caldav_calendar_shortcode_impl($atts);
    } catch (\Throwable $e) {
        caldav_log_error('Shortcode exception', ['error' => $e->getMessage()]);
        $a = shortcode_atts(['connection' => ''], $atts, 'caldav_calendar');
        $slug = caldav_sanitize_slug($a['connection'] ?? '');
        caldav_record_fetch_error($slug, 'PHP-fout tijdens opbouwen kalender: ' . $e->getMessage());
        $lastgood = caldav_lastgood_for_display($slug);
        if (!empty($lastgood)) {
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
        'htmltagmonth' => caldav_sanitize_tag($a['htmltagmonth'], 'h4'),
        'htmltagdate' => caldav_sanitize_tag($a['htmltagdate'], 'h5'),
        'date_format' => sanitize_text_field($a['date_format']),
        'nomonthheaders' => $nomonthheaders,
        'lang' => $lang,
        'translation_notice' => $translation_notice
    ];

    if ($cached_events !== false) {
        return empty($cached_events)
            ? caldav_no_events_html($lang)
            : caldav_generate_output($cached_events, $clean_options);
    }

    // Rate limiting geldt alleen voor echte aanroepen naar de CalDAV-server.
    // Voorheen stond dit vóór de cache, waardoor de elfde bezoeker binnen een
    // minuut een foutmelding kreeg terwijl de agenda gewoon in de cache stond.
    if (!caldav_check_rate_limit($url)) {
        caldav_log_error('Rate limit exceeded', ['url' => $url]);
        $lastgood = caldav_lastgood_for_display($slug, $pastdays);
        if (!empty($lastgood)) {
            return caldav_generate_output($lastgood, $clean_options);
        }
        return $err('<div class="caldav-error">Te veel verzoeken. Probeer over een minuut opnieuw.</div>');
    }

    // Terugval bij een ophaal-/serverfout: toon de laatst bekende goede agenda
    // i.p.v. een lege/foutpagina. Pas als die er ook niet is, val terug op de
    // (op niet-NL onderdrukte) foutmelding.
    // $reason wordt (beknopt, zonder credentials) vastgelegd zodat de beheerder
    // in wp-admin ziet dat de kalender op de terugval draait.
    $fallback = function ($dutch_error_html, $reason = '') use ($slug, $clean_options, $err, $pastdays) {
        caldav_record_fetch_error($slug, $reason !== '' ? $reason : 'Onbekende ophaalfout.');

        $lastgood = caldav_lastgood_for_display($slug, $pastdays);
        if (!empty($lastgood)) {
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
        // Ook dat cachen, anders vroeg elke paginaweergave de server opnieuw.
        caldav_clear_fetch_error($slug);
        caldav_cache_events($cache_key, []);
        return caldav_no_events_html($lang);
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

    $site_tz = caldav_site_timezone();
    $now = new DateTimeImmutable('now', $site_tz);
    $pastLimit = $now->sub(new DateInterval('P' . intval($options['pastdays']) . 'D'));
    $futureLimit = $now->add(new DateInterval('P' . intval($options['limitdays']) . 'D'));

    // Vooraf: welke losse keren zijn apart gewijzigd? Die slaan we straks over
    // in de reeks, anders staat die dag dubbel op de site.
    $overrides = caldav_collect_recurrence_overrides($events_raw[1]);

    foreach ($events_raw[1] as $vevent) {
        // Een VEVENT kan VALARM-blokken bevatten met hun eigen DESCRIPTION,
        // SUMMARY en DURATION. Die zouden hieronder als eigenschap van het
        // event worden opgepikt (een herinnering van PT15M zou dan de eindtijd
        // bepalen), dus knippen we ze er eerst uit.
        $vevent = preg_replace('/BEGIN:VALARM.*?END:VALARM/s', '', $vevent);

        // Een geannuleerde afspraak hoort niet op de site.
        if (preg_match('/^STATUS:CANCELLED/mi', $vevent)) {
            continue;
        }

        // Eigenschappen kunnen parameters hebben (SUMMARY;LANGUAGE=nl:...,
        // DTSTART;VALUE=DATE:...). Voorheen werd alleen DTSTART;TZID= herkend,
        // waardoor hele-dag-afspraken met VALUE=DATE (iCloud, Google,
        // Nextcloud) volledig wegvielen.
        $prop = caldav_ical_prop($vevent, 'SUMMARY');
        $summary = $prop ? trim(str_replace("\n", ' ', caldav_ical_unescape($prop['value']))) : '';

        $prop = caldav_ical_prop($vevent, 'DESCRIPTION');
        $description = $prop ? trim(str_replace("\n", '<br>', caldav_ical_unescape($prop['value']))) : '';

        $prop = caldav_ical_prop($vevent, 'LOCATION');
        $location = $prop ? trim(str_replace("\n", ', ', caldav_ical_unescape($prop['value']))) : '';

        $start = caldav_ical_datetime(caldav_ical_prop($vevent, 'DTSTART'));
        if (!$start) {
            continue;
        }
        $dtstart = $start['dt'];
        $is_all_day = $start['all_day'];

        $end = caldav_ical_datetime(caldav_ical_prop($vevent, 'DTEND'));
        $dtend = $end ? $end['dt'] : null;

        // Een VEVENT mag DTEND óf DURATION hebben, nooit allebei. Diverse
        // servers (o.a. Stalwart) schrijven consequent DURATION; zonder deze
        // omrekening zou de eindtijd overal wegvallen.
        $prop = caldav_ical_prop($vevent, 'DURATION');
        if (!$dtend && $prop) {
            $dtend = caldav_apply_duration($dtstart, $prop['value']);
        }

        // Een hele-dag-afspraak zonder einde duurt die ene dag. Zo blijft hij
        // de hele dag zichtbaar in plaats van om middernacht te verdwijnen.
        if (!$dtend && $is_all_day) {
            $dtend = $dtstart->add(new DateInterval('P1D'));
        }

        // Datums die uit de reeks moeten vallen: expliciet geschrapte keren
        // (EXDATE) plus keren die als los gewijzigd event al meekomen.
        $excluded = caldav_collect_exdates($vevent);
        if (preg_match('/^UID:([^\r\n]+)/m', $vevent, $uid_match)) {
            $uid = trim($uid_match[1]);
            if (!empty($overrides[$uid])) {
                $excluded += $overrides[$uid];
            }
        }

        $recurring_dates = caldav_expand_recurring_events($vevent, $dtstart, $dtend, $options, $excluded);
        if ($recurring_dates === null) {
            $recurring_dates = [['start' => $dtstart, 'end' => $dtend]];
        }

        foreach ($recurring_dates as $occurrence) {
            // Zichtbaar zolang de afspraak nog niet voorbij is (niet alleen
            // zolang hij nog niet begonnen is).
            $last_moment = $occurrence['end'] ?: $occurrence['start'];
            if ($last_moment < $pastLimit || $occurrence['start'] > $futureLimit) {
                continue;
            }
            // Hele-dag-afspraken blijven op hun kalenderdag staan; tijden
            // worden in de tijdzone van de site getoond.
            $events[] = array(
                'summary' => $summary,
                'description' => $description,
                'location' => $location,
                'start' => $is_all_day ? $occurrence['start'] : $occurrence['start']->setTimezone($site_tz),
                'end' => ($occurrence['end'] && !$is_all_day) ? $occurrence['end']->setTimezone($site_tz) : $occurrence['end'],
                'is_all_day' => $is_all_day
            );
        }
    }

    return $events;
}

// Laatst bekende goede agenda, zonder afspraken die intussen voorbij zijn.
// Zonder dit filter bleef bij een langere storing een dienst van vorige week
// bovenaan staan.
function caldav_lastgood_for_display($slug, $pastdays = 0) {
    $limit = (new DateTimeImmutable('now'))->sub(new DateInterval('P' . max(0, intval($pastdays)) . 'D'));
    $events = array_values(array_filter(caldav_get_lastgood($slug), function ($event) use ($limit) {
        if (empty($event['start']) || !($event['start'] instanceof DateTimeInterface)) {
            return false;
        }
        $last = (!empty($event['end']) && $event['end'] instanceof DateTimeInterface) ? $event['end'] : $event['start'];
        return $last >= $limit;
    }));
    usort($events, function ($a, $b) {
        return $a['start'] <=> $b['start'];
    });
    return $events;
}

function caldav_no_events_html($lang) {
    $no_events = $lang === 'nl' ? 'Geen komende evenementen gevonden.' : 'No upcoming events found.';
    return '<div class="caldav-no-events">' . esc_html($no_events) . '</div>';
}

// === OUTPUT GENERATORS ===

// Dag- en maandnamen per taal, los van de taal van de WordPress-installatie:
// een Engelse pagina op een Nederlandse site moet Engelse namen tonen.
// Index 1..12 voor maanden, 1..7 (ma..zo, ISO) voor dagen.
function caldav_date_names($lang) {
    static $names = null;
    if ($names === null) {
        $table = [
            'nl' => ['januari februari maart april mei juni juli augustus september oktober november december',
                     'jan feb mrt apr mei jun jul aug sep okt nov dec',
                     'maandag dinsdag woensdag donderdag vrijdag zaterdag zondag'],
            'en' => ['January February March April May June July August September October November December',
                     'Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec',
                     'Monday Tuesday Wednesday Thursday Friday Saturday Sunday'],
            'de' => ['Januar Februar März April Mai Juni Juli August September Oktober November Dezember',
                     'Jan Feb Mär Apr Mai Jun Jul Aug Sep Okt Nov Dez',
                     'Montag Dienstag Mittwoch Donnerstag Freitag Samstag Sonntag'],
            'fr' => ['janvier février mars avril mai juin juillet août septembre octobre novembre décembre',
                     'janv févr mars avr mai juin juil août sept oct nov déc',
                     'lundi mardi mercredi jeudi vendredi samedi dimanche'],
            'es' => ['enero febrero marzo abril mayo junio julio agosto septiembre octubre noviembre diciembre',
                     'ene feb mar abr may jun jul ago sep oct nov dic',
                     'lunes martes miércoles jueves viernes sábado domingo'],
        ];
        $names = [];
        foreach ($table as $code => $rows) {
            $names[$code] = [
                'months'       => array_combine(range(1, 12), explode(' ', $rows[0])),
                'months_short' => array_combine(range(1, 12), explode(' ', $rows[1])),
                'days'         => array_combine(range(1, 7), explode(' ', $rows[2])),
            ];
        }
    }
    $lang = strtolower((string) $lang);
    return $names[$lang] ?? $names['nl'];
}

// Maandkop, bijv. "Oktober 2026".
function caldav_month_heading($date, $lang) {
    $names = caldav_date_names($lang);
    return ucfirst($names['months'][(int) $date->format('n')]) . ' ' . $date->format('Y');
}

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
            $out .= '<' . $options['htmltagmonth'] . ' class="caldav-month-header">' . esc_html(caldav_month_heading($event['start'], $options['lang'] ?? 'nl')) . '</' . $options['htmltagmonth'] . '>';
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
    $names = caldav_date_names($options['lang'] ?? 'nl');
    $month_short = esc_html($names['months_short'][(int) $event['start']->format('n')]);

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
            $out .= '<' . $options['htmltagmonth'] . '>' . esc_html(caldav_month_heading($event['start'], $options['lang'] ?? 'nl')) . '</' . $options['htmltagmonth'] . '>';
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

    $names = caldav_date_names($options['lang'] ?? 'nl');

    foreach ($events as $event) {
        $month = $event['start']->format('F Y');

        $dateReadable = ucfirst($names['days'][(int) $event['start']->format('N')]) . ' '
                      . $event['start']->format('j') . ' '
                      . $names['months'][(int) $event['start']->format('n')];

        if ($month !== $currentMonth && $options['nomonthheaders'] !== 'true') {
            $monthFormatted = caldav_month_heading($event['start'], $options['lang'] ?? 'nl');
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
    if (current_user_can('manage_options') && get_option('caldav_show_activation_notice')) {
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

// === UPDATES VIA GITHUB ===
//
// WordPress kijkt via de Update URI-header niet op wordpress.org, maar vraagt
// het hier na. Bron is de nieuwste release op GitHub (drafts en prereleases
// telt GitHub niet mee). Geïnstalleerd wordt de zip die bij de release hangt,
// niet de automatische "Source code"-zip: die pakt uit in een map met een
// commit-hash in de naam, waarna WordPress de plugin als een andere ziet.

// Haalt de nieuwste release op, 12 uur gecachet. Zonder token staat GitHub
// 60 aanroepen per uur per IP toe; een mislukte aanroep wordt een uur
// onthouden zodat een storing niet bij elke paginaweergave opnieuw wordt
// geprobeerd.
function caldav_github_latest_release($force = false) {
    $cached = get_site_transient('caldav_github_release');
    if (!$force && is_array($cached)) {
        return empty($cached) ? null : $cached;
    }

    $response = wp_remote_get('https://api.github.com/repos/' . CALDAV_GITHUB_REPO . '/releases/latest', [
        'timeout' => 10,
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Modern-CalDAV-Calendar/' . CALDAV_PLUGIN_VERSION,
        ],
    ]);

    $data = null;
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
    }

    $package = '';
    if (is_array($data) && !empty($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            $name = isset($asset['name']) ? $asset['name'] : '';
            if (preg_match('/^modern-caldav-calendar.*\.zip$/i', $name) && !empty($asset['browser_download_url'])) {
                $package = $asset['browser_download_url'];
                break;
            }
        }
    }

    if (!is_array($data) || empty($data['tag_name']) || $package === '') {
        set_site_transient('caldav_github_release', [], HOUR_IN_SECONDS);
        return null;
    }

    $release = [
        'version'   => ltrim($data['tag_name'], 'vV'),
        'package'   => $package,
        'url'       => isset($data['html_url']) ? $data['html_url'] : 'https://github.com/' . CALDAV_GITHUB_REPO,
        'notes'     => isset($data['body']) ? (string) $data['body'] : '',
        'published' => isset($data['published_at']) ? $data['published_at'] : '',
    ];
    set_site_transient('caldav_github_release', $release, 12 * HOUR_IN_SECONDS);
    return $release;
}

// "Opnieuw controleren" onder Dashboard > Updates slaat de cache over, zodat
// een net uitgebrachte versie meteen zichtbaar is.
function caldav_github_force_check() {
    return !empty($_GET['force-check']) && current_user_can('update_plugins');
}

function caldav_github_update_check($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== plugin_basename(__FILE__)) {
        return $update;
    }

    $release = caldav_github_latest_release(caldav_github_force_check());
    if (!$release) {
        return $update;
    }

    // WordPress vergelijkt zelf de versie en toont alleen een update als die
    // nieuwer is dan de geïnstalleerde.
    return [
        'slug'         => dirname(plugin_basename(__FILE__)),
        'version'      => $release['version'],
        'url'          => $release['url'],
        'package'      => $release['package'],
        'requires_php' => '7.4',
    ];
}
add_filter('update_plugins_github.com', 'caldav_github_update_check', 10, 3);

// Venster "Details bekijken" bij de update: toont de release notes van GitHub.
function caldav_github_plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(plugin_basename(__FILE__))) {
        return $result;
    }

    $release = caldav_github_latest_release();
    if (!$release) {
        return $result;
    }

    return (object) [
        'name'          => 'Modern CalDAV Calendar',
        'slug'          => $args->slug,
        'version'       => $release['version'],
        'author'        => '<a href="https://boltconnect.nl">Bolt Connect</a>',
        'homepage'      => 'https://github.com/' . CALDAV_GITHUB_REPO,
        'download_link' => $release['package'],
        'requires_php'  => '7.4',
        'last_updated'  => $release['published'],
        'sections'      => [
            'changelog' => caldav_github_notes_html($release['notes'], $release['url']),
        ],
    ];
}
add_filter('plugins_api', 'caldav_github_plugin_info', 10, 3);

// Release notes zijn Markdown. Alleen het nodige: opsommingen worden een
// lijst, koppen vet, de rest losse alinea's. Alles wordt eerst ge-escaped.
function caldav_github_notes_html($markdown, $url) {
    $html = '';
    $in_list = false;
    foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
        $line = trim($line);
        $is_item = preg_match('/^[-*]\s+(.*)$/', $line, $m);
        if ($in_list && !$is_item) {
            $html .= '</ul>';
            $in_list = false;
        }
        if ($is_item) {
            if (!$in_list) {
                $html .= '<ul>';
                $in_list = true;
            }
            $html .= '<li>' . esc_html($m[1]) . '</li>';
        } elseif (preg_match('/^#+\s+(.*)$/', $line, $m)) {
            $html .= '<h4>' . esc_html($m[1]) . '</h4>';
        } elseif ($line !== '') {
            $html .= '<p>' . esc_html($line) . '</p>';
        }
    }
    if ($in_list) {
        $html .= '</ul>';
    }
    $html .= '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Bekijk de release op GitHub</a></p>';
    return $html;
}

// Staat de plugin in een map met een andere naam (bijvoorbeeld na het
// uploaden van een hernoemde zip), dan zou de update een tweede map naast de
// oude zetten en WordPress de plugin uitschakelen. Hernoem de uitgepakte map
// daarom naar de huidige mapnaam.
function caldav_github_fix_source_dir($source, $remote_source, $upgrader, $hook_extra = []) {
    global $wp_filesystem;

    if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
        return $source;
    }

    $wanted = trailingslashit($remote_source) . dirname(plugin_basename(__FILE__));
    if (untrailingslashit($source) === $wanted) {
        return $source;
    }
    if ($wp_filesystem && $wp_filesystem->move(untrailingslashit($source), $wanted, true)) {
        return trailingslashit($wanted);
    }
    return new WP_Error('caldav_update_dir', 'De update kon niet in de pluginmap worden geplaatst.');
}
add_filter('upgrader_source_selection', 'caldav_github_fix_source_dir', 10, 4);

// Na een update de cache legen, zodat de oude release niet blijft hangen.
function caldav_github_after_upgrade($upgrader, $options) {
    if (isset($options['action'], $options['type']) && $options['action'] === 'update' && $options['type'] === 'plugin'
        && !empty($options['plugins']) && in_array(plugin_basename(__FILE__), (array) $options['plugins'], true)) {
        delete_site_transient('caldav_github_release');
    }
}
add_action('upgrader_process_complete', 'caldav_github_after_upgrade', 10, 2);
