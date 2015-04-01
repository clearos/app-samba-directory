<?php

/**
 * Samba Directory group driver.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012-2013 ClearCenter
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

clearos_load_language('groups');
clearos_load_language('samba_common');
clearos_load_language('samba_directory');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\base\Shell as Shell;
use \clearos\apps\groups\Group_Engine as Group_Engine;
use \clearos\apps\samba_directory\Accounts_Driver as Accounts_Driver;
use \clearos\apps\samba_directory\Samba_Directory as Samba_Directory;
use \clearos\apps\samba_directory\User_Manager_Driver as User_Manager_Driver;
use \clearos\apps\samba_directory\Utilities as Utilities;
use \clearos\apps\users\User_Engine as User_Engine;

clearos_load_library('base/Shell');
clearos_load_library('groups/Group_Engine');
clearos_load_library('samba_directory/Accounts_Driver');
clearos_load_library('samba_directory/Samba_Directory');
clearos_load_library('samba_directory/User_Manager_Driver');
clearos_load_library('samba_directory/Utilities');
clearos_load_library('users/User_Engine');

// Exceptions
//-----------

use \Exception as Exception;
use \clearos\apps\base\Engine_Exception as Engine_Exception;
use \clearos\apps\base\Validation_Exception as Validation_Exception;
use \clearos\apps\groups\Group_Not_Found_Exception as Group_Not_Found_Exception;

clearos_load_library('base/Engine_Exception');
clearos_load_library('base/Validation_Exception');
clearos_load_library('groups/Group_Not_Found_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Samba Directory group driver.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012-2013 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

class Group_Driver extends Group_Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const COMMAND_WBINFO = '/usr/bin/wbinfo';
    const COMMAND_SAMBA_TOOL = '/usr/bin/samba-tool';

    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $ldaph = NULL;
    protected $group_name = NULL;
    protected $extensions = array();
    protected $info_map = array();
    protected $usermap_dn = NULL;
    protected $usermap_username = NULL;

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Group constructor.
     *
     * @param string $group_name group name.
     *
     * @return void
     */

    public function __construct($group_name)
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->group_name = $group_name;

        include clearos_app_base('samba_directory') . '/deploy/group_map.php';

        $this->info_map = $info_map;
    }

    /**
     * Adds a group to the system.
     *
     * @param string $group_info group information
     * @param array  $members    member list
     *
     * @return void
     * @throws Validation_Exception, Engine_Exception
     */

    public function add($group_info)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));

        if ($this->exists()) {
            $info = $this->_load_group_info();

            if ($info['core']['type'] == Group_Engine::TYPE_WINDOWS)
                $warning = lang('groups_group_is_reserved_for_windows');
            else if ($info['core']['type'] == Group_Engine::TYPE_BUILTIN)
                $warning = lang('groups_group_is_reserved_for_system');
            else if ($info['core']['type'] == Group_Engine::TYPE_SYSTEM)
                $warning = lang('groups_group_is_reserved_for_system');
            else if ($info['core']['type'] == Group_Engine::TYPE_HIDDEN)
                $warning = lang('groups_group_is_reserved_for_system');
            else
                $warning = lang('groups_group_already_exists');

            throw new Validation_Exception($warning);
        }

        $accounts = new Accounts_Driver();
        $unique_warning = $accounts->is_unique_id_message($this->group_name);

        if ($unique_warning)
            throw new Validation_Exception($unique_warning);

        // TODO - deal with flexshare conflicts somehow

        // Convert array into LDAP object
        //-------------------------------

        // Only "description" is required, and it is handled by samba-tool for now.

        // Add LDAP attributes from extensions
        //------------------------------------

        // TODO: extensions

        // Add the group to directory
        //---------------------------

        $description = (empty($group_info['core']['description'])) ? '' : " --description='" . $group_info['core']['description'] . "'";

        $shell = new Shell();
        $params = "group add '" . $this->group_name . "'" . $description;
        $shell->execute(self::COMMAND_SAMBA_TOOL, $params, TRUE);

        $this->_signal_transaction(lang('accounts_added_group'));
    }

    /**
     * Adds a member to a group.
     *
     * @param string $username username
     *
     * @return FALSE if user was already a member
     * @throws Validation_Exception, Engine_Exception
     */

    public function add_member($username)
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));

        $members = $this->get_members();

        if (in_array($username, $members)) {
            return FALSE;
        } else {
            $members[] = $username;
            $this->set_members($members);    
            return TRUE;
        }

        $this->_signal_transaction(lang('accounts_added_member_to_group'));
    }

    /**
     * Deletes a group from the system.
     *
     * @return void
     * @throws Group_Not_Found_Exception, Engine_Exception
     */

    public function delete()
    {
        clearos_profile(__METHOD__, __LINE__);

        // TODO -- it would be nice to check to see if group is still in use
        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));

        if (! $this->exists())
            throw new Group_Not_Found_Exception($this->group_name);

        $shell = new Shell();
        $shell->execute(self::COMMAND_SAMBA_TOOL, "group delete '" . $this->group_name . "'", TRUE);

        $this->_signal_transaction(lang('accounts_deleted_group'));
    }

    /**
     * Deletes a member from a group.
     *
     * @param string $username username
     *
     * @return FALSE if user was already not a member
     * @throws Validation_Exception, Engine_Exception
     */

    public function delete_member($username)
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));

        $members = $this->get_members();

        if (in_array($username, $members)) {
            $newmembers = array();

            foreach ($members as $member) {
                if ($member != $username)
                    $newmembers[] = $member;
            }

            $this->set_members($newmembers);    
            return TRUE;
        } else {
            return FALSE;
        }

        $this->_signal_transaction(lang('accounts_deleted_member'));
    }

    /**
     * Checks the existence of the group.
     *
     * @return boolean TRUE if group exists
     * @throws Engine_Exception
     */

    public function exists()
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $this->_load_group_info();
        } catch (Group_Not_Found_Exception $e) {
            return FALSE;
        } catch (Exception $e) {
            throw new Engine_Exception($e->GetMessage(), COMMON_ERROR);
        }

        return TRUE;
    }

    /**
     * Returns a list of group members.
     *
     * @return array list of group members
     * @throws Group_Not_Found_Exception, Engine_Exception
     */

    public function get_members()
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));

        $info = $this->_load_group_info();

        return $info['core']['members'];
    }

    /**
     * Returns the group description.
     *
     * @return string group description
     * @throws Group_Not_Found_Exception, Engine_Exception
     */

    public function get_description()
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));

        $info = $this->_load_group_info();

        $description = (empty($info['description'])) ? '' : $info['description'];

        return $description;
    }

    /**
     * Returns the group information.
     *
     * @return array group information
     * @throws Group_Not_Found_Exception, Engine_Exception
     */

    public function get_info()
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));

        $info = $this->_load_group_info();

        return $info;
    }

    /**
     * Retrieves default information for a new group.
     *
     * @return array group details
     * @throws Engine_Exception
     */

    public function get_info_defaults()
    {
        clearos_profile(__METHOD__, __LINE__);

        // TODO: implementent extensions
    }

    /**
     * Retrieves full information map for group.
     *
     * @throws Engine_Exception
     *
     * @return array group details
     */

    public function get_info_map()
    {
        clearos_profile(__METHOD__, __LINE__);

        $info_map = array();

        $info_map['core'] = $this->info_map;

        // Add group info map from extensions
        //----------------------------------

        // TODO
    }

    /**
     * Sets the group member list.
     *
     * @param array $members array of group members
     *
     * @return void
     * @throws Group_Not_Found_Exception, Engine_Exception, Validation_Exception
     */

    public function set_members($members)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));

        if (! $this->exists())
            throw new Group_Not_Found_Exception($this->group_name);

        // Check for invalid users
        //------------------------

        $user_manager = new User_Manager_Driver();
        $user_list = $user_manager->get_list(User_Engine::FILTER_ALL);

        $valid_members = array();

        foreach ($members as $user) {
            if (in_array($user, $user_list))
                $valid_members[] = $user;
        }

        $members_list = implode(',', $valid_members);
        $purge_list = implode(',', $user_list);

        // Set members list
        //-----------------

        $shell = new Shell();
        $params = "group removemembers '" . $this->group_name . "' '$purge_list'";
        $shell->execute(self::COMMAND_SAMBA_TOOL, $params, TRUE);

        $params = "group addmembers '" . $this->group_name . "' '$members_list'";
        $shell->execute(self::COMMAND_SAMBA_TOOL, $params, TRUE);

        $this->_signal_transaction(lang('accounts_updated_group_membership'));
    }

    /**
     * Updates a group on the system.
     *
     * @param array $group_info group information
     *
     * @return void
     * @throws Validation_Exception, Engine_Exception, User_Not_Found_Exception
     */

    public function update($group_info)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph == NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_group_name($this->group_name, FALSE, FALSE));
        // Validation_Exception::is_valid($this->validate_group_info($group_info));

        // Group does not exist error
        //---------------------------

        if (! $this->exists())
            throw new Group_Not_Found_Exception($this->group_name);

        // Convert user info to LDAP object
        //---------------------------------

        $group_info['core']['group_name'] = $this->group_name; // Extensions may need this info

        $ldap_object = $this->_convert_group_array_to_attributes($group_info, TRUE);

        // Update LDAP attributes from extensions
        //---------------------------------------
        // TODO

        // Modify LDAP object
        //-------------------

        $dn = $this->_get_dn_for_group($this->group_name);
        $this->ldaph->modify($dn, $ldap_object);

        // Ping the synchronizer
        //----------------------

        $this->_signal_transaction(lang('accounts_updated_group_information'));
    }

    ///////////////////////////////////////////////////////////////////////////////
    // V A L I D A T I O N   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Validation routine for group description.
     *
     * @param string $description description
     *
     * @return string error message description is invalid
     */

    public function validate_description($description)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! preg_match('/^([\w \.\-]*)$/', $description))
            return lang('groups_description_invalid');
    }

    /**
     * Validation routine for group name.
     *
     * @param string  $group_name       group name
     * @param boolean $check_uniqueness check for uniqueness
     * @param boolean $check_reserved   check for reserved IDs
     *
     * @return string error message if group name is invalid
     */

    public function validate_group_name($group_name, $check_uniqueness = TRUE, $check_reserved = TRUE)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! preg_match('/^([0-9a-zA-Z\.\-_\s\$]*)$/', $group_name))
            return lang('groups_group_name_invalid');

        if ($check_reserved) {
            $accounts = new Accounts_Driver();

            if ($message = $accounts->is_reserved_id_message($group_name))
                return $message;
        }

        if ($check_uniqueness) {
            $accounts = new Accounts_Driver();

            if ($message = $accounts->is_unique_id_message($group_name))
                return $message;
        }
    }

    ///////////////////////////////////////////////////////////////////////////////
    // P R I V A T E   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Converts group array to LDAP attributes.
     *
     * The Group_Manager class uses this method.  However, we do not want this
     * method to appear in the API documentation since it is really only for
     * internal use.
     * 
     * @param array $group_info group information
     *
     * @return group information in an LDAP attributes format
     * @throws Engine_Exception
     */

    protected function _convert_group_array_to_attributes($group_info)
    {
        clearos_profile(__METHOD__, __LINE__);

        $attributes = array();

        $attributes['objectClass'] = array(
            'group',
        );
        if (isset($group_info['core']['description']))
            $attributes['description'] = $group_info['core']['description'];

        return $attributes;
    }

    /**
     * Returns DN for given user group.
     *
     * @param string $group group name
     *
     * @return string DN
     * @throws Engine_Exception
     */

    protected function _get_dn_for_group($group)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $this->ldaph->search('(&(objectclass=group)(sAMAccountName=' . $this->ldaph->escape($group) . '))');
        $entry = $this->ldaph->get_first_entry();

        $dn = '';

        if ($entry)
            $dn = $this->ldaph->get_dn($entry);

        return $dn;
    }

    /**
     * Loads group information from directory.
     *
     * @return void
     * @throws Engine_Exception
     */

    protected function _load_group_from_directory()
    {
        clearos_profile(__METHOD__, __LINE__);

        // Load directory group object
        //----------------------------

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $result = $this->ldaph->search(
            "(&(cn=" . $this->group_name . ")(objectclass=group))",
            Utilities::get_base_dn()
        );

        $entry = $this->ldaph->get_first_entry($result);

        if (!$entry)
            return array();

        // Convert LDAP attributes into info array
        //----------------------------------------

        $attributes = $this->ldaph->get_attributes($entry);

        $info['core'] = Utilities::convert_attributes_to_array($attributes, $this->info_map);

        // Add user info from extensions
        //------------------------------

        // TODO: add extensions

        // Convert RFC2307BIS CN member list to username member list
        //----------------------------------------------------------

        if ($this->usermap_dn === NULL)
            $this->usermap_dn = Utilities::get_usermap('dn');

        if (empty($attributes['member'])) {
            $info['core']['members'] = array();
        } else {
            $raw_members = $attributes['member'];
            array_shift($raw_members);


            foreach ($raw_members as $membercn) {
                if (!empty($this->usermap_dn[$membercn]))
                    $info['core']['members'][] = $this->usermap_dn[$membercn];
            }
        }

        $basename = strtolower($this->group_name);

        if (preg_match('/_plugin$/', $basename))
            $info['core']['type'] = Group_Engine::TYPE_PLUGIN;
        else if (in_array($basename, Group_Engine::$windows_list))
            $info['core']['type'] = Group_Engine::TYPE_WINDOWS;
        else if (in_array($basename, Group_Engine::$builtin_list))
            $info['core']['type'] = Group_Engine::TYPE_BUILTIN;
        else if (in_array($basename, Group_Engine::$hidden_list))
            $info['core']['type'] = Group_Engine::TYPE_HIDDEN;
        else
            $info['core']['type'] = Group_Engine::TYPE_NORMAL;

        return $info;
    }

    /**
     * Loads group from information.
     * 
     * This method loads group information from /etc/groups if the group exists,
     * otherwise, group information is loaded from the directory.
     *
     * @return void
     * @throws Group_Not_Found_Exception, Engine_Exception
     */

    protected function _load_group_info()
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate group_name

        $posix_info = $this->_load_group_from_posix();

        if (! empty($posix_info))
            return $posix_info;

        $directory_info = $this->_load_group_from_directory();

        if (! empty($directory_info))
            return $directory_info;

        throw new Group_Not_Found_Exception($this->group_name);
    }

    /**
     * Signals a group transaction.
     *
     * @param string $transaction description of the transaction
     *
     * @return void
     */

    protected function _signal_transaction($transaction)
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            Utilities::signal_transaction($transaction . ' - ' . $this->group_name);
        } catch (Exception $e) {
            // Not fatal
        }
    }
}
