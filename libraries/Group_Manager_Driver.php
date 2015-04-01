<?php

/**
 * Samba Directory group manager driver.
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

clearos_load_language('samba_directory');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\base\Engine as Engine;
use \clearos\apps\base\Shell as Shell;
use \clearos\apps\groups\Group_Engine as Group_Engine;
use \clearos\apps\groups\Group_Manager_Engine as Group_Manager_Engine;
use \clearos\apps\samba_directory\Utilities as Utilities;
use \clearos\apps\samba_directory\Samba_Directory as Samba_Directory;

clearos_load_library('base/Engine');
clearos_load_library('base/Shell');
clearos_load_library('groups/Group_Engine');
clearos_load_library('groups/Group_Manager_Engine');
clearos_load_library('samba_directory/Utilities');
clearos_load_library('samba_directory/Samba_Directory');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Samba Directory group manager driver.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

class Group_Manager_Driver extends Group_Manager_Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const COMMAND_SAMBA_TOOL = '/usr/bin/samba-tool';
    const COMMAND_WBINFO = '/usr/bin/wbinfo';

    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $ldaph = NULL;
    protected $info_map = array();

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Group_Manager_Driver constructor.
     */

    public function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);

        include clearos_app_base('samba_directory') . '/deploy/group_map.php';

        $this->info_map = $info_map;
    }

    /**
     * Deletes the given username from all groups.
     *
     * @param string $username username
     *
     * @return void
     * @throws Engine_Exception
     */

    public function delete_group_memberships($username)
    {
        clearos_profile(__METHOD__, __LINE__);

        $group_list = $this->get_group_memberships($username);

        foreach ($group_list as $group_name) {
            $group = new Group_Driver($group_name);
            $group->delete_member($username);
        }
    }

    /**
     * Return a list of groups.
     *
     * @param string $filter group filter
     *
     * @return array a list of groups
     * @throws Engine_Exception
     */

    public function get_list($filter = Group_Engine::FILTER_DEFAULT)
    {
        clearos_profile(__METHOD__, __LINE__);

        $details = $this->_get_details($filter);

        $group_list = array();

        foreach ($details as $name => $info)
            $group_list[] = $info['core']['group_name'];

        return $group_list;
    }

    /**
     * Return a list of groups with detailed information.
     *
     * @param integer $filter filter for specific groups
     *
     * @return array an array containing group data
     * @throws Engine_Exception
     */

    public function get_details($filter = Group_Engine::FILTER_DEFAULT)
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->_get_details($filter);
    }

    /**
     * Updates group membership for given user.
     *
     * This method does not change the settings in built-in groups.
     *
     * @param string $username username
     * @param array  $groups   list of active groups
     *
     * @return void
     */

    public function update_group_memberships($username, $groups)
    {
        clearos_profile(__METHOD__, __LINE__);

        $current = $this->get_group_memberships($username);
        $all = $this->get_list(Group_Driver::FILTER_NORMAL);

        foreach ($all as $group_name) {
            if (in_array($group_name, $groups) && !in_array($group_name, $current)) {
                $group = new Group_Driver($group_name);
                $group->add_member($username);
            } else if (!in_array($group_name, $groups) && in_array($group_name, $current)) {
                $group = new Group_Driver($group_name);
                $group->delete_member($username);
            }
        }

        $this->_signal_transaction(lang('accounts_updated_group_membership'));
    }

    ///////////////////////////////////////////////////////////////////////////////
    // P R I V A T E   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Loads a full list of groups with detailed information.
     *
     * @param string $filter group filter
     *
     * @return array an array containing group data
     * @throws Engine_Exception
     */

    protected function _get_details($filter)
    {
        clearos_profile(__METHOD__, __LINE__);

        $directory_data = array();
        $posix_data = array();

        $directory_data = $this->_get_details_from_directory($filter);

        if (($filter === Group_Engine::FILTER_SYSTEM) || ($filter === Group_Engine::FILTER_ALL))
            $posix_data = $this->_get_details_from_posix();

        $data = array_merge($directory_data, $posix_data);

        return $data;
    }

    /**
     * Loads groups from directory.
     *
     * @param string $filter group filter
     *
     * @return array group information
     * @throws Engine_Exception
     */

    protected function _get_details_from_directory($filter)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $group_list = array();
        $usermap_dn = Utilities::get_usermap('dn');

        // Load groups from wbinfo
        //------------------------

        $shell = new Shell();
        $shell->execute(self::COMMAND_WBINFO, '-g');

        $valid_list = $shell->get_output();

        // Load groups from directory
        //---------------------------

        $result = $this->ldaph->search(
            "(&(objectclass=group))", 
            Utilities::get_base_dn()
        );

        $this->ldaph->sort($result, 'sAMAccountName');
        $entry = $this->ldaph->get_first_entry($result);

        while ($entry) {
            $attributes = $this->ldaph->get_attributes($entry);
            $group_name = $attributes['cn'][0];
            $group_info = array();

            // Bail if not listed in wbinfo -g
            //--------------------------------

            if (! in_array($group_name, $valid_list)) {
                $entry = $this->ldaph->next_entry($entry);
                continue;
            }

            // Convert directory attributes to PHP array
            //------------------------------------------

            $group_info['core'] = Utilities::convert_attributes_to_array($attributes, $this->info_map);

            // Add group type
            //---------------

            $basename = strtolower($group_name);

            if (preg_match('/_plugin$/', $basename))
                $group_info['core']['type'] = Group_Engine::TYPE_PLUGIN;
            else if (in_array($basename, Group_Engine::$windows_list))
                $group_info['core']['type'] = Group_Engine::TYPE_WINDOWS;
            else if (in_array($basename, Group_Engine::$builtin_list))
                $group_info['core']['type'] = Group_Engine::TYPE_BUILTIN;
            else if (in_array($basename, Group_Engine::$hidden_list))
                $group_info['core']['type'] = Group_Engine::TYPE_HIDDEN;
            else
                $group_info['core']['type'] = Group_Engine::TYPE_NORMAL;

            // Handle membership
            //------------------
            // Convert RFC2307BIS CN member list to username member list

            if (empty($attributes['member'])) {
                $group_info['core']['members'][] = array();
            } else {
                $raw_members = $attributes['member'];
                array_shift($raw_members);

                foreach ($raw_members as $membercn) {
                    if (!empty($usermap_dn[$membercn]))
                        $group_info['core']['members'][] = $usermap_dn[$membercn];
                }
            }

            // Add group info from extensions
            //-------------------------------
            // TODO

            // Add group to list
            //------------------

            if (($filter === Group_Engine::FILTER_ALL) 
                || (($filter === Group_Engine::FILTER_SYSTEM) && ($group_info['core']['type'] === Group_Engine::TYPE_SYSTEM))
                || (($filter === Group_Engine::FILTER_NORMAL) && ($group_info['core']['type'] === Group_Engine::TYPE_NORMAL))
                || (($filter === Group_Engine::FILTER_BUILTIN) && ($group_info['core']['type'] === Group_Engine::TYPE_BUILTIN))
                || (($filter === Group_Engine::FILTER_WINDOWS) && ($group_info['core']['type'] === Group_Engine::TYPE_WINDOWS))
                || (($filter === Group_Engine::FILTER_PLUGIN) && ($group_info['core']['type'] === Group_Engine::TYPE_PLUGIN))
                || (($filter === Group_Engine::FILTER_DEFAULT) 
                && (($group_info['core']['type'] === Group_Engine::TYPE_NORMAL) 
                || ($group_info['core']['type'] === Group_Engine::TYPE_BUILTIN)))
            )
                $group_list[$group_name] = $group_info;

            $entry = $this->ldaph->next_entry($entry);
        }

        return $group_list;
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
