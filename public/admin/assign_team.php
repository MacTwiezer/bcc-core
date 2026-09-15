<?php

require __DIR__ . '/../../src/bootstrap.php';

require_admin();

header('Location: /admin/index.php?ekibe_ata=1');
exit;
