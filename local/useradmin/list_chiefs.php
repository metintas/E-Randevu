<?php
// Bu dosya, index.php tarafından dahil edildiği için Moodle ortamı zaten yüklü olacaktır.
global $DB, $OUTPUT;

// Başhekim rolünün ID'si. Moodle sistemine göre değişebilir.
$chief_role_id = 10;

$sql = "SELECT u.id AS userid, u.username, u.firstname, u.lastname, u.email,
               cd.id AS chiefdoctor_custom_id, cd.hospital_id,
               h.name AS hospitalname
        FROM {user} u
        JOIN {role_assignments} ra ON ra.userid = u.id
        LEFT JOIN {chiefdoctors} cd ON cd.user_id = u.id
        LEFT JOIN {hospitals} h ON h.id = cd.hospital_id
        WHERE ra.roleid = :chiefroleid
        ORDER BY u.lastname, u.firstname";

try {
    $chiefs = $DB->get_records_sql($sql, ['chiefroleid' => $chief_role_id]);

    if (!empty($chiefs)) {
        echo '<div class="table-responsive">';
        echo '<table class="table table-bordered table-striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Kullanıcı Adı</th>';
        echo '<th>Adı Soyadı</th>';
        echo '<th>E-posta</th>';
        echo '<th>Hastane</th>';
        echo '<th>İşlemler</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        foreach ($chiefs as $chief) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($chief->username) . '</td>';
            echo '<td>' . htmlspecialchars($chief->firstname . ' ' . $chief->lastname) . '</td>';
            echo '<td>' . htmlspecialchars($chief->email) . '</td>';
            echo '<td>' . htmlspecialchars($chief->hospitalname ?: 'Belirtilmemiş') . '</td>';
            echo '<td>';
            echo '<a href="edit_chief.php?id=' . $chief->chiefdoctor_custom_id . '" class="btn btn-sm btn-info me-1">Düzenle</a>';
            echo '<a href="delete_chief.php?id=' . $chief->chiefdoctor_custom_id . '" class="btn btn-sm btn-danger" onclick="return confirm(\'Bu başhekimi silmek istediğinizden emin misiniz?\');">Sil</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    } else {
        echo $OUTPUT->notification('Henüz kayıtlı başhekim bulunmamaktadır.', 'info');
    }
} catch (Exception $e) {
    echo $OUTPUT->notification('Veritabanından başhekimler okunurken bir hata oluştu: ' . $e->getMessage(), 'error');
    if (defined('DEBUGGING') && DEBUGGING) {
        echo '<pre>' . print_r($e, true) . '</pre>';
    }
}
?>
