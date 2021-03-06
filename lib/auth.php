<?php

/**
 * Authentication classes
 * @package framework
 * @subpackage auth
 */

/**
 * Base class for authentication
 * Creating a new authentication method requires extending this class
 * and overriding the check_credentials method
 * @abstract
 */
abstract class Hm_Auth {

    /* site configuration object */
    protected $site_config = false;

    /* bool flag defining if users are internal */
    static public $internal_users = false;

    /**
     * Assign site config
     * @param object $config site config
     * @return void
     */
    public function __construct($config) {
        $this->site_config = $config;
    }

    /**
     * This is the method new auth mechs need to override.
     * @param string $user username
     * @param string $pass password
     * @return bool true if the user is authenticated, false otherwise
     */
    abstract public function check_credentials($user, $pass);

    /**
     * Optional method for auth mech to save login details
     * @param object $session session object
     * @return void
     */
    public function save_auth_detail($session) {}
}

/**
 * Used for testing
 */
class Hm_Auth_None extends Hm_Auth {

    /**
     * This is the method new auth mechs need to override.
     * @param string $user username
     * @param string $pass password
     * @return bool true if the user is authenticated, false otherwise
     */
    public function check_credentials($user, $pass) {
        return true;
    }

    /*
     * Create a new user
     * @param string $user username
     * @param string $pass password
     * @return bool
     */
    public function create($user, $pass) {
        return true;
    }
}

/**
 * Authenticate against an included DB
 */
class Hm_Auth_DB extends Hm_Auth {

    /* bool flag indicating this is an internal user setup */
    static public $internal_users = true;

    /**
     * Send the username and password to the configured DB for authentication
     * @param string $user username
     * @param string $pass password
     * @return bool true if authentication worked
     */
    public function check_credentials($user, $pass) {
        if ($this->connect()) {
            $sql = $this->dbh->prepare("select hash from hm_user where username = ?");
            if ($sql->execute(array($user))) {
                $row = $sql->fetch();
                if ($row['hash'] && Hm_Crypt::check_password($pass, $row['hash'])) {
                    return true;
                }
            }
        }
        sleep(2);
        Hm_Debug::add(sprintf('DB AUTH failed for %s', $user));
        return false;
    }

