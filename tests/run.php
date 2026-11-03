<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');
require __DIR__ . '/stub.php';
require __DIR__ . '/../Alarmanlage/module.php';

class T extends Alarmanlage
{
    public static int $now = 0;
    protected function Now(): int { return self::$now; }
    public function v($i) { return $this->values[$i]; }
}

$fail = 0;
function check(string $name, bool $ok, string $info = ''): void
{
    global $fail;
    echo ($ok ? "  OK   " : "  FAIL ") . $name . ($ok || $info === '' ? '' : "  -> $info") . "\n";
    if (!$ok) $fail++;
}

// Beispiel-IDs
const HAUSTUER = 30507, EG_FLUR = 30777, EG_WOHN = 13449, K_AUSSEN = 30503, K_KUECHE = 42278, K_WERK = 36974;
const SAB_SIRENE = 52347, SAB_KEYPAD = 15763, UNREACH_KELLER = 43114;
const SIRENE = 11988, BLITZ = 57261, LICHT = 53436;
const PRES_1 = 10408, PC = 13311, PARTY = 45119;

function make(array $over = []): T
{
    Sim::reset();
    foreach ([HAUSTUER => 0, EG_FLUR => false, EG_WOHN => false, K_AUSSEN => 0, K_KUECHE => false, K_WERK => false,
              SAB_SIRENE => 0, SAB_KEYPAD => false, UNREACH_KELLER => false, SIRENE => false, BLITZ => false, LICHT => false,
              PRES_1 => true, PC => false, PARTY => false] as $id => $v) {
        Sim::$vars[$id] = $v;
    }
    $m = new T();
    $m->props = array_merge([
        'Sensors' => json_encode([
            ['VariableID' => HAUSTUER, 'Name' => 'Haustür', 'Night' => false, 'Away' => true, 'Delayed' => true, 'Single' => false, 'Invert' => false],
            ['VariableID' => EG_FLUR, 'Name' => 'EG Flur', 'Night' => false, 'Away' => true, 'Delayed' => false, 'Single' => false, 'Invert' => false],
            ['VariableID' => EG_WOHN, 'Name' => 'EG Wohnzimmer', 'Night' => false, 'Away' => true, 'Delayed' => false, 'Single' => false, 'Invert' => false],
            ['VariableID' => K_AUSSEN, 'Name' => 'Keller Außentür', 'Night' => true, 'Away' => true, 'Delayed' => false, 'Single' => true, 'Invert' => false],
            ['VariableID' => K_KUECHE, 'Name' => 'Keller Küche', 'Night' => true, 'Away' => true, 'Delayed' => false, 'Single' => false, 'Invert' => false],
            ['VariableID' => K_WERK, 'Name' => 'Keller Werkstatt', 'Night' => true, 'Away' => true, 'Delayed' => false, 'Single' => false, 'Invert' => false],
        ]),
        'SabotageSources' => json_encode([['VariableID' => SAB_SIRENE, 'Name' => 'Sirene'], ['VariableID' => SAB_KEYPAD, 'Name' => 'Codeschloss']]),
        'UnreachSources' => json_encode([['VariableID' => UNREACH_KELLER, 'Name' => 'Notschalter Keller']]),
        'PresenceSources' => json_encode([['VariableID' => PRES_1, 'Name' => 'Person 1']]),
        'NightBlockers' => json_encode([['VariableID' => PC, 'Name' => 'PC'], ['VariableID' => PARTY, 'Name' => 'Party']]),
        'PushInstance' => 47259,
        'PushTargets' => json_encode([['TargetID' => 57739]]),
        'AlexaInstance' => 25015,
        'Outputs' => json_encode([
            ['VariableID' => SIRENE, 'Name' => 'Sirene', 'Night' => false, 'Away' => true],
            ['VariableID' => BLITZ, 'Name' => 'Blitz', 'Night' => true, 'Away' => true],
        ]),
        'Lights' => json_encode([['VariableID' => LICHT]]),
    ], $over);
    Sim::$mod = $m;
    $m->Create();
    $m->ApplyChanges();
    return $m;
}

T::$now = mktime(14, 0, 0, 11, 3, 2026);

echo "1) Scharfschalten Abwesend mit Ausgangsverzögerung\n";
$m = make();
$m->RequestAction('Mode', 2);
check('Zustand Ausgangsverzögerung', $m->v('State') === 1);
check('ExitTimer 60 s', $m->timers['ExitTimer'] === 60000);
Sim::set(EG_FLUR, true);
check('Melder während Ausgangsverzögerung ignoriert', $m->v('State') === 1);
Sim::set(EG_FLUR, false);
T::$now += 60; $m->RequestAction('ExitTimer', 0);
check('Zustand Scharf', $m->v('State') === 2);
check('Push "scharf"', (bool) preg_grep('/Abwesend scharf/', Sim::$push), implode(' | ', Sim::$push));

