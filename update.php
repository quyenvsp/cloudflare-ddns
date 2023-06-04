<?php

require __DIR__ . '/Cloudflare.php';

$confFile = __DIR__ . '/config.php';
if (!file_exists($confFile)) {
    echo "Missing config file. Please copy config.php.skel to config.php and fill out the values therein.\n";
    return 1;
}

$config = require $confFile;

foreach ([
             'cloudflare_email',
             'cloudflare_api_key',
             'is_token',
             'record_name',
             'ttl',
             'proxied',
             'protocol',
         ] as $key) {
    if (!isset($config[$key]) || $config[$key] === '') {
        echo "config.php is missing the '$key' config value\n";
        return 1;
    }
}

$api = new Cloudflare($config['cloudflare_email'], $config['cloudflare_api_key'], $config['is_token']);

$headers = getallheaders();
// map custom param support dyndns, no-ip,...
if (empty($_GET['ip'])) $_GET['ip'] = $_GET['myip'];
if (!isset($_GET['record']) && !empty($_GET['hostname'])) {
    $_GET['record'] = $_GET['hostname'];
}

// set record from request if value exists in config
$recordName = isset($_GET['record']) ? $_GET['record'] : null;
if (
    !$recordName ||
    !in_array($recordName, array_keys($config['record_name']))
) {
    echo "Missing 'record_name' param, or record_name is not in predefined list\n";
    return 1;
}

if (!empty($config['auth_token'])) {
    if (empty($_GET['auth_token']) && !empty($headers['Authorization'])) {
        $_GET['auth_token'] = $headers['Authorization'];
    }
    // API mode. Use IP from request params.
    if (
        empty($_GET['auth_token']) ||
        empty($_GET['ip']) ||
        $_GET['auth_token'] != $config['auth_token']
    ) {
        echo "Missing or invalid 'auth_token' param, or missing 'ip' param\n";
        return 1;
    }
    $ip = $_GET['ip'];
} else {
    // Local mode. Get IP from service.
    $ip = getIP($config['protocol']);
}

$verbose = !isset($argv[1]) || $argv[1] != '-s';

try {
    $zone = $config['record_name'][$recordName];
    if (!$zone) {
        echo "Zone of $recordName not found\n";
        return 1;
    }

    $records = $api->getZoneDnsRecords($zone, ['name' => $recordName]);
    $record = $records && $records[0]['name'] == $recordName ? $records[0] : null;

    # https://github.com/rrauenza/ez-ipupdate/blob/master/ez-ipupdate.c#LL1888C1-L2138C2
    if (!$record) {
        // No existing record found. Creating a new one
        $ret = $api->createDnsRecord($zone, 'A', $recordName, $ip, [
            'ttl' => $config['ttl'],
            'proxied' => $config['proxied'],
        ]);
        if ($verbose && !empty($ret['name'])) {
            printf("\ngood ");
        }
    } elseif (
        $record['type'] != 'A' ||
        $record['content'] != $ip ||
        $record['ttl'] != $config['ttl'] ||
        $record['proxied'] != $config['proxied']
    ) {
        // Updating record
        $ret = $api->updateDnsRecord($zone, $record['id'], [
            'type' => 'A',
            'name' => $recordName,
            'content' => $ip,
            'ttl' => $config['ttl'],
            'proxied' => $config['proxied'],
        ]);
        if ($verbose && !empty($ret['name'])) {
            printf("\ngood ");
        }
    } else {
        if ($verbose) {
            // Record appears OK. No need to update
            printf("\nnochg");
        }
    }
    return 0;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    return 1;
}

// http://stackoverflow.com/questions/3097589/getting-my-public-ip-via-api
// http://major.io/icanhazip-com-faq/
function getIP($protocol)
{
    $prefixes = [
        'ipv4' => 'ipv4.',
        'ipv6' => 'ipv6.',
        'auto' => '',
    ];
    if (!isset($prefixes[$protocol])) {
        throw new Exception('Invalid "protocol" config value.');
    }
    return trim(file_get_contents('http://' . $prefixes[$protocol] . 'icanhazip.com'));
}
