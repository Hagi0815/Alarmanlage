# Alarmanlage

IP-Symcon-Modul für eine Alarmanlage mit den Modi **Unscharf**, **Nacht** und **Abwesend**, Fehlalarmschutz über eine Mindestanzahl auslösender Melder, Ein- und Ausgangsverzögerung, Sabotageüberwachung sowie automatischem Scharfschalten über Anwesenheit und Nachtzeit.

Kompatibel ab IP-Symcon 8.0, getestet für 9.0.

## Installation

Im Modul-Control (Kern-Instanzen → Modules) die URL `https://github.com/Hagi0815/Alarmanlage` hinzufügen und eine Instanz **Alarmanlage** anlegen.

## Funktionsweise

### Zustände

| Wert | Zustand | Bedeutung |
| --- | --- | --- |
| 0 | Unscharf | Melder werden ignoriert, Sabotage meldet trotzdem |
| 1 | Ausgangsverzögerung | Countdown nach dem Scharfschalten, Melder werden ignoriert |
| 2 | Scharf | Melder werden ausgewertet |
| 3 | Voralarm | Mindestens ein Melder hat ausgelöst, die Mindestanzahl ist aber noch nicht erreicht |
| 4 | Eingangsverzögerung | Countdown, in dem unscharf geschaltet werden kann |
| 5 | ALARM | Alarmkette läuft |

### Fehlalarmschutz

Ein Alarm wird erst ausgelöst, wenn **innerhalb des Zeitfensters** (Standard 120 s) **mindestens N verschiedene Melder** ausgelöst haben (Standard 2). Löst nur ein Melder aus, geht die Anlage in den Voralarm und schickt optional eine Push-Nachricht. Läuft das Zeitfenster ab, ohne dass weitere Melder dazukommen, geht sie zurück auf Scharf.

Gezählt wird nur der Wechsel eines Melders von Ruhe auf aktiv. Ein Bewegungsmelder, der wiederholt `true` sendet, zählt also nur einmal.

Ausnahmen:

- **Einzeln ausreichend:** Dieser Melder löst ohne Mindestanzahl aus, z. B. eine Außentür, die nachts nie geöffnet wird.
- **Sabotage** löst bei scharfer Anlage sofort aus (abschaltbar).
- **Notalarm** über `ALM_Panic()` löst immer aus, auch bei unscharfer Anlage.

Ist in einem Modus die Mindestanzahl größer als die Zahl der Melder in diesem Modus und kein Melder „einzeln ausreichend“, zeigt die Instanz Status 201, weil dort nie ein Alarm ausgelöst werden könnte.

### Melder-Spalten

| Spalte | Bedeutung |
| --- | --- |
| Nacht | Melder wird im Modus Nacht überwacht |
| Abwesend | Melder wird im Modus Abwesend überwacht |
| Verzögert | Ist ein solcher Melder unter den auslösenden, startet statt des Alarms die Eingangsverzögerung (Türen) |
| Einzeln ausreichend | Löst ohne Mindestanzahl aus |
| Invertiert | `false`/`0` gilt als aktiv |

Als aktiv gilt: Boolean `true`, Integer/Float `> 0` (z. B. Türkontakt 1 = gekippt, 2 = offen).

Tipp: Eingangstüren als *Verzögert* **und** *Einzeln ausreichend* markieren. Dann startet das Öffnen allein die Eingangsverzögerung.

### Alarmkette

1. Zustand ALARM, Variable `Alarm` = true, Liste der ausgelösten Melder
2. Sirenen/Blitz je nach Modus (Spalten Nacht/Abwesend) für die eingestellte Dauer
3. Licht einschalten
4. Optionales Skript mit `$_IPS['ALARM_TYPE']`, `$_IPS['ALARM_TEXT']`, `$_IPS['MODE']`
5. Push über Notification Control an alle Empfänger und Ansage über ein Echo-Gerät
6. Wiederholung von Push und Ansage im eingestellten Abstand, begrenzt auf eine Anzahl

**Quittieren** (Variable `Alarm` auf false oder `ALM_Acknowledge`) stoppt Sirenen und Wiederholungen. Die Anlage bleibt im aktuellen Modus scharf. **Unscharf** beendet alles.

### Texte für Push und Ansage

Unter **Texte für Push und Ansage** lassen sich alle Nachrichten frei formulieren. Ein leeres Feld schaltet die jeweilige Nachricht ab. Die Buttons **Ansage testen** und **Push testen** senden den Alarmtext mit Beispielwerten.

| Feld | Standard |
| --- | --- |
| Alarm – Push | `{TYP} im Modus {MODUS}: {MELDER} ({ZEIT} Uhr) {WIEDERHOLUNG}` |
| Alarm – Ansage | `Achtung, {TYP}! Ausgelöst durch {MELDER}.` |
| Sabotage (unscharf) – Push | `Sabotage erkannt: {MELDER}` |
| Sabotage (unscharf) – Ansage | leer (keine Ansage) |
| Voralarm | `{MELDER} ({ANZAHL} von {MINDEST} Meldern)` |
| Scharfschalten gestartet | `{MODUS} wird in {SEKUNDEN} Sekunden scharf ({QUELLE})` |
| Scharf | `{MODUS} scharf` |
| Unscharf | `Unscharf ({QUELLE})` |

