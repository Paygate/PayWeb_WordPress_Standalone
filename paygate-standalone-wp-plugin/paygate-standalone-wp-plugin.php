<?php
/**
 *
 * Plugin Name: Paygate Standalone for WordPress
 * Plugin URI: https://github.com/Paygate/PayWeb_WordPress_Standalone
 * Description: Receive payments using the South African Paygate payments provider.
 * Version: 1.0.2
 * Tested: 6.5.2
 * Author: Payfast (Pty) Ltd
 * Author URI: https://payfast.io/
 * Developer: App Inlet (Pty) Ltd
 * Developer URI: https://www.appinlet.com/
 *
 * Copyright: © 2024 Payfast (Pty) Ltd
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

/** @noinspection PhpUndefinedConstantInspection */
require_once ABSPATH . 'wp-admin/includes/plugin.php';
// Exit if accessed directly.
if ( ! defined('ABSPATH')) {
    exit();
}

const LOCATION = 'Location: ';

require_once 'classes/Paygate.php';

add_action('plugins_loaded', 'paygateStandaloneInit');
add_action('admin_init', 'registerPaygateStandalonePluginSettings');
add_action('admin_post_paygate_standalone_wp_payment', 'paygateStandaloneWPPayment');
add_action('admin_post_nopriv_paygate_standalone_wp_payment', 'paygateStandaloneWPPayment');
add_action('admin_post_paygate_standalone_wp_payment_success', 'paygateStandaloneWPPaymentSuccess');
add_action('admin_post_nopriv_paygate_standalone_wp_payment_success', 'paygateStandaloneWPPaymentSuccess');
add_action('admin_post_paygate_standalone_wp_payment_failure', 'paygate_standalone_wp_payment_failure');
add_action('admin_post_nopriv_paygate_standalone_wp_payment_failure', 'paygate_standalone_wp_payment_failure');
add_action('init', 'createPostType');

// Our custom post type function
/** @noinspection PhpUnused */
function createPostType(): void
{
    register_post_type(
        'paygate_standalone_order',
        array(
            'labels'       => array(
                'name'          => __('Paygate Standalone Order'),
                'singular_name' => __('Paygate Standalone Order')
            ),
            'public'       => true,
            'has_archive'  => true,
            'rewrite'      => array('slug' => 'paygate_standalone_order'),
            'show_in_rest' => true,

        )
    );
}

/**
 * @throws Exception
 * @noinspection PhpUnused
 */
function paygateStandaloneWPPayment(): void
{
    $email  = filter_var($_POST['paygate_standalone_payment_email'], FILTER_SANITIZE_EMAIL);
    $amount = intval(filter_var(
        $_POST['paygate_standalone_payment_amount'],
        FILTER_SANITIZE_NUMBER_FLOAT,
        FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND
    ) * 100);

    if ($email == '') {
        header('Location:' . $_SERVER['HTTP_REFERER']);

        return;
    }

    /** Validate reCAPTCHA **/
    validateForm();

    $test_mode = get_option('paygate_standalone_test_mode') == 'yes';

    $paygate = new Paygate($test_mode);

    $reference = 'PAYGATE_' . random_int(100000, 999999) . '_' . date('Y-m-d');

    $post_id    = wp_insert_post(
        [
            'post_type'   => 'paygate_standalone_order',
            'post_status' => 'paygatesa_pending',
            'post_title'  => "PAYGATESA_order_$reference",
        ]
    );
    $return_url = admin_url() . "admin-post.php?action=paygate_standalone_wp_payment_success&post_id=$post_id&" .
                  "reference=$reference&amount=$amount";

    $dateTime      = new DateTime();
    $encryptionKey = $paygate->getEncryptionKey();

    $fields = array(
        'PAYGATE_ID' => $paygate->getPaygateId(),
        'REFERENCE'        => $reference,
        'AMOUNT'           => $amount,
        'CURRENCY'         => "ZAR",
        'RETURN_URL'       => $return_url,
        'TRANSACTION_DATE' => $dateTime->format('Y-m-d H:i:s'),
        'LOCALE'           => 'en-za',
        'COUNTRY'          => "ZAF",
        'EMAIL'            => $email,
    );

    $fields['CHECKSUM'] = md5(implode('', $fields) . $encryptionKey);

    $response = curlPost('https://secure.paygate.co.za/payweb3/initiate.trans', $fields);

    parse_str($response, $result);
    $pagateID     = $result['PAYGATE_ID'];
    $payRequestID = $result['PAY_REQUEST_ID'];
    $ref      = $result['REFERENCE'];
    $checksum       = $result['CHECKSUM'];
    $payementTitle = "PAYGATE";
    update_post_meta($post_id, 'PAYMENT_TITLE', $payementTitle);
    update_post_meta($post_id, 'PAYGATE_ID', $pagateID);
    update_post_meta($post_id, 'PAY_REQUEST_ID', $payRequestID);
    update_post_meta($post_id, 'REFERENCE', $ref);
    update_post_meta($post_id, 'CHECKSUM', $checksum);

    if (isset($result['ERROR'])) {
        echo "Error Code: " . $result['ERROR'];
        echo '<br/><br/><a href="' . get_option('paygate_standalone_failure_url') . '">Go Back</a>';
        exit(0);
    } else {
        if (!str_contains($response, "ERROR")) {
            // Redirect to payment portal
            echo <<<HTML
<p>Kindly wait while you're redirected to Paygate...</p>
<form action="https://secure.paygate.co.za/payweb3/process.trans" method="post" name="paygate_redirect">
        <input name="PAY_REQUEST_ID" type="hidden" value="$payRequestID" />
        <input name="CHECKSUM" type="hidden" value="$checksum" />
</form>
<script type="text/javascript">document.forms['paygate_redirect'].submit();</script>
HTML;
        }
    }
}

