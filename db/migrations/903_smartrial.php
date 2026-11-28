<?php

/**
 * [smartrial] Smart rial gateway settings. Off until the admin turns it on; the pool and the weights
 * (FrenzyEx 2, Tronado 2, TetraPay 1) default in smartrial_cfg() when smartrial_cfg is empty.
 */

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('config')) {
        return;
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO config (scope, name, value) VALUES ('pay', :name, :value)");
    foreach (['statussmartrial' => '0', 'smartrial_cfg' => '', 'smartrial_state' => ''] as $name => $value) {
        $stmt->execute([':name' => $name, ':value' => $value]);
    }
};
