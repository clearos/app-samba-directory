<?php

/**
 * Samba Directory settings controller.
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
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

use \clearos\apps\accounts\Accounts_Driver_Not_Set_Exception as Accounts_Driver_Not_Set_Exception;
use \clearos\apps\accounts\Accounts_Engine as Accounts_Engine;
use \Exception as Exception;

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Samba Directory settings controller.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage controllers
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

class Settings extends ClearOS_Controller
{
    /**
     * Samba Directory default controller.
     *
     * @return view
     */

    function index()
    {
        $this->_view_edit('view');
    }

    /**
     * Display edit view.
     *
     * @return view
     */

    function edit($override = FALSE)
    {
        $this->_view_edit('edit', $override);
    }

    /**
     * Display view view.
     *
     * @return view
     */

    function view()
    {
        $this->_view_edit('view');
    }

    /**
     * Generic view/edit.
     *
     * @param string  $mode     mode
     * @param boolean $override override driver warning
     *
     * @return view
     */

    function _view_edit($mode, $override)
    {
        // Show mode status widget if we're not initialized
        //-------------------------------------------------

        $this->load->module('accounts/system_mode');

        if (! $this->system_mode->initialized()) {
            $this->system_mode->widget();
            return;
        }

        // Load libraries
        //---------------

        $this->lang->load('samba_directory');
        $this->load->library('samba_directory/Samba_Directory');
        $this->load->library('samba_directory/Accounts_Driver');
        $this->load->library('samba_common/Samba');

        // Set validation rules
        //---------------------

        $data['validated'] = FALSE;

        $this->form_validation->set_policy('domain', 'samba_directory/Samba_Directory', 'validate_domain', TRUE);
        $this->form_validation->set_policy('realm', 'samba_directory/Samba_Directory', 'validate_realm', TRUE);
        $this->form_validation->set_policy('password', 'samba_directory/Samba_Directory', 'validate_password', TRUE);
        $this->form_validation->set_policy('netbios', 'samba_common/Samba', 'validate_netbios_name', TRUE);
        $this->form_validation->set_policy('comment', 'samba_common/Samba', 'validate_server_string', TRUE);
        $this->form_validation->set_policy('home_dir', 'samba_directory/Samba_Directory', 'validate_home_directory_templates', TRUE);
        $form_ok = $this->form_validation->run();

        // Handle form submit
        //-------------------

        if (($this->input->post('submit') && $form_ok))
            $data['validated'] = TRUE;

        // Load view data
        //---------------

        try {
            $data['mode'] = $mode;
            $data['override'] = $override;
            
            $data['netbios'] = $this->samba->get_netbios_name();
            $data['comment'] = $this->samba->get_server_string();
            $data['domain'] = $this->samba_directory->get_domain();
            $data['realm'] = $this->samba_directory->get_realm();
            $data['status'] = $this->accounts_driver->get_driver_status();
            $data['home_dir'] = $this->samba_directory->get_home_directory_template();
            $data['home_dirs'] = $this->samba_directory->get_home_directory_templates();
        } catch (Exception $e) {
            $this->page->view_exception($e);
            return;
        }

        // Load views
        //-----------

        $this->page->view_form('settings', $data, lang('base_settings'));
    }

    /**
     * Ajax helper for initialization.
     */

    function initialize()
    {
        // Load libraries
        //---------------

        $this->load->library('samba_directory/Samba_Directory');
        $this->load->library('samba_common/Samba');

        // Handle form submit
        //-------------------

        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Fri, 01 Jan 2010 05:00:00 GMT');
        header('Content-type: application/json');

        try {
            $this->samba_directory->run_initialize(
                $this->input->post('password'),
                $this->input->post('domain'),
                $this->input->post('realm'),
                $this->input->post('netbios'),
                $this->input->post('comment'),
                $this->input->post('home_dir')
            );
            echo json_encode(array('code' => 0));
        } catch (Exception $e) {
            echo json_encode(array('code' => clearos_exception_code($e), 'error_message' => clearos_exception_message($e)));
        }
    }
    /**
     * Returns accounts status.
     *
     * @return JSON accounts status information
     */

    function get_status()
    {
        // Load dependencies
        //------------------

        $this->load->library('samba_directory/Accounts_Driver');
        $this->load->library('samba_directory/Samba_Directory');

        // Run synchronize
        //----------------

        try {
            $data['status'] = $this->accounts_driver->get_system_status();
            $init_status = $this->samba_directory->get_initialization_status();
            $data['init_code'] = $init_status['code'];
            $data['init_message'] = $init_status['message'];
            $data['error_code'] = 0;
        } catch (Exception $e) {
            $data['error_code'] = clearos_exception_code($e);
            $data['error_message'] = clearos_exception_message($e);
        }

        // Return status message
        //----------------------

        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Fri, 01 Jan 2010 05:00:00 GMT');
        header('Content-type: application/json');

        $this->output->set_output(json_encode($data));
    }
}
