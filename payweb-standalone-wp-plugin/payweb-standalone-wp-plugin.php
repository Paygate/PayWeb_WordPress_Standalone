<?php
/**
 *
 * Plugin Name: PayGate PayWeb Standalone for Wordpress
 * Plugin URI: https://github.com/PayGate/PayWeb_Wordpress_Standalone
 * Description: Accept payments for WooCommerce using PayWeb's online payments service
 * Version: 1.0.1
 * Tested: 5.8.0
 * Author: PayGate (Pty) Ltd
 * Author URI: https://www.paygate.com/africa/
 * Developer: App Inlet (Pty) Ltd
 * Developer URI: https://www.appinlet.com/
 *
 * Copyright: © 2021 PayGate (Pty) Ltd
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

require_once ABSPATH . 'wp-admin/includes/plugin.php';
// Exit if accessed directly.
if ( ! defined('ABSPATH')) {
    exit();
}

const LOCATION = 'Location: ';

require_once 'classes/Payweb.php';

add_action('plugins_loaded', 'payweb_standalone_init');
add_action('admin_init', 'register_payweb_standalone_plugin_settings');
add_action('admin_post_payweb_standalone_wp_payment', 'payweb_standalone_wp_payment');
add_action('admin_post_nopriv_payweb_standalone_wp_payment', 'payweb_standalone_wp_payment');
add_action('admin_post_payweb_standalone_wp_payment_success', 'payweb_standalone_wp_payment_success');
add_action('admin_post_nopriv_payweb_standalone_wp_payment_success', 'payweb_standalone_wp_payment_success');
add_action('admin_post_payweb_standalone_wp_payment_failure', 'payweb_standalone_wp_payment_failure');
add_action('admin_post_nopriv_payweb_standalone_wp_payment_failure', 'payweb_standalone_wp_payment_failure');
add_action('init', 'create_posttype');

// Our custom post type function
function create_posttype()
{
    register_post_type(
        'payweb_standalone_order',
        array(
            'labels'       => array(
                'name'          => __('Payweb Standalone Order'),
                'singular_name' => __('Payweb Standalone Order')
            ),
            'public'       => true,
            'has_archive'  => true,
            'rewrite'      => array('slug' => 'payweb_standalone_order'),
            'show_in_rest' => true,

        )
    );
}

function payweb_standalone_wp_payment()
{
    $email  = filter_var($_POST['payweb_standalone_payment_email'], FILTER_SANITIZE_EMAIL);
    $amount = filter_var(
        $_POST['payweb_standalone_payment_amount'],
        FILTER_SANITIZE_NUMBER_FLOAT,
        FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND
    );

    if ($email == '') {
        header('Location:' . $_SERVER['HTTP_REFERER']);

        return;
    }

    $test_mode = get_option('payweb_standalone_test_mode') == 'yes';

    $payweb = new Payweb($test_mode);

    $reference = 'PAYWEB_' . random_int(100000, 999999) . '_' . date('Y-m-d');
    $eparts    = explode('@', $email);

    $post_id    = wp_insert_post(
        [
            'post_type'   => 'payweb_standalone_order',
            'post_status' => 'paywebsa_pending',
            'post_title'  => "PAYWEBSA_order_$reference",
        ]
    );
    $return_url = admin_url(
                  ) . "admin-post.php?action=payweb_standalone_wp_payment_success&post_id=$post_id&reference=$reference&amount=$amount";

    $DateTime      = new DateTime();
    $encryptionKey = $payweb->get_encryption_key();

    $fields = array(
        'PAYGATE_ID'       => $payweb->get_paygate_id(),
        'REFERENCE'        => $reference,
        'AMOUNT'           => $amount,
        'CURRENCY'         => "ZAR",
        'RETURN_URL'       => $return_url,
        'TRANSACTION_DATE' => $DateTime->format('Y-m-d H:i:s'),
        'LOCALE'           => 'en-za',
        'COUNTRY'          => "ZAF",
        'EMAIL'            => $email,
    );

    $fields['CHECKSUM'] = md5(implode('', $fields) . $encryptionKey);

    $response = curlPost('https://secure.paygate.co.za/payweb3/initiate.trans', $fields);

    parse_str($response, $result);
    $data1          = [];
    $PAYGATE_ID     = $result['PAYGATE_ID'];
    $PAY_REQUEST_ID = $result['PAY_REQUEST_ID'];
    $REFERENCE      = $result['REFERENCE'];
    $CHECKSUM       = $result['CHECKSUM'];
    $PAYMENT_TITLE  = "PAYGATE_PAYWEB";
    update_post_meta($post_id, 'PAYMENT_TITLE', $PAYMENT_TITLE);
    update_post_meta($post_id, 'PAYGATE_ID', $PAYGATE_ID);
    update_post_meta($post_id, 'PAY_REQUEST_ID', $PAY_REQUEST_ID);
    update_post_meta($post_id, 'REFERENCE', $REFERENCE);
    update_post_meta($post_id, 'CHECKSUM', $CHECKSUM);

    if (isset($result['ERROR'])) {
        echo "Error Code: " . $result['ERROR'];
        echo '<br/><br/><a href="' . $failure_url . '">Go Back</a>';
        exit(0);
    } else {
        $processData = array();
        if (strpos($response, "ERROR") === false) {
            // Redirect to payment portal
            echo <<<HTML
<p>Kindly wait while you're redirected to PayGate ...</p>
<form action="https://secure.paygate.co.za/payweb3/process.trans" method="post" name="payweb_redirect">
        <input name="PAY_REQUEST_ID" type="hidden" value="$PAY_REQUEST_ID" />
        <input name="CHECKSUM" type="hidden" value="$CHECKSUM" />
</form>
<script type="text/javascript">document.forms['payweb_redirect'].submit();</script>
HTML;
        }
    }
}

function curlPost($url, $fields)
{
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, count($fields));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
}

function payweb_standalone_wp_payment_success()
{
    $post_id            = filter_var($_REQUEST['post_id'], FILTER_SANITIZE_NUMBER_INT);
    $reference          = filter_var($_REQUEST['reference'], FILTER_SANITIZE_NUMBER_INT);
    $amount             = filter_var($_REQUEST['amount'], FILTER_SANITIZE_NUMBER_INT);
    $PAY_REQUEST_ID     = $_POST['PAY_REQUEST_ID'];
    $TRANSACTION_STATUS = $_POST['TRANSACTION_STATUS'];
    $CHECKSUM           = $_POST['CHECKSUM'];

    update_post_meta($post_id, 'PAY_REQUEST_ID', $PAY_REQUEST_ID);
    update_post_meta($post_id, 'TRANSACTION_STATUS', $TRANSACTION_STATUS);

    if (isset($_POST['TRANSACTION_STATUS'])) {
        $status  = $_POST['TRANSACTION_STATUS'];
        $qstring = "?reference=$reference&amount=$amount";
        switch ($status) {
            case 1:
                $status  = "success";
                $message = "Transaction Completed";
                update_post_meta($post_id, 'paywebsa_order_status', 'paid');
                header(LOCATION . site_url() . '/' . get_option('payweb_standalone_success_url') . $qstring);
                exit;
                break;
            case 2:
                $status  = "declined";
                $message = "Transaction has been declined";
                $qstring = "?reference=$reference&amount=$amount&reason=$message";
                update_post_meta($post_id, 'paywebsa_order_status', 'declined');
                header(LOCATION . site_url() . '/' . get_option('payweb_standalone_failure_url') . $qstring);
                exit;
                break;
            case 4:
                $status  = "cancelled";
                $message = "Transaction has been cancelled";
                $qstring = "?reference=$reference&amount=$amount&reason=$message";
                update_post_meta($post_id, 'paywebsa_order_status', 'cancelled');
                header(LOCATION . site_url() . '/' . get_option('payweb_standalone_failure_url') . $qstring);
                exit;
                break;
            default:
                $message = "The transaction could not be verified";
                $qstring = "?reference=$reference&amount=$amount&reason=$message";
                update_post_meta($post_id, 'paywebsa_order_status', 'pending');
                header(LOCATION . site_url() . '/' . get_option('payweb_standalone_failure_url') . $qstring);
                exit;
                break;
        }
    }
}

function register_payweb_standalone_plugin_settings()
{
    register_setting('payweb_standalone_plugin_options', 'payweb_standalone_title');
    register_setting('payweb_standalone_plugin_options', 'payweb_standalone_paygate_id');
    register_setting('payweb_standalone_plugin_options', 'payweb_standalone_encryption_key');
    register_setting('payweb_standalone_plugin_options', 'payweb_standalone_recaptcha_key');
    register_setting('payweb_standalone_plugin_options', 'payweb_standalone_test_mode');
    register_setting('payweb_standalone_plugin_options', 'payweb_standalone_success_url');
    register_setting('payweb_standalone_plugin_options', 'payweb_standalone_failure_url');
}

function payweb_standalone_init()
{
    // Add plugin settings
    add_action('admin_menu', 'register_payweb_standalone_plugin_page');

    // Create custom shortcodes
    add_shortcode('payweb_standalone_payment_checkout', 'add_payweb_standalone_payment_shortcode');
    add_shortcode('payweb_standalone_payment_success', 'add_payweb_standalone_payment_success_shortcode');
    add_shortcode('payweb_standalone_payment_failure', 'add_payweb_standalone_payment_failure_shortcode');
}

function add_payweb_standalone_payment_shortcode()
{
    $url = admin_url() . 'admin-post.php';
    $recaptcha_key = get_option('payweb_standalone_recaptcha_key');

    $html = <<<HTML
     <script src="https://www.google.com/recaptcha/api.js"></script>
     <script>
       function onSubmit(token) {
         document.getElementById("payweb-standalone-form").submit();
       }
     </script>
<form method="post" action="$url" id="payweb-standalone-form">
    <input type="hidden" name="action" value="payweb_standalone_wp_payment">
    <table class="form-table">
        <tbody>
            <tr>
                <td style="background-color: transparent; width:50%;" colspan="2">
                    <input style="width:100%" type="email" name="payweb_standalone_payment_email" placeholder="Email" required="">
                </td>
                <td style="background-color: transparent;" colspan="1">
                    <input style="width:100%" type="number" name="payweb_standalone_payment_amount" step="0.01" placeholder="Amount" required="">
                </td>
                <td style="background-color: transparent;" colspan="1">
                    <button style="width:100%" class="g-recaptcha" 
        data-sitekey="$recaptcha_key" 
        data-callback='onSubmit' 
        data-action='submit' type="submit">Pay Now</button>
                </td>
            </tr>
        </tbody>
    </table>
</form>
HTML;

    return $html;
}

function add_payweb_standalone_payment_success_shortcode()
{
    $reference = isset($_REQUEST['reference']) ? esc_html(
        filter_var($_REQUEST['reference'], FILTER_SANITIZE_STRING)
    ) : 'N/A';
    $amount    = isset($_REQUEST['amount']) ? esc_html(filter_var($_REQUEST['amount'], FILTER_SANITIZE_STRING)) : 'N/A';

    return <<<HTML
<p>Reference: $reference</p>
<p>Amount: $amount</p>
HTML;
}

function add_payweb_standalone_payment_failure_shortcode()
{
    $reference = isset($_REQUEST['reference']) ? esc_html(
        filter_var($_REQUEST['reference'], FILTER_SANITIZE_STRING)
    ) : 'N/A';
    $reason    = isset($_REQUEST['reason']) ? esc_html(filter_var($_REQUEST['reason'], FILTER_SANITIZE_STRING)) : 'N/A';
    $reason    = isset($_REQUEST['amount']) ? esc_html(filter_var($_REQUEST['amount'], FILTER_SANITIZE_STRING)) : 'N/A';

    return <<<HTML
<p>Reference: $reference</p>
<p>Reason: $reason</p>
HTML;
}

function register_payweb_standalone_plugin_page()
{
    add_menu_page(
        'PayWeb Standalone',
        'PayWeb Standalone',
        'manage_options',
        'payweb_standalone_plugin_settings',
        'payweb_standalone_option_page_content'
    );
}

function payweb_standalone_option_page_content()
{
    ?>
    <h2>PayWeb Standalone Payment Plugin</h2>
    <h3>Plugin Settings</h3>
    <form method="post" action="options.php">
        <?php
        settings_fields('payweb_standalone_plugin_options'); ?>
        <table class="form-table" aria-describedby="setting_table">
            <tbody>
            <tr>
                <th scope="row">Title</th>
                <td>
                    <input type="text" name="payweb_standalone_title" id="payweb_standalone_title"
                           value="<?php
                           echo get_option('payweb_standalone_title'); ?>"><br><span class="description"> Enter Title to be displayed on Payment page </span>
                </td>
            </tr>
            <tr>
                <th scope="row">PayGate ID</th>
                <td>
                    <input type="text" name="payweb_standalone_paygate_id" id="payweb_standalone_paygate_id"
                           value="<?php
                           echo get_option('payweb_standalone_paygate_id'); ?>"><br><span class="description"> Enter PayGate ID </span>
                </td>
            </tr>
            <tr>
                <th scope="row">Encryption Key</th>
                <td>
                    <input type="text" name="payweb_standalone_encryption_key" id="payweb_standalone_encryption_key"
                           value="<?php
                           echo get_option('payweb_standalone_encryption_key'); ?>"><br><span class="description"> Enter Encryption Key </span>
                </td>
            </tr>
            <tr>
                <th scope="row">Encryption Key</th>
                <td>
                    <input type="text" name="payweb_standalone_recaptcha_key" id="payweb_standalone_recaptcha_key"
                           value="<?php
                           echo get_option('payweb_standalone_recaptcha_key'); ?>"><br><span class="description"> Enter Recaptcha Key </span>
                </td>
            </tr>

            <tr>
                <th scope="row">Test Mode</th>
                <td>
                    <input type="checkbox" name="payweb_standalone_test_mode" id="payweb_standalone_test_mode"
                           value="yes"
                        <?php
                        echo get_option('payweb_standalone_test_mode') == 'yes' ? 'checked' : ''; ?>
                    ><br><span
                            class="description"> Uses test accounts if enabled. No real transactions processed </span>
                </td>
            </tr>

            <tr>
                <th scope="row">Failure URL</th>
                <td>
                    <input type="text" name="payweb_standalone_failure_url" id="payweb_standalone_failure_url"
                           value="<?php
                           echo get_option('payweb_standalone_failure_url'); ?>"><br><span class="description"> The URL (full or slug) to which the user is redirected on payment failure </span>
                </td>
            </tr>

            <tr>
                <th scope="row">Success URL</th>
                <td>
                    <input type="text" name="payweb_standalone_success_url" id="payweb_standalone_success_url"
                           value="<?php
                           echo get_option('payweb_standalone_success_url'); ?>"><br><span class="description"> The URL (full or slug) to which the user is redirected on payment success </span>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
        submit_button('Save Settings'); ?>
    </form>
    <?php
}
