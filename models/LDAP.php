<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/Address.php');

class LDAP {

    private static $ldap = null;
    protected $connection;
    protected $bind;
    protected $credentials;

    protected function __construct() {
        // singleton class
    }

    /**
     * Create LDAP connection
     * @return Object LDAP
     */
    public static function obtain() {
        if (is_null(self::$ldap)) {
            self::$ldap = new LDAP();
        }
        return self::$ldap;
    }

    /**
     * Return addresses that a user can access
     * @param  String $user LDAP username
     * @return Object Address
     */
    public function getUserRules($user) {
        $groupRules = $this->getGroupRules($user);
        $individualRules = $this->getIndividualRules($user);

        $rules = array_merge($groupRules, $individualRules);
        if (empty($rules)) {
            throw new \Exception("User $user has no access permissions");
        }
        return $this->consolidateRules($rules);
    }

    /**
     * Connect to LDAP server
     * @return null
     */
    protected function connect() {
        if (is_null($this->connection) || is_null($this->bind)) {
            
            $this->cr = $this->getCredentials();
            $this->connection = ldap_connect($this->cr["address"], $this->cr["port"]);
            $this->bind = ldap_bind($this->connection, $this->cr["bind"], $this->cr["bindPassword"]);
        }

    }

    /**
     * Return credentials for LDAP connection
     * @return Array 
     */
    protected function getCredentials() {
        return [
            "address" => LDAP_ADDRESS,
            "port" => LDAP_PORT,
            "bind" => LDAP_BIND,
            "bindPassword" => LDAP_BIND_PASSWORD,
            "baseDNIndividual" => LDAP_BASE_INDIVIDUAL,
            "filterIndividual" => LDAP_FILTER_INDIVIDUAL,
            "baseDNGroup" => LDAP_BASE_GROUP,
            "filterGroup" => LDAP_FILTER_GROUP
        ];
    }

    protected function searchLdap($baseDN, $filter, $singleLevel = false) {
        if ($singleLevel) {
            $search = ldap_list($this->connection, $baseDN, $filter);
        }
        else {
            $search = ldap_search($this->connection, $baseDN, $filter);
        }
        $results = ldap_get_entries($this->connection, $search);

        $neatResults = [];
        foreach ($results as $resultKey => $result) {
            if ($resultKey !== "count") {
                $neatResults[$resultKey] = [];
                $neatResults[$resultKey]["dn"] = $result["dn"];
                foreach ($result as $attrName => $attrValue) {
                    if (!is_numeric($attrName) && $attrName !== "count" && $attrName !== "dn") {
                        $neatResults[$resultKey][$attrName] = [];
                        foreach ($attrValue as $detailKey => $detail) {
                            if ($detailKey !== "count") {
                                $neatResults[$resultKey][$attrName][] = $detail;
                            }
                        }
                    }
                }
            }
        }
        return $neatResults;
    }
    /**
     * Retrieves LDAP rules for groups with which the user is associated
     * @param  String $user LDAP username
     * @return String[] Array of resource DNs user is permitted to access
     */
    protected function getGroupRules($user) {
        $this->connect();
        $userSearch = $this->cr["filterIndividual"] . $user;

        $results = $this->searchLdap($this->cr["baseDNGroup"], $this->cr["filterGroup"]);

        $accessRules = [];

        foreach ($results as $result) {
            $access = False;
            if ($members = $result["uniquemember"]) {
                foreach ($members as $member) {
                    if (mb_strpos($member, $userSearch) === 0) {
                        $access = True;
                        break;
                    }
                }
            }

            if ($access && array_key_exists("accessto", $result)) {
                foreach ($result["accessto"] as $accessibleDN) {
                    $accessRules[$accessibleDN] = 1;
                }
            }

        } 
        
        return $accessRules;
    }

    /**
     * Retrieves LDAP rules associated with the user individually
     * @param  String $user LDAP username
     * @return String[] Array of resource DNs user is permitted to access
     */
    protected function getIndividualRules($user) {
        $this->connect();

        $users = $this->searchLdap($this->cr["baseDNIndividual"], $this->cr["filterIndividual"] . $user);

        $accessRules = [];

        if (count($users) === 0) {
            throw new \Exception("$user could not be found on LDAP");
        }
        else if (count($users) > 1) {
            throw new \Exception("$user found multiple times on LDAP");
        }
        
        $user = $users[0];
        $results = [];
        if (array_key_exists("accessto", $user)) {
            foreach ($user["accessto"] as $accessibleDN) {
                $results[$accessibleDN] = 1;
            }
            return $results;
        }
    }

    /**
     * Consolidate accessible resources into Address objects
     * @param  String[] $rulesArray Array of resource DNs
     * @return Object[] Array of Address objects
     */
    protected function consolidateRules($rulesArray) {
        $addresses = [];
        foreach ($rulesArray as $ruleDN => $v) {
            $addresses = array_merge($addresses, $this->getNetwork($ruleDN));
        }
        
        return $addresses;
    }

    /**
     * Retrieves detailed information about a resource from LDAP and saves information in Address object
     * @param  String $ruleDN Resource DN
     * @return Object Address object
     */
    protected function getNetwork($ruleDN) {
        $this->connect();

        list($filter, $base) = explode(',', $ruleDN, 2);

        $ipResults = $this->searchLdap($base, $filter, true);

        $ip = $ipResults[0]["ipnetworknumber"][0];
        $netmask = $ipResults[0]["ipnetmasknumber"][0];

        $protocolResults = $this->searchLdap($ruleDN, "cn=*", true);

        $addresses = [];
        if (count($protocolResults) > 0) {
            foreach ($protocolResults as $result) {
                $address = new Address($ruleDN);
                $address->ip = $ip;
                $address->netmask = $netmask;
                $address->protocol = $result["ipserviceprotocol"][0];
                $address->port = $result["ipserviceport"][0];

                $addresses[] = $address;
            }
        }
        else {
            $address = new Address($ruleDN);
            $address->ip = $ip;
            $address->netmask = $netmask;

            $addresses[] = $address;
        }

        return $addresses;
    }
}
