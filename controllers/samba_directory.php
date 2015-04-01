<?php

/**
 * Samba Directory controller.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage controllers
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Samba Directory controller.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage controllers
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

class Samba_Directory extends ClearOS_Controller
{
    /**
     * Samba Directory summary view.
     *
     * @return view
     */

    function index()
    {
        // Load libraries
        //---------------

        $this->lang->load('samba_directory');

        // Load views
        //-----------

        $views = array('samba_directory/server', 'samba_directory/settings');

        $this->page->view_forms($views, lang('samba_directory_app_name'));
    }
}
