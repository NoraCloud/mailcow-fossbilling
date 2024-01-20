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

    /**
     * Methods retrieves information from server, assign's new values to
     * cloned Server_Account object and returns it.
     *
     * @return Server_Account
     */
    public function synchronizeAccount(Server_Account $a)
    {
        $this->getLog()->info('Synchronizing account with server ' . $a->getUsername());
        $new = clone $a;

        // @example - retrieve username from server and set it to cloned object
        // $new->setUsername('newusername');
        return $new;
    }

    /**
     * Create new account on server.
     */
    public function createAccount(Server_Account $a)
    {
        $p = $a->getPackage();
        $client = $a->getClient();
        // Prepare POST query
        $domaindata = [
            'json' => [
                "active" => "1",
                "aliases" => $p->getMaxSubdomains(),
                "backupmx" => "0",
                "defquota" => $p->getQuota(),
                "description" => $a->getDomain() . " domain",
                "domain" => $a->getDomain(),
                "mailboxes" => $p->getMaxPop(),
                "maxquota" => "10240",
                "quota" => "10240",
                "relay_all_recipients" => "0",
                "rl_frame" => "s",
                "rl_value" => "10",
                "restart_sogo" => "10",
            ]
        ];
        // Create domain on mailcow
        $result1 = $this->_makeRequest('POST', 'add/domain', $domaindata);
        if (str_contains($result1, 'success') {
            // Create Domain Admin in mailcow
            $domainAdminData = [
                'json' => [
                    "active" => "1",
                    "domains" => $a->getDomain(),
                    "password" => $a->getPassword(),
                    "password2" => $a->getPassword(),
                    "username" => "adm_" . str_replace(".", "", $a->getDomain())
                ]
            ];
            $result2 = $this->_makeRequest('POST', 'add/domain-admin', $domainAdminData);

        } else {
            $placeholders = [':action:' => __trans('create user'), ':type:' => 'Mailcow'];

            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
        if (! str_contains($result2, 'success')) {
            $placeholders = [':action:' => __trans('create domain'), ':type:' => 'Mailcow'];

            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
        return true;
    }

}