echo "2) Ein Melder = nur Voralarm\n";
Sim::$push = [];
Sim::set(EG_FLUR, true);
check('Zustand Voralarm', $m->v('State') === 3);
check('Kein Alarm', $m->v('Alarm') === false);
check('Push Voralarm 1 von 2', in_array('Voralarm: EG Flur (1 von 2 Meldern)', Sim::$push, true), implode(' | ', Sim::$push));
check('Keine Sirene', !in_array([SIRENE, true], Sim::$actions, true));
check('PrealarmTimer 120 s', $m->timers['PrealarmTimer'] === 120000, (string) $m->timers['PrealarmTimer']);
Sim::set(EG_FLUR, true);
check('Wiederholtes true ohne Änderung zählt nicht', $m->v('State') === 3 && count(json_decode($m->attrs['TriggerLog'], true)) === 1);

echo "3) Zeitfenster läuft ab -> zurück auf Scharf\n";
T::$now += 121; $m->RequestAction('PrealarmTimer', 0);
check('Zustand Scharf', $m->v('State') === 2);
Sim::set(EG_FLUR, false);
Sim::set(EG_WOHN, true);
check('Zweiter Melder nach Fensterablauf = wieder nur Voralarm', $m->v('State') === 3);

echo "4) Zwei Melder im Fenster -> Alarm\n";
T::$now += 30;
Sim::set(K_KUECHE, true);
check('Zustand ALARM', $m->v('State') === 5);
check('Alarm-Variable true', $m->v('Alarm') === true);
check('Sirene an (Abwesend)', in_array([SIRENE, true], Sim::$actions, true));
check('Blitz an', in_array([BLITZ, true], Sim::$actions, true));
check('Licht an', in_array([LICHT, true], Sim::$actions, true));
check('Alexa-Ansage', count(Sim::$tts) === 1);
check('Ansage-Text (Standard)', (Sim::$tts[0] ?? '') === 'Achtung, Einbruch! Ausgelöst durch EG Wohnzimmer und Keller Küche.', Sim::$tts[0] ?? '');
check('Push-Text (Standard)', in_array('ALARM: Einbruch im Modus Abwesend: EG Wohnzimmer und Keller Küche (14:03 Uhr)', Sim::$push, true), implode(' | ', Sim::$push));
check('Ausgelöste Melder enthalten beide', str_contains($m->v('TriggeredSensors'), 'EG Wohnzimmer') && str_contains($m->v('TriggeredSensors'), 'Keller Küche'), $m->v('TriggeredSensors'));
check('OutputTimer 180 s', $m->timers['OutputTimer'] === 180000);

echo "5) Wiederholung begrenzt\n";
for ($i = 0; $i < 12; $i++) { $m->RequestAction('RepeatTimer', 0); }
check('10 Wiederholungen + Erstmeldung', count(Sim::$tts) === 11, (string) count(Sim::$tts));
check('RepeatTimer gestoppt', $m->timers['RepeatTimer'] === 0);
check('Push-Wiederholung mit Zähler', (bool) preg_grep('/\(Wiederholung 10\)$/', Sim::$push), end(Sim::$push));
check('Ansage-Wiederholung ohne Zähler', end(Sim::$tts) === Sim::$tts[0]);

echo "6) Quittieren\n";
Sim::$actions = [];
$m->RequestAction('Alarm', false);
check('Zustand Scharf, Modus Abwesend', $m->v('State') === 2 && $m->v('Mode') === 2);
check('Sirene aus', in_array([SIRENE, false], Sim::$actions, true));

echo "7) Eingangsverzögerung über Haustür\n";
Sim::set(EG_WOHN, false); Sim::set(K_KUECHE, false);
Sim::set(HAUSTUER, 2);
check('Haustür allein = Voralarm', $m->v('State') === 3);
Sim::set(EG_FLUR, false); Sim::set(EG_FLUR, true);
check('Haustür + Flur = Eingangsverzögerung', $m->v('State') === 4);
check('EntryTimer 30 s', $m->timers['EntryTimer'] === 30000);
$m->RequestAction('Mode', 0);
check('Unscharf innerhalb Verzögerung -> kein Alarm', $m->v('State') === 0 && $m->v('Alarm') === false);
check('EntryTimer gestoppt', $m->timers['EntryTimer'] === 0);
$m->RequestAction('Mode', 2); $m->RequestAction('ExitTimer', 0);
Sim::set(HAUSTUER, 0); Sim::set(HAUSTUER, 2);
Sim::set(EG_FLUR, false); Sim::set(EG_FLUR, true);
$m->RequestAction('EntryTimer', 0);
check('Verzögerung abgelaufen -> ALARM', $m->v('State') === 5);
$m->RequestAction('Mode', 0);

