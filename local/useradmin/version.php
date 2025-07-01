<?php
// local/useradmin/version.php

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_useradmin';  // Eklentinin adı
$plugin->version = 2023112700; // Moodle sürüm numaralandırma formatı (YYYYMMDDXX)
                               // İlk sürüm için bu yeterlidir, gelecekte güncellersin.
$plugin->requires = 2022041900; // Moodle 4.0'ı veya daha yeni bir sürümü gerektirir.
                               // Kendi Moodle sürümüne göre bu numarayı güncelleyebilirsin.
$plugin->maturity = MATURITY_STABLE; // Kararlılık seviyesi (MATURITY_ALPHA, MATURITY_BETA, MATURITY_RC, MATURITY_STABLE)
$plugin->release = '1.0'; // Görünen sürüm numarası
$plugin->supportedby = 'Your Name/Organization'; // Opsiyonel