<?php

declare(strict_types=1);

/**
 * BatteryMonitor – findet alle batteriebetriebenen Geräte in IP-Symcon,
 * visualisiert den Batteriestand und meldet Geräte, die länger als die
 * eingestellte Dauer unter dem Schwellwert liegen.
 */
class BatteryMonitor extends IPSModule
{
    // Gerätenamen, die nur ein Unterordner sind (z. B. Gardena "Geräteinformationen")
    private const GENERIC_PARENTS = '/^(geräteinformationen|geraeteinformationen|device ?info(rmation)?|status|wartung|maintenance|diagnose|info)$/i';

    private const NAME_LEVEL = '/(batter|akku|^bat$|battery_?level)/i';
    private const NAME_FLAG = '/(batterie\s*schwach|battery[\s_-]?low|low[\s_-]?bat|lowbat|batterie\s*leer)/i';
    private const STRING_LOW = ['LOW', 'CRITICAL', 'REPLACE_NOW', 'EMPTY', 'SCHWACH', 'LEER', 'KRITISCH'];
    private const STRING_NONE = ['NO_BATTERY', 'MAINS', 'RECHARGEABLE_OK'];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyInteger('Threshold', 20);
        $this->RegisterPropertyInteger('Hysteresis', 5);
        $this->RegisterPropertyInteger('DurationHours', 24);
        $this->RegisterPropertyInteger('StaleDays', 7);
        $this->RegisterPropertyInteger('CheckInterval', 15);
        $this->RegisterPropertyBoolean('UseNameMatching', true);
        $this->RegisterPropertyString('ExcludePattern', 'SOC|Wirkarbeit|Leistung|Spannung|Voltage|kWh|Batterieüberwachung');
        $this->RegisterPropertyString('Excluded', '[]');

