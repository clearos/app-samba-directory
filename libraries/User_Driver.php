<?php

/**
 * Samba Directory user driver.
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
clearos_load_language('users');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\base\Shell as Shell;
use \clearos\apps\groups\Group_Engine as Group_Engine;
use \clearos\apps\samba_directory\Accounts_Driver as Accounts_Driver;
use \clearos\apps\samba_directory\Group_Driver as Group_Driver;
use \clearos\apps\samba_directory\Group_Manager_Driver as Group_Manager_Driver;
use \clearos\apps\users\User_Engine as User_Engine;

clearos_load_library('base/Shell');
clearos_load_library('groups/Group_Engine');
clearos_load_library('samba_directory/Accounts_Driver');
clearos_load_library('samba_directory/Group_Driver');
clearos_load_library('samba_directory/Group_Manager_Driver');
clearos_load_library('users/User_Engine');

// Exceptions
//-----------

use \clearos\apps\base\Validation_Exception as Validation_Exception;
use \clearos\apps\users\User_Not_Found_Exception as User_Not_Found_Exception;

clearos_load_library('base/Validation_Exception');
clearos_load_library('users/User_Not_Found_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Samba Directory user driver.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

class User_Driver extends User_Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const COMMAND_NTLM_AUTH = '/usr/bin/ntlm_auth';
    const COMMAND_SAMBA_TOOL = '/usr/bin/samba-tool';
    const COMMAND_WBINFO = '/usr/bin/wbinfo';

    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $ldaph = NULL;
    protected $username = NULL;
    protected $info_map = array();
    protected $plugins = array();
    protected $extensions = array();

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * User constructor.
     *
     * @param string $username username
     */

    public function __construct($username = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->username = $username;

        include clearos_app_base('samba_directory') . '/deploy/user_map.php';

        $this->info_map = $info_map;
    }

    /**
     * Adds a user to the system.
     *
     * @param array $user_info user information
     * @param array $password  password
     *
     * @return void
     * @throws Validation_Exception, Engine_Exception
     */

    public function add($user_info, $password)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username));
        Validation_Exception::is_valid($this->validate_password($password));
        Validation_Exception::is_valid($this->validate_user_info($user_info));

        // Convert user_info and password into attributes
        //------------------------------------------------
        // TODO

        // Add attributes from extensions
        //-------------------------------
        // TODO

        // Add the user to the directory
        //------------------------------

        $flags = '';

        if (! empty($user_info['core']['first_name']))
            $flags .= ' --given-name=' . escapeshellarg($user_info['core']['first_name']);

        if (! empty($user_info['core']['last_name']))
            $flags .= ' --surname=' . escapeshellarg($user_info['core']['last_name']);
            
        $shell = new Shell();
        $shell->execute(self::COMMAND_SAMBA_TOOL, "user add " . escapeshellarg($this->username) . " $flags --random-password", TRUE);

        $retval = $this->reset_password($password, $password, 'system');

        // Handle plugins
        //---------------

        $this->_handle_plugins($user_info);

        // Run post-add processing hook
        //-----------------------------

        // $this->_add_post_processing_hook($user_info);

        // Ping the synchronizer
        //----------------------

        $this->_signal_transaction(lang('accounts_added_user'));

        return $retval;
    }

    /**
     * Checks the password for the user.
     *
     * @param string $password password for the user
     *
     * @return boolean TRUE if password is correct
     * @throws Engine_Exception, User_Not_Found_Exception
     */

    public function check_password($password)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));
        Validation_Exception::is_valid($this->validate_password($password));

        // Check password
        //---------------

        $params = '--username=' . $this->username . ' --password=' . $password;
        $options['validate_exit_code'] = FALSE;

        $shell = new Shell();
        $retval = $shell->execute(self::COMMAND_NTLM_AUTH, $params, FALSE, $options);

        if ($retval === 0)
            return TRUE;
        else
            return FALSE;
    }

    /**
     * Deletes a user from the system.
     *
     * The actual delete from LDAP is done asynchronously.  This gives all
     * slave systems a chance to clean up before the object is completely 
     * deleted from LDAP.
     *
     * @return void
     */

    public function delete()
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));

        $shell = new Shell();
        $shell->execute(self::COMMAND_SAMBA_TOOL, "user delete '" . $this->username. "'", TRUE);

        $this->_signal_transaction(lang('accounts_deleted_user'));
    }

    /**
     * Checks if given user exists.
     *
     * @return boolean TRUE if user exists
     * @throws Engine_Exception
     */

    public function exists()
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $info = $this->_get_user_attributes();
            return TRUE;
        } catch (User_Not_Found_Exception $e) {
            return FALSE;
        }
    }

    /**
     * Returns the list of groups for given user.
     *
     * @return array a list of groups
     * @throws Engine_Exception
     */

    public function get_group_memberships()
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));

        $groups = new Group_Manager_Driver();

        $groups_info = $groups->get_details(Group_Engine::FILTER_ALL);

        $group_list = array();

        foreach ($groups_info as $group_name => $group_details) {
            if (in_array($this->username, $group_details['core']['members']))
                $group_list[] = $group_name;
        }

        return $group_list;
    }

    /**
     * Returns information for user.
     *
     * @return array user details
     * @throws Engine_Exception
     */

    public function get_info()
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));

        // Get user info
        //--------------

        $attributes = $this->_get_user_attributes();

        $info['core'] = Utilities::convert_attributes_to_array($attributes, $this->info_map);

        // Add group memberships
        //----------------------

        $info['groups'] = $this->get_group_memberships();

        // Add user info from extensions
        //------------------------------

        // TODO: add extension info


        // Add user info from plugins
        //---------------------------

        foreach ($this->_get_plugins() as $plugin => $details) {
            $plugin_name = $plugin . '_plugin';
            $state = (in_array($plugin_name, $info['groups'])) ? TRUE : FALSE;
            $info['plugins'][$plugin] = $state;
        }

        return $info;
    }

    /**
     * Retrieves default information for a new user.
     *
     * @return array user details
     * @throws Engine_Exception
     */

    public function get_info_defaults()
    {
        clearos_profile(__METHOD__, __LINE__);

        // TODO: implement
        return array();
    }

    /**
     * Returns full information map for user.
     *
     * @throws Engine_Exception
     *
     * @return array user details
     */

    public function get_info_map()
    {
        clearos_profile(__METHOD__, __LINE__);

        $info_map = array();

        $info_map['core'] = $this->info_map;

        // Add user info map from extensions
        //----------------------------------

        // TODO: implement extennsions
        // Add user info map from plugins
        //-------------------------------

        foreach ($this->_get_plugins() as $plugin => $details) {
            $plugin_name = $plugin . '_plugin';
            $info_map['plugins'][] = $plugin;
        }

        return $info_map;
    }

    /**
     * Reset the passwords for the user.
     *
     * Similar to set_password, but it uses administrative privileges.  This is
     * typically used for resetting a password while bypassing password
     * policies.  For example, an administrator may need to set a password
     * even when the password policy dictates that the password is not allowed
     * to change (minimum password age).
     *
     * @param string  $password      password
     * @param string  $verify        password verify
     * @param string  $requested_by  username requesting the password change
     *
     * @return string error message if password reset fails
     * @throws Engine_Exception, Validation_Exception
     */

    public function reset_password($password, $verify, $requested_by)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));
        Validation_Exception::is_valid($this->validate_username($requested_by, FALSE, FALSE));

        $options['validate_exit_code'] = FALSE;

        $shell = new Shell();
        $retval = $shell->execute(
            self::COMMAND_SAMBA_TOOL, 
            "user setpassword " . escapeshellarg($this->username) . " --newpassword=" . escapeshellarg($password),
            TRUE,
            $options
        );

        if ($retval == 0) {
            $this->_signal_transaction(lang('accounts_set_user_password'));
            return;
        }

        // Dirty.  Try to catch common error strings so that we can translate.
        $output = $shell->get_output();
        $error_message = isset($output[1]) ? $output[1] : $output[0]; // Default if our matching fails

        foreach ($output as $line) {
            if (preg_match("/password is too short/", $line)) {
                $error_message = lang('users_password_is_too_short');
            } else if (preg_match("/the password does not meet the complexity criteria/", $line)) {
                $error_message = lang('users_password_violates_quality_check');
            } else if (preg_match("/password can.t be changed on this account/", $line)) {
                $error_message = lang('users_password_cannot_be_changed_on_this_account');
            } else if (preg_match("/password is too young/", $line)) {
                $error_message = lang('users_password_is_too_young');
            } else if (preg_match("/password was already used/", $line)) {
                $error_message = lang('users_password_in_history');
            } else if (preg_match("/Constraint violation - check_password_restrictions/", $line)) {
                $error_message = preg_replace('/.*Constraint violation - check_password_restrictions\s*:\s*/', '', $line);
                $error_message = ucfirst(preg_replace("/'\)/", '', $error_message));
            }
        }

        return $error_message;
    }

    /**
     * Sets group memberships.
     *
     * @param array $groups groups
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function set_group_memberships($groups)
    {
        clearos_profile(__METHOD__, __LINE__);

        foreach ($groups as $group => $state) {
            $group = new Group_Driver($group);

            if ($state)
                $group->add_member($this->username);
            else
                $group->delete_member($this->username);
        }
    }

    /**
     * Sets the password for the user.
     *
     * @param string  $oldpassword   old password
     * @param string  $password      password
     * @param string  $verify        password verify
     * @param string  $requested_by  username requesting the password change
     *
     * @return string error message if password reset fails
     * @throws Engine_Exception, Validation_Exception
     */

    public function set_password($oldpassword, $password, $verify, $requested_by)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));
        Validation_Exception::is_valid($this->validate_username($requested_by, FALSE, FALSE));

        // Check old password first
        //-------------------------

        $options['validate_exit_code'] = FALSE;

        $shell = new Shell();
        $retval = $shell->execute(self::COMMAND_WBINFO, '-a' . escapeshellarg($this->username) . '%' . escapeshellarg($oldpassword), TRUE, $options);

        if ($retval != 0)
            throw new Validation_Exception(lang('users_old_password_invalid'));

        // Try changing the password
        //--------------------------

        $retval = $this->reset_password($password, $password, $requested_by);
        
        return $retval;
    }

    /**
     * Updates a user on the system.
     *
     * @param array $user_info user information
     * @param array $acl       access control list
     *
     * @return void
     * @throws Validation_Exception, Engine_Exception, User_Not_Found_Exception
     */

    public function update($user_info, $acl = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph == NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));
        Validation_Exception::is_valid($this->validate_user_info($user_info));

        // User does not exist error
        //--------------------------

        if (! $this->exists()) 
            throw new User_Not_Found_Exception();

        // Convert user info to LDAP object
        //---------------------------------

        $ldap_object = $this->_convert_user_array_to_attributes($user_info, TRUE);

        // Update LDAP attributes from extensions
        //---------------------------------------

        // TODO: implement extensions

        // Modify LDAP object
        //-------------------

        $dn = $this->_get_dn_for_uid($this->username);
        $this->ldaph->modify($dn, $ldap_object);

        // Handle plugins
        //---------------

        $this->_handle_plugins($user_info);

        // Ping the synchronizer
        //----------------------

        $this->_signal_transaction(lang('accounts_updated_user_information'));
    }

    ///////////////////////////////////////////////////////////////////////////////
    // V A L I D A T I O N   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Validation routine for first name.
     *
     * @param string $name first name
     *
     * @return string error message if first name is invalid
     */

    public function validate_first_name($name)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (preg_match("/([:;\/#!@])/", $name))
            return lang('users_first_name_invalid');
    }

    /**
     * Validation routine for GID number.
     *
     * @param integer $gid_number GID number
     *
     * @return string error message if GID number is invalid
     */

    public function validate_gid_number($gid_number)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! preg_match('/^[0-9]+$/', $gid_number))
            return lang('users_group_id_invalid');
    }

    /**
     * Validation routine for home directory
     *
     * @param string $homedir home directory
     *
     * @return string error message if home directory is invalid
     */

    public function validate_home_directory($homedir)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (preg_match("/([:;#!@])/", $homedir))
            return lang('users_home_directory_invalid');
    }

    /**
     * Validation routine for last name.
     *
     * @param string $name last name
     *
     * @return string error message if last name is invalid
     */

    public function validate_last_name($name)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (preg_match("/([:;\/#!@])/", $name))
            return lang('users_last_name_invalid');
    }

    /**
     * Validation routine for UID number.
     *
     * @param integer $uid_number UID number
     *
     * @return boolean TRUE if UID number is valid
     */

    public function validate_uid_number($uid_number)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! preg_match('/^[0-9]+$/', $uid_number))
            return lang('users_user_id_invalid');
        else if ($uid_number > self::UID_RANGE_NORMAL_MAX)
            return lang('users_user_id_invalid');
    }

    /**
     * Validation routine for username.
     *
     * @param string  $username         username
     * @param boolean $check_uniqueness check for uniqueness
     * @param boolean $check_reserved   check for reserved usernames
     *
     * @return string error message if username is invalid
     */

    public function validate_username($username, $check_uniqueness = TRUE, $check_reserved = TRUE)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (!preg_match("/^([A-Za-z0-9_\-\.\$]+)$/", $username))
            return lang('users_username_invalid');

        if ($check_reserved) {
            $accounts = new Accounts_Driver();

            if ($message = $accounts->is_reserved_id_message($username))
                return $message;
        }

        if ($check_uniqueness) {
            $accounts = new Accounts_Driver();

            if ($message = $accounts->is_unique_id_message($username))
                return $message;
        }
    }

    /**
     * Validates a user_info array.
     *
     * @param array   $user_info user information array
     * @param boolean $is_modify set to TRUE if using results on LDAP modif
     *
     * @return boolean TRUE if user_info is valid
     * @throws Engine_Exception, Validation_Exception
     */

    public function validate_user_info($user_info, $is_modify = FALSE)
    {
        clearos_profile(__METHOD__, __LINE__);

        return;
    }

    ///////////////////////////////////////////////////////////////////////////////
    // P R I V A T E   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Converts a user_info array into LDAP attributes.
     *
     * @param array   $user_info user information array
     * @param boolean $is_modify set to TRUE if using results on LDAP modify
     *
     * @return array LDAP attribute array
     * @throws Engine_Exception, Validation_Exception
     */

    protected function _convert_user_array_to_attributes($user_info, $is_modify)
    {
        clearos_profile(__METHOD__, __LINE__);

        /**
         * Prep the conversion.
         */

        $ldap_object = array();
        $old_attributes = array();

        try {
            if ($is_modify)
                $old_attributes = $this->_get_user_attributes();
        } catch (User_Not_Found_Exception $e) {
            // Not fatal
        }

        /**
         * Step 1 - convert user_info fields to LDAP fields
         *
         * Use the utility class for this job.
         */

        if (isset($user_info['core']))
            $ldap_object = Utilities::convert_array_to_attributes($user_info['core'], $this->info_map);

        /**
         * Step 2 - set core object classes.
         *
         * If this is an update, we need to make sure the objectclass list
         * includes pre-existing classes.
         */

        if (isset($old_attributes['objectClass'])) {
            $old_classes = $old_attributes['objectClass'];
            array_shift($old_classes);
            $ldap_object['objectClass'] = Utilities::merge_ldap_object_classes($ldap_object['objectClass'], $old_classes);
        }

        return $ldap_object;
    }

    /**
     * Returns DN for given user ID (username).
     *
     * @param string $uid user ID
     *
     * @return string DN
     * @throws Engine_Exception
     */

    protected function _get_dn_for_uid($uid)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $this->ldaph->search('(&(objectclass=person)(sAMAccountName=' . $this->ldaph->escape($uid) . '))');
        $entry = $this->ldaph->get_first_entry();

        $dn = '';

        if ($entry)
            $dn = $this->ldaph->get_dn($entry);

        return $dn;
    }

    /**
     * Returns plugins list.
     *
     * @access private
     * @return array extension list
     */

    protected function _get_plugins()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! empty($this->plugins))
            return $this->plugins;

        $accounts = new Accounts_Driver();

        $this->plugins = $accounts->get_plugins();

        return $this->plugins;
    }

    /**
     * Returns user information in hash array.
     *
     * @return array hash array of user information
     * @throws Engine_Exception, User_Not_Found_Exception
     */

    protected function _get_user_attributes()
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph == NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $dn = $this->_get_dn_for_uid($this->username);

        if (empty($dn))
            throw new User_Not_Found_Exception();

        $attributes = $this->ldaph->read($dn);
        $attributes['dn'] = $dn;

        return $attributes;
    }

    /**
     * Handles plugin attributes.
     *
     * @param array $user_info user info array
     *
     * @return void
     * @throws Engine_Exception
     */

    protected function _handle_plugins($user_info)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (empty($user_info['plugins']))
            return;

        foreach ($user_info['plugins'] as $plugin_name => $info) {
            $group = new Group_Driver($plugin_name . '_plugin');

            // Initialize plugins if group does not exist
            if (! $group->exists()) {
                $accounts = new Accounts_Driver();
                $accounts->initialize_plugin_groups();
            }

            if (! $group->exists())
                continue;

            if ($info['state'])
                $group->add_member($this->username);
            else
                $group->delete_member($this->username);
        }
    }

    /**
     * Signals a user transaction.
     *
     * @param string $transaction description of the transaction
     *
     * @return void
     */

    protected function _signal_transaction($transaction)
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            Utilities::signal_transaction($transaction . ' - ' . $this->username);
        } catch (Exception $e) {
            // Not fatal
        }
    }
}
