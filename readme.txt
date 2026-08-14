=== Modern CalDAV Calendar ===
Contributors: boltconnect
Tags: caldav, calendar, agenda, ical, events
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.4
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
* Meertaligheid via `lang`-parameter: datums/weekdagen automatisch vertaald, titel/beschrijving via DeepL
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

= 2.6.4 =
* Nieuw: knop "Testen" per verbinding — doet een echte CalDAV-aanroep en meldt direct of de URL werkt, met HTTP-code, aantal opgehaalde items, responstijd en de eerstvolgende afspraak
* Nieuw: statuskolom in het verbindingenoverzicht (OK / Fout, met de foutmelding en hoe oud de getoonde agenda is)
* Nieuw: waarschuwing in wp-admin zodra een kalender op de terugval draait — voorheen bleef een kapotte verbinding volledig onzichtbaar omdat bezoekers gewoon de laatst bekende agenda zagen
* Een geslaagde test leegt de cache, zodat de site meteen weer verse gegevens toont
* Fix: iCalendar-regels die met een kale LF gevouwen zijn worden nu ook correct ontvouwen (voorheen alleen CRLF)
* Opruiming: het verwijderen van een verbinding laat geen verweesde terugval-/statusopties meer achter in wp_options
* Intern: de CalDAV-aanroep zit in één gedeelde functie die de shortcode en de testknop allebei gebruiken
* Header bijgewerkt naar "Tested up to: 7.0"

= 2.6.3 =
* Stabiliteit: geen witte pagina's meer — hele shortcode in een try/catch-vangnet, kortere timeouts (CalDAV 15s, DeepL 8s) zodat een trage server PHP niet laat crashen
* Terugval op "laatst bekende goede agenda" bij een ophaal-/serverfout, zodat de kalender nooit leeg/wit wordt (per verbinding bewaard)
* Info-box verschijnt nu ook bij translation_notice="no" wanneer de vertaling (deels) mislukt en de inhoud in het Nederlands valt, zodat de bezoeker de terugval begrijpt
* Lukt de vertaling volledig, dan blijft translation_notice="no" box-loos zoals bedoeld

= 2.6.2 =
* Vertaal-melding omgezet naar een oranje waarschuwingsbox met icoon, inline gestyled (rendert altijd, ook bij gecachete CSS)
* Nieuwe parameter `translation_notice` (yes/no) om de box per shortcode aan/uit te zetten — handig om 'm te verbergen bij een count=1 widget

= 2.6.1 =
* Fix: DeepL "too many requests" (429) opgelost — vertalingen worden nu gebatcht (alle titels in 1 verzoek, alle beschrijvingen in 1 verzoek) i.p.v. per event
* Dubbele teksten (bijv. terugkerende beschrijvingen) worden maar één keer vertaald
* Cooldown/backoff bij rate-limit of quota-fout: geen herhaald bestoken van DeepL meer
* Elke unieke tekst wordt precies één keer vertaald en 30 dagen gecachet; alleen nieuwe/gewijzigde teksten triggeren een vertaling
* Nieuw: kleine info-box boven de kalender bij een andere taal dan NL ("automatisch vertaald"), die meekleurt met het gekozen thema (default/blue/dark)
* Op een vertaalde pagina worden geen (Nederlandse) foutmeldingen meer aan bezoekers getoond — stille terugval, fouten gaan alleen naar het debug-log

= 2.6 =
* Meertaligheid: nieuwe `lang`-parameter (nl, en, de, fr, es) op de shortcode
* Datums en weekdagen worden taalbewust weergegeven (geen API nodig)
* Titel en beschrijving optioneel automatisch vertaald via DeepL, met 30-daagse caching
* DeepL API-key instelbaar onder Instellingen (valt zacht terug op Nederlands zonder key)
* "Geen evenementen"-melding taalbewust

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

= 2.6.4 =
Aanrader na 2.6.3: die versie viel bij een storing stil terug op de laatst bekende agenda, waardoor een kapotte verbinding onopgemerkt bleef. 2.6.4 waarschuwt de beheerder en voegt een testknop per verbinding toe.

= 2.6 =
Nieuwe `lang`-parameter voor meertalige weergave. Vul optioneel een DeepL API-key in onder Instellingen om titel en beschrijving automatisch te laten vertalen.

= 2.5 =
Belangrijke wijziging: credentials moeten nu in admin-instellingen worden ingevoerd in plaats van in de shortcode. Zie de upgrade-instructies in de plugin documentatie.