        $this->RegisterPropertyInteger('VisuID', 0);
        $this->RegisterPropertyInteger('SMTPID', 0);
        $this->RegisterPropertyInteger('ScriptID', 0);
        $this->RegisterPropertyBoolean('NotifyStale', true);
        $this->RegisterPropertyBoolean('NotifyRecovery', true);
        $this->RegisterPropertyBoolean('SummaryEnabled', false);
        $this->RegisterPropertyString('SummaryTime', '{"hour":8,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('HtmlOnlyProblems', false);

        // Meldestruktur: Gruppen mit eigener Übersicht und eigenen Meldezielen
        $this->RegisterPropertyString('Groups', '[]');
        // Zuordnung Objekt (Variable/Gerät/Kategorie) -> Gruppen ("A, B" oder "-")
        $this->RegisterPropertyString('Assignments', '[]');

        $this->RegisterAttributeString('State', '{}');

        $this->RegisterTimer('CheckTimer', 0, 'BATM_Check($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SummaryTimer', 0, 'BATM_SendSummary($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableString('Overview', 'Batterieübersicht', '~HTMLBox', 10);
        $this->RegisterVariableBoolean('Alarm', 'Batteriealarm', '~Alert', 20);
        $this->RegisterVariableInteger('CriticalCount', 'Gemeldete Geräte', '', 30);
        $this->RegisterVariableInteger('LowCount', 'Unter Schwellwert', '', 40);
        $this->RegisterVariableInteger('DeviceCount', 'Überwachte Geräte', '', 50);
        $this->RegisterVariableString('CriticalList', 'Gemeldete Geräte (Liste)', '', 60);

        // Variablen je Meldegruppe anlegen bzw. aufräumen
        $keep = [];
        $pos = 100;
        foreach ($this->GetGroups() as $g) {
            if (!$g['visualize']) {
                continue;
            }
            $this->RegisterVariableString($g['ident'], 'Batterieübersicht ' . $g['name'], '~HTMLBox', $pos++);
            $this->RegisterVariableBoolean($g['alarmIdent'], 'Batteriealarm ' . $g['name'], '~Alert', $pos++);
            $keep[$g['ident']] = true;
            $keep[$g['alarmIdent']] = true;
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $ident = IPS_GetObject($cid)['ObjectIdent'];
            if (preg_match('/^Grp(Alarm)?[0-9a-f]{8}$/', $ident) && !isset($keep[$ident])) {
                $this->UnregisterVariable($ident);
            }
        }

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('CheckTimer', 0);
            $this->SetTimerInterval('SummaryTimer', 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);
        $this->SetTimerInterval('CheckTimer', max(1, $this->ReadPropertyInteger('CheckInterval')) * 60 * 1000);
        $this->ScheduleSummary();

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Check();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED && $this->ReadPropertyBoolean('Active')) {
            $this->Check();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $values = [];
        foreach ($this->Evaluate($this->Discover()) as $d) {
            $values[] = [
                'Device'   => $d['name'],
                'Location' => $d['location'],
                'Level'    => $d['level'] !== null ? $d['level'] . ' %' : '-',
                'Flag'     => $this->FlagText($d),
                'Updated'  => $d['updated'] > 0 ? date('d.m.Y H:i', $d['updated']) : '-',
                'Status'   => $this->StatusText($d['status']),
                'Groups'   => count($d['groups']) ? implode(', ', $d['groups']) : '(Standard)',
                'Vars'     => implode(', ', array_column($d['vars'], 'id')),
                'rowColor' => $this->RowColor($d['status'])
            ];
        }
        foreach ($form['actions'] as &$el) {
            if (($el['name'] ?? '') === 'Devices') {
                $el['values'] = $values;
                $el['caption'] = 'Gefundene Geräte (' . count($values) . ')';
            }
        }
        unset($el);
        return json_encode($form);
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (BATM_*)
    // ------------------------------------------------------------------

    /** Führt eine Prüfung durch, aktualisiert Visualisierung und meldet neue Fälle. */
    public function Check(): void
    {
        $now = time();
        $threshold = $this->ReadPropertyInteger('Threshold');
        $okLevel = $threshold + $this->ReadPropertyInteger('Hysteresis');
        $duration = $this->ReadPropertyInteger('DurationHours') * 3600;
        $staleSec = $this->ReadPropertyInteger('StaleDays') * 86400;

        $devices = $this->Discover();
        $state = json_decode($this->ReadAttributeString('State'), true);
        if (!is_array($state)) {
            $state = [];
        }

        $alerts = [];
        $recovered = [];
        $staleAlerts = [];

        foreach ($devices as $key => $d) {
            $below = $d['belowRaw'];
            $ok = !$below && ($d['level'] === null || $d['level'] >= $okLevel);

            // --- Batteriestand ---
            if ($below && !isset($state[$key])) {
                $since = $d['belowChanged'] > 0 ? min($d['belowChanged'], $now) : $now;
                $state[$key] = ['since' => $since, 'notified' => false];
            } elseif ($ok && isset($state[$key])) {
                if ($state[$key]['notified']) {
                    $recovered[] = $d;
                }
                unset($state[$key]);
            }
            if (isset($state[$key]) && !$state[$key]['notified'] && ($now - $state[$key]['since']) >= $duration) {
                $state[$key]['notified'] = true;
                $alerts[] = $d + ['since' => $state[$key]['since']];
            }

            // --- Keine Meldung mehr ---
            $sKey = 'stale:' . $key;
            $isStale = $staleSec > 0 && $d['updated'] > 0 && ($now - $d['updated']) > $staleSec;
            if ($isStale && !isset($state[$sKey])) {
                $state[$sKey] = ['since' => $d['updated'], 'notified' => true];
                $staleAlerts[] = $d;
            } elseif (!$isStale) {
                unset($state[$sKey]);
            }
        }

        // Verwaiste Einträge entfernen
        foreach (array_keys($state) as $k) {
            $base = str_replace('stale:', '', (string) $k);
            if (!isset($devices[$base])) {
                unset($state[$k]);
            }
        }
        $this->WriteAttributeString('State', json_encode($state));

        $evaluated = $this->Evaluate($devices, $state);
        $this->UpdateVisualization($evaluated);

        if (count($alerts) > 0) {
            $this->Notify('Batterie schwach', $alerts, fn ($d) => $this->DeviceLine($d) . ' – seit ' . date('d.m. H:i', $d['since']));
        }
        if (count($staleAlerts) > 0 && $this->ReadPropertyBoolean('NotifyStale')) {
            $this->Notify('Gerät meldet sich nicht', $staleAlerts, fn ($d) => $d['name'] . ' – letzte Meldung ' . date('d.m.Y H:i', $d['updated']));
        }
        if (count($recovered) > 0 && $this->ReadPropertyBoolean('NotifyRecovery')) {
            $this->Notify('Batterie wieder OK', $recovered, fn ($d) => $this->DeviceLine($d));
        }
    }

    /** Sendet eine Zusammenfassung aller gemeldeten Geräte (täglich per Timer oder manuell). */
    public function SendSummary(): void
    {
        $this->ScheduleSummary();
        $state = json_decode($this->ReadAttributeString('State'), true) ?: [];
        $list = array_filter($this->Evaluate($this->Discover(), $state), fn ($d) => in_array($d['status'], ['critical', 'stale']));
        if (count($list) === 0) {
            return;
        }
        $this->Notify('Batterie-Zusammenfassung', array_values($list), fn ($d) => $this->DeviceLine($d) . ($d['status'] === 'stale' ? ' (keine Meldung)' : ''));
    }

    /** Liefert alle gefundenen Geräte als JSON (für eigene Skripte). */
    public function GetDevices(): string
    {
        $state = json_decode($this->ReadAttributeString('State'), true) ?: [];
        return json_encode(array_values($this->Evaluate($this->Discover(), $state)));
    }

    /** Testnachricht über alle konfigurierten Kanäle. */
    public function SendTest(): void
    {
        $text = ['Testnachricht – die Benachrichtigung funktioniert.'];
        $this->Send('Batterieüberwachung Test', $text, [], ...$this->DefaultTargets());
        foreach ($this->GetGroups() as $g) {
            if ($g['notify']) {
                $this->Send('[' . $g['name'] . '] Test', $text, [], ...$this->GroupTargets($g));
            }
        }
    }

    /** Setzt den gespeicherten Zustand zurück (alle Fälle werden neu bewertet und ggf. erneut gemeldet). */
    public function ResetState(): void
    {
        $this->WriteAttributeString('State', '{}');
        $this->Check();
    }

    // ------------------------------------------------------------------
    // Erkennung
    // ------------------------------------------------------------------

    /**
     * Durchsucht alle Variablen und gruppiert Batterie-Variablen nach Gerät.
     * @return array<string,array>
     */
    private function Discover(): array
    {
        $threshold = $this->ReadPropertyInteger('Threshold');
        $useNames = $this->ReadPropertyBoolean('UseNameMatching');
        $pattern = trim($this->ReadPropertyString('ExcludePattern'));
        $excludeRegex = $pattern !== '' ? '/' . str_replace('/', '\/', $pattern) . '/i' : null;
        $excludedIDs = $this->ExcludedRoots();

        $devices = [];
        foreach (IPS_GetVariableList() as $vid) {
            if ($this->IsBelow($vid, $this->InstanceID) || $this->IsExcludedByRoot($vid, $excludedIDs)) {
                continue;
            }
            $kind = $this->Classify($vid, $useNames);
            if ($kind === null) {
                continue;
            }
            $path = $this->ObjectPath($vid);
            if ($excludeRegex !== null && @preg_match($excludeRegex, $path) === 1) {
                continue;
            }

            [$deviceID, $deviceName, $location] = $this->ResolveDevice($vid);
            $key = (string) $deviceID;
            $var = IPS_GetVariable($vid);
            $value = GetValue($vid);

            if (!isset($devices[$key])) {
                $devices[$key] = [
                    'id'           => $deviceID,
                    'name'         => $deviceName,
                    'location'     => $location,
                    'level'        => null,
                    'flag'         => null,
                    'text'         => null,
                    'updated'      => 0,
                    'belowRaw'     => false,
                    'belowChanged' => 0,
                    'vars'         => []
                ];
            }
            $dev = &$devices[$key];
            $dev['updated'] = max($dev['updated'], $var['VariableUpdated']);
            $dev['vars'][] = ['id' => $vid, 'kind' => $kind];

            switch ($kind) {
                case 'level':
                    $lvl = (int) round((float) $value);
                    $dev['level'] = $dev['level'] === null ? $lvl : min($dev['level'], $lvl);
                    if ($lvl < $threshold) {
                        $dev['belowRaw'] = true;
                        $dev['belowChanged'] = max($dev['belowChanged'], $var['VariableChanged']);
                    }
                    break;
                case 'flag':
                case 'flagReversed':
                    $low = $kind === 'flag' ? (bool) $value : !(bool) $value;
                    $dev['flag'] = ($dev['flag'] ?? false) || $low;
                    if ($low) {
                        $dev['belowRaw'] = true;
                        $dev['belowChanged'] = max($dev['belowChanged'], $var['VariableChanged']);
                    }
                    break;
                case 'text':
                    $txt = strtoupper(trim((string) $value));
                    if (in_array($txt, self::STRING_NONE)) {
                        array_pop($dev['vars']);
                        break;
                    }
                    $dev['text'] = $txt;
                    if (in_array($txt, self::STRING_LOW)) {
                        $dev['flag'] = true;
                        $dev['belowRaw'] = true;
                        $dev['belowChanged'] = max($dev['belowChanged'], $var['VariableChanged']);
                    }
                    break;
            }
            unset($dev);

            if (count($devices[$key]['vars']) === 0) {
                unset($devices[$key]);
            }
        }
        $this->AssignGroups($devices);
        return $devices;
    }

    // ------------------------------------------------------------------
    // Meldestruktur
    // ------------------------------------------------------------------

    /** @return array<string,array> Gruppen nach Name */
    private function GetGroups(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('Groups'), true) ?: [] as $r) {
            $name = trim((string) ($r['Name'] ?? ''));
            if ($name === '' || $name === '-' || isset($out[$name])) {
                continue;
            }
            $hash = substr(md5($name), 0, 8);
            $out[$name] = [
                'name'       => $name,
                'pattern'    => trim((string) ($r['Pattern'] ?? '')),
                'visualize'  => (bool) ($r['Visualize'] ?? true),
                'notify'     => (bool) ($r['Notify'] ?? true),
                'visu'       => (int) ($r['VisuID'] ?? 0),
                'smtp'       => (int) ($r['SMTPID'] ?? 0),
                'script'     => (int) ($r['ScriptID'] ?? 0),
                'ident'      => 'Grp' . $hash,
                'alarmIdent' => 'GrpAlarm' . $hash
            ];
        }
        return $out;
    }

