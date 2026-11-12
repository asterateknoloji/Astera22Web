<?php
declare(strict_types=1);

function geoip_continents(): array
{
    return [
        'EU' => [
            'name' => 'Avrupa',
            'countries' => explode(' ', 'AD AL AT AX BA BE BG BY CH CY CZ DE DK EE ES FI FO FR GB GG GI GR HR HU IE IM IS IT JE LI LT LU LV MC MD ME MK MT NL NO PL PT RO RS RU SE SI SJ SK SM TR UA VA XK'),
        ],
        'AS' => [
            'name' => 'Asya',
            'countries' => explode(' ', 'AE AF AM AZ BD BH BN BT CC CN CX GE HK ID IL IN IO IQ IR JO JP KG KH KP KR KW KZ LA LB LK MM MN MO MV MY NP OM PH PK PS QA SA SG SY TH TJ TL TM TW UZ VN YE'),
        ],
        'AF' => [
            'name' => 'Afrika',
            'countries' => explode(' ', 'AO BF BI BJ BW CD CF CG CI CM CV DJ DZ EG EH ER ET GA GH GM GN GQ GW KE KM LR LS LY MA MG ML MR MU MW MZ NA NE NG RE RW SC SD SH SL SN SO SS ST SZ TD TG TN TZ UG YT ZA ZM ZW'),
        ],
        'NA' => [
            'name' => 'Kuzey Amerika',
            'countries' => explode(' ', 'AG AI AW BB BL BM BQ BS BZ CA CR CU CW DM DO GD GL GP GT HN HT JM KN KY LC MF MQ MS MX NI PA PM PR SV SX TC TT US VC VG VI'),
        ],
        'SA' => [
            'name' => 'Güney Amerika',
            'countries' => explode(' ', 'AR BO BR CL CO EC FK GF GS GY PE PY SR UY VE'),
        ],
        'OC' => [
            'name' => 'Okyanusya',
            'countries' => explode(' ', 'AS AU CK FJ FM GU KI MH MP NC NF NR NU NZ PF PG PN PW SB TK TO TV UM VU WF WS'),
        ],
    ];
}

function geoip_country_name(string $countryCode): string
{
    $countryCode = strtoupper(trim($countryCode));
    if ($countryCode === 'XK') {
        return 'Kosova';
    }
    if (class_exists('Locale')) {
        $name = \Locale::getDisplayRegion('en_' . $countryCode, 'tr_TR');
        if (is_string($name) && $name !== '' && strtoupper($name) !== $countryCode) {
            return $name;
        }
    }
    static $isoNames = null;
    if ($isoNames === null) {
        $isoNames = [];
        $isoFile = '/usr/share/iso-codes/json/iso_3166-1.json';
        if (is_readable($isoFile)) {
            $decoded = json_decode((string) file_get_contents($isoFile), true);
            foreach ((array) ($decoded['3166-1'] ?? []) as $country) {
                $code = strtoupper((string) ($country['alpha_2'] ?? ''));
                $name = (string) ($country['common_name'] ?? $country['name'] ?? '');
                if ($code !== '' && $name !== '') {
                    $isoNames[$code] = $name;
                }
            }
        }
    }
    if (isset($isoNames[$countryCode])) {
        return $isoNames[$countryCode];
    }
    return $countryCode;
}

function geoip_all_country_codes(): array
{
    $codes = [];
    foreach (geoip_continents() as $continent) {
        foreach ($continent['countries'] as $code) {
            $codes[$code] = true;
        }
    }
    return array_keys($codes);
}

function geoip_normalize_policy_mode(mixed $mode): string
{
    $mode = strtolower(trim((string) $mode));
    return in_array($mode, ['allow_only', 'passthrough'], true) ? $mode : 'allow_only';
}

function geoip_default_rules(): array
{
    return [[
        'country' => 'TR',
        'action' => 'allow',
        'ipv4' => true,
        'ipv6' => true,
    ]];
}

function geoip_normalize_rules(mixed $rawRules): array
{
    if (is_string($rawRules)) {
        $decoded = json_decode($rawRules, true);
        $rawRules = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($rawRules)) {
        return [];
    }

    $known = array_fill_keys(geoip_all_country_codes(), true);
    $rules = [];
    foreach ($rawRules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $country = strtoupper(trim((string) ($rule['country'] ?? '')));
        $action = strtolower(trim((string) ($rule['action'] ?? '')));
        if (!isset($known[$country]) || !in_array($action, ['allow', 'block'], true)) {
            continue;
        }
        $ipv4 = !empty($rule['ipv4']);
        $ipv6 = !empty($rule['ipv6']);
        if (!$ipv4 && !$ipv6) {
            continue;
        }
        $rules[$country] = [
            'country' => $country,
            'action' => $action,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
        ];
    }
    ksort($rules);
    return array_values($rules);
}

function geoip_rules_by_set(array $rules): array
{
    $sets = [
        'allow_ipv4' => [],
        'block_ipv4' => [],
        'allow_ipv6' => [],
        'block_ipv6' => [],
    ];
    foreach (geoip_normalize_rules($rules) as $rule) {
        if ($rule['ipv4']) {
            $sets[$rule['action'] . '_ipv4'][] = strtolower($rule['country']);
        }
        if ($rule['ipv6']) {
            $sets[$rule['action'] . '_ipv6'][] = strtolower($rule['country']);
        }
    }
    return $sets;
}

function geoip_remote_status(): array
{
    $result = pbx_ssh(
        'if [ -x /usr/local/sbin/astera-geoip ]; then '
        . '/usr/local/sbin/astera-geoip status; '
        . 'else printf %s ' . escapeshellarg('{"installed":false}') . '; fi'
    );
    $status = json_decode((string) ($result['output'] ?? ''), true);
    if (!is_array($status)) {
        $status = ['installed' => false, 'error' => (string) ($result['output'] ?? '')];
    }
    return $status;
}

function geoip_install_remote_helper(): array
{
    $local = dirname(__DIR__) . '/bin/geoip_root.sh';
    $remote = '/tmp/astera-geoip-' . bin2hex(random_bytes(5));
    $upload = pbx_upload($local, $remote);
    if (empty($upload['ok'])) {
        return $upload;
    }
    return pbx_ssh(
        "set -e; sed -i 's/\\r$//' " . escapeshellarg($remote)
        . '; install -o root -g root -m 0750 ' . escapeshellarg($remote)
        . ' /usr/local/sbin/astera-geoip; rm -f ' . escapeshellarg($remote)
        . '; if systemctl list-unit-files astera-geoip.service --no-legend 2>/dev/null '
        . "| grep -q '^astera-geoip\\.service'; then "
        . '/usr/local/sbin/astera-geoip firewall; '
        . 'else /usr/local/sbin/astera-geoip install; fi'
    );
}
