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
    /** Max passes when emptying a domain before deleting it. */
    private const CANCEL_MAX_ATTEMPTS = 3;

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

        $headers = [
            'X-API-Key' => $this->_config['accesshash'],
            'Content-Type' => 'application/json',
        ];

        $insecure = ($this->_config['allow_self_signed'] ?? '0') === '1';

        try {
            $client = $this->getHttpClient()->withOptions([
                'verify_peer' => !$insecure,
                'verify_host' => !$insecure,
                'timeout' => 25,
            ]);
            $response = $client->request($type, $host . $path, ['headers' => $headers] + $params);
            $status = $response->getStatusCode();
            // false = do not throw on 4xx/5xx, we want Mailcow's own error message
            $raw = $response->getContent(false);
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            error_log('Mailcow transport error for ' . $path . ': ' . $e->getMessage());
            throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration', [':type:' => 'Mailcow']);
        }

        if ($status === 401 || $status === 403 || str_contains($raw, 'authentication failed')) {
            throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration', [':type:' => 'Mailcow']);
        }

        $data = json_decode($raw, true);

        if ($status >= 400 || !$this->_isSuccessOrData($data)) {
            error_log("Mailcow returned HTTP $status with body $raw for the $path command");
        }

        return ['status' => $status, 'data' => $data, 'raw' => $raw];
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
     * Normalize a Mailcow action response to a list of entries.
     * Mailcow returns either [{type,msg},...] or a single {type,msg} object.
     */
    private function _entries($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['type'])) {
            return [$data];
        }

        return array_values(array_filter($data, 'is_array'));
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
     * Return list-type GET data (always an array).
     */
    private function _list(array $result): array
    {
        return (is_array($result['data']) && !isset($result['data']['type'])) ? $result['data'] : [];
    }

    private function _fail(string $action): never
    {
        throw new Server_Exception(
            'Failed to :action: on the :type: server, check the error logs for further details',
            [':action:' => __trans($action), ':type:' => 'Mailcow']
        );
    }

    /**
     * Read a custom package value with a fallback default.
     */
    private function _custom(Server_Package $p, string $key, $default = null)
    {
        $value = $p->getCustomValue($key);

        return ($value === null || $value === '') ? $default : $value;
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

        $rate = $this->_custom($p, 'ratelimit');
        if ($rate !== null) {
            $attr['rl_value'] = $rate;
            $attr['rl_frame'] = 'h';
        }

        return $attr;
    }

    /**
     * This method is called to check if configuration is correct
     * and class can connect to server.
     *
     * @return bool
     */
    public function testConnection()
    {
        $result = $this->_makeRequest('GET', 'get/status/version');
        if (is_array($result['data']) && isset($result['data']['version'])) {
            return true;
        }

        throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration', [':type:' => 'Mailcow']);
    }

    /**
     * Create new account on server.
     */
    public function createAccount(Server_Account $a)
    {
        $p = $a->getPackage();
        $domain = $a->getDomain();
        $adminUser = $this->generateUsername($domain);

        $domainData = [
            'json' => [
                'active' => '1',
                'backupmx' => '0',
                'gal' => '1',
                'description' => $domain . ' domain',
                'domain' => $domain,
                'relay_all_recipients' => '0',
                'restart_sogo' => '10',
            ] + $this->_packageAttributes($p),
        ];

        // 1. Create domain
        if (!$this->_isSuccess($this->_makeRequest('POST', 'add/domain', $domainData))) {
            $this->_fail('create domain');
        }

        // 2. Create domain admin
        $domainAdminData = [
            'json' => [
                'active' => '1',
                'domains' => $domain,
                'password' => $a->getPassword(),
                'password2' => $a->getPassword(),
                'username' => $adminUser,
            ],
        ];
        if (!$this->_isSuccess($this->_makeRequest('POST', 'add/domain-admin', $domainAdminData))) {
            $this->_rollbackCreate($domain, $adminUser);
            $this->_fail('create domain admin');
        }

        // 3. Set domain admin ACL
        $domainAclData = [
            'json' => [
                'items' => [$adminUser],
                'attr' => [
                    'da_acl' => [
                        'quarantine',
                        'login_as',
                        'sogo_access',
                        'app_passwds',
                        'bcc_maps',
                        'filters',
                        'spam_policy',
                        'protocol_access',
                        'smtp_ip_access',
                    ],
                ],
            ],
        ];
        if (!$this->_isSuccess($this->_makeRequest('POST', 'edit/da-acl', $domainAclData))) {
            $this->_rollbackCreate($domain, $adminUser);
            $this->_fail('edit ACL');
        }

        return true;
    }

    /**
     * Best-effort cleanup after a partially failed createAccount().
     * Never throws, so the original error is the one reported.
     */
    private function _rollbackCreate(string $domain, string $adminUser): void
    {
        try {
            $this->_makeRequest('POST', 'delete/domain-admin', ['json' => [$adminUser]]);
            $this->_makeRequest('POST', 'delete/domain', ['json' => [$domain]]);
        } catch (\Throwable $e) {
            error_log('Mailcow rollback failed for ' . $domain . ': ' . $e->getMessage());
        }
    }

    /**
     * Suspend account on server.
     */
    public function suspendAccount(Server_Account $a)
    {
        $this->_setActive($a, '0', 'suspend account');

        return true;
    }

    /**
     * Unsuspend account on server.
     */
    public function unsuspendAccount(Server_Account $a)
    {
        $this->_setActive($a, '1', 'unsuspend account');

        return true;
    }

    /**
     * Activate / deactivate both the domain and its domain admin.
     */
    private function _setActive(Server_Account $a, string $active, string $action): void
    {
        $domainData = [
            'json' => [
                'attr' => ['active' => $active],
                'items' => [$a->getDomain()],
            ],
        ];
        if (!$this->_isSuccess($this->_makeRequest('POST', 'edit/domain', $domainData))) {
            $this->_fail($action);
        }

        $adminData = [
            'json' => [
                'attr' => ['active' => $active],
                'items' => [$this->generateUsername($a->getDomain())],
            ],
        ];
        if (!$this->_isSuccess($this->_makeRequest('POST', 'edit/domain-admin', $adminData))) {
            $this->_fail($action);
        }
    }

    /**
     * Cancel account on server.
     */
    public function cancelAccount(Server_Account $a)
    {
        $domain = $a->getDomain();

        for ($attempt = 1; $attempt <= self::CANCEL_MAX_ATTEMPTS; ++$attempt) {
            $result = $this->_makeRequest('POST', 'delete/domain', ['json' => [$domain]]);

            if ($this->_isSuccess($result)) {
                // The domain admin is normally removed with the domain; clean up if it was not.
                try {
                    $this->_makeRequest('POST', 'delete/domain-admin', ['json' => [$this->generateUsername($domain)]]);
                } catch (\Throwable $e) {
                    // ignore: already deleted
                }

                return true;
            }

            if (!str_contains($result['raw'], 'domain_not_empty')) {
                $this->_fail('delete domain');
            }

            $this->_emptyDomain($domain);
        }

        $this->_fail('delete domain');
    }

    /**
     * Delete everything that prevents a domain from being removed.
     */
    private function _emptyDomain(string $domain): void
    {
        // Mailboxes (also removes their own aliases)
        $mailboxes = $this->_list($this->_makeRequest('GET', 'get/mailbox/all/' . rawurlencode($domain)));
        $usernames = array_column($mailboxes, 'username');
        if ($usernames) {
            if (!$this->_isSuccess($this->_makeRequest('POST', 'delete/mailbox', ['json' => array_values($usernames)]))) {
                $this->_fail('delete mailbox');
            }
        }

        // Remaining aliases
        $aliases = $this->_list($this->_makeRequest('GET', 'get/alias/all'));
        $aliasIds = array_column(array_filter($aliases, fn ($item) => ($item['domain'] ?? null) === $domain), 'id');
        if ($aliasIds) {
            if (!$this->_isSuccess($this->_makeRequest('POST', 'delete/alias', ['json' => array_values($aliasIds)]))) {
                $this->_fail('delete alias');
            }
        }

        // Resources
        $resources = $this->_list($this->_makeRequest('GET', 'get/resource/all'));
        $resourceNames = array_column(array_filter($resources, fn ($item) => ($item['domain'] ?? null) === $domain), 'name');
        if ($resourceNames) {
            if (!$this->_isSuccess($this->_makeRequest('POST', 'delete/resource', ['json' => array_values($resourceNames)]))) {
                $this->_fail('delete resource');
            }
        }
    }

    public function synchronizeAccount(Server_Account $a)
    {
        $this->getLog()->info('Synchronizing account with server ' . $a->getUsername());
        $new = clone $a;

        // @example - retrieve username from server and set it to cloned object
        // $new->setUsername('newusername');
        return $new;
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
     * Change account username on server.
     */
    public function changeAccountUsername(Server_Account $a, $new): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'Mailcow', ':action:' => __trans('username changes')]);
    }

    /**
     * Change account domain on server.
     */
    public function changeAccountDomain(Server_Account $a, $new): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'Mailcow', ':action:' => __trans('changing the account domain')]);
    }

    /**
     * Change account IP on server.
     */
    public function changeAccountIp(Server_Account $a, $new): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'Mailcow', ':action:' => __trans('changing the account IP')]);
    }
}
