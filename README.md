# Modern CalDAV Calendar

WordPress-plugin die een CalDAV-agenda op je site toont via een shortcode.
Werkt met onder meer Nextcloud, Stalwart, Baïkal, SOGo en Radicale.

Inloggegevens worden in de admin-instellingen bewaard, niet in de shortcode,
zodat ze niet in post-content of database-back-ups terechtkomen.

## Wat het doet

- Vier weergaven: `old` (klassieke lijst), `modern` (grid), `list`, `card`
- Herhalende afspraken (RRULE), inclusief `BYDAY` met ordinaal (`2TU`, `-1FR`),
  `BYSETPOS`, `BYMONTH` en `BYMONTHDAY=-1`
- Uitzonderingen: geschrapte keren (`EXDATE`), losse gewijzigde keren
  (`RECURRENCE-ID`) en `STATUS:CANCELLED`
- Zowel `DTEND` als `DURATION` voor de eindtijd
- Nederlandse lokalisatie, plus optionele vertaling van titel en beschrijving
  via DeepL of Google Cloud Translation (`lang="en"` en verder)
- Meerdere verbindingen, met een **Testen**-knop die een echte CalDAV-aanroep
  doet en meldt of de URL werkt
- Waarschuwt in wp-admin zodra een agenda op de terugval draait, zodat een
  kapotte verbinding niet stilletjes een bevroren agenda blijft tonen
- Caching (60–3600 s) en rate limiting

## Installeren

Download `modern-caldav-calendar-x.y.z.zip` bij de
[nieuwste release](https://github.com/Bolt-Connect/modern-caldav-calendar/releases/latest)
en upload die via **Plugins > Nieuwe plugin > Uploaden**. Gebruik niet de
"Source code"-zip van GitHub: die pakt uit in een map met een andere naam.

Daarna komen updates vanzelf binnen onder **Dashboard > Updates** (vanaf
WordPress 5.8). De plugin kijkt hooguit twee keer per dag op GitHub;
**Opnieuw controleren** op die pagina kijkt meteen.

Tijden worden getoond in de tijdzone van de site. Kies onder **Instellingen >
Algemeen** een plaats (bijv. Amsterdam) en niet een vaste afwijking als
UTC+0, anders loopt de agenda bij zomer- en wintertijd een uur mis.

## Gebruiken

Maak eerst een verbinding aan onder **Instellingen > CalDAV Calendar**, en
verwijs vanuit de shortcode naar de slug:

```
[caldav_calendar connection="mijn-agenda" view="old" count="15"]
```

| Parameter | Standaard | Betekenis |
|---|---|---|
| `connection` | — | Slug van de verbinding (verplicht) |
| `view` | `old` | `old`, `modern`, `list` of `card` |
| `count` | `10` | Aantal afspraken |
| `limitdays` | `365` | Hoe ver vooruit |
| `pastdays` | `0` | Hoe ver terug |
| `lang` | `nl` | `nl`, `en`, `de`, `fr`, `es` |
| `show_time` | `yes` | Tijden tonen |
| `show_description` | `yes` | Beschrijving tonen |
| `nomonthheaders` | `false` | Maandkoppen verbergen |
| `translation_notice` | `yes` | Melding "automatisch vertaald" |

### De juiste agenda-URL

Vul de URL van de **agenda** in, niet die van de map met agenda's. Op dat
laatste adres antwoordt een CalDAV-server met HTTP 405 op een
`calendar-query`-aanroep, ook al meldt `OPTIONS` dat hij `REPORT` ondersteunt.

Weet je het niet zeker: vul de map-URL in en klik op **Testen**. De plugin
zoekt dan zelf de onderliggende agenda's op en toont per agenda het aantal
items. Vindt hij er precies één, dan vult hij de URL meteen correct in.

## Ontwikkeling

Eén bestand, `modern-caldav-calendar.php`, zonder build-stap of dependencies.

- `readme.txt` is het WordPress.org-formaat en bevat de volledige changelog
- Versienummer staat op drie plekken: de plugin-header, `CALDAV_PLUGIN_VERSION` en `Stable tag` in `readme.txt`
### Uitbrengen

1. Versie ophogen in de plugin-header, `CALDAV_PLUGIN_VERSION` en `Stable tag`
   in `readme.txt`; changelog bijwerken
2. Committen, taggen en de zip bouwen vanaf de tag:

   ```bash
   git tag v2.7.0
   git archive --format=zip --prefix=modern-caldav-calendar/ \
     -o modern-caldav-calendar-2.7.0.zip v2.7.0
   git push origin main v2.7.0
   gh release create v2.7.0 modern-caldav-calendar-2.7.0.zip --title "2.7.0" --notes "..."
   ```

De updater leest de versie uit de tagnaam (`v` ervoor mag) en installeert
de zip die bij de release hangt. Die zip moet `modern-caldav-calendar*.zip`
heten. Drafts en prereleases worden genegeerd.

De `--prefix` is niet optioneel: WordPress verwacht de bestanden in een map.
Gebruik geen `Compress-Archive` uit Windows PowerShell 5.1 — die schrijft
backslashes als padscheiding, waarna WordPress meldt dat het pluginbestand
niet bestaat.

## Licentie

GPL v2 of later.
