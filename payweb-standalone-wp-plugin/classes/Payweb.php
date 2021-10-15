<?php
/*P
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the MIT License
 */

class Payweb
{

    protected static $test_paygate_id = '10011072130';
    protected static $test_encryption_key = 'secret';
    protected $api_url;
    protected $pay_url;

    public function __construct($test_mode)
    {
        if ($test_mode) {
            $this->paygate_id     = self::$test_paygate_id;
            $this->encryption_key = self::$test_encryption_key;
        } else {
            $this->paygate_id     = get_option('payweb_standalone_paygate_id');
            $this->encryption_key = get_option('payweb_standalone_encryption_key');
        }
    }

    /**
     * @return false|mixed|string|void
     */
    public function get_paygate_id()
    {
        return $this->paygate_id;
    }

    /**
     * @return false|mixed|string|void
     */
    public function get_encryption_key()
    {
        return $this->encryption_key;
    }

    /**
     * @return string
     */
    public function get_api_url(): string
    {
        return $this->api_url;
    }

}