| Platzhalter | Inhalt |
| --- | --- |
| `{TYP}` | Einbruch, Sabotage oder Notalarm |
| `{MELDER}` | Ausgelöste Melder, z. B. „Flur und Wohnzimmer“ |
| `{MODUS}` | Unscharf, Nacht, Abwesend (bei Notalarm: Notalarm) |
| `{ZEIT}`, `{DATUM}` | Zeitpunkt, z. B. 22:41 und 03.11.2026 |
| `{ANZAHL}`, `{MINDEST}` | Zahl der ausgelösten Melder und Mindestanzahl |
| `{SEKUNDEN}` | Ausgangsverzögerung |
| `{QUELLE}` | Auslöser des Schaltvorgangs, z. B. manuell, Rückkehr, Nachtbeginn |
| `{WIEDERHOLUNG}` | Bei Wiederholungen „(Wiederholung n)“, sonst leer |

Beispiel-Ansage mit Standardtext: *„Achtung, Einbruch! Ausgelöst durch EG Flur und Keller Küche.“* Die Ansage wird bei jeder Wiederholung gleich gesprochen. Die Push-Nachricht bekommt bei Wiederholungen den Zähler angehängt. Warnungen beim Scharfschalten (offene oder nicht erreichbare Melder) werden immer an den Scharf-Text angehängt.

### Sabotage und Erreichbarkeit

- Sabotage-Variablen (Bool oder Integer) melden **immer** per Push. Die Variable `Sabotage` zeigt, ob gerade eine Quelle aktiv ist.
- Erreichbarkeits-Variablen (z. B. `UNREACH`): Beim Scharfschalten kommt eine Warnung, wenn ein Gerät nicht erreichbar ist. Fällt eines im scharfen Zustand aus, gibt es eine Push-Nachricht.
- Beim Scharfschalten werden außerdem offene bzw. aktive Melder gemeldet.

### Automatik

Die Variable **Automatik** schaltet die Automatik ein und aus.

- **Abwesend:** Sind alle Anwesenheitsquellen (z. B. UniFi Presence) länger als die eingestellte Zeit auf abwesend, schaltet die Anlage auf Abwesend. Bei Rückkehr schaltet sie optional unscharf, aber nur aus dem Modus Abwesend.
- **Nacht:** Zur Startzeit wird der Modus Nacht scharf geschaltet, wenn jemand anwesend ist und keine Nachtsperre aktiv ist. Zur Endzeit wird wieder unscharf geschaltet. Der Zeitraum darf über Mitternacht gehen.
- **Nachtsperren** (z. B. Partymodus, PC an) werden mit Entprellung ausgewertet. Wird eine Sperre während der Nacht aktiv, schaltet die Anlage unscharf. Fällt sie weg, wird wieder scharf geschaltet. Wer nachts von Hand unscharf schaltet, wird bis zum nächsten Nachtbeginn nicht automatisch wieder scharf geschaltet.

## Variablen

| Ident | Name | Typ |
| --- | --- | --- |
| Mode | Modus | Integer, schaltbar (ALM.Mode) |
| State | Zustand | Integer (ALM.State) |
| Alarm | Alarm | Boolean, schaltbar (false = quittieren) |
| Sabotage | Sabotage | Boolean |
| Automatic | Automatik | Boolean, schaltbar |
| Countdown | Verzögerung | Integer (s) |
| TriggeredSensors | Ausgelöste Melder | String |
| LastEvent | Letztes Ereignis | String |

## PHP-Befehle

```php
ALM_SetMode(int $InstanzID, int $Modus);       // 0 Unscharf, 1 Nacht, 2 Abwesend
ALM_Acknowledge(int $InstanzID);               // Alarm quittieren
ALM_Panic(int $InstanzID, string $Text);       // Notalarm, unabhängig vom Modus
ALM_GetOpenSensors(int $InstanzID, int $Modus); // aktive/offene Melder eines Modus
ALM_TestSpeech(int $InstanzID);                // Alarm-Ansage mit Beispielwerten
ALM_TestPush(int $InstanzID);                  // Alarm-Push mit Beispielwerten
```

Beispiel Codeschloss-Skript, gültiger Code schaltet unscharf:

```php
ALM_SetMode(12345, 0);
```

Beispiel Notschalter (Ereignis auf die STATE-Variable):

```php
if ($_IPS['VALUE']) {
    ALM_Panic(12345, IPS_GetName(IPS_GetParent($_IPS['VARIABLE'])));
}
```

## Tests

`php tests/run.php` führt die Logiktests mit einem IPSModule-Stub aus (Schwelle, Zeitfenster, Verzögerungen, Sabotage, Notalarm, Nacht- und Abwesenheitsautomatik, Neustart).
