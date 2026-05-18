# Migratieplan: v2.4 → v2.5

**Doelgroep:** GGW-site (huidige v2.4-installatie)
**Reden:** v2.5 verplaatst credentials uit de shortcode naar admin-instellingen om voldoen aan WordPress.org standaarden.

---

## Wat verandert er?

| Onderwerp | v2.4 | v2.5 |
|---|---|---|
| Credentials | In shortcode (`url="..." user="..." pass="..."`) | In admin onder Instellingen > CalDAV Calendar > Verbindingen |
| Shortcode-param `url` | Ja | **Verwijderd** |
| Shortcode-param `user` | Ja | **Verwijderd** |
| Shortcode-param `pass` | Ja | **Verwijderd** |
| Nieuwe param `connection` | n.v.t. | Verplicht, verwijst naar verbindingsslug |
| Andere parameters | Werken | Werken ongewijzigd |

---

## Migratiestappen

### 1. Backup huidige situatie

Noteer (of maak een screenshot van):
- De volledige inhoud van elke `[caldav_calendar ...]` shortcode op de site
- Welke pagina(s)/posts deze gebruiken

Bewaar de v2.4 plugin-file als reserve (staat al in `Backup/caldav-shortcode.php`).

### 2. Plugin updaten

Overschrijf `wp-content/plugins/modern-caldav-calendar/caldav-shortcode.php` met de v2.5 versie.

### 3. Verbinding aanmaken in admin

WordPress admin > **Instellingen** > **CalDAV Calendar** > tab **Verbindingen** > **Toevoegen**:

| Veld | Invullen met |
|---|---|
| Naam | Vrije omschrijving (bv. "GGW Hoofdagenda") |
| Slug | Technische ID, alleen letters/cijfers/streepjes (bv. `ggw-hoofd`) |
| URL | Waarde van het oude `url=` attribuut |
| Gebruikersnaam | Waarde van het oude `user=` attribuut |
| Wachtwoord | Waarde van het oude `pass=` attribuut |

Opslaan.

### 4. Shortcode(s) aanpassen

Op elke pagina/post waar de oude shortcode staat, vervangen:

**Voor:**
```
[caldav_calendar url="https://server/dav/" user="naam" pass="ww" view="old" count="10"]
```

**Na:**
```
[caldav_calendar connection="ggw-hoofd" view="old" count="10"]
```

Alle overige parameters (`view`, `count`, `limitdays`, `pastdays`, `theme`, `show_time`, `show_description`, `nomonthheaders`, `date_format`, `htmltagmonth`, `htmltagdate`) blijven werken zoals voorheen.

### 5. Testen

- Open de pagina(s) met de shortcode in een browser
- Controleer of de agenda inhoud toont
- Controleer of er geen foutmelding verschijnt (bv. "Verbinding niet gevonden")

### 6. Cache legen (indien nodig)

Bij hardnekkige weergaveproblemen: admin > CalDAV Calendar > tab Instellingen > **Cache legen**.

---

## Rollback

Als iets misgaat:

1. Zet `caldav-shortcode.php` terug naar v2.4 (gebruik `Backup/caldav-shortcode.php`)
2. Herstel de oude shortcode-inhoud op de pagina('s)
3. Optioneel: verwijder de aangemaakte verbinding uit de admin (heeft geen effect zolang v2.4 actief is, maar netter om op te ruimen)

---

## Verwachte foutmeldingen na update zonder migratie

Als de plugin geüpdatet is maar de shortcode nog v2.4-syntax gebruikt:

> "Verbinding niet gevonden of niet geconfigureerd."

Dit betekent: maak de verbinding aan in de admin en pas de shortcode aan (stappen 3 en 4 hierboven).

---

## Tijdsinschatting

5-10 minuten per site, mits credentials onder handbereik zijn.