function curlPost($url, $fields): bool|string
{
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, count($fields));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
}

/** @noinspection PhpUnused */
function paygateStandaloneWPPaymentSuccess(): void
{
    $post_id            = filter_var($_REQUEST['post_id'], FILTER_SANITIZE_NUMBER_INT);
    $reference          = filter_var($_REQUEST['reference'], FILTER_SANITIZE_NUMBER_INT);
    $amount             = filter_var($_REQUEST['amount'], FILTER_SANITIZE_NUMBER_INT);
    $payRequestID     = $_POST['PAY_REQUEST_ID'];
    $transactionStatus = $_POST['TRANSACTION_STATUS'];

    update_post_meta($post_id, 'PAY_REQUEST_ID', $payRequestID);
    update_post_meta($post_id, 'TRANSACTION_STATUS', $transactionStatus);

    if (isset($_POST['TRANSACTION_STATUS'])) {
        $status  = $_POST['TRANSACTION_STATUS'];
        $qstring = "?reference=$reference&amount=$amount";
        switch ($status) {
            case 1:
                update_post_meta($post_id, 'paygatesa_order_status', 'paid');
                header(LOCATION . site_url() . '/' . get_option('paygate_standalone_success_url') . $qstring);
                break;
            case 2:
                $message = "Transaction has been declined";
                $qstring = "?reference=$reference&amount=$amount&reason=$message";
                update_post_meta($post_id, 'paygatesa_order_status', 'declined');
                header(LOCATION . site_url() . '/' . get_option('paygate_standalone_failure_url') . $qstring);
                break;
            case 4:
                $message = "Transaction has been cancelled";
                $qstring = "?reference=$reference&amount=$amount&reason=$message";
                update_post_meta($post_id, 'paygatesa_order_status', 'cancelled');
                header(LOCATION . site_url() . '/' . get_option('paygate_standalone_failure_url') . $qstring);
                break;
            default:
                $message = "The transaction could not be verified";
                $qstring = "?reference=$reference&amount=$amount&reason=$message";
                update_post_meta($post_id, 'paygatesa_order_status', 'pending');
                header(LOCATION . site_url() . '/' . get_option('paygate_standalone_failure_url') . $qstring);
        }
        exit;
    }
}

/** @noinspection PhpUnused */
function registerPaygateStandalonePluginSettings(): void
{
    register_setting('paygate_standalone_plugin_options', 'paygate_standalone_title');
    register_setting('paygate_standalone_plugin_options', 'paygate_standalone_paygate_id');
    register_setting('paygate_standalone_plugin_options', 'paygate_standalone_encryption_key');
    register_setting('paygate_standalone_plugin_options', 'paygate_standalone_recaptcha_key');
    register_setting('paygate_standalone_plugin_options', 'paygate_standalone_recaptcha_secret');
    register_setting('paygate_standalone_plugin_options', 'paygate_standalone_test_mode');
    register_setting('paygate_standalone_plugin_options', 'paygate_standalone_success_url');
    register_setting('paygate_standalone_plugin_options', 'paygate_standalone_failure_url');
}