echo "8) Nachtmodus: nur Nachtmelder, Blitz ohne Sirene\n";
Sim::set(HAUSTUER, 0); Sim::set(EG_FLUR, false);
Sim::$actions = [];
$m->RequestAction('Mode', 1); $m->RequestAction('ExitTimer', 0);
Sim::set(EG_FLUR, true); Sim::set(EG_WOHN, true);
check('EG-Melder im Nachtmodus ignoriert', $m->v('State') === 2);
Sim::set(K_KUECHE, false); Sim::set(K_KUECHE, true);
Sim::set(K_WERK, true);
check('Zwei Kellermelder -> ALARM', $m->v('State') === 5);
check('Blitz an', in_array([BLITZ, true], Sim::$actions, true));
check('Sirene NICHT an', !in_array([SIRENE, true], Sim::$actions, true));
$m->RequestAction('Mode', 0);

echo "9) Einzeln ausreichend\n";
$m->RequestAction('Mode', 1); $m->RequestAction('ExitTimer', 0);
Sim::set(K_AUSSEN, 1);
check('Keller-Außentür allein -> ALARM', $m->v('State') === 5);
$m->RequestAction('Mode', 0);
Sim::set(K_AUSSEN, 0);

echo "10) Sabotage\n";
Sim::$push = [];
Sim::set(SAB_KEYPAD, true);
check('Unscharf: Sabotage-Variable true', $m->v('Sabotage') === true);
check('Unscharf: Push Sabotage', (bool) preg_grep('/Sabotage erkannt: Codeschloss/', Sim::$push), implode(' | ', Sim::$push));
check('Unscharf: kein Alarm', $m->v('State') === 0);
Sim::set(SAB_KEYPAD, false);
check('Sabotage-Variable wieder false', $m->v('Sabotage') === false);
$m->RequestAction('Mode', 2); $m->RequestAction('ExitTimer', 0);
Sim::set(SAB_SIRENE, 1);
check('Scharf: Sabotage (Integer) -> ALARM', $m->v('State') === 5 && str_contains($m->attrs['AlarmText'], 'SABOTAGE'));
$m->RequestAction('Mode', 0);
Sim::set(SAB_SIRENE, 0);

echo "11) Notalarm bei unscharfer Anlage\n";
Sim::$actions = [];
$m->Panic('Notschalter Bett');
check('ALARM', $m->v('State') === 5);
check('Alle Ausgänge an', in_array([SIRENE, true], Sim::$actions, true) && in_array([BLITZ, true], Sim::$actions, true));
$m->Acknowledge();
check('Quittiert -> Unscharf', $m->v('State') === 0);

echo "12) Nicht erreichbar beim Scharfschalten\n";
Sim::$push = [];
Sim::$vars[UNREACH_KELLER] = true;
$m->RequestAction('Mode', 2); $m->RequestAction('ExitTimer', 0);
check('Warnung im Push', (bool) preg_grep('/nicht erreichbar: Notschalter Keller/', Sim::$push), implode(' | ', Sim::$push));
$m->RequestAction('Mode', 0);
Sim::$vars[UNREACH_KELLER] = false;

echo "13) Nachtautomatik\n";
$m = make(['NightAuto' => true, 'BlockerDelay' => 300]);
$m->RequestAction('Automatic', true);
T::$now = mktime(21, 30, 0, 11, 3, 2026);
$m->ApplyChanges();
check('NightTimer auf 22:00', $m->timers['NightTimer'] === (30 * 60 + 5) * 1000 && $m->attrs['NightNext'] === 'start');
Sim::$vars[PC] = true;
T::$now = mktime(22, 0, 5, 11, 3, 2026);
$m->RequestAction('NightTimer', 0);
check('PC an -> nicht scharf', $m->v('Mode') === 0);
check('Nächster Termin 05:00', $m->attrs['NightNext'] === 'end');
T::$now = mktime(0, 35, 0, 11, 4, 2026);
Sim::set(PC, false);
check('Entprellung läuft', $m->timers['BlockerTimer'] === 300000);
T::$now += 300;
$m->RequestAction('BlockerTimer', 0);
check('PC nach Mitternacht aus -> Nacht scharf (alter Bug)', $m->v('Mode') === 1);
Sim::set(PARTY, true);
$m->RequestAction('BlockerTimer', 0);
check('Partymodus -> unscharf', $m->v('Mode') === 0);
Sim::set(PARTY, false);
$m->RequestAction('BlockerTimer', 0);
check('Partymodus aus -> wieder Nacht', $m->v('Mode') === 1);
$m->RequestAction('Mode', 0);
$m->RequestAction('BlockerTimer', 0);
check('Manuell unscharf -> kein automatisches Wiederscharfschalten', $m->v('Mode') === 0);
$m->RequestAction('Mode', 1); $m->RequestAction('ExitTimer', 0);
T::$now = mktime(5, 0, 5, 11, 4, 2026);
$m->RequestAction('NightTimer', 0);
check('05:00 -> unscharf', $m->v('Mode') === 0);
check('Nächster Termin 22:00', $m->attrs['NightNext'] === 'start');

