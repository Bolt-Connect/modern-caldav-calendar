=== Modern CalDAV Calendar ===
Contributors: boltconnect
Tags: caldav, calendar, agenda, ical, events
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Toon een CalDAV-agenda op je WordPress-site met meerdere weergaven, recurring events en Nederlandse lokalisatie.

== Description ==

Modern CalDAV Calendar haalt evenementen op uit een CalDAV-server (zoals Nextcloud, Baïkal, iCloud, SOGo, Radicale) en toont deze op je site via een shortcode. Credentials worden veilig opgeslagen in admin-instellingen, niet in shortcodes.

= Features =

* Vier weergaven: klassiek (lijst), modern (grid), list (gestructureerd), card (kaarten)
* Drie kleurthema's: default, dark, blue
* Ondersteuning voor recurring events (RRULE) — dagelijks, wekelijks, maandelijks, jaarlijks
* RRULE-filters: BYDAY, BYMONTHDAY, INTERVAL, COUNT, UNTIL
* Nederlandse lokalisatie: weekdagen en maanden in het Nederlands
* Configureerbare caching (60-3600 seconden)
* Meerdere CalDAV-verbindingen beheerbaar via admin
* Rate limiting tegen misbruik
* Volledig responsive design

= Veiligheid =

* Credentials nooit in shortcodes of post-content
* SSL-certificaat verificatie verplicht
* Nonce-verificatie op alle admin-acties
* Capability checks op admin-functies
* Input sanitization volgens WordPress standaarden
* Schone deinstallatie (verwijdert alle data en cache)

= Gebruik =

1. Maak een verbinding aan onder Instellingen > CalDAV Calendar > Verbindingen
2. Gebruik de slug van die verbinding in je shortcode: `[caldav_calendar connection="mijn-agenda"]`

== Installation ==

1. Upload de plugin-map naar `/wp-content/plugins/` of installeer via het WordPress-plugin scherm
2. Activeer de plugin via het 'Plugins'-menu
3. Ga naar Instellingen > CalDAV Calendar > Verbindingen en voeg een nieuwe verbinding toe
4. Vul de CalDAV-server URL, gebruikersnaam en wachtwoord in
5. Plaats de shortcode `[caldav_calendar connection="slug-van-je-verbinding"]` op een pagina of bericht

== Frequently Asked Questions ==

= Welke CalDAV-servers worden ondersteund? =

Alle standaard CalDAV-servers, waaronder Nextcloud, Baïkal, iCloud, SOGo, Radicale, DAViCal en Synology Calendar.

= Hoe maak ik meerdere agenda's zichtbaar? =

Maak voor elke agenda een aparte verbinding aan met een unieke slug. Gebruik vervolgens meerdere shortcodes, elk met de juiste `connection="slug"` parameter.

= Worden recurring events ondersteund? =

Ja, de plugin ondersteunt RRULE met FREQ (DAILY, WEEKLY, MONTHLY, YEARLY), INTERVAL, COUNT, UNTIL, BYDAY en BYMONTHDAY filters.

= Hoe wijzig ik de weergavestijl? =

Gebruik de `view` parameter: `[caldav_calendar connection="..." view="modern"]`. Beschikbare waardes: old, modern, list, card.

= Worden wachtwoorden veilig opgeslagen? =

Credentials worden opgeslagen in `wp_options` (niet in post-content) en alleen server-side gebruikt voor cURL-verzoeken. Ze worden nooit naar de browser gestuurd.

= Hoe leeg ik de cache? =

Ga naar Instellingen > CalDAV Calendar > Instellingen > Cache legen.

== Screenshots ==

1. Verbindingen-tab in de admin
2. Voorbeeld van de moderne weergave op een pagina
3. Klassieke (Nederlandse) lijstweergave
4. Card-weergave met dark theme

== Changelog ==

= 2.5 =
* Architectuurwijziging: credentials verplaatst van shortcode naar admin-instellingen
* Nieuwe Verbindingen-tab voor het beheer van meerdere CalDAV-koppelingen
* Tabbed admin-interface (Verbindingen / Instellingen / Hulp)
* Shortcode-parameters `url`, `user`, `pass` verwijderd; nieuwe parameter `connection`
* `register_uninstall_hook` toegevoegd voor schone deinstallatie
* Function prefixing voor WordPress.org compliance
* `wp_kses_post()` toegepast op event descriptions
* Plugin header uitgebreid (Requires PHP, License, Text Domain, etc.)
* Kapotte CSS-enqueue verwijderd

= 2.4 =
* Verbeterde RRULE-ondersteuning voor recurring events
* BYDAY en BYMONTHDAY filters
* COUNT en UNTIL beperkingen
* INTERVAL-ondersteuning (bijv. elke 2 weken)
* URL fix: `%40` wordt vervangen naar `@` voor username-in-URL credentials

= 2.2 =
* Activation/deactivation hooks
* PHP-versie- en cURL-check bij activatie
* Nonce-verificatie en capability checks op admin-formulieren

= 2.1 =
* Nederlandse weekdagen en maandheaders
* `view`-parameter geïntroduceerd

= 2.0 =
* Initiële versie met ABSPATH-check en basisbeveiliging

== Upgrade Notice ==

= 2.5 =
Belangrijke wijziging: credentials moeten nu in admin-instellingen worden ingevoerd in plaats van in de shortcode. Zie de upgrade-instructies in de plugin documentatie.
