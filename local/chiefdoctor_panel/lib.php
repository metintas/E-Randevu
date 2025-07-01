<?php
defined('MOODLE_INTERNAL') || die();
function local_chiefdoctor_panel_extend_navigation(global_navigation $nav) {
    if (has_capability('local/chiefdoctor_panel:createslots', context_system::instance())) {
        $nav->add(get_string('pluginname', 'local_chiefdoctor_panel'), new moodle_url('/local/chiefdoctor_panel/index.php'));
    }
}