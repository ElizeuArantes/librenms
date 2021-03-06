<?php
/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * libreNMS HTTP-Authentication and LDAP Authorization Library
 * @author Maximilian Wilhelm <max@rfc2324.org>
 * @copyright 2016 LibreNMS, Barbarossa
 * @license GPL
 * @package LibreNMS
 * @subpackage Authentication
 *
 * This Authentitation / Authorization module provides the ability to let
 * the webserver (e.g. Apache) do the user Authentication (using Kerberos
 * f.e.) and let libreNMS do the Authorization of the already known user.
 * Authorization and setting of libreNMS user level is done by LDAP group
 * names specified in the configuration file. The group configuration is
 * basicly copied from the existing ldap Authentication module.
 *
 * Most of the code is copied from the http-auth and ldap Authentication
 * modules already existing.
 *
 * To save lots of redundant queries to the LDAP server and speed up the
 * libreNMS WebUI, all information is cached within the PHP $_SESSION as
 * long as specified in $config['auth_ldap_cache_ttl'] (Default: 300s).
 */

namespace LibreNMS\Authentication;

use LibreNMS\Config;
use LibreNMS\Exceptions\AuthenticationException;

class LdapAuthorizationAuthorizer extends AuthorizerBase
{
    protected $ldap_connection;
    protected static $AUTH_IS_EXTERNAL = 1;

    public function __construct()
    {
        if (! isset($_SESSION['username'])) {
            $_SESSION['username'] = '';
        }

        if (!function_exists('ldap_connect')) {
            throw new AuthenticationException("PHP does not support LDAP, please install or enable the PHP LDAP extension.");
        }

        /**
         * Set up connection to LDAP server
         */
        $this->ldap_connection = @ldap_connect(Config::get('auth_ldap_server'), Config::get('auth_ldap_port'));
        if (! $this->ldap_connection) {
            throw new AuthenticationException('Fatal error while connecting to LDAP server ' . Config::get('auth_ldap_server') . ':' . Config::get('auth_ldap_port') . ': ' . ldap_error($this->ldap_connection));
        }
        if (Config::get('auth_ldap_version')) {
            ldap_set_option($this->ldap_connection, LDAP_OPT_PROTOCOL_VERSION, Config::get('auth_ldap_version'));
        }

        if (Config::get('auth_ldap_starttls') && (Config::get('auth_ldap_starttls') == 'optional' || Config::get('auth_ldap_starttls') == 'require')) {
            $tls = ldap_start_tls($this->ldap_connection);
            if (Config::get('auth_ldap_starttls') == 'require' && $tls === false) {
                throw new AuthenticationException('Fatal error: LDAP TLS required but not successfully negotiated:' . ldap_error($this->ldap_connection));
            }
        }
    }


    public function authenticate($username, $password)
    {
        if (isset($_SERVER['REMOTE_USER'])) {
            $_SESSION['username'] = mres($_SERVER['REMOTE_USER']);

            if ($this->userExists($_SESSION['username'])) {
                return true;
            }

            $_SESSION['username'] = Config::get('http_auth_guest');
            return true;
        }

        throw new AuthenticationException();
    }

    public function userExists($username, $throw_exception = false)
    {
        if ($this->authLdapSessionCacheGet('user_exists')) {
            return 1;
        }

        $filter  = '(' . Config::get('auth_ldap_prefix') . $username . ')';
        $search  = ldap_search($this->ldap_connection, trim(Config::get('auth_ldap_suffix'), ','), $filter);
        $entries = ldap_get_entries($this->ldap_connection, $search);
        if ($entries['count']) {
            /*
         * Cache positiv result as this will result in more queries which we
         * want to speed up.
         */
            $this->authLdapSessionCacheSet('user_exists', 1);
            return 1;
        }

        /*
         * Don't cache that user doesn't exists as this might be a misconfiguration
         * on some end and the user will be happy if it "just works" after the user
         * has been added to LDAP.
         */
        return 0;
    }


    public function getUserlevel($username)
    {
        $userlevel = $this->authLdapSessionCacheGet('userlevel');
        if ($userlevel) {
            return $userlevel;
        } else {
            $userlevel = 0;
        }

        // Find all defined groups $username is in
        $filter  = '(&(|(cn=' . join(')(cn=', array_keys(Config::get('auth_ldap_groups'))) . '))(' . Config::get('auth_ldap_groupmemberattr') .'=' . $this->getMembername($username) . '))';
        $search  = ldap_search($this->ldap_connection, Config::get('auth_ldap_groupbase'), $filter);
        $entries = ldap_get_entries($this->ldap_connection, $search);

        // Loop the list and find the highest level
        foreach ($entries as $entry) {
            $groupname = $entry['cn'][0];
            $authLdapGroups = Config::get('auth_ldap_groups');
            if ($authLdapGroups[$groupname]['level'] > $userlevel) {
                $userlevel = $authLdapGroups[$groupname]['level'];
            }
        }

        $this->authLdapSessionCacheSet('userlevel', $userlevel);
        return $userlevel;
    }



