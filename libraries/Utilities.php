<?php

/**
 * Samba Directory utilities class.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2013 ClearCenter
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

// clearos_load_language('base');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\accounts\Nscd as Nscd;
use \clearos\apps\base\Configuration_File as Configuration_File;
use \clearos\apps\base\Engine as Engine;
use \clearos\apps\ldap\LDAP_Client as LDAP_Client;
use \clearos\apps\samba_directory\Accounts_Driver as Accounts_Driver;
use \clearos\apps\samba_directory\Samba_Directory as Samba_Directory;

clearos_load_library('accounts/Nscd');
clearos_load_library('base/Configuration_File');
clearos_load_library('base/Engine');
clearos_load_library('ldap/LDAP_Client');
clearos_load_library('samba_directory/Accounts_Driver');
clearos_load_library('samba_directory/Samba_Directory');

// Exceptions
//-----------

use \Exception as Exception;
use \clearos\apps\base\Engine_Exception as Engine_Exception;
use \clearos\apps\base\File_Not_Found_Exception as File_Not_Found_Exception;

clearos_load_library('base/Engine_Exception');
clearos_load_library('base/File_Not_Found_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Samba Directory utilities class.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage libraries
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2013 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

class Utilities extends Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * OpenLDAP directory utilities constructor.
     */

    public function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);
    }

    /**
     * Converts LDAP attributes into a hash array.
     *
     * The attributes array that comes from an ldap_read is not what we want to
     * send back to the API call.  Instead, a basic hash array is created
     * by mapping LDAP attributes like: 
     *
     *    [facsimileTelephoneNumber] => Array
     *        (
     *            [count] => 1
     *            [0] => 1234567
     *        )
     *    [7] => facsimileTelephoneNumber
     *
     * To:
     *
     *   [fax] => 1234567 
     *
     * @param string $attributes LDAP attributes
     * @param string $mapping    attribute to array mapping information
     *
     * @return array attributes in a hash array
     */

    public static function convert_attributes_to_array($attributes, $mapping)
    {
        clearos_profile(__METHOD__, __LINE__);

        $info = array();

        foreach ($mapping as $infoname => $detail) {
            if (empty($attributes[$detail['attribute']])) {
                if ($detail['type'] == 'boolean')
                    $info[$infoname] = FALSE;
                else
                    $info[$infoname] = NULL;
            } else {
                if ($infoname != 'password') {
                    if ($detail['type'] == 'boolean') {
                        $info[$infoname] = ($attributes[$detail['attribute']][0] == 'TRUE') ? TRUE : FALSE;
                    } elseif ($detail['type'] == 'string_array') {
                        array_shift($attributes[$detail['attribute']]);
                        $info[$infoname] = $attributes[$detail['attribute']];
                    } else {
                        $info[$infoname] = $attributes[$detail['attribute']][0];
                    }
                }
            }
        }

        return $info;
    }

    /**
     * Converts hash array into LDAP attributes.
     *
     * Gotcha: in order to delete an attribute on an update, the LDAP object item
     * must be set to an empty array.  See http://ca.php.net/ldap_modify for
     * more information.  However, the empty array on a new user causes
     * an error.  In this case, leaving the LDAP object item undefined
     * is the correct behavior.
     *
     * @param string  $array     hash array
     * @param string  $mapping   attribute to array mapping information
     * @param boolean $is_modify modify flag
     *
     * @return array LDAP attributes
     */

    public static function convert_array_to_attributes($array, $mapping, $is_modify = TRUE)
    {
        clearos_profile(__METHOD__, __LINE__);

        $ldap_object = array();
        $object_classes = array();

        foreach ($array as $info => $value) {
            if (isset($mapping[$info]['attribute'])) {
                $attribute = $mapping[$info]['attribute'];

                // Clean up string arrays (missing keys, empty values)
                if ($mapping[$info]['type'] == 'string_array') {
                    $string_array = array();

                    foreach ($value as $item) {
                        if (!empty($item))
                            $string_array[] = $item;
                    }
                } else {
                    $string_array = NULL;
                }

                if (($value === NULL) || ($value === '') || (isset($string_array) && is_array($string_array) && empty($string_array))) {
                    // Delete
                    if ($is_modify)
                        $ldap_object[$attribute] = array();

                } else {
                    // Add/modify
                    if ($mapping[$info]['type'] == 'boolean') {
                        $ldap_object[$attribute] = ($value) ? 'TRUE' : 'FALSE';
                    } else if ($mapping[$info]['type'] == 'string_array') {
                        $ldap_object[$attribute] = $string_array;
                    } else {
                        $ldap_object[$attribute] = $array[$info];
                    }

                    $object_classes[] = $mapping[$info]['object_class'];
                }
            }
        }

        $ldap_object['objectClass'] = array_unique($object_classes);

        return $ldap_object;
    }

    /** 
     * Returns the base DN.
     *
     * @return string base DN
     * @throws Engine_Exception
     */

    public static function get_base_dn()
    {
        clearos_profile(__METHOD__, __LINE__);

        $file = new Configuration_File(Samba_Directory::FILE_LDAP_CONFIG);
        $config = $file->load();

        return $config['base_dn'];
    }

    /**
     * Creates an LDAP connection handle.
     *
     * Many libraries that use OpenLDAP need to:
     *
     * - grab LDAP credentials for connecting to the server
     * - connect to LDAP
     * - perform a bunch of LDAP acctions (search, read, etc)
     *
     * This method provides a common method for doing the firt two steps.
     *
     * @return LDAP handle
     * @throws Engine_Exception
     */

    public static function get_ldap_handle()
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $file = new Configuration_File(Samba_Directory::FILE_LDAP_CONFIG);
            $read_config = $file->load();
        } catch (File_Not_Found_Exception $e) {
            return NULL;
        } catch (Exception $e) {
            throw new Engine_Exception(clearos_exception_message($e));
        }

        $read_config['referrals'] = FALSE;
        $read_config['bind_host'] = '127.0.0.1';
        $read_config['bind_pw'] = openssl_decrypt(
            base64_decode($read_config['bind_pw_hash1']),
            Samba_Directory::CONSTANT_ENCRYPTION,
            Samba_Directory::CONSTANT_HASH,
            TRUE,
            Samba_Directory::CONSTANT_VECTOR
        );

        $write_config = $read_config;

        $ldaph = new LDAP_Client($read_config, $write_config);

        return $ldaph;
    }

    /**
     * Loads group list arrays to help with mapping usernames to DNs.
     *
     * RFC2307bis lists a group of users by DN (which is a CN/common name
     * in our implementation).  Since we prefer seeing a group listed by
     * usernames, this method is used to create two hash arrays to map
     * the usernames and DNs.
     *
     * @param string $type type of map (dn or username)
     *
     * @return void
     */

    public static function get_usermap($type)
    {
        clearos_profile(__METHOD__, __LINE__);

        $ldaph = self::get_ldap_handle();

        $usermap_dn = array();
        $usermap_username = array();

        $result = $ldaph->search(
            "(&(cn=*)(objectclass=person))",
            self::get_base_dn(),
            array('dn', 'sAMAccountName')
        );

        $entry = $ldaph->get_first_entry($result);

        while ($entry) {
            $attrs = $ldaph->get_attributes($entry);
            $dn = $ldaph->get_dn($entry);
            $uid = $attrs['sAMAccountName'][0];

            $usermap_dn[$dn] = $uid;
            $usermap_username[$uid] = $dn;

            $entry = $ldaph->next_entry($entry);
        }

        if ($type === 'dn')
            return $usermap_dn;
        else
            return $usermap_username;
    }

    /**
     * Merges two LDAP object class lists.
     *
     * @param array $array1 LDAP object class list
     * @param array $array2 LDAP object class list
     *
     * @return array object class list
     */

    public static function merge_ldap_object_classes($array1, $array2)
    {
        clearos_profile(__METHOD__, __LINE__);

        $raw_merged = array_merge($array1, $array2);
        $raw_merged = array_unique($raw_merged);

        // PHPism.  Merged arrays have gaps in the keys of the array.
        // The LDAP object barfs on this, so we need to re-key.

        $merged = array();

        foreach ($raw_merged as $class)
            $merged[] = $class;

        return $merged;
    }

    /**
     * Signals a transaction in the user/group/plugin world.
     *
     * All write actions to the directory should call this method.  
     * External applications can then be notified that something in
     * the directory has changed.
     *
     * @param string $action description of transaction
     *
     * @return object extension object
     */

    public static function signal_transaction($action)
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $nscd = new Nscd();
            $nscd->clear_cache();
        } catch (Exception $e) {
            // Not fatal
        }

        $accounts = new Accounts_Driver();
        $accounts->log_transaction($action);
    }
}
