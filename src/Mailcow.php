<?php
/**
 * Copyright 2024 Noracloud
 * Copyright 2022-2023 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright Noracloud (https://www.noracloud.fr)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
class Server_Manager_Mailcow extends Server_Manager
{
    /**
     * Method is called just after obejct contruct is complete.
     * Add required parameters checks here.
     */
    public function init()
    {
    }

    /**
     * Return server manager parameters.
     */
    public static function getForm(): array
    {
        return [
            'label' => 'Mailcow',
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'accesshash',
                            'type' => 'text',
                            'label' => 'API Key',
                            'placeholder' => 'API Key to authenticate Mailcow service',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Returns link to account management page.
     *
     * @return string
     */
    public function getLoginUrl(Server_Account $account = null)
    {
        return 'https://' . $this->_config['host'] . '/';
    }

    /**
     * Returns link to reseller account management.
     *
     * @return string
     */
    public function getResellerLoginUrl(Server_Account $account = null)
    {
        return $this->getLoginUrl();
    }

    private function _makeRequest($type, $path, $params = [])
    {
        $host = 'https://' . $this->_config['host'] . '/api/v1/';

        // Server credentials
        $headers['X-API-Key'] = $this->_config['accesshash'];

        // Send POST query
        $client = $this->getHttpClient()->withOptions([
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => 10
        ]);
        $response = $client->request($type, $host . $path, [
            'headers' => $headers,
            'body' => $params != [] ? $params : null
        ]);
        $result = $response->getContent();

        if (str_contains($result, 'authentication failed')) {
            throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration', [':type:' => 'Mailcow']);
        } elseif (str_contains($result, 'error')) {
            error_log("Mailcow returned error $result for the " . $params['cmd'] . 'command');
        }

        return $result;
    }

    private function _getPackageName(Server_Package $package)
    {
        $name = $package->getName();

        return $name;
    }

    /**
     * This method is called to check if configuration is correct
     * and class can connect to server.
     *
     * @return bool
     */
    public function testConnection()
    {

        // Make request and check sys info
        $result = $this->_makeRequest('GET', 'get/status/version');
        if (str_contains($result, 'version')) {
            return true;
        } else {
            throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration', [':type:' => 'Mailcow']);
        }

        return true;
    }

}
