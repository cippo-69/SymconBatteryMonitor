# BatteryMonitor (Batterieüberwachung) für IP-Symcon

Findet automatisch alle batteriebetriebenen Geräte, zeigt ihren Batteriestand als
HTML-Übersicht an und meldet Geräte, die **länger als die eingestellte Dauer
(Standard 24 h) unter dem Schwellwert (Standard 20 %)** liegen.

## Erkennung

| Quelle | Typ | Bedeutung |
|---|---|---|
| Profil `~Battery.100` | Integer/Float | Batteriestand in % (Zigbee2MQTT, Z-Wave, Hue, Gardena …) |
| Profil `~Battery` | Boolean | `true` = Batterie schwach |
| Profil `~Battery.Reversed` | Boolean | `false` = Batterie schwach |
| String-Profil mit „Battery“ (z. B. `Gardena.Battery`) | String | `LOW`, `CRITICAL`, `REPLACE_NOW` = schwach; `NO_BATTERY` wird ignoriert |
| Name (optional) | Boolean | „Batterie schwach“, „battery_low“, „LOWBAT“ … |
| Name (optional) | Integer/Float | „Batterie“, „Battery“, „Akku“ – nur mit %-Profil oder ohne Profil und Wert 0–100 |

Mehrere Batterie-Variablen eines Geräts (z. B. Z-Wave „Batterie“ + „Battery Status“)
werden zu einer Zeile zusammengefasst. Liegt die Variable in einem Unterordner wie
„Geräteinformationen“, wird der darüberliegende Gerätename verwendet.

Ausschließen: über die RegEx auf den Objektpfad (Standard schließt Hausspeicher-Werte
wie `SOC`, `kWh`, `Leistung` aus) oder über die Liste (eine Kategorie schließt alles darunter aus).

## Logik

* Unterschreitet ein Gerät den Schwellwert (oder meldet „schwach“), startet die Zeit.
  Startzeit ist die letzte Wertänderung der Variable – ein Gerät, das schon seit
  Tagen bei 5 % steht, wird also beim ersten Lauf sofort gemeldet.
* Nach Ablauf der Dauer wird **einmal** gemeldet (Status „Gemeldet“).
* Erst ab Schwellwert + Hysterese gilt das Gerät wieder als OK (optional Meldung „wieder OK“).
* Zusätzlich: Geräte ohne Aktualisierung seit X Tagen (Status „Keine Meldung“).

## Meldestruktur (welches Gerät wird wo angezeigt und gemeldet)

**Meldegruppen** – jede Gruppe hat:
* eine eigene Übersicht (`Batterieübersicht <Name>` als HTMLBox + `Batteriealarm <Name>`), wenn „Anzeigen“ aktiv ist,
* eigene Meldeziele (Visualisierung/WebFront, SMTP, Skript), wenn „Melden“ aktiv ist –
  ohne eigene Ziele werden die Standardziele verwendet,
* optional eine RegEx auf den Gerätepfad zur automatischen Zuordnung.

**Zuordnung** – Objekt (Variable, Gerät oder Kategorie) → Gruppe(n), kommagetrennt.
Eine Kategorie gilt für alles darunter, die nächstliegende Zuordnung gewinnt und hat
Vorrang vor den RegEx-Regeln. `-` heißt: nicht melden (nur in der Gesamtübersicht).

Reihenfolge: Zuordnung → RegEx der Gruppen (ein Gerät kann in mehreren Gruppen sein)
→ keine Gruppe = Standardziele. Die Gesamtübersicht `Batterieübersicht` zeigt immer alle Geräte.

Beispiel:

| Gruppe | RegEx | Ziele |
|---|---|---|
| W50 | `^Native-Geraete/.*/W50` | WebFront W50 |
| W51EG | `W51EG` | Visualisierung W51EG |
| W51OG | `W51OG` | Visualisierung W51OG + E-Mail |
| Rauchmelder | `-RM` | alle Visualisierungen |
| Garten | `GARDENA` | E-Mail |

Zuordnung: `Native-Geraete/Hue` → `W50`, einzelnes Testgerät → `-`.

## Variablen der Instanz

`Batterieübersicht` (~HTMLBox), `Batteriealarm` (~Alert), `Gemeldete Geräte`,
`Unter Schwellwert`, `Überwachte Geräte`, `Gemeldete Geräte (Liste)`.

## Benachrichtigung

Kachel-Visualisierung (`VISU_PostNotification`) oder WebFront (`WFC_PushNotification`),
E-Mail über SMTP-Instanz, eigenes Skript (`$_IPS['TITLE']`, `$_IPS['TEXT']`,
`$_IPS['DEVICES']` als JSON), immer zusätzlich im Meldungs-Log.

## Funktionen

```php
BATM_Check(int $InstanceID);          // Prüfung sofort ausführen
BATM_SendSummary(int $InstanceID);    // Zusammenfassung senden
BATM_GetDevices(int $InstanceID);     // JSON aller Geräte inkl. Status
BATM_SendTest(int $InstanceID);       // Testnachricht
BATM_ResetState(int $InstanceID);     // gemerkte Fälle zurücksetzen
```

## Installation

Ordner `BatteryMonitor` als Git-Repository bereitstellen und in der Modulverwaltung
hinzufügen, oder nach `<IPS>/modules/BatteryMonitor` kopieren und
`MC_ReloadModule` / Dienst neu starten. Danach Instanz „BatteryMonitor“ anlegen.
