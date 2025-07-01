<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance()); // Sadece adminler çalıştırabilsin

global $DB;

$startDate = new DateTime();
$endDate = new DateTime('+15 days');
$slotDuration = new DateInterval('PT10M'); // 10 dakika
$workStart = new DateTime('08:00');
$workEnd = new DateTime('16:50');

// Tüm doktorları al
$doctors = $DB->get_records('doctors');

foreach ($doctors as $doctor) {
    $date = clone $startDate;
    while ($date <= $endDate) {
        $day = $date->format('Y-m-d');

        $time = clone $workStart;
        while ($time <= $workEnd) {
            $slot_date = $day;
            $slot_time = $time->format('H:i:s');

            // Aynı slot zaten varsa ekleme
            $exists = $DB->record_exists('appointment_slots', [
                'doctor_id' => $doctor->id,
                'slot_date' => $slot_date,
                'slot_time' => $slot_time,
            ]);

            if (!$exists) {
                $record = new stdClass();
                $record->doctor_id = $doctor->id;
                $record->slot_date = $slot_date;
                $record->slot_time = $slot_time;
                $record->is_booked = 0;

                $DB->insert_record('appointment_slots', $record);
            }

            $time->add($slotDuration);
        }

        $date->modify('+1 day');
    }
}

echo "Tüm boş slotlar başarıyla oluşturuldu.";