    /**
     * Delete a user account from the db
     * @param string $user username
     * @return bool true if successful
     */
    public function delete($user) {
        if ($this->connect()) {
            $sql = $this->dbh->prepare("delete from hm_user where username = ?");
            if ($sql->execute(array($user)) && $sql->rowCount() == 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create a new or re-use an existing DB connection
     * @return bool true if the connection is available
     */
    protected function connect() {
        $this->dbh = Hm_DB::connect($this->site_config);
        if ($this->dbh) {
            return true;
        }
        Hm_Debug::add(sprintf('Unable to connect to the DB auth server %s', $this->site_config->get('db_host')));
        return false;
    }

    /**
     * Change the password for a user in the DB
     * @param string $user username
     * @param string $pass password
     * @return bool true on success
     */
    public function change_pass($user, $pass) {
        $this->connect();
        $hash = Hm_Crypt::hash_password($pass);
        $sql = $this->dbh->prepare("update hm_user set hash=? where username=?");
        if ($sql->execute(array($hash, $user)) && $sql->rowCount() == 1) {
            Hm_Msgs::add("Password changed");
            return true;
        }
        return false;
    }

    /**
     * Create a new user in the DB
     * @param object $request request details
     * @param string $user username
     * @param string $pass password
     * @return bool
     */
    public function create($user, $pass) {
        $this->connect();
        $created = false;
        $sql = $this->dbh->prepare("select username from hm_user where username = ?");
        if ($sql->execute(array($user))) {
            $res = $sql->fetch();
            if (!empty($res)) {
                Hm_Msgs::add("ERRThat username is already in use");
            }
            else {
                $sql = $this->dbh->prepare("insert into hm_user values(?,?)");
                $hash = Hm_Crypt::hash_password($pass);
                if ($sql->execute(array($user, $hash))) {
                    Hm_Msgs::add("Account created");
                    $created = true;
                }
            }
        }
        return $created;
    }
}

/**
 * Authenticate against an IMAP server
 */
class Hm_Auth_IMAP extends Hm_Auth {

    /**
     * Assign site config, get required libs
     * @param object $config site config
     * @return void
     */
    public function __construct($config) {
        $this->site_config = $config;
        require_once APP_PATH.'modules/imap/hm-imap.php';
    }

    /* IMAP authentication server settings */
    private $imap_settings = array();

    /**
     * Send the username and password to the configured IMAP server for authentication
     * @param string $user username
     * @param string $pass password
     * @return bool true if authentication worked
     */
    public function check_credentials($user, $pass) {
        $imap = new Hm_IMAP();
        list($server, $port, $tls) = $this->get_imap_config();
        if ($user && $pass && $server && $port) {
            $this->imap_settings = array(
                'server' => $server,
                'port' => $port,
                'tls' => $tls,
                'username' => $user,
                'password' => $pass,
                'no_caps' => false,
                'blacklisted_extensions' => array('enable')
            );
            $imap->connect($this->imap_settings);
            if ($imap->get_state() == 'authenticated') {
                return true;
            }
            if ($imap->get_state() != 'connected') {
                Hm_Debug::add($imap->show_debug(true));
                Hm_Debug::add(sprintf('Unable to connect to the IMAP auth server %s', $server));
                return false;
            }
            Hm_Debug::add($imap->show_debug(true));
            Hm_Debug::add(sprintf('IMAP AUTH failed for %s', $user));
            return false;
        }
        Hm_Debug::add($imap->show_debug(true));
        Hm_Debug::add('Invalid IMAP auth configuration settings');
        return false;
    }

    /**
     * Save IMAP server details
     * @param object $session session object
     * @return void
     */
    public function save_auth_detail($session) {
        $session->set('imap_auth_server_settings', $this->imap_settings);
    }

    /**
     * Get IMAP server details from the site config
     * @return array list of required details
     */
    private function get_imap_config() {
        $server = $this->site_config->get('imap_auth_server', false);
        $port = $this->site_config->get('imap_auth_port', false);
        $tls = $this->site_config->get('imap_auth_tls', false);
        return array($server, $port, $tls);
    }
}

/**
 * Authenticate against a POP3 server
 */
class Hm_Auth_POP3 extends Hm_Auth {

    /* POP3 authentication server settings */
    private $pop3_settings = array();

    /**
     * Assign site config, get required libs
     * @param object $config site config
     * @return void
     */
    public function __construct($config) {
        $this->site_config = $config;
        require_once APP_PATH.'modules/pop3/hm-pop3.php';
    }

    /**
     * Send the username and password to the configured POP3 server for authentication
     * @param string $user username
     * @param string $pass password
     * @return bool true if authentication worked
     */
    public function check_credentials($user, $pass) {
        $pop3 = new Hm_POP3();
        $authed = false;
        list($server, $port, $tls) = $this->get_pop3_config();
        if ($user && $pass && $server && $port) {
            $this->pop3_settings = array(
                'server' => $server,
                'port' => $port,
                'tls' => $tls,
                'username' => $user,
                'password' => $pass,
                'no_caps' => true
            );
            $pop3->server = $server;
            $pop3->port = $port;
            $pop3->tls = $tls;
            if ($pop3->connect()) {
                if ($pop3->auth($user, $pass)) {
                    return true;
                }
                Hm_Debug::add($pop3->puke());
                Hm_Debug::add(sprintf('POP3 AUTH failed for %s', $user));
                return false;
            }
            Hm_Debug::add($pop3->puke());
            Hm_Debug::add(sprintf('Unable to connect to the POP3 auth server %s', $server));
            return false;
        }
        Hm_Debug::add($pop3->puke());
        Hm_Debug::add('Invalid POP3 auth configuration settings');
        return false;
    }

    /**
     * Get POP3 server details from the site config
     * @return array list of required details
     */
    private function get_pop3_config() {
        $server = $this->site_config->get('pop3_auth_server', false);
        $port = $this->site_config->get('pop3_auth_port', false);
        $tls = $this->site_config->get('pop3_auth_tls', false);
        return array($server, $port, $tls);
    }

    /**
     * Save POP3 server details
     * @param object $session session object
     * @return void
     */
    public function save_auth_detail($session) {
        $session->set('pop3_auth_server_settings', $this->pop3_settings);
    }
}

/**
 * Authenticate against an LDAP server
 */
class Hm_Auth_LDAP extends Hm_Auth {

    protected $config = array();
    protected $fh;
    protected $source = 'ldap';

    private function connect_details() {
        $prefix = 'ldaps://';
        $server = 'localhost';
        $port = 389;
        if (array_key_exists('server', $this->config)) {
            $server = $this->config['server'];
        }
        if (array_key_exists('port', $this->config)) {
            $port = $this->config['port'];
        }
        if (array_key_exists('enable_tls', $this->config) && !$this->config['enable_tls']) {
            $prefix = 'ldap://';
        }
        return $prefix.$server.':'.$port;
    }

    public function check_credentials($user, $pass) {
        list($server, $port, $tls, $base_dn) = $this->get_ldap_config();
        if ($server && $port && $base_dn) {
            $user = sprintf('cn=%s,%s', $user, $base_dn);
            $this->config = array(
                'server' => $server,
                'port' => $port,
                'enable_tls' => $tls,
                'base_dn' => $base_dn,
                'user' => $user,
                'pass' => $pass
            );
            return $this->connect();
        }
        Hm_Debug::add('Invalid LDAP auth configuration settings');
        return false;
    }

    private function get_ldap_config() {
        $server = $this->site_config->get('ldap_auth_server', false);
        $port = $this->site_config->get('ldap_auth_port', false);
        $tls = $this->site_config->get('ldap_auth_tls', false);
        $base_dn = $this->site_config->get('ldap_auth_base_dn', false);
        return array($server, $port, $tls, $base_dn);

    }

    public function connect() {
        if (!Hm_Functions::function_exists('ldap_connect')) {
            return false;
        }
        $uri = $this->connect_details();
        $this->fh = @ldap_connect($uri);
        if ($this->fh) {
            ldap_set_option($this->fh, LDAP_OPT_PROTOCOL_VERSION, 3);
            return $this->auth();
        }
        Hm_Debug::add(sprintf('Unable to connect to the LDAP auth server %s', $this->config['server']));
        return false;
    }

    protected function auth() {
        $result = @ldap_bind($this->fh, $this->config['user'], $this->config['pass']);
        ldap_unbind($this->fh);
        if (!$result) {
            Hm_Debug::add(sprintf('LDAP AUTH failed for %s', $this->config['user']));
        }
        return $result;
    }
}