/** @noinspection PhpUnused */
function paygateStandaloneInit(): void
{
    // Add plugin settings
    add_action('admin_menu', 'registerPaygateStandalonePluginPage');

    // Create custom shortcodes
    add_shortcode('paygate_standalone_payment_checkout', 'addPaygateStandalonePaymentShortcode');
    add_shortcode('paygate_standalone_payment_success', 'addPaygateStandalonePaymentSuccessShortcode');
    add_shortcode('paygate_standalone_payment_failure', 'addPaygateStandalonePaymentFailureShortcode');
}

/** @noinspection PhpUnused */
function addPaygateStandalonePaymentShortcode(): string
{
    $url           = admin_url() . 'admin-post.php';
    $recaptcha_key = get_option('paygate_standalone_recaptcha_key');

    return <<<HTML
     <script src="https://www.google.com/recaptcha/api.js"></script>
     <script>
       function onSubmit(token) {
         document.getElementById("paygate-standalone-form").submit();
       }
     </script>
<form method="post" action="$url" id="paygate-standalone-form">
    <input type="hidden" name="action" value="paygate_standalone_wp_payment">
    <table class="form-table">
        <tbody>
            <tr>
                <td style="background-color: transparent; width:50%;" colspan="2">
                    <input style="width:100%" type="email" name="paygate_standalone_payment_email"
                    placeholder="Email" required="">
                </td>
                <td style="background-color: transparent;" colspan="1">
                    <input style="width:100%" type="number" name="paygate_standalone_payment_amount"
                    step="0.01" placeholder="Amount" required="">
                </td>
                <td style="background-color: transparent;" colspan="1">
                    <button style="width:100%" class="g-recaptcha"
        data-sitekey="$recaptcha_key"
        data-callback='onSubmit'
        data-action='paygate_standalone_wp_payment' type="submit">Pay Now</button>
                </td>
            </tr>
        </tbody>
    </table>
</form>
HTML;
}

/** @noinspection PhpUnused */
function addPaygateStandalonePaymentSuccessShortcode(): string
{
    $reference = isset($_REQUEST['reference']) ? esc_html(
        htmlspecialchars($_REQUEST['reference'])
    ) : 'N/A';
    $amount    = isset($_REQUEST['amount']) ? esc_html(htmlspecialchars($_REQUEST['amount'])) / 100 : 'N/A';

    return <<<HTML
<p>Reference: $reference</p>
<p>Amount: $amount</p>
HTML;
}

/** @noinspection PhpUnused */
function addPaygateStandalonePaymentFailureShortcode(): string
{
    $reference = isset($_REQUEST['reference']) ? esc_html(
        htmlspecialchars($_REQUEST['reference'])
    ) : 'N/A';
    $reason    = isset($_REQUEST['reason']) ? esc_html(htmlspecialchars($_REQUEST['reason'])) : 'N/A';
    $amount    = isset($_REQUEST['amount']) ? esc_html(htmlspecialchars($_REQUEST['amount'])) / 100 : 'N/A';

    return <<<HTML
<p>Reference: $reference</p>
<p>Reason: $reason</p>
<p>Amount: $amount</p>
HTML;
}

/** @noinspection PhpUnused */
function registerPaygateStandalonePluginPage(): void
{
    add_menu_page(
        'Paygate Standalone',
        'Paygate Standalone',
        'manage_options',
        'paygate_standalone_plugin_settings',
        'paygateStandaloneOptionPageContent'
    );
}

