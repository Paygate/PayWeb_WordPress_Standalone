<?php /** @noinspection PhpUnused */

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the MIT License
 */

class Paygate
{

    protected static string $testPaygateId = '10011072130';
    protected static string $testEncryptionKey = 'secret';
    protected string $apiUrl;
    /** @noinspection PhpUnused */
    protected string $payUrl;
    /**
     * @var false|mixed|string|null
     */
    private mixed $paygateID;
    /**
     * @var false|mixed|string|null
     */
    private mixed $encryptionKey;

    public function __construct($test_mode)
    {
        if ($test_mode) {
            $this->paygateID     = self::$testPaygateId;
            $this->encryptionKey = self::$testEncryptionKey;
        } else {
            $this->paygateID     = get_option('paygate_standalone_paygate_id');
            $this->encryptionKey = get_option('paygate_standalone_encryption_key');
        }
    }

    /**
     * @return false|mixed|string|void
     */
    public function getPaygateId()
    {
        return $this->paygateID;
    }

    /**
     * @return false|mixed|string|void
     */
    public function getEncryptionKey()
    {
        return $this->encryptionKey;
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

}
