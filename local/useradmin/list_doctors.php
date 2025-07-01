<?php
// Bu dosya, index.php tarafından dahil edildiği için Moodle ortamı zaten yüklü olacaktır.
global $DB, $OUTPUT;

$sql = "SELECT u.id AS userid, u.username, u.firstname, u.lastname, u.email,
               d.id AS doctorid, d.hospital_id, d.polyclinic_id,
               h.name AS hospitalname,
               p.name AS polyclinicname
        FROM {doctors} d  -- Başlangıç tablosu olarak mdl_doctors'ı kullanıyoruz
        JOIN {user} u ON u.id = d.user_id -- Moodle kullanıcısı ile birleştirme
        LEFT JOIN {hospitals} h ON h.id = d.hospital_id
        LEFT JOIN {polyclinics} p ON p.id = d.polyclinic_id
        ORDER BY u.lastname, u.firstname";

try {
    $doctors = $DB->get_records_sql($sql); // Artık bir parametreye ihtiyacımız yok

    if (!empty($doctors)) {
        echo '<div class="table-responsive">';
        echo '<table class="table table-bordered table-striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Kullanıcı Adı</th>';
        echo '<th>Adı Soyadı</th>';
        echo '<th>E-posta</th>';
        echo '<th>Hastane</th>';
        echo '<th>Poliklinik</th>';
        echo '<th>İşlemler</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        foreach ($doctors as $doctor) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($doctor->username) . '</td>';
            echo '<td>' . htmlspecialchars($doctor->firstname . ' ' . $doctor->lastname) . '</td>';
            echo '<td>' . htmlspecialchars($doctor->email) . '</td>';
            echo '<td>' . htmlspecialchars($doctor->hospitalname ?: 'Belirtilmemiş') . '</td>';
            echo '<td>' . htmlspecialchars($doctor->polyclinicname ?: 'Belirtilmemiş') . '</td>';
            echo '<td>';
            echo '<a href="edit_doctor.php?id=' . $doctor->doctorid . '" class="btn btn-sm btn-info me-1">Düzenle</a>';
            echo '<a href="delete_doctor.php?id=' . $doctor->doctorid . '" class="btn btn-sm btn-danger" onclick="return confirm(\'Bu doktoru silmek istediğinizden emin misiniz?\');">Sil</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    } else {
        echo $OUTPUT->notification('Henüz kayıtlı doktor bulunmamaktadır.', 'info');
    }
} catch (Exception $e) {
    echo $OUTPUT->notification('Veritabanından doktorlar okunurken bir hata oluştu: ' . $e->getMessage(), 'error');
    if (defined('DEBUGGING') && DEBUGGING) {
        echo '<pre>' . print_r($e, true) . '</pre>';
    }
}
?>