<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

class auth_plugin_doctorlogin extends auth_plugin_base {

    public function __construct() {
        $this->authtype = 'doctorlogin';
        $this->config = get_config('auth_doctorlogin');
    }

    // Kullanıcı doğrulama fonksiyonu
    public function user_login($username, $password) {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);

        if (!$user) {
            return false;
        }

        if (!validate_internal_user_password($user, $password)) {
            return false;
        }

        $doctor = $DB->get_record('doctors', ['user_id' => $user->id]);

        if (!$doctor) {
            return false;
        }

        return true;
    }

    // Moodle kullanıcıyı oluştururken veya güncellerken çağrılır
    public function get_userinfo($username) {
        global $DB, $CFG;

        $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);

        if (!$user) {
            return false;
        }

        $doctor = $DB->get_record('doctors', ['user_id' => $user->id]);
        if (!$doctor) {
            return false;
        }

        return [
            'username'    => $user->username,
            'firstname'   => $user->firstname ?: 'Doktor',
            'lastname'    => $user->lastname ?: 'Kullanıcı',
            'email'       => $user->email ?: $user->username . '@example.com',
            'password'    => '',
            'idnumber'    => $user->idnumber ?: '',
            'auth'        => $this->authtype,
            'confirmed'   => 1,
            'mnethostid'  => $CFG->mnet_localhost_id,
        ];
    }
}
