<?php

namespace AperturePro\Installer;

class Activator {
    public static function activate() {
        try {
            Schema::createTables();
            Installer::runInitialSetup();
        } catch (\Throwable $e) {
            error_log('Aperture Pro activation failed: ' . $e->getMessage());
        }
    }
}
