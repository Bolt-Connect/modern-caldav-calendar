<?php
/*
Plugin Name: Modern CalDAV Calendar
Description: Moderne CalDAV kalender plugin met styling, herhalingen en veiligheid
Version: 2.4
Author: Bolt Connect
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

// Define plugin constants
define('CALDAV_PLUGIN_VERSION', '2.4');
define('CALDAV_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CALDAV_PLUGIN_URL', plugin_dir_url(__FILE__));

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, 'caldav_plugin_activate');
register_deactivation_hook(__FILE__, 'caldav_plugin_deactivate');

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
    add_option('caldav_show_activation_notice', true);
}

function caldav_plugin_deactivate() {
    caldav_clear_all_cache();
}

// Security & caching functions
function caldav_sanitize_url($url) {
    $url = str_replace('%40', '@', $url);
    $url = sanitize_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    return $url;
}

function caldav_get_cache_key($url, $user, $options = []) {
    $key_data = [
        'url' => $url,
        'user' => $user,
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

// Enqueue styles
function caldav_enqueue_styles() {
    wp_enqueue_style('caldav-style', plugin_dir_url(__FILE__) . 'caldav-style.css', array(), CALDAV_PLUGIN_VERSION);
}
add_action('wp_enqueue_scripts', 'caldav_enqueue_styles');

// Admin menu
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

// Admin page
function caldav_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Je hebt niet voldoende rechten om deze pagina te bekijken.'));
    }
    
    // Handle cache clearing
    if (isset($_POST['clear_cache']) && wp_verify_nonce($_POST['caldav_nonce'] ?? '', 'caldav_clear_cache')) {
        caldav_clear_all_cache();
        echo '<div class="notice notice-success"><p>Cache geleegd!</p></div>';
    }
    
    // Handle settings update
    if (isset($_POST['update_settings']) && wp_verify_nonce($_POST['caldav_nonce'] ?? '', 'caldav_update_settings')) {
        $cache_enabled = isset($_POST['cache_enabled']) ? true : false;
        $cache_duration = intval($_POST['cache_duration'] ?? 300);
        $cache_duration = max(60, min(3600, $cache_duration));
        
        update_option('caldav_cache_enabled', $cache_enabled);
        update_option('caldav_cache_duration', $cache_duration);
        
        echo '<div class="notice notice-success"><p>Instellingen opgeslagen!</p></div>';
    }
    
    $cache_enabled = get_option('caldav_cache_enabled', true);
    $cache_duration = get_option('caldav_cache_duration', 300);
    ?>
    <div class="wrap">
        <h1>CalDAV Calendar Plugin v<?php echo CALDAV_PLUGIN_VERSION; ?></h1>
        
        <!-- Settings Form -->
        <h2>Instellingen</h2>
        <form method="post">
            <?php wp_nonce_field('caldav_update_settings', 'caldav_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Cache inschakelen</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cache_enabled" <?php checked($cache_enabled); ?> />
                            Cache kalenderdata om prestaties te verbeteren
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Cache duur (seconden)</th>
                    <td>
                        <input type="number" name="cache_duration" value="<?php echo esc_attr($cache_duration); ?>" min="60" max="3600" />
                        <p class="description">Tussen 60 en 3600 seconden (1 minuut tot 1 uur)</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="update_settings" class="button-primary" value="Instellingen Opslaan" />
            </p>
        </form>
        
        <!-- Cache Management -->
        <h2>Cache Beheer</h2>
        <form method="post">
            <?php wp_nonce_field('caldav_clear_cache', 'caldav_nonce'); ?>
            <p>
                <input type="submit" name="clear_cache" class="button" value="Cache Legen" />
                <span class="description">Leeg alle opgeslagen kalenderdata om fresh data op te halen.</span>
            </p>
        </form>
        
        <hr>
        
        <h2>Hoe te gebruiken</h2>
        <p>Gebruik de shortcode <code>[caldav_calendar]</code> op je pagina's en berichten om je CalDAV kalender weer te geven.</p>
        
        <h3>Basis gebruik</h3>
        <code>[caldav_calendar url="https://jouw-caldav-server.com/calendar" user="gebruikersnaam" pass="wachtwoord"]</code>
        
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
                <tr>
                    <td><code>url</code></td>
                    <td>CalDAV kalender URL (verplicht)</td>
                    <td>-</td>
                    <td>Je CalDAV server URL</td>
                </tr>
                <tr>
                    <td><code>user</code></td>
                    <td>Gebruikersnaam voor authenticatie</td>
                    <td>-</td>
                    <td>Je gebruikersnaam</td>
                </tr>
                <tr>
                    <td><code>pass</code></td>
                    <td>Wachtwoord voor authenticatie</td>
                    <td>-</td>
                    <td>Je wachtwoord</td>
                </tr>
                <tr>
                    <td><code>view</code></td>
                    <td>Weergave stijl</td>
                    <td>old</td>
                    <td>old, modern, list, card</td>
                </tr>
                <tr>
                    <td><code>theme</code></td>
                    <td>Kleurthema</td>
                    <td>default</td>
                    <td>default, dark, blue</td>
                </tr>
                <tr>
                    <td><code>count</code></td>
                    <td>Maximum aantal evenementen</td>
                    <td>10</td>
                    <td>1-100</td>
                </tr>
                <tr>
                    <td><code>limitdays</code></td>
                    <td>Aantal dagen vooruit kijken</td>
                    <td>365</td>
                    <td>Elk getal</td>
                </tr>
                <tr>
                    <td><code>pastdays</code></td>
                    <td>Aantal dagen terug kijken</td>
                    <td>0</td>
                    <td>Elk getal</td>
                </tr>
                <tr>
                    <td><code>nomonthheaders</code></td>
                    <td>Maandkoppen verbergen</td>
                    <td>false</td>
                    <td>true, false</td>
                </tr>
                <tr>
                    <td><code>show_time</code></td>
                    <td>Tijd weergeven</td>
                    <td>yes</td>
                    <td>yes, no</td>
                </tr>
                <tr>
                    <td><code>show_description</code></td>
                    <td>Beschrijving weergeven</td>
                    <td>yes</td>
                    <td>yes, no</td>
                </tr>
            </tbody>
        </table>
        
        <h3>Voorbeelden</h3>
        <h4>Volgende evenement zonder maandkop</h4>
        <code>[caldav_calendar url="..." user="..." pass="..." count="1" nomonthheaders="true"]</code>
        
        <h4>5 evenementen zonder maandkoppen</h4>
        <code>[caldav_calendar url="..." user="..." pass="..." count="5" nomonthheaders="true"]</code>
        
        <h4>Moderne kaart weergave</h4>
        <code>[caldav_calendar url="..." user="..." pass="..." view="card" theme="dark" count="15"]</code>
        
        <div style="background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin-top: 20px;">
            <h4>✨ Nieuw in v2.4:</h4>
            <p><strong>Verbeterde ondersteuning voor herhalende evenementen!</strong></p>
            <ul>
                <li>✅ Dagelijks, wekelijks, maandelijks en jaarlijks herhalende evenementen</li>
                <li>✅ BYDAY filter (bijv. elke maandag en woensdag)</li>
                <li>✅ BYMONTHDAY filter (bijv. elke 15e van de maand)</li>
                <li>✅ COUNT en UNTIL beperkingen</li>
                <li>✅ Interval ondersteuning (bijv. elke 2 weken)</li>
                <li>✅ Automatische expansie tot de limietdatum</li>
            </ul>
        </div>
        
        <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin-top: 20px;">
            <h4>⚠️ Beveiligingsnotitie</h4>
            <p>Zorg ervoor dat je CalDAV gebruikersnaam en wachtwoord veilig zijn. Overweeg het gebruik van app-specifieke wachtwoorden waar mogelijk.</p>
        </div>
    </div>
    <?php
}

// Improved recurring events expansion
function expand_recurring_events($vevent, $dtstart, $dtend, $options) {
    if (!preg_match('/RRULE:(.*?)(\r\n|$)/s', $vevent, $m)) {
        return null;
    }
    
    $rrule_string = trim($m[1]);
    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $pastLimit = $now->sub(new DateInterval('P' . intval($options['pastdays']) . 'D'));
    $futureLimit = $now->add(new DateInterval('P' . intval($options['limitdays']) . 'D'));
    
    // Parse RRULE
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
    
    // Bepaal eindatum
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
        
        // BYDAY filter
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
        
        // BYMONTHDAY filter
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
        
        // Add event if it passes filters
        if ($include_event && $current >= $pastLimit && $current <= $end_for_expansion) {
            $occurrence_end = $dtend;
            if ($dtend && $dtstart !== $dtend) {
                $diff = $dtstart->diff($dtend);
                $occurrence_end = $current->add($diff);
            }
            
            $expanded_events[] = array(
                'start' => $current,
                'end' => $occurrence_end
            );
            $generated++;
        }
        
        // Increment
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

// Main shortcode function
function caldav_calendar_shortcode($atts) {
    $a = shortcode_atts(array(
        'url' => '',
        'user' => '',
        'pass' => '',
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
        'nomonthheaders' => 'false'
    ), $atts);

    // Validate URL
    $url = caldav_sanitize_url($a['url']);
    if (!$url) {
        caldav_log_error('Invalid URL provided', ['url' => $a['url']]);
        return '<div class="caldav-error">Ongeldige kalender URL opgegeven.</div>';
    }

    // Sanitize inputs
    $user = sanitize_text_field($a['user']);
    $pass = sanitize_text_field($a['pass']);
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

    // Rate limiting
    if (!caldav_check_rate_limit($url)) {
        caldav_log_error('Rate limit exceeded', ['url' => $url]);
        return '<div class="caldav-error">Te veel verzoeken. Probeer over een minuut opnieuw.</div>';
    }

    // Check cache
    $options_for_cache = [
        'count' => $count,
        'limitdays' => $limitdays,
        'pastdays' => $pastdays
    ];
    $cache_key = caldav_get_cache_key($url, $user, $options_for_cache);
    $cached_events = caldav_get_cached_events($cache_key);
    
    if ($cached_events !== false) {
        $clean_options = [
            'count' => $count,
            'view' => $view,
            'theme' => $theme,
            'show_time' => $show_time,
            'show_description' => $show_description,
            'htmltagmonth' => sanitize_key($a['htmltagmonth']),
            'htmltagdate' => sanitize_key($a['htmltagdate']),
            'date_format' => sanitize_text_field($a['date_format']),
            'nomonthheaders' => $nomonthheaders
        ];
        return generate_calendar_output($cached_events, $clean_options);
    }

    // XML REPORT body
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

    // cURL request with security
    $ch = curl_init();
    if (!$ch) {
        caldav_log_error('Failed to initialize cURL');
        return '<div class="caldav-error">Kon verbinding niet initialiseren.</div>';
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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
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
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($resp === false || !empty($curl_error)) {
        caldav_log_error('cURL error', ['error' => $curl_error, 'url' => $url]);
        return '<div class="caldav-error">Netwerkfout bij ophalen kalender: ' . esc_html($curl_error) . '</div>';
    }

    if ($httpcode >= 400) {
        caldav_log_error('HTTP error', ['code' => $httpcode, 'url' => $url]);
        $error_messages = [
            401 => 'Authenticatie gefaald. Controleer gebruikersnaam en wachtwoord.',
            403 => 'Toegang geweigerd.',
            404 => 'Kalender niet gevonden.',
            500 => 'Server fout.'
        ];
        $error_msg = $error_messages[$httpcode] ?? 'HTTP fout ' . $httpcode;
        return '<div class="caldav-error">' . esc_html($error_msg) . '</div>';
    }

    // Extract calendar data
    if (!preg_match_all('/<([a-zA-Z0-9]+:)?calendar-data[^>]*>(.*?)<\/([a-zA-Z0-9]+:)?calendar-data>/s', $resp, $matches)) {
        caldav_log_error('No calendar data found', ['url' => $url]);
        return '<div class="caldav-error">Geen kalenderdata gevonden.</div>';
    }
    
    if (empty($matches[2])) {
        return '<div class="caldav-error">Geen iCalendar data ontvangen.</div>';
    }

    $ical_data = implode("\n", $matches[2]);
    $ical = preg_replace("/\r\n[ \t]/", '', $ical_data);

    // Parse events
    $parse_options = [
        'limitdays' => $limitdays,
        'pastdays' => $pastdays
    ];
    $events = parse_caldav_events($ical, $parse_options);

    if (empty($events)) {
        return '<div class="caldav-no-events">Geen komende evenementen gevonden.</div>';
    }

    // Cache events
    caldav_cache_events($cache_key, $events);

    // Sort events
    usort($events, function($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    // Generate output
    $clean_options = [
        'count' => $count,
        'view' => $view,
        'theme' => $theme,
        'show_time' => $show_time,
        'show_description' => $show_description,
        'htmltagmonth' => sanitize_key($a['htmltagmonth']),
        'htmltagdate' => sanitize_key($a['htmltagdate']),
        'date_format' => sanitize_text_field($a['date_format']),
        'nomonthheaders' => $nomonthheaders
    ];

    return generate_calendar_output($events, $clean_options);
}

function parse_caldav_events($ical, $options) {
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical, $events_raw);
    $events = array();
    
    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $pastLimit = $now->sub(new DateInterval('P' . intval($options['pastdays']) . 'D'));
    $futureLimit = $now->add(new DateInterval('P' . intval($options['limitdays']) . 'D'));

    foreach ($events_raw[1] as $vevent) {
        // Extract fields
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

        // Parse start time
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

        // Parse end time
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
        
        // Try to expand recurring events
        $recurring_dates = expand_recurring_events($vevent, $dtstart, $dtend, $options);
        
        if ($recurring_dates !== null) {
            // Recurring event - add all occurrences
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
            // Single event
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

function generate_calendar_output($events, $options) {
    if ($options['view'] === 'old' || $options['view'] === 'simple') {
        return generate_old_view($events, $options);
    }
    
    $theme_class = 'caldav-theme-' . $options['theme'];
    $view_class = 'caldav-view-' . $options['view'];
    
    $out = '<div class="caldav-calendar ' . $theme_class . ' ' . $view_class . '">';
    
    switch ($options['view']) {
        case 'card':
            $out .= generate_card_view($events, $options);
            break;
        case 'modern':
            $out .= generate_modern_view($events, $options);
            break;
        default:
            $out .= generate_list_view($events, $options);
            break;
    }
    
    $out .= '</div>';
    
    return $out;
}

function generate_modern_view($events, $options) {
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
        
        $out .= generate_event_card($event, $options);
        
        $i++;
        if ($i >= intval($options['count'])) break;
    }
    
    if ($currentMonth !== '') {
        $out .= '</div>';
    }
    
    $out .= '</div>';
    return $out;
}

function generate_card_view($events, $options) {
    $out = '<div class="caldav-card-grid">';
    $i = 0;
    
    foreach ($events as $event) {
        $out .= generate_event_card($event, $options, true);
        
        $i++;
        if ($i >= intval($options['count'])) break;
    }
    
    $out .= '</div>';
    return $out;
}

function generate_event_card($event, $options, $standalone = false) {
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
        $out .= '<div class="caldav-event-description">' . $event['description'] . '</div>';
    }
    
    $out .= '</div>';
    $out .= '</div>';
    
    return $out;
}

function generate_list_view($events, $options) {
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
            $out .= '<div class="caldav-event-description">' . $event['description'] . '</div>';
        }
        
        $out .= '<div class="caldav-event-datetime"><small>' . esc_html($event['start']->format($options['date_format'])) . '</small></div>';
        $out .= '</div>';
        $out .= '</li>';
        
        $i++;
        if ($i >= intval($options['count'])) break;
    }
    
    $out .= '</ul>';
    return $out;
}

function generate_old_view($events, $options) {
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
    
    foreach ($events as $event) {
        $month = $event['start']->format('F Y');
        
        $dayName = $event['start']->format('l');
        $dayNumber = $event['start']->format('j');
        $monthName = $event['start']->format('F');
        
        $dayDutch = isset($days[$dayName]) ? ucfirst($days[$dayName]) : ucfirst(strtolower($dayName));
        $monthNameDutch = isset($months[$monthName]) ? $months[$monthName] : strtolower($monthName);
        
        $dateReadable = $dayDutch . ' ' . $dayNumber . ' ' . $monthNameDutch;
        
        if ($month !== $currentMonth && $options['nomonthheaders'] !== 'true') {
            $monthOnly = $event['start']->format('F');
            $year = $event['start']->format('Y');
            
            $monthDutchOnly = isset($months[$monthOnly]) ? $months[$monthOnly] : strtolower($monthOnly);
            $monthFormatted = ucfirst($monthDutchOnly) . ' ' . $year;
            
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
            $out .= '<p class="caldav-description">' . $event['description'] . '</p>';
        }
        
        $out .= '</li>';
        
        $i++;
        if ($i >= intval($options['count'])) break;
    }
    
    $out .= '</ul>';
    return $out;
}

add_shortcode('caldav_calendar', 'caldav_calendar_shortcode');

// Activation notice
function caldav_show_activation_notice() {
    if (get_option('caldav_show_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>CalDAV Calendar Plugin v<?php echo CALDAV_PLUGIN_VERSION; ?> geactiveerd!</strong> Ga naar <a href="<?php echo esc_url(admin_url('options-general.php?page=caldav-calendar')); ?>">Instellingen > CalDAV Calendar</a> voor instructies en instellingen.</p>
        </div>
        <?php
        delete_option('caldav_show_activation_notice');
    }
}
add_action('admin_notices', 'caldav_show_activation_notice');

// Inline CSS
function caldav_inline_styles() {
    echo '<style>
    .caldav-calendar {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        line-height: 1.6;
    }
    
    .caldav-modern-grid {
        display: grid;
        gap: 2rem;
    }
    
    .caldav-month-section {
        background: #ffffff;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        border: 1px solid #e1e5e9;
    }
    
    .caldav-month-section.caldav-no-header {
        padding-top: 0.5rem;
    }
    
    .caldav-month-header {
        color: #2c3e50;
        margin: 0 0 1.5rem 0;
        font-size: 1.5rem;
        font-weight: 600;
        border-bottom: 2px solid #3498db;
        padding-bottom: 0.5rem;
    }
    
    .caldav-event-card {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem;
        margin-bottom: 1rem;
        background: #f8f9fa;
        border-radius: 8px;
        border-left: 4px solid #3498db;
        transition: all 0.3s ease;
    }
    
    .caldav-event-card:hover {
        background: #e9ecef;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .caldav-date-badge {
        display: flex;
        flex-direction: column;
        align-items: center;
        background: #3498db;
        color: white;
        border-radius: 8px;
        padding: 0.5rem;
        min-width: 60px;
        text-align: center;
    }
    
    .caldav-day {
        font-size: 1.5rem;
        font-weight: bold;
        line-height: 1;
    }
    
    .caldav-month {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .caldav-event-content {
        flex: 1;
    }
    
    .caldav-event-title {
        margin: 0 0 0.5rem 0;
        color: #2c3e50;
        font-size: 1.1rem;
        font-weight: 600;
    }
    
    .caldav-event-time,
    .caldav-event-location {
        font-size: 0.9rem;
        color: #7f8c8d;
        margin-bottom: 0.5rem;
    }
    
    .caldav-event-description {
        color: #5a6c7d;
        font-size: 0.9rem;
        margin-top: 0.5rem;
    }
    
    .caldav-error,
    .caldav-no-events {
        background: #fee;
        color: #c33;
        padding: 1rem;
        border-radius: 6px;
        border: 1px solid #fcc;
    }
    
    .caldav-theme-blue .caldav-month-section {
        background: #f8f9ff;
        border-color: #4a90e2;
    }
    
    .caldav-theme-blue .caldav-month-header {
        border-color: #4a90e2;
    }
    
    .caldav-theme-blue .caldav-event-card {
        background: #f0f4ff;
        border-left-color: #4a90e2;
    }
    
    .caldav-theme-blue .caldav-date-badge {
        background: #4a90e2;
    }
    
    .caldav-theme-dark .caldav-month-section {
        background: #2c3e50;
        color: #ecf0f1;
        border-color: #34495e;
    }
    
    .caldav-theme-dark .caldav-month-header {
        color: #ecf0f1;
        border-color: #e74c3c;
    }
    
    .caldav-theme-dark .caldav-event-card {
        background: #34495e;
        border-left-color: #e74c3c;
    }
    
    .caldav-theme-dark .caldav-event-title {
        color: #ecf0f1;
    }
    
    .caldav-theme-dark .caldav-date-badge {
        background: #e74c3c;
    }
    
    .caldav-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
    }
    
    .caldav-card-standalone {
        background: #ffffff;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        border: 1px solid #e1e5e9;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        transition: all 0.3s ease;
    }
    
    .caldav-card-standalone:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }
    
    .caldav-events-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .caldav-month-divider {
        background: #f8f9fa;
        padding: 1rem;
        margin: 1.5rem 0 1rem 0;
        border-radius: 6px;
        border-left: 4px solid #3498db;
    }
    
    .caldav-month-divider h4 {
        margin: 0;
        color: #2c3e50;
        font-size: 1.3rem;
        font-weight: 600;
    }
    
    .caldav-event-item {
        display: flex;
        gap: 1rem;
        padding: 1rem;
        margin-bottom: 0.5rem;
        background: #fff;
        border-radius: 6px;
        border: 1px solid #e1e5e9;
        transition: all 0.3s ease;
    }
    
    .caldav-event-item:hover {
        background: #f8f9fa;
        border-color: #3498db;
    }
    
    .caldav-event-date {
        min-width: 120px;
        margin: 0;
        font-size: 0.9rem;
        color: #7f8c8d;
        font-weight: 500;
    }
    
    .caldav-event-details {
        flex: 1;
    }
    
    .caldav-event-datetime {
        margin-top: 0.5rem;
        color: #7f8c8d;
    }
    
    .caldav-events {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .caldav-events .caldav-month {
        list-style: none;
        margin: 1.5rem 0 0.5rem 0;
    }
    
    .caldav-events .caldav-month h4 {
        color: #2c3e50;
        margin: 0;
        font-size: 1.2rem;
        font-weight: 600;
    }
    
    .caldav-events .caldav-event {
        list-style: none;
        margin-bottom: 1rem;
        padding: 0;
    }
    
    .caldav-events .caldav-event h5 {
        color: #34495e;
        margin: 0.5rem 0 0.3rem 0;
        font-size: 1rem;
        font-weight: 500;
    }
    
    .caldav-events .caldav-summary {
        margin: 0.3rem 0;
        font-size: 1rem;
    }
    
    .caldav-events .caldav-time {
        margin: 0.3rem 0;
        font-size: 1rem;
        color: #2c3e50;
    }
    
    .caldav-events .caldav-description {
        margin: 0.3rem 0;
        color: #666;
        font-size: 0.9rem;
    }
    
    @media (max-width: 768px) {
        .caldav-event-card,
        .caldav-card-standalone {
            flex-direction: column;
            align-items: stretch;
        }
        
        .caldav-date-badge {
            flex-direction: row;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .caldav-card-grid {
            grid-template-columns: 1fr;
        }
        
        .caldav-event-item {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .caldav-event-date {
            min-width: auto;
        }
    }
    </style>';
}
add_action('wp_head', 'caldav_inline_styles');
?>