    /** @return array<int,string[]> Objekt-ID => Gruppennamen */
    private function GetAssignments(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('Assignments'), true) ?: [] as $r) {
            $id = (int) ($r['ObjectID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $names = array_values(array_filter(array_map('trim', explode(',', (string) ($r['Groups'] ?? ''))), 'strlen'));
            $out[$id] = $names;
        }
        return $out;
    }

    /**
     * Ordnet jedem Gerät seine Gruppen zu:
     * 1. Nächstliegende Zuordnung (Variable → Gerät → Kategorien nach oben) gewinnt.
     * 2. Sonst alle Gruppen, deren RegEx auf den Gerätepfad passt.
     * 3. Sonst keine Gruppe → Standard (globale Ziele).
     * "-" bedeutet: nicht melden (bleibt in der Gesamtübersicht sichtbar).
     */
    private function AssignGroups(array &$devices): void
    {
        $groups = $this->GetGroups();
        $assign = $this->GetAssignments();
        foreach ($devices as &$d) {
            $d['path'] = ($d['location'] !== '' ? $d['location'] . '/' : '') . $d['name'];
            $found = null;
            $id = $d['vars'][0]['id'];
            while ($id > 0) {
                if (isset($assign[$id])) {
                    $found = $assign[$id];
                    break;
                }
                $id = IPS_GetParent($id);
            }
            if ($found === null) {
                $found = [];
                foreach ($groups as $g) {
                    if ($g['pattern'] !== '' && @preg_match('/' . str_replace('/', '\/', $g['pattern']) . '/i', $d['path']) === 1) {
                        $found[] = $g['name'];
                    }
                }
            }
            $d['groups'] = in_array('-', $found, true)
                ? ['-']
                : array_values(array_filter($found, fn ($n) => isset($groups[$n])));
        }
        unset($d);
    }

    private function DefaultTargets(): array
    {
        return [$this->ReadPropertyInteger('VisuID'), $this->ReadPropertyInteger('SMTPID'), $this->ReadPropertyInteger('ScriptID')];
    }

    /** Gruppe ohne eigene Ziele verwendet die Standardziele. */
    private function GroupTargets(array $g): array
    {
        if ($g['visu'] === 0 && $g['smtp'] === 0 && $g['script'] === 0) {
            return $this->DefaultTargets();
        }
        return [$g['visu'], $g['smtp'], $g['script']];
    }

    /** Bestimmt, ob und wie eine Variable einen Batteriezustand beschreibt. */
    private function Classify(int $vid, bool $useNames): ?string
    {
        $var = IPS_GetVariable($vid);
        $type = $var['VariableType'];
        $profile = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
        $name = IPS_GetName($vid);

        // 1. Eindeutige Systemprofile
        if ($profile === '~Battery.100' && ($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT)) {
            return 'level';
        }
        if ($profile === '~Battery' && $type === VARIABLETYPE_BOOLEAN) {
            return 'flag';
        }
        if ($profile === '~Battery.Reversed' && $type === VARIABLETYPE_BOOLEAN) {
            return 'flagReversed';
        }
        if ($profile === '~HTMLBox' || $profile === '~TextBox') {
            return null;
        }

        // 2. Herstellerprofile mit "Battery" im Namen (z. B. Gardena.Battery)
        if ($type === VARIABLETYPE_STRING && stripos($profile, 'batter') !== false) {
            return 'text';
        }

        if (!$useNames) {
            return null;
        }

        // 3. Namensbasierte Erkennung
        if ($type === VARIABLETYPE_BOOLEAN && preg_match(self::NAME_FLAG, $name)) {
            return 'flag';
        }
        if (($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT) && preg_match(self::NAME_LEVEL, $name)) {
            if ($profile !== '' && IPS_VariableProfileExists($profile)) {
                $p = IPS_GetVariableProfile($profile);
                if (trim($p['Suffix']) !== '%') {
                    return null; // z. B. Volt oder kWh
                }
                return 'level';
            }
            $v = (float) GetValue($vid);
            return ($v >= 0 && $v <= 100) ? 'level' : null;
        }
        return null;
    }

    /** Liefert [Geräte-ID, Gerätename, Ort] für eine Batterie-Variable. */
    private function ResolveDevice(int $vid): array
    {
        $device = IPS_GetParent($vid);
        if ($device > 0 && preg_match(self::GENERIC_PARENTS, IPS_GetName($device)) && IPS_GetParent($device) > 0) {
            $device = IPS_GetParent($device);
        }
        if ($device === 0) {
            return [$vid, IPS_GetName($vid), ''];
        }
        $parent = IPS_GetParent($device);
        $location = $parent > 0 ? $this->ObjectPath($parent) : '';
        return [$device, IPS_GetName($device), $location];
    }

    private function ExcludedRoots(): array
    {
        $ids = [];
        $list = json_decode($this->ReadPropertyString('Excluded'), true) ?: [];
        foreach ($list as $row) {
            $id = (int) ($row['ObjectID'] ?? 0);
            if ($id > 0 && IPS_ObjectExists($id)) {
                $ids[$id] = true;
            }
        }
        return $ids;
    }

    private function IsExcludedByRoot(int $id, array $roots): bool
    {
        if (count($roots) === 0) {
            return false;
        }
        while ($id > 0) {
            if (isset($roots[$id])) {
                return true;
            }
            $id = IPS_GetParent($id);
        }
        return false;
    }

    private function IsBelow(int $id, int $root): bool
    {
        while ($id > 0) {
            if ($id === $root) {
                return true;
            }
            $id = IPS_GetParent($id);
        }
        return false;
    }

    private function ObjectPath(int $id): string
    {
        $parts = [];
        while ($id > 0) {
            array_unshift($parts, IPS_GetName($id));
            $id = IPS_GetParent($id);
        }
        return implode('/', $parts);
    }

    // ------------------------------------------------------------------
    // Bewertung und Visualisierung
    // ------------------------------------------------------------------

    /** Ergänzt Status (ok / low / critical / stale / unknown) und "unter Schwelle seit". */
    private function Evaluate(array $devices, ?array $state = null): array
    {
        if ($state === null) {
            $state = json_decode($this->ReadAttributeString('State'), true) ?: [];
        }
        $now = time();
        $staleSec = $this->ReadPropertyInteger('StaleDays') * 86400;
        foreach ($devices as $key => &$d) {
            $s = $state[$key] ?? null;
            $d['since'] = $s['since'] ?? 0;
            if ($s !== null && $s['notified']) {
                $d['status'] = 'critical';
            } elseif ($s !== null) {
                $d['status'] = 'low';
            } elseif ($staleSec > 0 && $d['updated'] > 0 && ($now - $d['updated']) > $staleSec) {
                $d['status'] = 'stale';
            } elseif ($d['level'] === null && $d['flag'] === null) {
                $d['status'] = 'unknown';
            } else {
                $d['status'] = 'ok';
            }
            // Ein gemeldetes Gerät, das sich zusätzlich nicht mehr meldet, bleibt "critical".
        }
        unset($d);

        $order = ['critical' => 0, 'low' => 1, 'stale' => 2, 'unknown' => 3, 'ok' => 4];
        uasort($devices, function ($a, $b) use ($order) {
            $c = $order[$a['status']] <=> $order[$b['status']];
            if ($c !== 0) {
                return $c;
            }
            $la = $a['level'] ?? ($a['flag'] ? -1 : 101);
            $lb = $b['level'] ?? ($b['flag'] ? -1 : 101);
            return $la <=> $lb ?: strcasecmp($a['name'], $b['name']);
        });
        return $devices;
    }

    private function UpdateVisualization(array $devices): void
    {
        // Geräte mit Zuordnung "-" zählen nicht für Alarm und Meldeliste
        $reportable = array_filter($devices, fn ($d) => !in_array('-', $d['groups'], true));
        $counts = array_count_values(array_column($reportable, 'status'));
        $critical = $counts['critical'] ?? 0;
        $low = ($counts['low'] ?? 0) + $critical;

        $this->SetValue('DeviceCount', count($devices));
        $this->SetValue('CriticalCount', $critical);
        $this->SetValue('LowCount', $low);
        $this->SetValue('Alarm', $critical > 0);
        $names = array_map(fn ($d) => $this->DeviceLine($d), array_filter($reportable, fn ($d) => $d['status'] === 'critical'));
        $this->SetValue('CriticalList', count($names) ? implode("\n", $names) : 'Keine');

        $this->SetValue('Overview', $this->BuildHtml($devices));

        foreach ($this->GetGroups() as $g) {
            if (!$g['visualize'] || @$this->GetIDForIdent($g['ident']) === false) {
                continue;
            }
            $members = array_filter($devices, fn ($d) => in_array($g['name'], $d['groups'], true));
            $this->SetValue($g['ident'], $this->BuildHtml($members));
            $this->SetValue($g['alarmIdent'], count(array_filter($members, fn ($d) => $d['status'] === 'critical')) > 0);
        }
    }

    private function BuildHtml(array $devices): string
    {
        $counts = array_count_values(array_column($devices, 'status'));
        $critical = $counts['critical'] ?? 0;
        $low = ($counts['low'] ?? 0) + $critical;
        $threshold = $this->ReadPropertyInteger('Threshold');
        $onlyProblems = $this->ReadPropertyBoolean('HtmlOnlyProblems');
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $css = '<style>'
            . '.batm{width:100%;border-collapse:collapse;font-size:14px;color:inherit}'
            . '.batm th{text-align:left;font-weight:600;padding:6px 8px;border-bottom:1px solid rgba(128,128,128,.4)}'
            . '.batm td{padding:5px 8px;border-bottom:1px solid rgba(128,128,128,.15);vertical-align:middle}'
            . '.batm .loc{opacity:.6;font-size:12px}'
            . '.batm .bar{position:relative;width:110px;height:14px;border-radius:7px;background:rgba(128,128,128,.25);overflow:hidden;display:inline-block;vertical-align:middle}'
            . '.batm .bar span{position:absolute;left:0;top:0;bottom:0;border-radius:7px}'
            . '.batm .pct{display:inline-block;width:44px;text-align:right;margin-left:6px;font-variant-numeric:tabular-nums}'
            . '.batm .st{padding:2px 8px;border-radius:9px;font-size:12px;white-space:nowrap}'
            . '.st-critical{background:#d9363e;color:#fff}.st-low{background:#e8a317;color:#000}'
            . '.st-stale{background:#7a5cc2;color:#fff}.st-ok{background:#2e9d4f;color:#fff}.st-unknown{background:#888;color:#fff}'
            . '.batm-sum{margin:0 0 8px 0;font-size:14px}'
            . '@media (max-width:600px){.batm .hide-s{display:none}}'
            . '</style>';

        $html = $css . '<div class="batm-sum">' . count($devices) . ' Geräte · '
            . '<b>' . $critical . '</b> gemeldet · ' . $low . ' unter ' . $threshold . ' % · Stand ' . date('d.m.Y H:i') . '</div>';
        $html .= '<table class="batm"><tr><th>Gerät</th><th>Batterie</th><th class="hide-s">Letzte Meldung</th><th class="hide-s">Unter Schwelle seit</th><th>Status</th></tr>';

        foreach ($devices as $d) {
            if ($onlyProblems && $d['status'] === 'ok') {
                continue;
            }
            if ($d['level'] !== null) {
                $lvl = max(0, min(100, $d['level']));
                $color = $lvl < $threshold ? '#d9363e' : ($lvl < 50 ? '#e8a317' : '#2e9d4f');
                $bat = '<span class="bar"><span style="width:' . $lvl . '%;background:' . $color . '"></span></span><span class="pct">' . $lvl . ' %</span>';
                if ($d['flag']) {
                    $bat .= ' ⚠';
                }
            } else {
                $bat = $h($this->FlagText($d));
            }
            $html .= '<tr><td>' . $h($d['name']) . '<div class="loc">' . $h($d['location']) . '</div></td>'
                . '<td>' . $bat . '</td>'
                . '<td class="hide-s">' . ($d['updated'] > 0 ? date('d.m.Y H:i', $d['updated']) : '–') . '</td>'
                . '<td class="hide-s">' . ($d['since'] > 0 ? date('d.m.Y H:i', $d['since']) : '–') . '</td>'
                . '<td><span class="st st-' . $d['status'] . '">' . $h($this->StatusText($d['status'])) . '</span></td></tr>';
        }
        return $html . '</table>';
    }

    private function FlagText(array $d): string
    {
        if ($d['text'] !== null && $d['flag'] === null) {
            return $d['text'];
        }
        if ($d['flag'] === null) {
            return '-';
        }
        return $d['flag'] ? 'schwach' : 'OK';
    }

    private function StatusText(string $status): string
    {
        return [
            'critical' => 'Gemeldet',
            'low'      => 'Unter Schwelle',
            'stale'    => 'Keine Meldung',
            'unknown'  => 'Unbekannt',
            'ok'       => 'OK'
        ][$status] ?? $status;
    }

    private function RowColor(string $status): string
    {
        return [
            'critical' => '#FFC7C7',
            'low'      => '#FFE8B0',
            'stale'    => '#E3D9F7'
        ][$status] ?? '';
    }

    private function DeviceLine(array $d): string
    {
        $value = $d['level'] !== null ? $d['level'] . ' %' : $this->FlagText($d);
        return $d['name'] . ($d['location'] !== '' ? ' (' . $d['location'] . ')' : '') . ': ' . $value;
    }

    // ------------------------------------------------------------------
    // Benachrichtigung
    // ------------------------------------------------------------------

    /**
     * Verteilt Meldungen gemäß Meldestruktur: je Gruppe eine Nachricht an deren Ziele,
     * nicht zugeordnete Geräte an die Standardziele, "-" wird nicht gemeldet.
     */
    private function Notify(string $title, array $devices, callable $line): void
    {
        $groups = $this->GetGroups();
        $buckets = [];
        foreach ($devices as $d) {
            $targets = $d['groups'] ?? [];
            if (in_array('-', $targets, true)) {
                continue;
            }
            if (count($targets) === 0) {
                $targets = [''];
            }
            foreach ($targets as $t) {
                if ($t !== '' && !$groups[$t]['notify']) {
                    continue;
                }
                $buckets[$t][] = $d;
            }
        }
        foreach ($buckets as $t => $list) {
            $lines = array_map($line, $list);
            if ($t === '') {
                $this->Send($title, $lines, $list, ...$this->DefaultTargets());
            } else {
                $this->Send('[' . $t . '] ' . $title, $lines, $list, ...$this->GroupTargets($groups[$t]));
            }
        }
    }

    private function Send(string $title, array $lines, array $devices, int $visu, int $smtp, int $script): void
    {
        $text = implode("\n", $lines);
        $this->LogMessage($title . ': ' . str_replace("\n", '; ', $text), KL_WARNING);

        if ($visu > 0 && IPS_InstanceExists($visu)) {
            $short = mb_substr($text, 0, 250);
            try {
                if (function_exists('VISU_PostNotification') && $this->IsTileVisu($visu)) {
                    VISU_PostNotification($visu, $title, $short, 'Battery', $this->InstanceID);
                } elseif (function_exists('WFC_PushNotification')) {
                    WFC_PushNotification($visu, mb_substr($title, 0, 32), $short, '', $this->InstanceID);
                }
            } catch (Throwable $e) {
                $this->LogMessage('Push fehlgeschlagen: ' . $e->getMessage(), KL_ERROR);
            }
        }

        if ($smtp > 0 && IPS_InstanceExists($smtp) && function_exists('SMTP_SendMail')) {
            try {
                SMTP_SendMail($smtp, $title, $text);
            } catch (Throwable $e) {
                $this->LogMessage('E-Mail fehlgeschlagen: ' . $e->getMessage(), KL_ERROR);
            }
        }

        if ($script > 0 && IPS_ScriptExists($script)) {
            IPS_RunScriptEx($script, [
                'TITLE'   => $title,
                'TEXT'    => $text,
                'DEVICES' => json_encode($devices)
            ]);
        }
    }

    private function IsTileVisu(int $id): bool
    {
        $info = IPS_GetInstance($id)['ModuleInfo'];
        // Kachel-Visualisierung (IPS 7+) – WebFront hat {3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}
        return strcasecmp($info['ModuleID'], '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}') !== 0;
    }

    private function ScheduleSummary(): void
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('SummaryEnabled')) {
            $this->SetTimerInterval('SummaryTimer', 0);
            return;
        }
        $t = json_decode($this->ReadPropertyString('SummaryTime'), true) ?: ['hour' => 8, 'minute' => 0, 'second' => 0];
        $next = mktime((int) $t['hour'], (int) $t['minute'], (int) ($t['second'] ?? 0));
        if ($next <= time() + 5) {
            $next = strtotime('+1 day', $next);
        }
        $this->SetTimerInterval('SummaryTimer', ($next - time()) * 1000);
    }
}
