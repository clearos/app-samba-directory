<?php

/**
 * Samba Directory accounts driver class.
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

clearos_load_language('samba_common');
clearos_load_language('samba_directory');
clearos_load_language('accounts');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\accounts\Accounts_Engine as Accounts_Engine;
use \clearos\apps\base\File as File;
use \clearos\apps\base\Folder as Folder;
use \clearos\apps\base\Shell as Shell;

clearos_load_library('accounts/Accounts_Engine');
clearos_load_library('base/File');
clearos_load_library('base/Folder');
clearos_load_library('base/Shell');

// Exceptions
//-----------

use \Exception as Exception;
use \clearos\apps\base\Engine_Exception as Engine_Exception;

clearos_load_library('base/Engine_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Samba Directory accounts driver class.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

class Accounts_Driver extends Accounts_Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const DRIVER_NAME = 'samba_directory';
    const COMMAND_AUTHCONFIG = '/usr/sbin/authconfig';
    const COMMAND_WBINFO = '/usr/bin/wbinfo';
    const FILE_INITIALIZING = '/var/clearos/samba_directory/lock/initializing';
    const PATH_EXTENSIONS = '/var/clearos/samba_directory/extensions';

    // Status codes for username/group/alias uniqueness
    const STATUS_ALIAS_EXISTS = 'alias';
    const STATUS_GROUP_EXISTS = 'group';
    const STATUS_USERNAME_EXISTS = 'user';
    const STATUS_UNIQUE = 'unique';

    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $config = NULL;
    protected $modes = NULL;
    protected $extensions = array();
    protected $reserved_ids = array('root', 'administrator', 'krbtgt', 'guest');

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Accounts driver constructor.
     */

    public function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->modes = array(
            self::MODE_MASTER => lang('accounts_master'),
            self::MODE_SLAVE => lang('accounts_slave'),
            self::MODE_STANDALONE => lang('accounts_standalone')
        );

        parent::__construct();
    }

    /**
     * Returns capabililites.
     *
     * @return string capabilities
     */

    public function get_capability()
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->get_mode() == self::MODE_SLAVE)
            return Accounts_Engine::CAPABILITY_READ_ONLY;
        else
            return Accounts_Engine::CAPABILITY_READ_WRITE;
    }

    /**
     * Returns state of driver.
     *
     * @return boolean state of driver
     * @throws Engine_Exception
     */

    public function get_driver_status()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->_get_driver_status(self::DRIVER_NAME);
    }

    /**
     * Returns list of directory extensions.
     *
     * @return array extension list
     * @throws Engine_Exception
     */

    public function get_extensions()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! empty($this->extensions))
            return $this->extensions;

        $folder = new Folder(self::PATH_EXTENSIONS);

        $list = $folder->get_listing();

        foreach ($list as $extension_file) {
            if (preg_match('/\.php$/', $extension_file)) {
                $extension = array();
                include self::PATH_EXTENSIONS . '/' . $extension_file;
                $this->extensions[$extension['extension']] = $extension;
            }
        }

        return $this->extensions;
    }

    /**
     * Returns the mode of the accounts engine.
     *
     * The return values are:
     * - Accounts_Engine::MODE_STANDALONE
     * - Accounts_Engine::MODE_MASTER
     * - Accounts_Engine::MODE_SLAVE
     *
     * @return string mode of the directory
     * @throws Engine_Exception
     */

    public function get_mode()
    {
        clearos_profile(__METHOD__, __LINE__);

        // TODO - handle master/slave (primary/secondary)
        return self::MODE_MASTER;
    }

    /**
     * Returns a list of available modes.
     *
     * @return array list of modes
     * @throws Engine_Exception
     */

    public function get_modes()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->modes;
    }

    /**
     * Returns status of account system.
     *
     * - Accounts_Engine::STATUS_INITIALIZING
     * - Accounts_Engine::STATUS_UNINITIALIZED
     * - Accounts_Engine::STATUS_OFFLINE
     * - Accounts_Engine::STATUS_ONLINE
     *
     * @return string account system status
     * @throws Engine_Exception
     */

    public function get_system_status()
    {
        clearos_profile(__METHOD__, __LINE__);

        // Check initializing
        //-------------------

        $file = new File(self::FILE_INITIALIZING);

        if ($file->exists()) {
            $initializing_lock = @fopen(self::FILE_INITIALIZING, 'r');
            if (!flock($initializing_lock, LOCK_SH | LOCK_NB))
                return Accounts_Engine::STATUS_INITIALIZING;
        }

        // Check initialized
        //------------------

        if (! $this->is_initialized())
            return Accounts_Engine::STATUS_UNINITIALIZED;

        // Ping DC
        //--------

        $options['validate_exit_code'] = FALSE;
        $shell = new Shell();

        for ($inx = 1; $inx < 3; $inx++) {
            try {
                $retval = $shell->execute(self::COMMAND_WBINFO, '-p', FALSE, $options);

                if ($retval === 0)
                    return Accounts_Engine::STATUS_ONLINE;
            } catch (Exception $e) {
                // Can fail intermittently... normal
            }
        }

        return Accounts_Engine::STATUS_OFFLINE;
    }

    /**
     * Check for reserved usernames, groups and aliases in the directory.
     *
     * @param string $id username, group or alias
     *
     * @return boolean TRUE if ID is reserved
     */

    public function is_reserved_id($id)
    {
        clearos_profile(__METHOD__, __LINE__);

        foreach ($this->reserved_ids as $reserved_id) {
            if (preg_match('/' . $reserved_id . '/i', $id))
                return TRUE;

        }

        return FALSE;
    }

    /**
     * Check for reserved usernames, groups and aliases in the directory.
     *
     * @param string $id username, group or alias
     *
     * @return string warning message if ID is reserved
     */

    public function is_reserved_id_message($id)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->is_reserved_id($id))
            return lang('accounts_reserved_for_system_use');
        else
            return '';
    }

    /**
     * Check for overlapping usernames, groups and aliases in the directory.
     *
     * @param string $id                     username, group or alias
     * @param string $ignore_aliases_for_uid ignore aliases for given uid
     *
     * @return string warning type if ID is not unique
     */

    public function is_unique_id($id, $ignore_aliases_for_uid = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        // FIXME-- implement
        return self::STATUS_UNIQUE;
    }

    /**
     * Check for overlapping usernames, groups and aliases in the directory.
     *
     * @param string $id                     username, group or alias
     * @param string $ignore_aliases_for_uid ignore aliases for given uid
     *
     * @return string warning message if ID is not unique
     */

    public function is_unique_id_message($id, $ignore_aliases_for_uid = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        $status = $this->is_unique_id($id, $ignore_aliases_for_uid);

        if ($status === self::STATUS_USERNAME_EXISTS)
            return lang('accounts_username_with_this_name_exists');
        else if ($status === self::STATUS_ALIAS_EXISTS)
            return lang('accounts_alias_with_this_name_exists');
        else if ($status === self::STATUS_GROUP_EXISTS)
            return lang('accounts_group_with_this_name_exists');
        else
            return '';
    }

    /**
     * Restarts the relevant daemons in a sane order.
     *
     * @return void
     */

    public function synchronize()
    {
        clearos_profile(__METHOD__, __LINE__);
    }
}
