# GameAP-Modul für WHMCS

Stellt Gameserver im Control Panel [GameAP](https://gameap.com) aus WHMCS
heraus bereit. Eine bezahlte Bestellung legt den Server an, eine unbezahlte
sperrt den Zugriff darauf, eine stornierte entfernt ihn – ohne dass ein
Administrator das Panel anfassen muss.

English version: [README.md](README.md) · Русская версия: [README_RU.md](README_RU.md) · Versión en español: [README_ES.md](README_ES.md)

## Was das Modul tut

| WHMCS-Ereignis | Was in GameAP passiert |
|-------------------------------|----------------------------------------------------------------|
| Anlegen (Create) | Findet das Panel-Konto des Kunden oder legt es an, wählt einen Node, reserviert einen freien Portblock, legt den Server an, stellt die Installation in die Warteschlange, ordnet den Server dem Konto zu und erteilt dem Kunden die Rechte darauf |
| Sperren (Suspend) | Stoppt den Server und blockiert ihn, sodass der Kunde die Kontrolle darüber verliert |
| Entsperren (Unsuspend) | Hebt die Blockierung auf und startet den Server, falls so konfiguriert, wieder |
| Kündigen (Terminate) | Löst den Server vom Konto und entfernt ihn samt Dateien (oder deaktiviert ihn nur, falls so konfiguriert) |
| Paketwechsel (Change package) | Übernimmt neue Limits und Mod-Variablen. Das Spiel wird nie geändert |
| Kundenbereich | Serverstatus, Verbindungsadresse und Anmeldung im Panel mit einem Klick |

Ein täglicher Cron-Abgleich korrigiert Abweichungen nur in eine Richtung –
WHMCS wird gelesen, das Panel wird korrigiert –, sodass ein vorübergehender
Abrechnungszustand nie einen Server zerstören kann. Er hebt nur Blockierungen
auf, die das Modul selbst gesetzt hat; ein Server, den ein Administrator im
Panel blockiert hat, bleibt blockiert.

Passwörter von Panel-Konten werden nicht aus WHMCS heraus verwaltet: Das Panel
erlaubt einem API-Token nicht, das Passwort eines bestehenden Kontos zu ändern.
Der Kunde ändert es im Profil des Panels oder nutzt dessen
Passwort-Zurücksetzung.

## Voraussetzungen

- WHMCS 8.9 oder neuer auf PHP 8.1 oder neuer
- GameAP **4.5.0 oder neuer** – die Token-Berechtigungen für den Zugriff auf
  Benutzer, Nodes und Spiele sowie die Single-Sign-on-Endpunkte kamen mit 4.5.0

## Installation

1. Laden Sie das Release-Archiv herunter und entpacken Sie es über das
   WHMCS-Stammverzeichnis, sodass die Dateien in `modules/servers/gameap/`
   landen.
2. Erstellen Sie im Panel, angemeldet als **Administrator**, ein persönliches
   Zugriffstoken (Profil → API-Tokens) mit den in
   [docs/INSTALL.md](docs/INSTALL.md) aufgeführten Berechtigungen. Das Token
   muss einem Administrator gehören: Das Panel weist Provisionierungsaufrufe
   anderer Konten ab.
3. Öffnen Sie in WHMCS **System Settings → Products/Services → Servers** und
   fügen Sie einen Server hinzu:
   - **Hostname** – der Hostname des Panels
   - **Password** – das Token (WHMCS speichert dieses Feld verschlüsselt)
   - **Username / Access Hash** – leer lassen
   - **Secure** – an, außer das Panel läuft über reines HTTP
   - **Type** – GameAP
4. Klicken Sie auf **Test Connection**. Dabei wird jede Berechtigung geprüft,
   die das Modul braucht, und jede fehlende wird benannt.
5. Legen Sie ein Produkt vom Typ GameAP an und füllen Sie die
   Moduleinstellungen aus.

Ausführliche Anleitung: [docs/INSTALL.md](docs/INSTALL.md).

## Produkteinstellungen

Die wichtigsten, benannt wie in WHMCS. Slots, RAM, CPU, Festplatte, der
Spiel-Mod und der Servername lassen sich je Dienst über eine konfigurierbare
Option (Configurable Option) oder ein benutzerdefiniertes Feld (Custom Field)
von WHMCS mit demselben Namen überschreiben; die übrigen Einstellungen werden
nur aus dem Produkt gelesen.

| Einstellung | Bedeutung |
|--------------------|-------------------------------------------------------------|
| Game code | Spielcode des Panels, zum Beispiel `cs2` |
| Game mod | Name des Mods. Leer nimmt den (alphabetisch) ersten Mod des Spiels |
| Nodes | Kommagetrennte Node-Namen. Leer bedeutet: jeder aktivierte Node |
| Slots, RAM, CPU | Ressourcen. RAM in MB, CPU in Prozent eines Kerns (100 = ein Kern); das Modul rechnet in die Einheiten des Panels um. Slots landen in der Mod-Variable, die Sie unten angeben |
| Port range | Bereich, aus dem Ports vergeben werden, zum Beispiel `27000-28000` |
| Mod variables | `key=value` je Zeile. `{slots}` wird ersetzt |
| Client permissions | Ein Recht je Zeile. Leer erteilt den vollständigen Server-Rechtesatz des Panels |
| Install on create | Ob die Spielinstallation sofort in die Warteschlange gestellt wird |
| On suspend | Stoppen und blockieren, nur blockieren oder nur stoppen |
| On terminate | Server samt Dateien löschen oder nur deaktivieren |

## Sicherheit

Das Modul hält genau ein Geheimnis: das Panel-Token im Feld Password des
Servers, das WHMCS verschlüsselt ablegt. Es gelangt nie ins Modulprotokoll –
jede Anfrage läuft über eine einzige Logging-Stelle, die es maskiert – und
`$params` wird nie protokolliert, weil es sowohl das Token als auch die
persönlichen Daten des Kunden enthält.

Das Token sollte auf die Berechtigungen aus
[docs/INSTALL.md](docs/INSTALL.md) beschränkt sein und auf nichts weiter. Das
Panel weigert sich, über ein Token ein Anmeldeticket für einen anderen
Administrator auszustellen, ein Passwort zu ändern oder Administratorkonten
anzufassen, sodass ein geleaktes Token nicht zum Panel-Zugang wird. Siehe
[docs/SECURITY.md](docs/SECURITY.md).

## Entwicklung

```bash
composer install
composer test    # phpunit
composer lint    # PSR-12
```

Die Provisionierungslogik liegt in `modules/servers/gameap/lib/` und ist reines
PHP ohne WHMCS-Abhängigkeit, daher wird sie von gewöhnlichen Unit-Tests
abgedeckt. Die WHMCS-Einstiegspunkte in `gameap.php` übersetzen `$params`
lediglich in Aufrufe darauf.

Das Audit, das das aktuelle Release geprägt hat, samt jedem Panel- und
WHMCS-Verhalten, gegen das geprüft wurde, steht in
[docs/AUDIT.md](docs/AUDIT.md).

## Lizenz

MIT