    public function getUserid($username)
    {
        $user_id = $this->authLdapSessionCacheGet('userid');
        if (isset($user_id)) {
            return $user_id;
        } else {
            $user_id = -1;
        }

        $filter  = '(' . Config::get('auth_ldap_prefix') . $username . ')';
        $search  = ldap_search($this->ldap_connection, trim(Config::get('auth_ldap_suffix'), ','), $filter);
        $entries = ldap_get_entries($this->ldap_connection, $search);

        if ($entries['count']) {
            $user_id = $entries[0]['uidnumber'][0];
        }

        $this->authLdapSessionCacheSet('userid', $user_id);
        return $user_id;
    }


    public function getUserlist()
    {
        $userlist = array ();

        $filter = '(' . Config::get('auth_ldap_prefix') . '*)';

        $search  = ldap_search($this->ldap_connection, trim(Config::get('auth_ldap_suffix'), ','), $filter);
        $entries = ldap_get_entries($this->ldap_connection, $search);

        if ($entries['count']) {
            foreach ($entries as $entry) {
                $username    = $entry['uid'][0];
                $realname    = $entry['cn'][0];
                $user_id     = $entry['uidnumber'][0];
                $email       = $entry[Config::get('auth_ldap_emailattr')][0];
                $ldap_groups = $this->getGroupList();
                foreach ($ldap_groups as $ldap_group) {
                    $ldap_comparison = ldap_compare(
                        $this->ldap_connection,
                        $ldap_group,
                        Config::get('auth_ldap_groupmemberattr'),
                        $this->getMembername($username)
                    );
                    if (! Config::has('auth_ldap_group') || $ldap_comparison === true) {
                        $userlist[] = array(
                            'username' => $username,
                            'realname' => $realname,
                            'user_id'  => $user_id,
                            'email'    => $email,
                        );
                    }
                }
            }
        }

        return $userlist;
    }


    public function getUser($user_id)
    {
        foreach ($this->getUserlist() as $users) {
            if ($users['user_id'] === $user_id) {
                return $users['username'];
            }
        }
        return 0;
    }


    protected function getMembername($username)
    {
        if (Config::get('auth_ldap_groupmembertype') == 'fulldn') {
            $membername = Config::get('auth_ldap_prefix') . $username . Config::get('auth_ldap_suffix');
        } elseif (Config::get('auth_ldap_groupmembertype') == 'puredn') {
            $filter  = '(' . Config::get('auth_ldap_attr.uid') . '=' . $username . ')';
            $search  = ldap_search($this->ldap_connection, Config::get('auth_ldap_groupbase'), $filter);
            $entries = ldap_get_entries($this->ldap_connection, $search);
            $membername = $entries[0]['dn'];
        } else {
            $membername = $username;
        }

        return $membername;
    }


    protected function authLdapSessionCacheGet($attr)
    {
        $ttl = 300;
        if (Config::get('auth_ldap_cache_ttl')) {
            $ttl = Config::get('auth_ldap_cache_ttl');
        }

        // auth_ldap cache present in this session?
        if (! isset($_SESSION['auth_ldap'])) {
            return null;
        }

        $cache = $_SESSION['auth_ldap'];

        // $attr present in cache?
        if (! isset($cache[$attr])) {
            return null;
        }

        // Value still valid?
        if (time() - $cache[$attr]['last_updated'] >= $ttl) {
            return null;
        }

        $cache[$attr]['value'];
    }


    protected function authLdapSessionCacheSet($attr, $value)
    {
        $_SESSION['auth_ldap'][$attr]['value'] = $value;
        $_SESSION['auth_ldap'][$attr]['last_updated'] = time();
    }


    public function getGroupList()
    {
        $ldap_groups   = array();
        $default_group = 'cn=groupname,ou=groups,dc=example,dc=com';
        if (Config::has('auth_ldap_group')) {
            if (Config::get('auth_ldap_group') !== $default_group) {
                $ldap_groups[] = Config::get('auth_ldap_group');
            }
        }

        foreach (Config::get('auth_ldap_groups') as $key => $value) {
            $dn            = "cn=$key,".Config::get('auth_ldap_groupbase');
            $ldap_groups[] = $dn;
        }

        return $ldap_groups;
    }
}
