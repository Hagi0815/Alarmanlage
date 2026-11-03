<?php

declare(strict_types=1);

/**
 * Alarmanlage für IP-Symcon
 *
 * Modi:     0 = Unscharf, 1 = Nacht (Teilbereich), 2 = Abwesend (alles),
 *           3 = Außenhaut (nur Türen/Fenster, innen frei bewegen)
 * Zustände: 0 = Unscharf, 1 = Ausgangsverzögerung, 2 = Scharf, 3 = Voralarm,
 *           4 = Eingangsverzögerung, 5 = ALARM
 *
 * Fehlalarmschutz: Ein Alarm wird erst ausgelöst, wenn innerhalb des
 * Zeitfensters mindestens "RequiredSensors" verschiedene Melder ausgelöst
 * haben. Einzelne Melder können als "einzeln ausreichend" markiert werden.
 */
class Alarmanlage extends IPSModule
{
    public const MODE_OFF = 0;
    public const MODE_NIGHT = 1;
    public const MODE_AWAY = 2;
    public const MODE_PERIMETER = 3;

    private const MODE_COLUMNS = [1 => 'Night', 2 => 'Away', 3 => 'Perimeter'];

    public const STATE_OFF = 0;
    public const STATE_EXIT = 1;
    public const STATE_ARMED = 2;
    public const STATE_PREALARM = 3;
    public const STATE_ENTRY = 4;
    public const STATE_ALARM = 5;

    /** Standardtexte; Platzhalter siehe README bzw. Konfigurationsformular */
    public const TEXT_DEFAULTS = [
        'TextAlarmPush'     => '{TYP} im Modus {MODUS}: {MELDER} ({ZEIT} Uhr) {WIEDERHOLUNG}',
        'TextAlarmSpeech'   => 'Achtung, {TYP}! Ausgelöst durch {MELDER}.',
        'TextSabotagePush'  => 'Sabotage erkannt: {MELDER}',
        'TextSabotageSpeech' => '',
        'TextPrealarmPush'  => '{MELDER} ({ANZAHL} von {MINDEST} Meldern)',
        'TextArmingPush'    => '{MODUS} wird in {SEKUNDEN} Sekunden scharf ({QUELLE})',
        'TextArmedPush'     => '{MODUS} scharf',
        'TextDisarmedPush'  => 'Unscharf ({QUELLE})',
    ];

    private const TIMERS = ['ExitTimer', 'EntryTimer', 'PrealarmTimer', 'RepeatTimer', 'OutputTimer', 'AwayTimer', 'NightTimer', 'BlockerTimer', 'CountdownTimer'];

    public function GetVersion(): string
    {
        return '1.2.0-2026-11-03';
    }

