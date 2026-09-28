<?php
declare(strict_types=1);

function astera_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

define('PBX_HOST', astera_env('ASTERA_PBX_HOST', '192.168.181.74'));
define('PBX_SSH_HOST', astera_env('ASTERA_PBX_SSH_HOST', PBX_HOST));
define('PANEL_DOMAIN', astera_env('ASTERA_PANEL_DOMAIN', 'santral.astera.com.tr'));
// Dinamik WAN IP sabit yazılırsa REGISTER Contact eski adrese yönlenir.
// Boş bırakıldığında operatör REGISTER paketinin kaynak NAT adresini kullanır.
define('PBX_PUBLIC_IP', astera_env('ASTERA_PBX_PUBLIC_IP', ''));
define('PBX_LOCAL_NET', astera_env('ASTERA_PBX_LOCAL_NET', '192.168.181.0/24'));
define('SSH_USER', astera_env('ASTERA_SSH_USER', 'root'));
define('SSH_PASS', astera_env('ASTERA_SSH_PASS', 'nt1975tn'));
define('SSH_KEY', astera_env('ASTERA_SSH_KEY', ''));
define('SSH_KNOWN_HOSTS', astera_env('ASTERA_SSH_KNOWN_HOSTS', ''));
define('AMI_PORT', 5038);
define('AMI_USER', astera_env('ASTERA_AMI_USER', 'asterisk22web'));
define('AMI_SECRET', astera_env('ASTERA_AMI_SECRET', 'Nt1975AmiWeb'));
define('PBX_DB_NAME', astera_env('ASTERA_DB_NAME', 'asterisk'));
define('WEB_USER', astera_env('ASTERA_WEB_USER', 'admin'));
define('WEB_PASS', astera_env('ASTERA_WEB_PASS', 'nt1975tn'));
define('ROOT_PATH', __DIR__);
define('DATA_PATH', ROOT_PATH . DIRECTORY_SEPARATOR . 'data');
define('GEN_PATH', DATA_PATH . DIRECTORY_SEPARATOR . 'generated');
define('PLINK', ROOT_PATH . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'plink.exe');
define('PSCP', ROOT_PATH . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'pscp.exe');
define('PBX_HOSTKEY', 'SHA256:oQRYnla9Xad+iGjFD7ZzWs5Jnb9knypLVT7ldbsn/es');
