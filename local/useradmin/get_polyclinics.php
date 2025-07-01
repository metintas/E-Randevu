<?php
require_once('../../config.php');
require_login(); // İsteğe bağlı, güvenlik için eklenebilir.

global $DB;

$hospital_id = optional_param('hospital_id', 0, PARAM_INT); // Hastane ID'si
$polyclinics = [];

if ($hospital_id > 0) {
    $records = $DB->get_records('polyclinics', ['hospital_id' => $hospital_id], 'name ASC');
    foreach ($records as $p) {
        $polyclinics[] = ['id' => $p->id, 'name' => htmlspecialchars($p->name)];
    }
}

// JSON olarak çıktıyı ver
header('Content-Type: application/json');
echo json_encode($polyclinics);
?>