    public function Create(): void
    {
        parent::Create();

        // Melder
        $this->RegisterPropertyString('Sensors', '[]');
        $this->RegisterPropertyInteger('RequiredSensors', 2);
        $this->RegisterPropertyInteger('TriggerWindow', 120);
        $this->RegisterPropertyBoolean('PrealarmPush', true);
        $this->RegisterPropertyBoolean('BlockArmingIfOpen', false);

        // Verzögerungen
        $this->RegisterPropertyInteger('ExitDelay', 60);
        $this->RegisterPropertyInteger('EntryDelay', 30);

        // Sabotage und Erreichbarkeit
        $this->RegisterPropertyString('SabotageSources', '[]');
        $this->RegisterPropertyBoolean('SabotageAlarmWhenArmed', true);
        $this->RegisterPropertyString('UnreachSources', '[]');

        // Automatik
        $this->RegisterPropertyString('PresenceSources', '[]');
        $this->RegisterPropertyBoolean('AutoAway', false);
        $this->RegisterPropertyInteger('AwayDelay', 10);
        $this->RegisterPropertyBoolean('AutoDisarmOnReturn', true);
        $this->RegisterPropertyBoolean('NightAuto', false);
        $this->RegisterPropertyString('NightStart', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyString('NightEnd', '{"hour":5,"minute":0,"second":0}');
        $this->RegisterPropertyString('NightBlockers', '[]');
        $this->RegisterPropertyInteger('BlockerDelay', 300);

        // Alarmierung
        $this->RegisterPropertyInteger('PushInstance', 0);
        $this->RegisterPropertyString('PushTargets', '[]');
        $this->RegisterPropertyBoolean('StatusPush', true);
        $this->RegisterPropertyInteger('AlexaInstance', 0);
        $this->RegisterPropertyInteger('AlexaVolume', 35);
        $this->RegisterPropertyInteger('RepeatInterval', 60);
        $this->RegisterPropertyInteger('RepeatCount', 10);
        $this->RegisterPropertyString('Outputs', '[]');
        $this->RegisterPropertyInteger('OutputDuration', 180);
        $this->RegisterPropertyString('Lights', '[]');
        $this->RegisterPropertyInteger('AlarmScript', 0);

        // Texte (leer = keine Nachricht)
        foreach (self::TEXT_DEFAULTS as $property => $default) {
            $this->RegisterPropertyString($property, $default);
        }

        // Laufzeitdaten
        $this->RegisterAttributeString('TriggerLog', '{}');
        $this->RegisterAttributeString('ArmedBy', '');
        $this->RegisterAttributeInteger('RepeatsDone', 0);
        $this->RegisterAttributeString('AlarmText', '');
        $this->RegisterAttributeString('AlarmVars', '{}');
        $this->RegisterAttributeInteger('DelayEnd', 0);
        $this->RegisterAttributeBoolean('NightManualOff', false);
        $this->RegisterAttributeInteger('AlarmMode', 0);
        $this->RegisterAttributeString('NightNext', '');
        $this->RegisterAttributeString('LastActive', '{}');

        foreach (self::TIMERS as $timer) {
            $this->RegisterTimer($timer, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'' . $timer . '\', 0);');
        }

        $this->CreateProfiles();

        $this->RegisterVariableInteger('Mode', 'Modus', 'ALM.Mode', 10);
        $this->EnableAction('Mode');
        $this->RegisterVariableInteger('State', 'Zustand', 'ALM.State', 20);
        $this->RegisterVariableBoolean('Alarm', 'Alarm', '~Alert', 30);
        $this->EnableAction('Alarm');
        $this->RegisterVariableBoolean('Sabotage', 'Sabotage', '~Alert', 40);
        $this->RegisterVariableBoolean('Automatic', 'Automatik', '~Switch', 50);
        $this->EnableAction('Automatic');
        $this->RegisterVariableInteger('Countdown', 'Verzögerung', 'ALM.Seconds', 60);
        $this->RegisterVariableString('TriggeredSensors', 'Ausgelöste Melder', '~TextBox', 70);
        $this->RegisterVariableString('LastEvent', 'Letztes Ereignis', '', 80);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        foreach (['Sensors', 'SabotageSources', 'UnreachSources', 'PresenceSources', 'NightBlockers'] as $list) {
            foreach ($this->GetList($list) as $row) {
                $id = (int) ($row['VariableID'] ?? 0);
                if ($id > 0 && IPS_VariableExists($id)) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }

        $this->InitEdgeState();
        $this->CreateProfiles();
        $this->ValidateConfiguration();
        $this->RecoverState();
        $this->UpdateSabotageVariable();
        $this->ScheduleNightTimer();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE) {
            return;
        }

        if ($row = $this->FindRow('Sensors', $SenderID)) {
            if ($this->RisingEdge('Sensors', $SenderID, $row)) {
                $this->HandleSensor($SenderID, $row);
            }
        }
        if ($row = $this->FindRow('SabotageSources', $SenderID)) {
            $this->UpdateSabotageVariable();
            if ($this->RisingEdge('SabotageSources', $SenderID, null)) {
                $this->HandleSabotage($this->RowName($row));
            }
        }
        if ($row = $this->FindRow('UnreachSources', $SenderID)) {
            if ($this->RisingEdge('UnreachSources', $SenderID, null) && $this->IsArmedState()) {
                $this->Push('Alarmanlage', 'Melder nicht erreichbar: ' . $this->RowName($row), 'alarm');
                $this->Event('Nicht erreichbar: ' . $this->RowName($row));
            }
        }
        if ($this->FindRow('PresenceSources', $SenderID)) {
            $this->EvaluatePresence();
        }
        if ($this->FindRow('NightBlockers', $SenderID)) {
            $delay = max(0, $this->ReadPropertyInteger('BlockerDelay'));
            if ($delay === 0) {
                $this->EvaluateNightBlockers();
            } else {
                $this->SetTimerInterval('BlockerTimer', $delay * 1000);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mode':
                $this->SetModeInternal((int) $Value, 'manuell');
                break;
            case 'Alarm':
                if (!$Value) {
                    $this->Acknowledge();
                }
                break;
            case 'Automatic':
                $this->SetValue('Automatic', (bool) $Value);
                if ($Value) {
                    $this->EvaluatePresence();
                    $this->ScheduleNightTimer();
                }
                break;
            case 'ExitTimer':
                $this->OnExitTimer();
                break;
            case 'EntryTimer':
                $this->OnEntryTimer();
                break;
            case 'PrealarmTimer':
                $this->OnPrealarmTimer();
                break;
            case 'RepeatTimer':
                $this->OnRepeatTimer();
                break;
            case 'OutputTimer':
                $this->SetTimerInterval('OutputTimer', 0);
                $this->SwitchOutputs(false);
                break;
            case 'AwayTimer':
                $this->OnAwayTimer();
                break;
            case 'NightTimer':
                $this->OnNightTimer();
                break;
            case 'BlockerTimer':
                $this->SetTimerInterval('BlockerTimer', 0);
                $this->EvaluateNightBlockers();
                break;
            case 'CountdownTimer':
                $this->UpdateCountdown();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen (ALM_*)
    // ---------------------------------------------------------------------

    /** Modus setzen: 0 = Unscharf, 1 = Nacht, 2 = Abwesend */
    public function SetMode(int $Mode): void
    {
        $this->SetModeInternal($Mode, 'Skript');
    }

    /** Laufenden Alarm quittieren; die Anlage bleibt im aktuellen Modus scharf. */
    public function Acknowledge(): void
    {
        if (!$this->GetValue('Alarm') && $this->GetValue('State') !== self::STATE_ALARM) {
            return;
        }
        $this->StopAlarmOutputs();
        $this->SetValue('Alarm', false);
        $this->ClearTriggerLog();
        $mode = $this->GetValue('Mode');
        $this->SetValue('State', $mode === self::MODE_OFF ? self::STATE_OFF : self::STATE_ARMED);
        $this->Event('Alarm quittiert');
        $this->Push('Alarmanlage', 'Alarm quittiert');
    }

    /** Notalarm, unabhängig vom Modus (z. B. für Notschalter). */
    public function Panic(string $Text): void
    {
        $this->TriggerAlarm('Notalarm', [$Text !== '' ? $Text : 'Notschalter'], true);
    }

    /** Ansage mit dem Alarmtext und Beispielwerten testen. */
    public function TestSpeech(): string
    {
        $text = $this->RenderText('TextAlarmSpeech', $this->SampleVars());
        $this->Speak($text);
        return $text;
    }

    /** Push mit dem Alarmtext und Beispielwerten testen. */
    public function TestPush(): string
    {
        $text = $this->RenderText('TextAlarmPush', $this->SampleVars());
        $this->Push('ALARM (Test)', $text);
        return $text;
    }

    /** Liefert die Melder, die im angegebenen Modus gerade aktiv/offen sind. */
    public function GetOpenSensors(int $Mode): array
    {
        $open = [];
        foreach ($this->SensorsForMode($Mode) as $row) {
            $id = (int) $row['VariableID'];
            if (IPS_VariableExists($id) && $this->SensorActive($row, GetValue($id))) {
                $open[] = $this->RowName($row);
            }
        }
        return $open;
    }

    // ---------------------------------------------------------------------
    // Scharf/Unscharf
    // ---------------------------------------------------------------------

    protected function SetModeInternal(int $mode, string $source): void
    {
        if ($mode !== self::MODE_OFF && !isset(self::MODE_COLUMNS[$mode])) {
            throw new Exception('Ungültiger Modus: ' . $mode);
        }
        $current = $this->GetValue('Mode');

        if ($mode === self::MODE_OFF) {
            if ($current === self::MODE_OFF && $this->GetValue('State') === self::STATE_OFF) {
                return;
            }
            if ($source === 'manuell' && $current === self::MODE_NIGHT && $this->InNightWindow()) {
                $this->WriteAttributeBoolean('NightManualOff', true);
            }
            $this->Disarm($source);
            return;
        }

        if ($current === $mode && $this->GetValue('State') !== self::STATE_OFF) {
            return;
        }

        // Wechsel zwischen den Modi: laufenden Alarm beibehalten
        $alarmActive = $this->GetValue('State') === self::STATE_ALARM;

        if (!$alarmActive && $this->ReadPropertyBoolean('BlockArmingIfOpen')) {
            $open = $this->GetOpenSensors($mode);
            if (count($open) > 0) {
                $text = $this->ModeName($mode) . ' nicht scharf geschaltet (' . $source . ') – offen: ' . $this->JoinNames($open);
                $this->Event($text);
                $this->Push('Alarmanlage', $text, 'alarm');
                return;
            }
        }

        $this->SetValue('Mode', $mode);
        $this->WriteAttributeString('ArmedBy', $source);
        $this->WriteAttributeBoolean('NightManualOff', false);
        $this->ClearTriggerLog();
        $this->SetTimerInterval('EntryTimer', 0);
        $this->SetTimerInterval('PrealarmTimer', 0);

        if ($alarmActive) {
            $this->Event('Modus ' . $this->ModeName($mode) . ' (' . $source . '), Alarm läuft weiter');
            return;
        }

        $delay = max(0, $this->ReadPropertyInteger('ExitDelay'));
        $this->Event('Scharfschalten ' . $this->ModeName($mode) . ' (' . $source . ')');
        if ($delay > 0) {
            $this->SetValue('State', self::STATE_EXIT);
            $this->StartDelay('ExitTimer', $delay);
            if ($this->ReadPropertyBoolean('StatusPush')) {
                $this->Push('Alarmanlage', $this->RenderText('TextArmingPush', ['MODUS' => $this->ModeName($mode), 'SEKUNDEN' => $delay, 'QUELLE' => $source]));
            }
        } else {
            $this->OnExitTimer();
        }
    }

    protected function OnExitTimer(): void
    {
        $this->StopDelay('ExitTimer');
        $mode = $this->GetValue('Mode');
        if ($mode === self::MODE_OFF) {
            return;
        }
        $this->ClearTriggerLog();
        $this->SetValue('State', self::STATE_ARMED);
        $this->Event('Scharf: ' . $this->ModeName($mode));

        $warnings = [];
        $open = $this->GetOpenSensors($mode);
        if (count($open) > 0) {
            $warnings[] = 'aktiv/offen: ' . implode(', ', $open);
        }
        $unreach = [];
        foreach ($this->GetList('UnreachSources') as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id > 0 && IPS_VariableExists($id) && $this->IsActive(GetValue($id))) {
                $unreach[] = $this->RowName($row);
            }
        }
        if (count($unreach) > 0) {
            $warnings[] = 'nicht erreichbar: ' . implode(', ', $unreach);
        }

        $text = $this->RenderText('TextArmedPush', ['MODUS' => $this->ModeName($mode), 'QUELLE' => $this->ReadAttributeString('ArmedBy')]);
        if (count($warnings) > 0) {
            // Warnungen werden immer gesendet, auch wenn der Text leer ist
            $this->Push('Alarmanlage', ($text !== '' ? $text . ' – ' : '') . 'Achtung, ' . implode('; ', $warnings), 'alarm');
        } elseif ($this->ReadPropertyBoolean('StatusPush')) {
            $this->Push('Alarmanlage', $text);
        }
    }

    protected function Disarm(string $source): void
    {
        $wasAlarm = $this->GetValue('State') === self::STATE_ALARM;
        $this->StopAlarmOutputs();
        foreach (['ExitTimer', 'EntryTimer', 'PrealarmTimer', 'AwayTimer'] as $timer) {
            $this->SetTimerInterval($timer, 0);
        }
        $this->StopDelay('');
        $this->ClearTriggerLog();
        $this->SetValue('Mode', self::MODE_OFF);
        $this->SetValue('State', self::STATE_OFF);
        $this->SetValue('Alarm', false);
        $this->WriteAttributeString('ArmedBy', '');
        $this->Event('Unscharf (' . $source . ')');
        if ($this->ReadPropertyBoolean('StatusPush') || $wasAlarm) {
            $this->Push('Alarmanlage', $this->RenderText('TextDisarmedPush', ['MODUS' => 'Unscharf', 'QUELLE' => $source]));
        }
    }

    // ---------------------------------------------------------------------
    // Melderauswertung
    // ---------------------------------------------------------------------

    protected function HandleSensor(int $variableID, array $row): void
    {
        $state = $this->GetValue('State');
        $mode = $this->GetValue('Mode');
        $name = $this->RowName($row);

        if ($state === self::STATE_ALARM) {
            if ($this->SensorInMode($row, $this->ReadAttributeInteger('AlarmMode'))) {
                $this->AppendTriggered($name);
            }
            return;
        }
        if (!in_array($state, [self::STATE_ARMED, self::STATE_PREALARM, self::STATE_ENTRY], true)) {
            return;
        }
        if (!$this->SensorInMode($row, $mode)) {
            return;
        }

        $now = $this->Now();
        $log = $this->PurgedTriggerLog($now);
        $log[(string) $variableID] = $now;
        $this->WriteAttributeString('TriggerLog', json_encode($log));
        $this->UpdateTriggeredVariable($log);
        $this->Event('Melder: ' . $name);

        if ($state === self::STATE_ENTRY) {
            return;
        }

        $required = max(1, $this->ReadPropertyInteger('RequiredSensors'));
        $single = (bool) ($row['Single'] ?? false);
        $count = count($log);

        if ($single || $count >= $required) {
            if ($this->LogContainsDelayed($log) && $this->ReadPropertyInteger('EntryDelay') > 0) {
                $this->StartEntryDelay();
            } else {
                $this->TriggerAlarm('Einbruch', $this->LogNameList($log), false);
            }
            return;
        }

        $this->SetValue('State', self::STATE_PREALARM);
        $this->SchedulePrealarmReset($log, $now);
        if ($this->ReadPropertyBoolean('PrealarmPush')) {
            $this->Push('Voralarm', $this->RenderText('TextPrealarmPush', ['MELDER' => $name, 'ANZAHL' => $count, 'MINDEST' => $required]));
        }
    }

    protected function StartEntryDelay(): void
    {
        $delay = max(0, $this->ReadPropertyInteger('EntryDelay'));
        $this->SetTimerInterval('PrealarmTimer', 0);
        $this->SetValue('State', self::STATE_ENTRY);
        $this->StartDelay('EntryTimer', $delay);
        $this->Event('Eingangsverzögerung ' . $delay . ' s');
    }

    protected function OnEntryTimer(): void
    {
        $this->StopDelay('EntryTimer');
        if ($this->GetValue('State') !== self::STATE_ENTRY) {
            return;
        }
        $log = json_decode($this->ReadAttributeString('TriggerLog'), true) ?: [];
        $this->TriggerAlarm('Einbruch', $this->LogNameList($log), false);
    }

    protected function OnPrealarmTimer(): void
    {
        $this->SetTimerInterval('PrealarmTimer', 0);
        if ($this->GetValue('State') !== self::STATE_PREALARM) {
            return;
        }
        $now = $this->Now();
        $log = $this->PurgedTriggerLog($now);
        $this->WriteAttributeString('TriggerLog', json_encode($log));
        if (count($log) === 0) {
            $this->SetValue('State', self::STATE_ARMED);
            $this->SetValue('TriggeredSensors', '');
            $this->Event('Voralarm zurückgesetzt');
        } else {
            $this->SchedulePrealarmReset($log, $now);
        }
    }

    protected function SchedulePrealarmReset(array $log, int $now): void
    {
        $window = max(1, $this->ReadPropertyInteger('TriggerWindow'));
        $oldest = min($log);
        $remaining = max(1, $oldest + $window - $now);
        $this->SetTimerInterval('PrealarmTimer', $remaining * 1000);
    }

    // ---------------------------------------------------------------------
    // Sabotage
    // ---------------------------------------------------------------------

    protected function HandleSabotage(string $name): void
    {
        $this->Event('Sabotage: ' . $name);
        if ($this->ReadPropertyBoolean('SabotageAlarmWhenArmed') && $this->IsArmedState()) {
            $this->TriggerAlarm('Sabotage', [$name], false);
            return;
        }
        if ($this->GetValue('State') === self::STATE_ALARM) {
            $this->AppendTriggered('Sabotage ' . $name);
        }
        $vars = ['TYP' => 'Sabotage', 'MELDER' => $name];
        $this->Push('SABOTAGE', $this->RenderText('TextSabotagePush', $vars), 'alarm');
        $this->Speak($this->RenderText('TextSabotageSpeech', $vars));
    }

    protected function UpdateSabotageVariable(): void
    {
        $any = false;
        foreach ($this->GetList('SabotageSources') as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id > 0 && IPS_VariableExists($id) && $this->IsActive(GetValue($id))) {
                $any = true;
                break;
            }
        }
        if ($this->GetValue('Sabotage') !== $any) {
            $this->SetValue('Sabotage', $any);
        }
    }

    // ---------------------------------------------------------------------
    // Alarmkette
    // ---------------------------------------------------------------------

    protected function TriggerAlarm(string $type, array $names, bool $panic): void
    {
        $text = $this->JoinNames($names);
        if ($this->GetValue('State') === self::STATE_ALARM) {
            $this->AppendTriggered($text);
            return;
        }

        $mode = $this->GetValue('Mode');
        $this->WriteAttributeInteger('AlarmMode', $panic ? -1 : $mode);
        $this->StopDelay('');
        $this->SetTimerInterval('PrealarmTimer', 0);

        $this->SetValue('State', self::STATE_ALARM);
        $this->SetValue('Alarm', true);
        $message = strtoupper($type) . ': ' . $text;
        $this->WriteAttributeString('AlarmText', $message);
        $log = json_decode($this->ReadAttributeString('TriggerLog'), true) ?: [];
        $this->SetValue('TriggeredSensors', $type === 'Einbruch' && count($log) > 0 ? $this->LogNames($log) : $text);
        $this->Event($message);
        IPS_LogMessage('Alarmanlage', $message);

        $this->SwitchOutputs(true);
        $duration = max(0, $this->ReadPropertyInteger('OutputDuration'));
        $this->SetTimerInterval('OutputTimer', $duration * 1000);

        foreach ($this->GetList('Lights') as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id > 0 && IPS_VariableExists($id)) {
                @RequestAction($id, true);
            }
        }

        $script = $this->ReadPropertyInteger('AlarmScript');
        if ($script > 0 && IPS_ScriptExists($script)) {
            IPS_RunScriptEx($script, ['ALARM_TYPE' => $type, 'ALARM_TEXT' => $text, 'MODE' => $mode]);
        }

        $vars = [
            'TYP'    => $type,
            'MELDER' => $text,
            'MODUS'  => $panic ? 'Notalarm' : $this->ModeName($mode),
            'ZEIT'   => date('H:i', $this->Now()),
            'DATUM'  => date('d.m.Y', $this->Now()),
            'ANZAHL' => count($names),
        ];
        $this->WriteAttributeString('AlarmVars', json_encode($vars));
        $this->SendAlarmMessages($vars);
        $this->WriteAttributeInteger('RepeatsDone', 0);
        $interval = max(0, $this->ReadPropertyInteger('RepeatInterval'));
        if ($interval > 0 && $this->ReadPropertyInteger('RepeatCount') > 0) {
            $this->SetTimerInterval('RepeatTimer', $interval * 1000);
        }
    }

    protected function OnRepeatTimer(): void
    {
        $done = $this->ReadAttributeInteger('RepeatsDone');
        if (!$this->GetValue('Alarm') || $done >= $this->ReadPropertyInteger('RepeatCount')) {
            $this->SetTimerInterval('RepeatTimer', 0);
            return;
        }
        $this->WriteAttributeInteger('RepeatsDone', $done + 1);
        $vars = json_decode($this->ReadAttributeString('AlarmVars'), true) ?: [];
        $vars['WIEDERHOLUNG'] = '(Wiederholung ' . ($done + 1) . ')';
        $this->SendAlarmMessages($vars);
    }

    protected function SendAlarmMessages(array $vars): void
    {
        $this->Push('ALARM', $this->RenderText('TextAlarmPush', $vars), 'alarm');
        $this->Speak($this->RenderText('TextAlarmSpeech', $vars));
    }

    protected function StopAlarmOutputs(): void
    {
        $this->SetTimerInterval('RepeatTimer', 0);
        if ($this->GetValue('State') === self::STATE_ALARM || $this->GetTimerInterval('OutputTimer') > 0) {
            $this->SwitchOutputs(false);
        }
        $this->SetTimerInterval('OutputTimer', 0);
    }

    protected function SwitchOutputs(bool $on): void
    {
        $alarmMode = $this->ReadAttributeInteger('AlarmMode');
        foreach ($this->GetList('Outputs') as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id <= 0 || !IPS_VariableExists($id)) {
                continue;
            }
            if ($on) {
                $column = self::MODE_COLUMNS[$alarmMode] ?? '';
                $use = $alarmMode === -1 || ($column !== '' && ($row[$column] ?? false));
                if (!$use) {
                    continue;
                }
            }
            if (@RequestAction($id, $on) === false) {
                $this->SendDebug('Output', 'RequestAction fehlgeschlagen für ' . $id, 0);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Automatik: Anwesenheit und Nacht
    // ---------------------------------------------------------------------

    protected function EvaluatePresence(): void
    {
        if (!$this->GetValue('Automatic') || count($this->GetList('PresenceSources')) === 0) {
            return;
        }
        $present = $this->AnyonePresent();
        $mode = $this->GetValue('Mode');

        if ($present) {
            $this->SetTimerInterval('AwayTimer', 0);
            if ($mode === self::MODE_AWAY && $this->ReadPropertyBoolean('AutoDisarmOnReturn')) {
                $this->SetModeInternal(self::MODE_OFF, 'Rückkehr');
            }
            return;
        }

        if ($this->ReadPropertyBoolean('AutoAway') && $mode !== self::MODE_AWAY && $this->GetTimerInterval('AwayTimer') === 0) {
            $this->SetTimerInterval('AwayTimer', max(1, $this->ReadPropertyInteger('AwayDelay')) * 60 * 1000);
        }
    }

    protected function OnAwayTimer(): void
    {
        $this->SetTimerInterval('AwayTimer', 0);
        if (!$this->GetValue('Automatic') || !$this->ReadPropertyBoolean('AutoAway')) {
            return;
        }
        if (!$this->AnyonePresent() && $this->GetValue('Mode') !== self::MODE_AWAY) {
            $this->SetModeInternal(self::MODE_AWAY, 'Abwesenheit');
        }
    }

    protected function OnNightTimer(): void
    {
        $event = $this->ReadAttributeString('NightNext');

        if ($this->GetValue('Automatic') && $this->ReadPropertyBoolean('NightAuto')) {
            if ($event === 'end' && $this->GetValue('Mode') === self::MODE_NIGHT) {
                $this->SetModeInternal(self::MODE_OFF, 'Nachtende');
            } elseif ($event === 'start') {
                $this->WriteAttributeBoolean('NightManualOff', false);
                $this->TryNightArm('Nachtbeginn');
            }
        }
        $this->ScheduleNightTimer();
    }

    protected function EvaluateNightBlockers(): void
    {
        if (!$this->GetValue('Automatic') || !$this->ReadPropertyBoolean('NightAuto') || !$this->InNightWindow()) {
            return;
        }
        $mode = $this->GetValue('Mode');
        if ($this->NightBlocked()) {
            if ($mode === self::MODE_NIGHT && $this->ReadAttributeString('ArmedBy') !== 'manuell' && $this->GetValue('State') !== self::STATE_ALARM) {
                $this->SetModeInternal(self::MODE_OFF, 'Nachtsperre');
            }
            return;
        }
        if (!$this->ReadAttributeBoolean('NightManualOff')) {
            $this->TryNightArm('Nachtsperre aufgehoben');
        }
    }

    protected function TryNightArm(string $source): void
    {
        if ($this->GetValue('Mode') !== self::MODE_OFF || $this->NightBlocked()) {
            return;
        }
        if (count($this->GetList('PresenceSources')) > 0 && !$this->AnyonePresent()) {
            return;
        }
        $this->SetModeInternal(self::MODE_NIGHT, $source);
    }

    protected function ScheduleNightTimer(): void
    {
        if (!$this->ReadPropertyBoolean('NightAuto')) {
            $this->SetTimerInterval('NightTimer', 0);
            return;
        }
        $now = $this->Now();
        $next = PHP_INT_MAX;
        $nextEvent = '';
        foreach (['start' => 'NightStart', 'end' => 'NightEnd'] as $event => $property) {
            $minute = $this->ReadTimeProperty($property);
            $ts = mktime(intdiv($minute, 60), $minute % 60, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
            if ($ts <= $now) {
                $ts = mktime(intdiv($minute, 60), $minute % 60, 0, (int) date('n', $now), (int) date('j', $now) + 1, (int) date('Y', $now));
            }
            if ($ts < $next) {
                $next = $ts;
                $nextEvent = $event;
            }
        }
        $this->WriteAttributeString('NightNext', $nextEvent);
        // 5 s Puffer, damit die Minute beim Auslösen sicher erreicht ist
        $this->SetTimerInterval('NightTimer', ($next - $now + 5) * 1000);
    }

    protected function InNightWindow(): bool
    {
        $minute = $this->MinuteOfDay($this->Now());
        $start = $this->ReadTimeProperty('NightStart');
        $end = $this->ReadTimeProperty('NightEnd');
        if ($start === $end) {
            return false;
        }
        return $start < $end
            ? ($minute >= $start && $minute < $end)
            : ($minute >= $start || $minute < $end);
    }

    protected function AnyonePresent(): bool
    {
        foreach ($this->GetList('PresenceSources') as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id > 0 && IPS_VariableExists($id) && $this->IsActive(GetValue($id))) {
                return true;
            }
        }
        return false;
    }

    protected function NightBlocked(): bool
    {
        foreach ($this->GetList('NightBlockers') as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id > 0 && IPS_VariableExists($id) && $this->IsActive(GetValue($id))) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Hilfsfunktionen
    // ---------------------------------------------------------------------

    protected function Now(): int
    {
        return time();
    }

    /** Push an alle Empfänger. Leerer Text = keine Nachricht. */
    protected function Push(string $title, string $text, string $sound = 'default'): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $instance = $this->ReadPropertyInteger('PushInstance');
        if ($instance > 0 && IPS_InstanceExists($instance) && function_exists('NC_PushNotification')) {
            foreach ($this->GetList('PushTargets') as $row) {
                $target = (int) ($row['TargetID'] ?? 0);
                if ($target > 0) {
                    @NC_PushNotification($instance, $target, $title, $text, $sound);
                }
            }
        }
        $this->SendDebug('Push', $title . ': ' . $text, 0);
    }

    /** Ansage über das Echo-Gerät. Leerer Text = keine Ansage. */
    protected function Speak(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $alexa = $this->ReadPropertyInteger('AlexaInstance');
        if ($alexa > 0 && IPS_InstanceExists($alexa) && function_exists('ECHOREMOTE_TextToSpeechVolume')) {
            @ECHOREMOTE_TextToSpeechVolume($alexa, $text, $this->ReadPropertyInteger('AlexaVolume'));
        }
        $this->SendDebug('Ansage', $text, 0);
    }

    /** Ersetzt {PLATZHALTER} im Text der Eigenschaft und räumt doppelte Leerzeichen auf. */
    protected function RenderText(string $property, array $vars): string
    {
        $vars += [
            'ZEIT'  => date('H:i', $this->Now()),
            'DATUM' => date('d.m.Y', $this->Now()),
            'MODUS' => $this->ModeName($this->GetValue('Mode')),
            'WIEDERHOLUNG' => '',
        ];
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }
        $text = strtr($this->ReadPropertyString($property), $replace);
        $text = preg_replace('/\(\s*\)/', '', $text);
        return trim(preg_replace('/\s{2,}/', ' ', $text));
    }

    /** "A", "A und B", "A, B und C" – liest sich auch in der Ansage natürlich. */
    protected function JoinNames(array $names): string
    {
        $names = array_values(array_unique(array_filter($names, 'strlen')));
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' und ' . $last;
    }

    protected function Event(string $text): void
    {
        $this->SetValue('LastEvent', date('d.m.Y H:i:s', $this->Now()) . ' – ' . $text);
        $this->SendDebug('Event', $text, 0);
    }

    protected function StartDelay(string $timer, int $seconds): void
    {
        $this->WriteAttributeInteger('DelayEnd', $this->Now() + $seconds);
        $this->SetValue('Countdown', $seconds);
        $this->SetTimerInterval($timer, $seconds * 1000);
        $this->SetTimerInterval('CountdownTimer', 1000);
    }

    protected function StopDelay(string $timer): void
    {
        if ($timer !== '') {
            $this->SetTimerInterval($timer, 0);
        } else {
            $this->SetTimerInterval('ExitTimer', 0);
            $this->SetTimerInterval('EntryTimer', 0);
        }
        $this->SetTimerInterval('CountdownTimer', 0);
        $this->SetValue('Countdown', 0);
    }

    protected function UpdateCountdown(): void
    {
        $remaining = max(0, $this->ReadAttributeInteger('DelayEnd') - $this->Now());
        $this->SetValue('Countdown', $remaining);
        if ($remaining === 0) {
            $this->SetTimerInterval('CountdownTimer', 0);
        }
    }

    protected function RecoverState(): void
    {
        // Nach Neustart laufen keine Timer mehr: Zwischenzustände sicher auflösen
        $state = $this->GetValue('State');
        $mode = $this->GetValue('Mode');
        if ($mode === self::MODE_OFF && $state !== self::STATE_OFF && $state !== self::STATE_ALARM) {
            $this->SetValue('State', self::STATE_OFF);
        } elseif ($mode !== self::MODE_OFF && in_array($state, [self::STATE_EXIT, self::STATE_PREALARM, self::STATE_ENTRY], true)) {
            $this->ClearTriggerLog();
            $this->SetValue('State', self::STATE_ARMED);
            $this->SetValue('Countdown', 0);
            $this->Event('Nach Neustart: ' . $this->ModeName($mode) . ' scharf');
        } elseif ($state === self::STATE_ALARM && $this->GetTimerInterval('OutputTimer') === 0) {
            // Ausgänge nach Neustart sicher wieder abschalten
            $this->SetTimerInterval('OutputTimer', max(1, $this->ReadPropertyInteger('OutputDuration')) * 1000);
        }
    }

    protected function ValidateConfiguration(): void
    {
        $required = max(1, $this->ReadPropertyInteger('RequiredSensors'));
        foreach (array_keys(self::MODE_COLUMNS) as $mode) {
            $rows = $this->SensorsForMode($mode);
            if (count($rows) === 0) {
                continue;
            }
            $hasSingle = false;
            foreach ($rows as $row) {
                if ($row['Single'] ?? false) {
                    $hasSingle = true;
                }
            }
            if (count($rows) < $required && !$hasSingle) {
                $this->SetStatus(201);
                return;
            }
        }
        $this->SetStatus(102);
    }

    /**
     * Flankenerkennung über den zuletzt bekannten Zustand (unabhängig vom
     * Aufbau der VM_UPDATE-Daten): nur der Wechsel inaktiv -> aktiv zählt.
     */
    protected function RisingEdge(string $list, int $variableID, ?array $sensorRow): bool
    {
        $last = json_decode($this->ReadAttributeString('LastActive'), true) ?: [];
        $value = GetValue($variableID);
        $active = $sensorRow !== null ? $this->SensorActive($sensorRow, $value) : $this->IsActive($value);
        $key = $list . ':' . $variableID;
        $was = (bool) ($last[$key] ?? false);
        $last[$key] = $active;
        $this->WriteAttributeString('LastActive', json_encode($last));
        return $active && !$was;
    }

    protected function InitEdgeState(): void
    {
        $last = [];
        foreach (['Sensors', 'SabotageSources', 'UnreachSources'] as $list) {
            foreach ($this->GetList($list) as $row) {
                $id = (int) ($row['VariableID'] ?? 0);
                if ($id > 0 && IPS_VariableExists($id)) {
                    $value = GetValue($id);
                    $last[$list . ':' . $id] = $list === 'Sensors' ? $this->SensorActive($row, $value) : $this->IsActive($value);
                }
            }
        }
        $this->WriteAttributeString('LastActive', json_encode($last));
    }

    /**
     * Melder aktiv? Zahlen (Fenstergriff 0/1/2, Türkontakt 0/1) gelten ab dem
     * Schwellwert der Melderzeile als aktiv, Boolean bei true. "Invertiert"
     * kehrt das Ergebnis um.
     */
    protected function SensorActive(array $row, $value): bool
    {
        $invert = (bool) ($row['Invert'] ?? false);
        if (is_int($value) || is_float($value)) {
            $active = $value >= max(1, (int) ($row['Threshold'] ?? 1));
            return $invert ? !$active : $active;
        }
        return $this->IsActive($value, $invert);
    }

    protected function IsActive($value, bool $invert = false): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            $active = $value;
        } elseif (is_int($value) || is_float($value)) {
            $active = $value > 0;
        } else {
            $active = (string) $value !== '' && (string) $value !== '0';
        }
        return $invert ? !$active : $active;
    }

