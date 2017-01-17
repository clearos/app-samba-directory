<?php

/////////////////////////////////////////////////////////////////////////////
// General information
/////////////////////////////////////////////////////////////////////////////

$app['basename'] = 'samba_directory';
$app['version'] = '2.3.0';
$app['release'] = '1';
$app['vendor'] = 'ClearFoundation';
$app['packager'] = 'ClearFoundation';
$app['license'] = 'GPLv3';
$app['license_core'] = 'LGPLv3';
$app['description'] = lang('samba_directory_app_description');

/////////////////////////////////////////////////////////////////////////////
// App name and categories
/////////////////////////////////////////////////////////////////////////////

$app['name'] = lang('samba_directory_app_name');
$app['category'] = lang('base_category_server');
$app['subcategory'] = lang('base_subcategory_directory');

/////////////////////////////////////////////////////////////////////////////
// Packaging
/////////////////////////////////////////////////////////////////////////////

$app['core_provides'] = array(
    'system-ldap-driver',
    'system-windows-driver',
    'system-accounts',
    'system-accounts-driver',
    'system-groups-driver',
    'system-users-driver',
);

$app['core_requires'] = array(
    'app-accounts >= 1:1.5.5',
    'app-groups-core >= 1:1.5.10',
    'app-ldap-core >= 1:1.5.5',
    'app-mode-core',
    'app-network-core',
    'app-ntp-core',
    'app-samba-common-core >= 1:2.3.0',
    'krb5-workstation',
    'nscd',
    'samba >= 4.4.4',
    'samba-dc',
    'samba-client',
    'samba-winbind-clients',
);

$app['requires'] = array(
    'app-users',
    'app-groups => 1:1.2.3',
);

$app['core_directory_manifest'] = array(
    '/var/clearos/samba_directory' => array(),
    '/var/clearos/samba_directory/backup' => array(),
    '/var/clearos/samba_directory/extensions' => array(),
    '/var/clearos/samba_directory/lock' => array(
        'mode' => '0775',
        'owner' => 'root',
        'group' => 'webconfig',
    ),
);

$app['core_file_manifest'] = array(
    'samba.php'=> array('target' => '/var/clearos/base/daemon/samba.php'),
    'samba_directory.php' => array('target' => '/var/clearos/accounts/drivers/samba_directory.php'),
    'app-samba-directory-sudoers' => array('target' => '/etc/sudoers.d/app-samba-directory'),
    'app-samba-dc-initialize' => array(
        'target' => '/usr/sbin/app-samba-dc-initialize',
        'mode' => '0755'
    ),
);
