<?php

/**
 * Samba Directory controller.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage views
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

///////////////////////////////////////////////////////////////////////////////
// Load dependencies
///////////////////////////////////////////////////////////////////////////////

$this->lang->load('base');
$this->lang->load('samba_common');
$this->lang->load('samba_directory');

use \clearos\apps\accounts\Accounts_Engine as Accounts_Engine;

///////////////////////////////////////////////////////////////////////////////
// Warnings
///////////////////////////////////////////////////////////////////////////////

if ($status === Accounts_Engine::DRIVER_OTHER) {
    echo infobox_warning(lang('base_warning'), lang('accounts_different_directory_is_already_configured'));

    if (! $override)
        return;
}

///////////////////////////////////////////////////////////////////////////////
// Form handling
///////////////////////////////////////////////////////////////////////////////

// Go straight to edit mode if we're not initialized
//--------------------------------------------------

if ($status === Accounts_Engine::DRIVER_UNSET) {
    $read_only = FALSE; 
    $domain_read_only = FALSE;
    $realm_read_only = FALSE;
    $buttons = array(
        form_submit_custom('submit', lang('samba_directory_initialize_directory')),
    );
} else if ($mode === 'view') {
    $read_only = TRUE; 
    $domain_read_only = TRUE;
    $realm_read_only = TRUE;
    // $buttons = array(anchor_edit('/app/samba_directory/settings/edit'));
    $buttons = array();
} else {
    $read_only = FALSE; 
    $domain_read_only = TRUE;
    $realm_read_only = TRUE;
    $buttons = array(
        form_submit_update(),
        anchor_cancel('/app/samba_directory', 'low')
    );
}

///////////////////////////////////////////////////////////////////////////////
// Status boxes
///////////////////////////////////////////////////////////////////////////////

echo "<div id='initializing_box' style='display:none;'>";

echo infobox_highlight(
    lang('base_status'), 
    "<div id='initialization_result'></div>"
);

echo "</div>";

//////////////////////////////////////////////////////////////////////////////
// Form
///////////////////////////////////////////////////////////////////////////////

echo "<div id='configuration' style='display:none;'>";

echo "<input type='hidden' id='init_validated' value='$validated'>";

echo form_open('samba_directory/settings/edit');
echo form_header(lang('base_settings'));

echo fieldset_header(lang('samba_common_domain_settings'));
echo field_input('domain', $domain, lang('samba_common_windows_domain'), $domain_read_only);
echo field_input('realm', $realm, lang('samba_common_realm'), $realm_read_only);
echo fieldset_footer();

echo fieldset_header(lang('samba_common_server_settings'));
echo field_input('netbios', $netbios, lang('samba_common_server_name'), $read_only);
echo field_input('comment', $comment, lang('samba_common_server_comment'), $read_only);
echo field_dropdown('home_dir', $home_dirs, $home_dir, lang('samba_common_home_directories'), $read_only);
echo fieldset_footer();

if (! $read_only) {
    echo fieldset_header(lang('samba_common_administrator_account'));
    echo field_password('password', $password, lang('base_password'));
    echo fieldset_footer();
}

echo field_button_set($buttons);

echo form_footer();
echo form_close();

echo "</div>";
