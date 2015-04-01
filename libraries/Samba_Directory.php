<?php

/**
 * Samba Directory class.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

///////////////////////////////////////////////////////////////////////////////
// N A M E S P A C E
///////////////////////////////////////////////////////////////////////////////

namespace clearos\apps\samba_directory;

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('base');
clearos_load_language('samba_directory');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\accounts\Accounts_Configuration as Accounts_Configuration;
use \clearos\apps\base\Engine as Engine;
use \clearos\apps\base\File as File;
use \clearos\apps\base\Folder as Folder;
use \clearos\apps\base\Shell as Shell;
use \clearos\apps\network\Iface_Manager as Iface_Manager;
use \clearos\apps\network\Resolver as Resolver;
use \clearos\apps\samba_common\Samba as Samba;
use \clearos\apps\samba_directory\Accounts_Driver as Accounts_Driver;
use \clearos\apps\samba_directory\Samba_Daemon as Samba_Daemon;

clearos_load_library('accounts/Accounts_Configuration');
clearos_load_library('base/Engine');
clearos_load_library('base/File');
clearos_load_library('base/Folder');
clearos_load_library('base/Shell');
clearos_load_library('network/Iface_Manager');
clearos_load_library('network/Resolver');
clearos_load_library('samba_common/Samba');
clearos_load_library('samba_directory/Accounts_Driver');
clearos_load_library('samba_directory/Samba_Daemon');

// Exceptions
//-----------

use \clearos\apps\base\Engine_Exception as Engine_Exception;
use \clearos\apps\base\Validation_Exception as Validation_Exception;

clearos_load_library('base/Engine_Exception');
clearos_load_library('base/Validation_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Samba Directory class.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

class Samba_Directory extends Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const COMMAND_AUTHCONFIG = '/usr/sbin/authconfig';
    const COMMAND_SAMBA_TOOL = '/usr/bin/samba-tool';
    const COMMAND_SAMBA_INITIALIZE = '/usr/sbin/app-samba-dc-initialize';
    const PATH_BACKUP = '/var/clearos/samba_directory/backup';
    const PATH_SAMBA_DATA = '/var/lib/samba';
    const FILE_STATUS = '/var/clearos/samba_directory/status';
    const FILE_CONFIG = '/etc/samba/smb.conf';
    const FILE_INITIALIZED = '/var/clearos/samba_directory/initialized';
    const FILE_INITIALIZE_LOG = 'samba_initialize.log';
    const FILE_INITIALIZING = '/var/clearos/samba_directory/lock/initializing';
    const FILE_KERBEROS_CONFIG = '/etc/krb5.conf';
    const FILE_KERBEROS_PROVISIONED = '/var/lib/samba/private/krb5.conf';
    const FILE_LDAP_CONFIG = '/var/clearos/samba_directory/ldap.conf';
    const CONSTANT_PASSWORD_MIN_LENGTH = 7;
    const CONSTANT_ENCRYPTION = 'AES-256-CBC';
    const CONSTANT_VECTOR = 'a39df391818812bc';
    const CONSTANT_HASH = '4120a7442f298b9b68194a4ba5e0dffd';
    const HOME_DIR_USERNAME_ONLY = 'username_only';
    const HOME_DIR_WITH_DOMAIN = 'with_domain';
    const DEFAULT_SERVER_COMMENT = 'ClearOS Server';

    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $home_dirs = array();

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Samba Directory constructor.
     */

    public function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->home_dirs = array(
            self::HOME_DIR_USERNAME_ONLY => lang('samba_directory_username_only') . ' - ' . '/home/' . lang('base_username'),
            self::HOME_DIR_WITH_DOMAIN => lang('samba_directory_with_domain') . ' - ' . '/home/' . lang('samba_common_windows_domain') . '/' . lang('base_username'),
        );
    }

    /**
     * Returns the Windows domain.
     *
     * @return string Windows domain
     * @throws Engine_Exception, Directory_Unavailable_Exception
     */

    public function get_domain()
    {
        clearos_profile(__METHOD__, __LINE__);

        $samba = new Samba();

        return $samba->get_workgroup();
    }

    /**
     * Returns state of internal DNS server.
     *
     * @return boolean TRUE if internal DNS is running
     * @throws Engine_Exception
     */

    public function get_dns_state()
    {
        clearos_profile(__METHOD__, __LINE__);

        $samba = new Samba_Daemon();

        return $samba->get_running_state();
    }

    /**
     * Returns home directory template.
     *
     * @return array home directory template
     * @throws Engine_Exception
     */

    public function get_home_directory_template()
    {
        clearos_profile(__METHOD__, __LINE__);

        $samba = new Samba();
        $template = $samba->get_home_directory_template();

        if ($template == '/home/%ACCOUNTNAME%')
            return self::HOME_DIR_USERNAME_ONLY;
        else if ($template == '/home/%WORKGROUP%/%ACCOUNTNAME%')
            return self::HOME_DIR_WITH_DOMAIN;
        else
            return $template;
    }

    /**
     * Returns home directory template options.
     *
     * @return array home directory template options
     * @throws Engine_Exception
     */

    public function get_home_directory_templates()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->home_dirs;
    }

    /**
     * Returns the ADS realm.
     *
     * @return string ADS realm
     * @throws Engine_Exception, Directory_Unavailable_Exception
     */

    public function get_realm()
    {
        clearos_profile(__METHOD__, __LINE__);

        $samba = new Samba();

        return $samba->get_realm();
    }

    /**
     * Returns connection status.
     *
     * @return string connection status message
     */

    public function get_connection_status()
    {
        clearos_profile(__METHOD__, __LINE__);

        $file = new File(CLEAROS_TEMP_DIR . '/' . self::FILE_INITIALIZE_LOG);

        if ($file->exists()) {
            $lines = $file->get_contents_as_array();
            krsort($lines);
            foreach ($lines as $line) {
                if (preg_match('/^[^\s]/', $line))
                    return $line;
            }
        }

        return '';
    }

    /**
     * Initializes directory.
     *
     * @param string $password      administrator password
     * @param string $domain        Windows domain, for example DIRECTORY
     * @param string $realm         realm, for example DIRECTORY
     * @param string $netbios_name  netbios name (server name)
     * @param string $server_string server string
     * @param string $home_dirs     home directory template
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function initialize($password, $domain, $realm, $netbios_name = NULL, $server_string = NULL, $home_dirs = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_password($password));
        Validation_Exception::is_valid($this->validate_domain($domain));
        Validation_Exception::is_valid($this->validate_realm($realm));

        if (! is_null($netbios_name))
            Validation_Exception::is_valid($this->validate_netbios_name($netbios_name));

        if (! is_null($server_string))
            Validation_Exception::is_valid($this->validate_server_string($server_string));

        if (! is_null($home_dirs))
            Validation_Exception::is_valid($this->validate_home_directory_templates($home_dirs));

        // Bail if initialized
        //--------------------

        $driver = new Accounts_Driver();

        if ($driver->is_initialized())
            return;

        // Lock state file
        //----------------

        $initializing_lock = fopen(self::FILE_INITIALIZING, 'w');

        if (!flock($initializing_lock, LOCK_EX | LOCK_NB)) {
            clearos_log('samba_directory', 'local initialization is already running');
            return;
        }

        // Move smb.conf out of the way
        //-----------------------------

        $file = new File(self::FILE_CONFIG);

        if ($file->exists())
            $file->move_to(self::PATH_BACKUP . '/smb-' . date('Y-M-d-H:i:s') . '.conf');

        // Disable built-in DNS server
        //----------------------------

        if (clearos_library_installed('dns/Dnsmasq')) {
            clearos_load_library('dns/Dnsmasq');
            $dnsmasq = new \clearos\apps\dns\Dnsmasq();
            $dnsmasq->set_state(FALSE);

            // Do a hard restart
            if ($dnsmasq->get_running_state()) {
                $dnsmasq->set_running_state(FALSE);
                $dnsmasq->set_running_state(TRUE);
            }
        }

        // Grab a LAN IP
        //--------------

        $iface_manager = new Iface_Manager();
        $trusted_ips = $iface_manager->get_most_trusted_ips();

        // If server name is not set, provision will use hostname 
        //-------------------------------------------------------

        $hostname_parameter = empty($netbios_name) ? '' : ' --host-name=\'' . $netbios_name . '\'';
        $server_string = empty($server_string) ? self::DEFAULT_SERVER_COMMENT : $server_string;

        if ($home_dirs == self::HOME_DIR_USERNAME_ONLY)
            $home_dirs_option = ' --option=\'template homedir = /home/%ACCOUNTNAME%\' ';
        else if ($home_dirs == self::HOME_DIR_WITH_DOMAIN)
            $home_dirs_option = ' --option=\'template homedir = /home/%WORKGROUP%/%ACCOUNTNAME%\' ';
        else
            $home_dirs_option = '';

        // Shutdown Samba if running
        //--------------------------

        $samba_daemon = new Samba_Daemon();

        if ($samba_daemon->get_running_state())
            $samba_daemon->set_running_state(FALSE);

        // Clear out old data
        //-------------------

        $folder = new Folder(self::PATH_SAMBA_DATA);

        $file_list = $folder->get_recursive_listing();

        foreach ($file_list as $filename) {
            $file = new File(self::PATH_SAMBA_DATA . '/' . $filename, TRUE);
            $file->delete();
        }

        // Provision Samba
        //----------------

        $options['validate_exit_code'] = FALSE;
        $options['log'] = self::FILE_INITIALIZE_LOG;

        $shell = new Shell();
        $retval = $shell->execute(self::COMMAND_SAMBA_TOOL,
            'domain provision ' .
            ' --realm=' . $realm . 
            ' --domain=' . $domain . 
            ' --adminpass=\'' . $password . '\'' .
            ' --server-role=dc ' . 
            ' --use-rfc2307 ' . 
            ' --host-ip=' . $trusted_ips[0] .
            $hostname_parameter .
            $home_dirs_option .
            ' --option=\'server string = ' . $server_string . '\'' .
            ' --option=\'winbind enum users = yes\' ' .
            ' --option=\'winbind enum groups = yes\' ' .
            ' --option=\'winbind separator = +\' ' .
            ' --option=\'wins support = yes\' ' .
            ' --option=\'wins server =\' ' .
            ' --option=\'passwd program = /usr/sbin/userpasswd %u\' ' .
            ' --option=\'passwd chat = *password:* %n\n *password:* %n\n *successfully.* \' ' .
            ' --option=\'passwd chat timeout = 10\' ' .
            ' --option=\'utmp = yes\' ',
            TRUE,
            $options
        );

        if ($retval != 0)
            throw new Engine_Exception($this->get_connection_status());

        // Save LDAP configuration
        //------------------------

        $file = new File(self::FILE_LDAP_CONFIG);
        if ($file->exists())
            $file->delete();

        $file->create('root', 'webconfig', '0640');
        $encrypted_password = base64_encode(openssl_encrypt($password, self::CONSTANT_ENCRYPTION, self::CONSTANT_HASH, TRUE, self::CONSTANT_VECTOR));
        // $password = openssl_decrypt($encrypted_password, self::CONSTANT_ENCRYPTION, self::CONSTANT_HASH, TRUE, self::CONSTANT_VECTOR);
        
        $config_data = "mode = primary\n";
        $config_data .= "base_dn = dc=" . strtolower(preg_replace('/\./', ',dc=', $realm)) . "\n";
        $config_data .= "bind_dn = Administrator@$realm\n";
        $config_data .= "bind_pw_hash1 = $encrypted_password\n";

        $file->add_lines($config_data);

        // Set DNS forwarder
        //------------------

        $resolver = new Resolver();
        $forwarders = $resolver->get_nameservers();

        $samba_common = new Samba();
        $samba_common->set_dns_forwarder($forwarders[0]);

        // Provision Kerberos
        //-------------------

        $existing_config = new File(self::FILE_KERBEROS_CONFIG);
        $provisioned_config = new File(self::FILE_KERBEROS_PROVISIONED, TRUE);

        if ($provisioned_config->exists()) {
            if ($existing_config->exists())
                $existing_config->move_to(self::PATH_BACKUP . '/krb5-' . date('Y-M-d-H:i:s') . '.conf');

            $provisioned_config->copy_to(self::FILE_KERBEROS_CONFIG);
        }

        // Set accounts driver at this point (no later to avoid port conflict with OpenLDAP)
        //----------------------------------------------------------------------------------

        $driver_config = new Accounts_Configuration();
        $driver_config->set_driver(Accounts_Driver::DRIVER_NAME);

        if (clearos_library_installed('openldap/LDAP_Driver')) {
            clearos_load_library('openldap/LDAP_Driver');

            $openldap = new \clearos\apps\openldap\LDAP_Driver();
            $openldap->reset(FALSE);
        }

        // Start Samba
        //------------

        $samba = new Samba_Daemon();
        $samba->set_running_state(TRUE);
        $samba->set_boot_state(TRUE);

        // Run authconfig to handle the settings changes
        //----------------------------------------------

        $realm = strtoupper($realm);
        $domain = strtoupper($domain);

        $params = '--enableshadow --passalgo=sha512 ' .
            '--enablecache --enablelocauthorize --enablemkhomedir ' .
            '--enablewinbind --enablewinbindauth ' .
            '--disableldap --disableldapauth ' .
            '--krb5kdc=' . $realm . ' --krb5realm=' . $realm . ' ' .
            '--update';

        $shell = new Shell();
        $shell->execute(self::COMMAND_AUTHCONFIG, $params, TRUE);

        $driver->set_initialized();
        $driver->synchronize();

        // Cleanup file / file lock
        //-------------------------

        flock($initializing_lock, LOCK_UN);
        fclose($initializing_lock);

        $file = new File(self::FILE_INITIALIZING);

        if ($file->exists())
            $file->delete();

        $this->set_initialized();
    }

    /**
     * Runs initialize directory.
     *
     * @param string $password      administrator password
     * @param string $domain        Windows domain, for example DIRECTORY
     * @param string $realm         realm, for example DIRECTORY
     * @param string $netbios_name  netbios name (server name)
     * @param string $server_string server string
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function run_initialize($password, $domain, $realm, $netbios_name, $server_string, $home_dirs)
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_password($password));
        Validation_Exception::is_valid($this->validate_domain($domain));
        Validation_Exception::is_valid($this->validate_realm($realm));
        Validation_Exception::is_valid($this->validate_netbios_name($netbios_name));
        Validation_Exception::is_valid($this->validate_server_string($server_string));
        Validation_Exception::is_valid($this->validate_home_directory_templates($home_dirs));

        $options['background'] = TRUE;

        $params = "-r '$realm' -d '$domain' -n '$netbios_name' -c '$server_string' -p '$password' -t '$home_dirs'";

        $shell = new Shell();
        $shell->execute(self::COMMAND_SAMBA_INITIALIZE, $params, TRUE, $options);
    }

    /**
     * Sets initialized flag.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function set_initialized()
    {
        clearos_profile(__METHOD__, __LINE__);

        $file = new File(self::FILE_INITIALIZED);

        if (! $file->exists())
            $file->create('root', 'root', '0644');
    }

    ///////////////////////////////////////////////////////////////////////////////
    // V A L I D A T I O N   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Validation routine for domain.
     *
     * @param string $domain domain
     *
     * @return string error message if domain is invalid
     */

    public function validate_domain($domain)
    {
        clearos_profile(__METHOD__, __LINE__);

        $samba = new Samba();

        if (empty($domain))
            return lang('samba_directory_domain_invalid');

        if ($error_message = $samba->validate_workgroup($domain))
            return $error_message;
    }

    /**
     * Validation routine for home directory templates.
     *
     * @param string $template home directory templates
     *
     * @return string error message if home directory templates is invalid
     */

    public function validate_home_directory_templates($template)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! array_key_exists($template, $this->home_dirs))
            return lang('base_parameter_invalid');
    }

    /**
     * Validation routine for netbios name
     *
     * @param string $netbios_name netbios name
     *
     * @return string error message if netbios name is invalid
     */

    public function validate_netbios_name($netbios_name)
    {
        clearos_profile(__METHOD__, __LINE__);

        $samba = new Samba();

        return $samba->validate_netbios_name($netbios_name);
    }

    /**
     * Validation routine for password.
     *
     * @param string $password password
     *
     * @return string error message if password is invalid
     */

    public function validate_password($password)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (strlen($password) < self::CONSTANT_PASSWORD_MIN_LENGTH)
            return lang('samba_directory_password_must_be_7_or_more_characters');

        if (empty($password))
            return lang('base_password_is_invalid');
    }

    /**
     * Validation routine for realm.
     *
     * @param string $realm realm
     *
     * @return string error message if realm is invalid
     */

    public function validate_realm($realm)
    {
        clearos_profile(__METHOD__, __LINE__);

        $samba = new Samba();

        return $samba->validate_realm($realm);
    }

    /**
     * Validation routine for server string.
     *
     * @param string $server_string server string
     *
     * @return string error message if server string is invalid
     */

    public function validate_server_string($server_string)
    {
        clearos_profile(__METHOD__, __LINE__);

        $samba = new Samba();

        return $samba->validate_server_string($server_string);
    }
}
