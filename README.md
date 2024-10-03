# PayWeb_WordPress_Standalone

## Paygate Standalone plugin v1.0.3 for WordPress v6.6.2

This is the Paygate Standalone plugin for WordPress. Please feel free to contact the Payfast support team at
support@payfast.help should you require any assistance.

## Installation

1. **Download the Plugin**
    - Visit the [releases page](https://github.com/Paygate/PayWeb_WordPress_Standalone/releases) and download the latest release (currently v1.0.3).

2. **Install the Plugin**
    - Log in to your WordPress Admin panel.
    - Navigate to **Plugins > Add New > Upload Plugin**.
    - Click **Choose File** and select `paygate-standalone-wp-plugin.zip` from the unzipped folder.
    - Click **Install Now**.
    - After installation, click **Activate Plugin**.

3. **Configure the Plugin**
    - Go to **WordPress Admin > Paygate Standalone** and configure the plugin settings according to your needs.

4. **Add Shortcodes**
    - Insert the following shortcodes on the appropriate pages as per your plugin settings:
        - For success page: `[paygate_standalone_payment_success]`
        - For failure page: `[paygate_standalone_payment_failure]`
    - Add the primary checkout shortcode on your main checkout page:
        - `[paygate_standalone_payment_checkout]`


## Collaboration

Please submit pull requests with any tweaks, features or fixes you would like to share.

