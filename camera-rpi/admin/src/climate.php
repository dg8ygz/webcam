<?php
declare(strict_types=1);

/** Display-only labels; authoritative defaults/ranges come from the root validator. */
function climate_labels(): array
{
    return [
        'dew_margin_c' => ['Taupunktabstand (°C)', 'Heizen, wenn Lufttemperatur minus Taupunkt höchstens diesen Wert erreicht.'],
        'hysteresis_c' => ['Hysterese (°C)', 'Ausschalten erst oberhalb Taupunktabstand plus Hysterese. Gilt auch für die Sommerkühlung.'],
        'off_delay_seconds' => ['Ausschaltverzögerung (s)', 'Der sichere Taupunktabstand muss so lange ununterbrochen bestehen. Sicherheitsabschaltungen warten nicht.'],
        'summer_c' => ['Sommerkühlung ab (°C)', 'Ungemittelte Temperatur: Heizung aus, Lüfter auf Sommer-PWM.'],
        'summer_fan_percent' => ['Sommer-PWM (%)', 'Lüfterleistung bei warmer Gehäuseluft.'],
        'fan_min_percent' => ['PWM-Minimum im Heizbetrieb (%)', 'Danach entspricht PWM der gemittelten Luftfeuchte, begrenzt durch Minimum und Maximum.'],
        'fan_max_percent' => ['PWM-Maximum im Heizbetrieb (%)', 'Anlaufimpuls und Sicherheitskühlung dürfen immer 100 % verwenden.'],
        'sensor_max_age_seconds' => ['Maximales Messwertalter (s)', 'Fehlerhafte oder veraltete Messpaare: Heizung sofort aus. Ohne Capture-Pause Lüfter 100 %.'],
        'average_samples' => ['Messpaare für Mittelwert / Anlauf', 'Nur neue erfolgreiche Messpaare zählen. Nach Sensorausfall wird der Puffer neu aufgebaut.'],
    ];
}

/** Auth/CSRF already checked by the controller; send bounded typed data, no paths or shell. */
function save_climate_form(): void
{
    $config = ['mode' => post_text('climate_mode', 16)];
    foreach (climate_labels() as $name => $_label) {
        $raw = post_text($name, 12);
        $decimal = in_array($name, ['dew_margin_c', 'hysteresis_c', 'summer_c'], true);
        if (!preg_match($decimal ? '/^[0-9]+(?:\.[0-9]+)?$/D' : '/^[0-9]+$/D', $raw)) {
            throw new RuntimeException("Ungültiger Zahlenwert: $name");
        }
        $config[$name] = $decimal ? (float)$raw : (int)$raw;
    }
    $payload = json_encode([
        'config' => $config,
        'revision' => post_text('climate_revision', 64),
    ], JSON_THROW_ON_ERROR);
    bridge_call(['action' => 'climate_save', 'climate_json' => $payload]);
}

/** Read-only settings/status; absence of the optional module must not hide target-server forms. */
function climate_view(): array
{
    try {
        $reply = bridge_call(['action' => 'climate_get']);
        $data = json_decode($reply['stdout'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['config'], $data['revision'], $data['limits'], $data['status'])) {
            throw new RuntimeException('Unvollständige Klima-Antwort.');
        }
        return $data;
    } catch (Throwable $error) {
        return ['error' => 'Klima-Modul noch nicht verfügbar oder Konfiguration ungültig. ' . $error->getMessage()];
    }
}
