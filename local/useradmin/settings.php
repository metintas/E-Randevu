// local/useradmin/settings.php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // External sayfa tanımı (admin_externalpage_setup için)
    admin_externalpage_setup('local_useradmin');

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_useradmin',
        get_string('pluginname', 'local_useradmin'),
        new moodle_url('/local/useradmin/index.php'),
        'moodle/site:config'
    ));
}