/** @noinspection PhpUnused */
function paygateStandaloneOptionPageContent(): void
{
    ?>
    <h2>Paygate Standalone Payment Plugin</h2>
    <h3>Plugin Settings</h3>
    <!--suppress HtmlUnknownTarget -->
    <form method="post" action="options.php">
        <?php
        settings_fields('paygate_standalone_plugin_options'); ?>
        <table class="form-table" aria-describedby="setting_table">
            <tbody>
            <tr>
                <th scope="row">Title</th>
                <td>
                    <label for="paygate_standalone_title"></label>
                    <input type="text" name="paygate_standalone_title" id="paygate_standalone_title"
                                                                        value="<?php
                                                                        echo get_option(
                                                                            'paygate_standalone_title'
                                                                        ); ?>"><br>
                    <span class="description"> Enter Title to be displayed on Payment page </span>
                </td>
            </tr>
            <tr>
                <th scope="row">Paygate ID</th>
                <td>
                    <label for="paygate_standalone_paygate_id"></label>
                    <input type="text" name="paygate_standalone_paygate_id" id="paygate_standalone_paygate_id"
                                                                             value="<?php
                                                                             echo get_option(
                                                                                 'paygate_standalone_paygate_id'
                                                                             ); ?>"><br>
                    <span class="description"> Enter Paygate ID </span>
                </td>
            </tr>
            <tr>
                <th scope="row">Encryption Key</th>
                <td>
                    <label for="paygate_standalone_encryption_key"></label>
                    <input type="text" name="paygate_standalone_encryption_key" id="paygate_standalone_encryption_key"
                           value="<?php
                           echo get_option('paygate_standalone_encryption_key'); ?>"><br>
                    <span class="description"> Enter Encryption Key </span>
                </td>
            </tr>
            <tr>
                <th scope="row">Recaptcha Key</th>
                <td>
                    <label for="paygate_standalone_recaptcha_key"></label>
                    <input type="text" name="paygate_standalone_recaptcha_key" id="paygate_standalone_recaptcha_key"
                           value="<?php
                           echo get_option('paygate_standalone_recaptcha_key'); ?>"><br>
                    <span class="description"> Enter Recaptcha Key </span>
                </td>
            </tr>

            <tr>
                <th scope="row">Recaptcha Secret</th>
                <td>
                    <label for="paygate_standalone_recaptcha_secret"></label>
                    <input type="text" name="paygate_standalone_recaptcha_secret"
                           id="paygate_standalone_recaptcha_secret"
                           value="<?php
                           echo get_option('paygate_standalone_recaptcha_secret'); ?>"><br>
                    <span class="description"> Enter Recaptcha Secret </span>
                </td>
            </tr>

            <tr>
                <th scope="row">Test Mode</th>
                <td>
                    <label for="paygate_standalone_test_mode"></label>
                    <input type="checkbox" name="paygate_standalone_test_mode" id="paygate_standalone_test_mode"
                                                                            value="yes"
                        <?php
                        echo get_option('paygate_standalone_test_mode') == 'yes' ? 'checked' : ''; ?>><br>
                    <span class="description">
                        Use test account if enabled. No real transactions processed
                    </span>
                </td>
            </tr>

            <tr>
                <th scope="row">Failure URL</th>
                <td>
                    <label for="paygate_standalone_failure_url"></label>
                    <input type="text" name="paygate_standalone_failure_url" id="paygate_standalone_failure_url"
                           value="<?php
                           echo get_option('paygate_standalone_failure_url'); ?>"><br>
                    <span class="description">
                        The URL (full or slug) to which the user is redirected on payment failure
                    </span>
                </td>
            </tr>

            <tr>
                <th scope="row">Success URL</th>
                <td>
                    <label for="paygate_standalone_success_url"></label>
                    <input type="text" name="paygate_standalone_success_url" id="paygate_standalone_success_url"
                           value="<?php
                           echo get_option('paygate_standalone_success_url'); ?>"><br>
                    <span class="description">
                        The URL (full or slug) to which the user is redirected on payment success
                    </span>
                </td>
            </tr>
            </tbody>
        </table>
        <?php
        submit_button('Save Settings'); ?>
    </form>
    <?php
}


function validateForm()
{
    $referer_location = $_SERVER['HTTP_REFERER'];
    $token            = $_POST['g-recaptcha-response'];
    $action           = $_POST['action'];
    $secret        = get_option('paygate_standalone_recaptcha_secret');

    // call curl to POST request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('secret' => $secret, 'response' => $token)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $arrResponse = json_decode($response, true);

    // verify the response
    if ($arrResponse["success"] == '1' && $arrResponse["action"] == $action && $arrResponse["score"] >= 0.5) {
        return true;
    } else {
        // spam submission
        header('Location:' . $referer_location);
        exit(0);
    }
}