    protected function IsArmedState(): bool
    {
        return in_array($this->GetValue('State'), [self::STATE_ARMED, self::STATE_PREALARM, self::STATE_ENTRY], true);
    }

    protected function SensorInMode(array $row, int $mode): bool
    {
        $column = self::MODE_COLUMNS[$mode] ?? '';
        if ($column === '') {
            return false;
        }
        // Ältere Konfigurationen ohne Spalte: Abwesend = an, sonst aus
        return (bool) ($row[$column] ?? ($mode === self::MODE_AWAY));
    }

    protected function SensorsForMode(int $mode): array
    {
        $rows = [];
        foreach ($this->GetList('Sensors') as $row) {
            if ((int) ($row['VariableID'] ?? 0) > 0 && $this->SensorInMode($row, $mode)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    protected function PurgedTriggerLog(int $now): array
    {
        $window = max(1, $this->ReadPropertyInteger('TriggerWindow'));
        $log = json_decode($this->ReadAttributeString('TriggerLog'), true) ?: [];
        return array_filter($log, function ($ts) use ($now, $window) {
            return ($now - (int) $ts) < $window;
        });
    }

    protected function ClearTriggerLog(): void
    {
        $this->WriteAttributeString('TriggerLog', '{}');
        $this->SetValue('TriggeredSensors', '');
    }

    protected function LogContainsDelayed(array $log): bool
    {
        foreach (array_keys($log) as $id) {
            $row = $this->FindRow('Sensors', (int) $id);
            if ($row && ($row['Delayed'] ?? false)) {
                return true;
            }
        }
        return false;
    }

    protected function LogNameList(array $log): array
    {
        asort($log);
        $names = [];
        foreach (array_keys($log) as $id) {
            $row = $this->FindRow('Sensors', (int) $id);
            $names[] = $row ? $this->RowName($row) : (string) $id;
        }
        return $names;
    }

    protected function LogNames(array $log): string
    {
        asort($log);
        $names = [];
        foreach ($log as $id => $ts) {
            $row = $this->FindRow('Sensors', (int) $id);
            $names[] = ($row ? $this->RowName($row) : (string) $id) . ' (' . date('H:i:s', (int) $ts) . ')';
        }
        return implode(', ', $names);
    }

    protected function UpdateTriggeredVariable(array $log): void
    {
        $this->SetValue('TriggeredSensors', $this->LogNames($log));
    }

    protected function AppendTriggered(string $text): void
    {
        $current = $this->GetValue('TriggeredSensors');
        if (strpos($current, $text) === false) {
            $this->SetValue('TriggeredSensors', $current === '' ? $text : $current . ', ' . $text);
        }
    }

    protected function GetList(string $property): array
    {
        $list = json_decode($this->ReadPropertyString($property), true);
        return is_array($list) ? $list : [];
    }

    protected function FindRow(string $property, int $variableID): ?array
    {
        foreach ($this->GetList($property) as $row) {
            if ((int) ($row['VariableID'] ?? 0) === $variableID) {
                return $row;
            }
        }
        return null;
    }

    protected function RowName(array $row): string
    {
        $name = trim((string) ($row['Name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $id = (int) ($row['VariableID'] ?? 0);
        return ($id > 0 && IPS_ObjectExists($id)) ? IPS_GetName($id) : '#' . $id;
    }

    protected function SampleVars(): array
    {
        $names = array_slice(array_map([$this, 'RowName'], $this->SensorsForMode(self::MODE_AWAY)), 0, 2);
        return [
            'TYP'    => 'Einbruch',
            'MELDER' => $this->JoinNames(count($names) > 0 ? $names : ['Flur', 'Wohnzimmer']),
            'MODUS'  => 'Abwesend',
            'ANZAHL' => max(2, count($names)),
        ];
    }

    protected function ModeName(int $mode): string
    {
        return [self::MODE_OFF => 'Unscharf', self::MODE_NIGHT => 'Nacht', self::MODE_AWAY => 'Abwesend', self::MODE_PERIMETER => 'Außenhaut'][$mode] ?? (string) $mode;
    }

    protected function ReadTimeProperty(string $property): int
    {
        $time = json_decode($this->ReadPropertyString($property), true);
        if (!is_array($time)) {
            return 0;
        }
        return ((int) ($time['hour'] ?? 0)) * 60 + (int) ($time['minute'] ?? 0);
    }

    protected function MinuteOfDay(int $timestamp): int
    {
        return (int) date('G', $timestamp) * 60 + (int) date('i', $timestamp);
    }

    private function CreateProfiles(): void
    {
        if (!IPS_VariableProfileExists('ALM.Mode')) {
            IPS_CreateVariableProfile('ALM.Mode', 1);
            IPS_SetVariableProfileIcon('ALM.Mode', 'Shield');
            IPS_SetVariableProfileAssociation('ALM.Mode', 0, 'Unscharf', '', 0x4CAF50);
            IPS_SetVariableProfileAssociation('ALM.Mode', 1, 'Nacht', '', 0x3F51B5);
            IPS_SetVariableProfileAssociation('ALM.Mode', 2, 'Abwesend', '', 0xF44336);
        }
        // ab Version 1.2: Modus Außenhaut auch in bestehenden Profilen ergänzen
        IPS_SetVariableProfileAssociation('ALM.Mode', 3, 'Außenhaut', '', 0xFF9800);
        if (!IPS_VariableProfileExists('ALM.State')) {
            IPS_CreateVariableProfile('ALM.State', 1);
            IPS_SetVariableProfileIcon('ALM.State', 'Alert');
            IPS_SetVariableProfileAssociation('ALM.State', 0, 'Unscharf', '', 0x4CAF50);
            IPS_SetVariableProfileAssociation('ALM.State', 1, 'Ausgangsverzögerung', '', 0xFFC107);
            IPS_SetVariableProfileAssociation('ALM.State', 2, 'Scharf', '', 0x2196F3);
            IPS_SetVariableProfileAssociation('ALM.State', 3, 'Voralarm', '', 0xFF9800);
            IPS_SetVariableProfileAssociation('ALM.State', 4, 'Eingangsverzögerung', '', 0xFF5722);
            IPS_SetVariableProfileAssociation('ALM.State', 5, 'ALARM', '', 0xF44336);
        }
        if (!IPS_VariableProfileExists('ALM.Seconds')) {
            IPS_CreateVariableProfile('ALM.Seconds', 1);
            IPS_SetVariableProfileText('ALM.Seconds', '', ' s');
            IPS_SetVariableProfileIcon('ALM.Seconds', 'Clock');
        }
    }
}
