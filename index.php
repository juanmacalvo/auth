<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @author  Erlend Strømsvik - Ny Media AS
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package auth/saml
 *
 * Authentication Plugin: SAML based SSO Authentication
 *
 * Authentication using SAML2 with SimpleSAMLphp.
 *
 * Based on plugins made by Sergio Gómez (moodle_ssp) and Martin Dougiamas (Shibboleth).
 */

define('SAML_INTERNAL', 1);

try {
    // In order to avoid session problems we first do the SAML issues and then
    // we log in and register the attributes of user, but we need to read the value of the $CFG->dataroot.
    $dataroot = null;
    if (file_exists(dirname(dirname(__DIR__)).'/config.php')) {
        $configcontent = file_get_contents(dirname(dirname(__DIR__)).'/config.php');

        $matches = [];
        if (preg_match('/\$CFG->dataroot\s*=\s*["\'](.+)["\'];/i', $configcontent, $matches)) {
            $dataroot = $matches[1];
        }
    }

    // We read saml parameters from a config file instead from the database
    // due we can not operate with the moodle database without load all
    // moodle session issue.
    if (isset($dataroot) && file_exists($dataroot.'/saml_config.php')) {
        $contentfile = file_get_contents($dataroot.'/saml_config.php');
    } else if (file_exists('saml_config.php')) {
        $contentfile = file_get_contents('saml_config.php');
    } else {
        throw(new Exception('SAML config params are not set.'));
    }

    $samlparam = json_decode($contentfile);

    if (!file_exists($samlparam->samllib.'/_autoload.php')) {
        throw(new Exception('simpleSAMLphp lib loader file does not exist: '.$samlparam->samllib.'/_autoload.php'));
    }
    include_once($samlparam->samllib.'/_autoload.php');
    $as = new SimpleSAML_Auth_Simple($samlparam->sp_source);

    if (isset($_GET["logout"])) {
        if (isset($_SERVER['SCRIPT_URI'])) {
            $urltogo = $_SERVER['SCRIPT_URI'];
            $urltogo = str_replace('auth/saml/index.php', '', $urltogo);
        } else if (isset($_SERVER['HTTP_REFERER'])) {
            $urltogo = $_SERVER['HTTP_REFERER'];
        } else {
            $urltogo = '/';
        }

        if ($samlparam->dosinglelogout) {
            $as->logout($urltogo);
            assert(false); // The previous line issues a redirect.
        } else {
            header('Location: '.$urltogo);
            exit();
        }
    }

    if (!isset($_GET['normal'])) {
        $as->requireAuth();
    }

    $validsamlsession = $as->isAuthenticated();
    $samlattributes = $as->getAttributes();
} catch (Exception $e) {
    session_write_close();
    require_once(dirname(dirname(__DIR__)).'/config.php');
    require_once('error.php');

    global $err, $PAGE, $OUTPUT;
    $PAGE->set_url('/auth/saml/index.php');
    $PAGE->set_context(CONTEXT_SYSTEM::instance());

    $pluginconfig = get_config('auth_saml');
    $enrolconfig = get_config('enrol_saml');
    
    $urltogo = $CFG->wwwroot;
    if ($CFG->wwwroot[strlen($CFG->wwwroot) - 1] != '/') {
        $urltogo .= '/';
    }

    $err['login'] = $e->getMessage();
    auth_saml_log_error('Moodle SAML module:'. $err['login'], $pluginconfig->samllogfile);
    auth_saml_error($err['login'], $urltogo, $pluginconfig->samllogfile);
}

// Now we close simpleSAMLphp session.
session_write_close();

// We load all moodle config and libs.
require_once(dirname(dirname(__DIR__)).'/config.php');
require_once('error.php');

global $CFG, $USER, $SAML_COURSE_INFO, $SESSION, $err, $DB, $PAGE;

$PAGE->set_url('/auth/saml/index.php');
$PAGE->set_context(CONTEXT_SYSTEM::instance());

$urltogo = $CFG->wwwroot;
if ($CFG->wwwroot[strlen($CFG->wwwroot) - 1] != '/') {
    $urltogo .= '/';
}