echo "14) Abwesenheitsautomatik\n";
$m = make(['AutoAway' => true, 'AwayDelay' => 10]);
$m->RequestAction('Automatic', true);
Sim::set(PRES_1, false);
check('AwayTimer 10 min', $m->timers['AwayTimer'] === 600000);
$m->RequestAction('AwayTimer', 0);
check('Abwesend scharf (mit Ausgangsverzögerung)', $m->v('Mode') === 2 && $m->v('State') === 1);
$m->RequestAction('ExitTimer', 0);
Sim::set(PRES_1, true);
check('Rückkehr -> unscharf', $m->v('Mode') === 0 && $m->v('State') === 0);
Sim::set(PRES_1, false);
Sim::set(PRES_1, true);
check('Kurz weg und zurück -> AwayTimer gestoppt', $m->timers['AwayTimer'] === 0);
$m->RequestAction('Automatic', false);
Sim::set(PRES_1, false);
check('Automatik aus -> kein AwayTimer', $m->timers['AwayTimer'] === 0);

echo "15) Konfigurationsprüfung\n";
$m = make(['RequiredSensors' => 2]);
check('Status 102 bei gültiger Konfiguration', $m->status === 102);
$m = make(['RequiredSensors' => 3, 'Sensors' => json_encode([
    ['VariableID' => K_KUECHE, 'Night' => true, 'Away' => true],
    ['VariableID' => K_WERK, 'Night' => true, 'Away' => true],
])]);
check('Status 201: 2 Melder, Mindestanzahl 3', $m->status === 201);

echo "16) Neustart während Eingangsverzögerung\n";
$m = make();
$m->RequestAction('Mode', 2); $m->RequestAction('ExitTimer', 0);
Sim::set(HAUSTUER, 2); Sim::set(EG_FLUR, true);
check('Eingangsverzögerung', $m->v('State') === 4);
$m->ApplyChanges();
check('Nach ApplyChanges: Scharf', $m->v('State') === 2);

echo "17) Eigene Texte\n";
$m = make([
    'TextAlarmSpeech' => 'Einbrecher im Haus! {MELDER} hat ausgelöst um {ZEIT} Uhr.',
    'TextAlarmPush' => '',
    'TextSabotageSpeech' => 'Sabotage an {MELDER}',
    'TextArmedPush' => 'Haus gesichert ({MODUS})',
]);
$m->RequestAction('Mode', 2); $m->RequestAction('ExitTimer', 0);
check('Eigener Scharf-Text', in_array('Alarmanlage: Haus gesichert (Abwesend)', Sim::$push, true), implode(' | ', Sim::$push));
Sim::$push = [];
Sim::set(EG_FLUR, true); Sim::set(EG_WOHN, true);
check('Eigene Ansage mit Platzhaltern', (Sim::$tts[0] ?? '') === 'Einbrecher im Haus! EG Flur und EG Wohnzimmer hat ausgelöst um ' . date('H:i', T::$now) . ' Uhr.', Sim::$tts[0] ?? '');
check('Leerer Push-Text -> kein Alarm-Push', !preg_grep('/^ALARM/', Sim::$push), implode(' | ', Sim::$push));
$m->RequestAction('Mode', 0);
Sim::$tts = [];
Sim::set(SAB_KEYPAD, true);
check('Sabotage-Ansage bei unscharf', Sim::$tts === ['Sabotage an Codeschloss'], implode(' | ', Sim::$tts));
check('Test-Ansage liefert Text', str_starts_with($m->TestSpeech(), 'Einbrecher im Haus! '));
Sim::$push = [];
$m->Panic('Notschalter Bett');
check('Notalarm-Ansage', end(Sim::$tts) === 'Einbrecher im Haus! Notschalter Bett hat ausgelöst um ' . date('H:i', T::$now) . ' Uhr.', end(Sim::$tts));

echo "\n" . ($fail === 0 ? 'ALLE TESTS BESTANDEN' : "$fail TEST(S) FEHLGESCHLAGEN") . "\n";
exit($fail === 0 ? 0 : 1);
