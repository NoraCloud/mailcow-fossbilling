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
     * Method is called just after object construct is complete.
     * Add required parameters checks here.
     */
    public function init()
    {
        if (empty($this->_config['host'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => 'Mailcow', ':missing' => 'hostname'], 2001);
        }
        if (empty($this->_config['accesshash'])) {
            throw new Server_Exception('The ":server_manager" server manager is not fully configured. Please configure the :missing', [':server_manager' => 'Mailcow', ':missing' => 'API key'], 2001);
        }
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
                        [
                            'name' => 'allow_self_signed',
                            'type' => 'radio',
                            'label' => 'Allow self-signed / invalid TLS certificate (insecure)',
                            'options' => [
                                '0' => 'No (recommended)',
                                '1' => 'Yes',
                            ],
                            'default_value' => '0',
                            'required' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Override default username generator method
     *
     * @return string
     */
    public function generateUsername($domainName)
    {
        return     "adm_" . str_replace(".", "", $domainName);
    }

    /**
     * Returns link to account management page.
     *
     * @return string
     */
    public function getLoginUrl(?Server_Account $account = null)
    {
        return 'https://' . $this->_config['host'] . '/';
    }

    /**
     * Returns link to reseller account management.
     *
     * @return string
     */
    public function getResellerLoginUrl(?Server_Account $account = null)
    {
        return $this->getLoginUrl();
    }

    /**
     * Send a request to the Mailcow API.
     *
     * @return array{status:int, data:mixed, raw:string}
     */
    private function _makeRequest(string $type, string $path, array $params = []): array
    {
        $host = 'https://' . $this->_config['host'] . '/api/v1/';

        // Server headers
        $headers['X-API-Key'] = $this->_config['accesshash'];
        $headers['Content-Type'] = "application/json";

        // Send POST query
        $client = $this->getHttpClient()->withOptions([
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => 25
        ]);
        $response = $client->request($type, $host . $path, [
            'headers' => $headers,
            ...$params 
        ]);
        $result = $response->getContent();

        if (str_contains($result, 'authentication failed')) {
            throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration', [':type:' => 'Mailcow']);
        }

        return $result;
    }

    /**
     * Build the domain limits shared by create and package change.
     */
    private function _packageAttributes(Server_Package $p): array
    {
        $defQuota = $p->getQuota();
        $maxQuota = $this->_custom($p, 'maxquota', $defQuota);

        $attr = [
            'aliases' => $p->getMaxSubdomains(), // FOSSBilling "subdomains" limit is used as Mailcow alias limit
            'defquota' => $defQuota,             // default per-mailbox quota (MiB)
            'maxquota' => $maxQuota,             // max per-mailbox quota (MiB)
            'quota' => $maxQuota,                // total domain quota (MiB)
            'mailboxes' => $p->getMaxPop(),
        ];

        if ($status >= 400 || !$this->_isSuccessOrData($data)) {
            error_log("Mailcow returned HTTP $status with body $raw for the $path command");
        }

        return $attr;
    }

    /**
     * True if a response is not an explicit Mailcow error (used only for logging).
     */
    private function _isSuccessOrData($data): bool
    {
        foreach ($this->_entries($data) as $entry) {
            if (($entry['type'] ?? null) === 'error' || ($entry['type'] ?? null) === 'danger') {
                return false;
            }
        }

        return true;
    }

    /**
     * True only when the response has at least one entry and all are "success".
     */
    private function _isSuccess(array $result): bool
    {
        $entries = $this->_entries($result['data']);
        if ($result['status'] >= 400 || !$entries) {
            return false;
        }
        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) !== 'success') {
                return false;
            }
        }

        return true;
    }

    /**
     * Create new account on server.
     */
    public function createAccount(Server_Account $a)
    {
        $p = $a->getPackage();
        // Prepare POST query
        $domainData = [
            'json' => [
                "active" => "1",
                "aliases" => $p->getMaxSubdomains(),
                "backupmx" => "0",
                "gal" => true,
                "defquota" => $p->getQuota(),
                "description" => $a->getDomain() . " domain",
                "domain" => $a->getDomain(),
                "mailboxes" => $p->getMaxPop(),
                "maxquota" => $p->getCustomValue('maxquota'),
                "quota" => $p->getCustomValue('maxquota'),
                "relay_all_recipients" => "0",
                "rl_frame" => "h",
                "rl_value" => $p->getCustomValue('ratelimit'),
                "restart_sogo" => "10",
            ]
        ];

        // Create domain on mailcow
        $result1 = $this->_makeRequest('POST', 'add/domain', $domainData);
        if (str_contains($result1, 'success')) {
            // Create Domain Admin in mailcow
            $domainAdminData = [
                'json' => [
                    "active" => "1",
                    "domains" => $a->getDomain(),
                    "password" => $a->getPassword(),
                    "password2" => $a->getPassword(),
                    "username" => $this->generateUsername($a->getDomain())
                ]
            ];
            $result2 = $this->_makeRequest('POST', 'add/domain-admin', $domainAdminData);
            if (str_contains($result2, 'success')) {
                $domainAclData = [
                    'json' => [
                        "items" => [
                            $this->generateUsername($a->getDomain())
                        ],
                        "attr" => [
                            "da_acl" => [
                                "quarantine",
                                "login_as",
                                "sogo_access",
                                "app_passwds",
                                "bcc_maps",
                                "filters",
                                "spam_policy",
                                "protocol_access",
                                "smtp_ip_access",
                            ]
                        ]
                    ]
                ];
                $result3 = $this->_makeRequest('POST', 'edit/da-acl', $domainAclData);
                if (!str_contains($result3, 'success')) {
                    $placeholders = [':action:' => 'edit ACL', ':type:' => 'Mailcow'];

                    throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);

                } 

            }
            else {
                $placeholders = [':action:' => __trans('create domain'), ':type:' => 'Mailcow'];

                throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
            }

        } else {
            $placeholders = [':action:' => __trans('create user'), ':type:' => 'Mailcow'];

            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
        return true;
    }

    /**
     * Suspend account on server.
     */
    public function suspendAccount(Server_Account $a)
    {
        $domainData = [
            'json' => [
                "attr" => [
                    "active" => "0",
                ],
                "items" => $a->getDomain(),
            ]
        ];
        // Make request and suspend user
        $result = $this->_makeRequest('POST', 'edit/domain', $domainData);
        if (!str_contains($result, 'success')) {
            $placeholders = [':action:' => __trans('suspend account'), ':type:' => 'Mailcow'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
        return true;
    }

    /**
     * Unsuspend account on server.
     */
    public function unsuspendAccount(Server_Account $a)
    {
        $domainData = [
            'json' => [
                "attr" => [
                    "active" => "1",
                ],
                "items" => $a->getDomain(),
            ]
        ];
        // Make request and suspend user
        $result = $this->_makeRequest('POST', 'edit/domain', $domainData);
        if (!str_contains($result, 'success')) {
            $placeholders = [':action:' => __trans('unsuspend account'), ':type:' => 'Mailcow'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
        return true;
    }

    /**
     * Cancel account on server.
     */
    public function cancelAccount(Server_Account $a)
    {
        // Prepare POST query
        $domainData = [
            'json' => [
                $a->getDomain(),
            ]
        ];
        // Delete domain on mailcow
        $result1 = $this->_makeRequest('POST', 'delete/domain', $domainData);
        if (str_contains($result1, 'success')) {
            // Delete Domain Admin in mailcow
            $domainAdminData = [
                'json' => [
                    $this->generateUsername($a->getDomain()),
                ]
            ];
            /*
             *    NOTE : THE DOMAIN ADMIN SEEMS TO BE DELETE WHEN DELETEING DOMAIN
             *
             * $result2 = $this->_makeRequest('POST', 'delete/domain-admin', $domainAdminData);

            if (! str_contains($result2, 'success')) {
                $placeholders = [':action:' => __trans('delete domain'), ':type:' => 'Mailcow'];

                throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);

            } */
        } else if (str_contains($result1, 'domain_not_empty')) {
            $mailboxes = json_decode($this->_makeRequest('GET', 'get/mailbox/all/' . $a->getDomain()), true);
            if(count($mailboxes) > 0) {
                $mailboxesData = [
                    'json' => array_column(array_filter($mailboxes, function($item) use ($a) { return $item['domain'] == $a->getDomain(); }), 'username')
                ];
                $result2 = $this->_makeRequest('POST', 'delete/mailbox', $mailboxesData);
                if(!str_contains($result2, 'success')) {

                    $placeholders = [':action:' => __trans('delete mailbox'), ':type:' => 'Mailcow'];
                    throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);        
                }
            }

            $resources = json_decode($this->_makeRequest('GET', 'get/resource/all'), true);
            if(count($resources) > 0) {
                $resourcesData = [
                    'json' => array_column(array_filter($resources, function($item) use ($a) { return $item['domain'] == $a->getDomain(); }), 'name')
                ];
                $result3 = $this->_makeRequest('POST', 'delete/resource', $resourcesData);
                if(!str_contains($result3, 'success')) {
                    $placeholders = [':action:' => __trans('delete domain'), ':type:' => 'Mailcow'];
                    throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);        
                }
            }
            return $this->cancelAccount($a);
        } else {
            $placeholders = [':action:' => __trans('delete user'), ':type:' => 'Mailcow'];

            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }       
        return true;
    }

    /**
     * Change account package on server.
     */
    public function changeAccountPackage(Server_Account $a, Server_Package $p)
    {
        $packageData = [
            'json' => [
                'attr' => $this->_packageAttributes($p),
                'items' => [$a->getDomain()],
            ],
        ];

        if (!$this->_isSuccess($this->_makeRequest('POST', 'edit/domain', $packageData))) {
            $this->_fail('change account package');
        }

        return true;
    }

    /**
     * Change account password on server.
     */
    public function changeAccountPassword(Server_Account $a, $new)
    {
        $domainAdminData = [
            'json' => [
                'items' => [$this->generateUsername($a->getDomain())],
                'attr' => [
                    'password' => $new,
                    'password2' => $new,
                ],
            ],
        ];

        if (!$this->_isSuccess($this->_makeRequest('POST', 'edit/domain-admin', $domainAdminData))) {
            $this->_fail('change account password');
        }

        return true;
    }

    /**
     * Change account IP on server.
     */
    public function changeAccountIp(Server_Account $a, $new): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'Mailcow', ':action:' => __trans('changing the account IP')]);
    }
}