// Set return rul from wantsurl.
if (isset($_REQUEST['wantsurl'])) {
    $urltogo = $_REQUEST['wantsurl'];
}

// Get the plugin config for saml.
$pluginconfig = get_config('auth_saml');
$enrolconfig = get_config('enrol_saml');

if (!$validsamlsession) {
    // Not valid session. Ship user off to Identity Provider.
    unset($USER);
    try {
        $as = new SimpleSAML_Auth_Simple($samlparam->sp_source);
        $as->requireAuth();
    } catch (Exception $e) {
        $err['login'] = $e->getMessage();
        auth_saml_error($err['login'], $urltogo, $pluginconfig->samllogfile);
    }
} else {
    require_once($CFG->dirroot.'/auth/saml/locallib.php');

    // Valid session. Register or update user in Moodle, log him on, and redirect to Moodle front.
    if (isset($pluginconfig->samlhookfile) && $pluginconfig->samlhookfile != '') {
        include_once(resolve_samlhookfile($pluginconfig->samlhookfile));
    }

    if (function_exists('saml_hook_attribute_filter')) {
        saml_hook_attribute_filter($samlattributes);
    }

    // We require the plugin to know that we are now doing a saml login in hook puser_login.
    $SESSION->auth_saml_login = true;

    // Make variables accessible to saml->get_userinfo. Information will be
    // requested from authenticate_user_login -> create_user_record
    // update_user_record.
    $SESSION->auth_saml_login_attributes = $samlattributes;

    if (isset($pluginconfig->username) && $pluginconfig->username != '') {
        $usernamefield = $pluginconfig->username;
    } else {
        $usernamefield = 'eduPersonPrincipalName';
    }

    if (!isset($samlattributes[$usernamefield])) {
        $err['login'] = get_string("auth_saml_username_not_found", "auth_saml", $usernamefield);
        auth_saml_error($err['login'], $CFG->wwwroot.'/auth/saml/login.php', $pluginconfig->samllogfile, true);
    }
    $username = $samlattributes[$usernamefield][0];
    $username = trim(strtolower($username));

    // Check if user exist.
    $userexists = $DB->get_record("user", ["username" => $username]);

    if (function_exists('saml_hook_user_exists')) {
        $userexists = $userexists && saml_hook_user_exists($username, $samlattributes, $userexists);
    }

    if (!$userexists && $pluginconfig->disablejit) {
        $jitdisabled = get_string("auth_saml_jit_not_active", "auth_saml", $username);
        $err['login'] = "<p>". $jitdisabled . "</p>";
        auth_saml_error($err, $CFG->wwwroot.'/auth/saml/login.php', $pluginconfig->samllogfile, true);
    }

    $samlcourses = [];
    if ($enrolconfig->supportcourses != 'nosupport' && isset($enrolconfig->courses)) {
        if (!isset($samlattributes[$enrolconfig->courses])) {
            $err['login'] = get_string("auth_saml_courses_not_found", "auth_saml", $enrolconfig->samlcourses);
            auth_saml_error($err['login'], $CFG->wwwroot.'/auth/saml/login.php', $pluginconfig->samllogfile);
        }
        $samlcourses = $samlattributes[$enrolconfig->courses];
    }

    // Obtain the course_mapping. Now $USER->mapped_courses have the mapped courses and $USER->mapped_roles the roles.
    if (!empty($samlcourses) && $enrolconfig->supportcourses != 'nosupport') {
        $anycourseactive = false;
        include_once($CFG->dirroot.'/auth/saml/course_and_role_mapping.php');
        if (!isset($SAML_COURSE_INFO)) {
            $SAML_COURSE_INFO = new stdClass();
        }
        $SAML_COURSE_INFO->mapped_roles = $mappedroles;
        $SAML_COURSE_INFO->mapped_courses = $mappedcourses;
    }

    $userauthorized = true;
    $errorauthorizing = '';

    // If user not exist in Moodle and not valid course active.
    if (!$userexists && (isset($anycourseactive) && !$anycourseactive)) {
        $errorauthorizing = get_string("auth_saml_not_authorize", "auth_saml", $username);
        $userauthorized = false;
    }

    if (function_exists('saml_hook_authorize_user')) {
        $result = saml_hook_authorize_user($username, $samlattributes, $userauthorized);
        if ($result !== true) {
            $userauthorized = false;
            $errorauthorizing = $result;
        }
    }

    if (!$userauthorized) {
        $err['login'] = "<p>" . $errorauthorizing . "</p>";
        auth_saml_error($err, $CFG->wwwroot.'/auth/saml/login.php', $pluginconfig->samllogfile, true);
    }

    // Just passes time as a password. User will never log in directly to moodle with this password anyway or so we hope?
    $user = authenticate_user_login($username, time());
    if ($user === false) {
        $err['login'] = get_string("auth_saml_error_authentication_process", "auth_saml", $username);
        auth_saml_error($err['login'], $CFG->wwwroot.'/auth/saml/login.php', $pluginconfig->samllogfile, true);
    }

    if ($pluginconfig->logextrainfo) {
        auth_saml_log_info($username.' logged', $pluginconfig->samllogfile);
    }

    // Sync system role.
    $samlroles = null;
    if (isset($pluginconfig->role) && isset($samlattributes[$pluginconfig->role])) {
        $samlroles = $samlattributes[$pluginconfig->role];

        $rolemapping = get_role_mapping_for_sync($pluginconfig);

        $usersystemroles = [];
        foreach ($samlroles as $samlrole) {
            foreach ($rolemapping as $shortname => $values) {
                if (in_array($samlrole, $values)) {
                    $usersystemroles[] = $shortname;
                }
            }
        }

        $roles = get_saml_assignable_role_names(2);
        // 2 is the admin user.

        $systemcontext = context_system::instance();
        foreach ($roles as $role) {
            $isrole = in_array(strtolower($role['shortname']), $usersystemroles);

            if ($isrole) {
                // Following calls will not create duplicates.
                role_assign($role['id'], $user->id, $systemcontext->id, 'auth_saml');
                if ($pluginconfig->logextrainfo) {
                    auth_saml_log_info("Systemrole ". $role['shortname']. 'assigned to '.$username, $pluginconfig->samllogfile);
                }
            } else {
                // Unassign only if previously assigned by this plugin.
                role_unassign($role['id'], $user->id, $systemcontext->id, 'auth_saml');
                if ($pluginconfig->logextrainfo) {
                    auth_saml_log_info("Systemrole ".$role['shortname']. 'unassigned to '.$username, $pluginconfig->samllogfile);
                }
            }
        }
    }

    // Complete the user login sequence.
    $user = get_complete_user_data('id', $user->id);
    if ($user === false) {
        $err['login'] = get_string("auth_saml_error_complete_user_data", "auth_saml", $username);
        auth_saml_error($err['login'], $CFG->wwwroot.'/auth/saml/login.php', $pluginconfig->samllogfile, true);
    }

    $USER = complete_user_login($user);

    if (function_exists('saml_hook_post_user_created')) {
        saml_hook_post_user_created($USER, $samlattributes);
    }

    if (isset($SESSION->wantsurl) && !empty($SESSION->wantsurl)) {
         $urltogo = $SESSION->wantsurl;
    }

    if (strpos($urltogo, 'auth/saml/index.php') !== false) {
        $urltogo = $CFG->wwwroot;
    }

    $USER->loggedin = true;
    $USER->site = $CFG->wwwroot;
    set_moodle_cookie($USER->username);

    if (isset($err) && !empty($err)) {
        if ($pluginconfig->dontdisplaytouser) {
            if (isset($err['course_enrollment'])) {
                if (!is_array($err['course_enrollment'])) {
                    $err['course_enrollment'] = [$err['course_enrollment']];
                }
                foreach ($err['course_enrollment'] as $errorMsg) {
                    auth_saml_log_error($errorMsg, $pluginconfig->samllogfile);
                }
                unset($err['course_enrollment']);
            }
        }
        if (!empty($err)) {
            auth_saml_error($err, $urltogo, $pluginconfig->samllogfile);
        }
    }
    redirect($urltogo);
}
