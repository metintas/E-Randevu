<?php
defined('MOODLE_INTERNAL') || die();

function local_useradmin_extend_navigation(\global_navigation $admin) {
    $admin->add('local_useradmin', new \navigation_node( // Düzeltildi: admin_node -> navigation_node
        get_string('pluginname', 'local_useradmin'),
        new moodle_url('/local/useradmin/index.php'),
        'local_useradmin',
        null,
        null,
        new pix_icon('i/users', get_string('pluginname', 'local_useradmin'))
    ));

    if ($admin->get('local_useradmin')) {
        $admin->get('local_useradmin')->add('doctors', new \navigation_node( // Düzeltildi: admin_node -> navigation_node
            get_string('doctors', 'local_useradmin'),
            new moodle_url('/local/useradmin/index.php', array('tab' => 'doctors'))
        ));
        $admin->get('local_useradmin')->add('chiefs', new \navigation_node( // Düzeltildi: admin_node -> navigation_node
            get_string('chiefs', 'local_useradmin'),
            new moodle_url('/local/useradmin/index.php', array('tab' => 'chiefs'))
        ));
        $admin->get('local_useradmin')->add('addnewdoctor', new \navigation_node( // Düzeltildi: admin_node -> navigation_node
            get_string('addnewdoctor', 'local_useradmin'),
            new moodle_url('/local/useradmin/insert_doctor.php')
        ));
        $admin->get('local_useradmin')->add('addnewchief', new \navigation_node( // Düzeltildi: admin_node -> navigation_node
            get_string('addnewchief', 'local_useradmin'),
            new moodle_url('/local/useradmin/insert_chief.php')
        ));
    }
}