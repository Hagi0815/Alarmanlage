<?php
declare(strict_types=1);

const VM_UPDATE = 10603;
const IPS_KERNELSTARTED = 10001;
const KR_READY = 10103;

class Sim
{
    public static array $vars = [];      // id => value
    public static array $names = [];
    public static array $push = [];
    public static array $tts = [];
    public static array $actions = [];   // [id, value]
    public static array $log = [];
    public static ?Alarmanlage $mod = null;

    public static function reset(): void
    {
        self::$vars = self::$names = self::$push = self::$tts = self::$actions = self::$log = [];
    }

    /** Variable ändern und VM_UPDATE an das Modul schicken */
    public static function set(int $id, $value): void
    {
        $old = self::$vars[$id] ?? null;
        self::$vars[$id] = $value;
        self::$mod->MessageSink(0, $id, VM_UPDATE, [$value, $old !== $value, $old, 0]);
    }
}

function GetValue(int $id) { return Sim::$vars[$id] ?? null; }
function IPS_VariableExists(int $id): bool { return array_key_exists($id, Sim::$vars); }
function IPS_ObjectExists(int $id): bool { return array_key_exists($id, Sim::$vars); }
function IPS_InstanceExists(int $id): bool { return $id > 0; }
function IPS_ScriptExists(int $id): bool { return false; }
function IPS_GetName(int $id): string { return Sim::$names[$id] ?? ('Var' . $id); }
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_LogMessage(string $s, string $m): void { Sim::$log[] = $m; }
function RequestAction(int $id, $v): bool { Sim::$actions[] = [$id, $v]; Sim::$vars[$id] = $v; return true; }
function NC_PushNotification(int $i, int $t, string $title, string $text, string $sound): bool { Sim::$push[] = "$title: $text"; return true; }
function ECHOREMOTE_TextToSpeechVolume(int $i, string $t, int $v): bool { Sim::$tts[] = $t; return true; }
function IPS_VariableProfileExists(string $p): bool { return true; }

class IPSModule
{
    public int $InstanceID = 99999;
    public array $props = [];
    public array $attrs = [];
    public array $values = [];
    public array $timers = [];
    public int $status = 0;
    public array $messages = [];

    public function __construct() {}
    public function Create(): void {}
    public function ApplyChanges(): void {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] ??= $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] ??= $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] ??= $v; }
    protected function ReadPropertyString($n): string { return $this->props[$n]; }
    protected function ReadPropertyInteger($n): int { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n): bool { return $this->props[$n]; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] ??= $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] ??= $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] ??= $v; }
    protected function ReadAttributeString($n): string { return $this->attrs[$n]; }
    protected function ReadAttributeInteger($n): int { return $this->attrs[$n]; }
    protected function ReadAttributeBoolean($n): bool { return $this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) { $this->timers[$n] = 0; }
    protected function SetTimerInterval($n, $i) { if (!array_key_exists($n, $this->timers)) throw new Exception("Timer $n"); $this->timers[$n] = $i; }
    protected function GetTimerInterval($n): int { return $this->timers[$n]; }
    protected function RegisterVariableInteger($i, $n, $p, $pos) { $this->values[$i] ??= 0; }
    protected function RegisterVariableBoolean($i, $n, $p, $pos) { $this->values[$i] ??= false; }
    protected function RegisterVariableString($i, $n, $p, $pos) { $this->values[$i] ??= ''; }
    protected function EnableAction($i) {}
    protected function SetValue($i, $v) {
        if (!array_key_exists($i, $this->values)) throw new Exception("Var $i");
        if (gettype($v) !== gettype($this->values[$i])) throw new Exception("Typ $i: " . gettype($v));
        $this->values[$i] = $v;
    }
    protected function GetValue($i) { return $this->values[$i]; }
    protected function RegisterMessage($s, $m) { $this->messages[$s][] = $m; }
    protected function UnregisterMessage($s, $m) { unset($this->messages[$s]); }
    protected function GetMessageList(): array { return $this->messages; }
    protected function SendDebug($a, $b, $c) {}
    protected function SetStatus($s) { $this->status = $s; }
}
