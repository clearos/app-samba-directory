<?php

/**
 * Samba Directory group driver.
 *
 * @category   apps
 * @package    samba-directory
 * @subpackage javascript
 * @author     ClearCenter <developer@clearcenter.com>
 * @copyright  2012 ClearCenter
 * @license    http://www.clearcenter.com/app_license ClearCenter license
 * @link       http://www.clearcenter.com/support/documentation/clearos/samba_directory/
 */

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
// J A V A S C R I P T
///////////////////////////////////////////////////////////////////////////////

header('Content-Type:application/x-javascript');
?>

$(document).ready(function() {

    // Translations
    //-------------

    lang_initializing = '<?php echo lang("base_initializing..."); ?>';

    // Prep
    //-----

    $("#initializing_box").hide();
    $("#configuration").hide();

    // Run connection attempt
    //-----------------------

    if ($("#init_validated").val() == 1) {
        var loading_options = Array();
        loading_options.text = lang_initializing;

        $("#initialization_result").html(theme_loading(loading_options));
        $("#initializing_box").show();
        $("#configuration").hide();

        $.ajax({
            type: 'POST',
            dataType: 'json',
            data: 
                'ci_csrf_token=' + $.cookie('ci_csrf_token') +
                '&netbios=' + $("#netbios").val() +
                '&comment=' + $("#comment").val() +
                '&password=' + $("#password").val() +
                '&domain=' + $("#domain").val() +
                '&realm=' + $("#realm").val() +
                '&home_dir=' + $("#home_dir").val()
            ,
            url: '/app/samba_directory/settings/initialize',
            success: function(payload) {
            },
            error: function() {
            }
        });
    }

    getDirectoryStatus();
});

function getDirectoryStatus() {
    $.ajax({
        url: '/app/samba_directory/settings/get_status',
        method: 'GET',
        dataType: 'json',
        success : function(payload) {
            showData(payload);
            window.setTimeout(getDirectoryStatus, 3000);
        },
        error: function (XMLHttpRequest, textStatus, errorThrown) {
            window.setTimeout(getDirectoryStatus, 3000);
        }
    });
}

function showData(payload) {
    if (payload.status == 'initializing') {
        var loading_options = Array();
        loading_options.text = (payload.init_message) ? payload.init_message : lang_initializing;

        $("#initialization_result").html(theme_loading(loading_options));
        $("#initializing_box").show();
        $("#configuration").hide();
    } else if ((payload.status == 'online') && ($(location).attr('href').match('.*\/settings\/edit$') != null)) {
        window.location = '/app/samba_directory/settings/view';
    } else if ($("#init_validated").val() == 1) {
        $("#initializing_box").show();
        $("#configuration").hide();
    } else {
        $("#initializing_box").hide();
        $("#configuration").show();
    }
}

// vim: ts=4 syntax=javascript
