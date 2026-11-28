<?php

/**
 * [tetra98] Settings rows for the TetraPay gateway on existing installations.
 * INSERT IGNORE: an admin who configured it before an update keeps the values.
 * statustetra98 / sandboxtetra98 use 0/1. Amounts are TOMAN.
 */

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('config')) {
        return;
    }
    $defaults = ['apitetra98' => '0', 'webhooktetra98' => '0', 'statustetra98' => '0', 'sandboxtetra98' => '0',
        'minbalancetetra98' => '20000', 'maxbalancetetra98' => '10000000', 'chashbacktetra98' => '0'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO config (scope, name, value) VALUES ('pay', :name, :value)");
    foreach ($defaults as $name => $value) {
        $stmt->execute([':name' => $name, ':value' => $value]);
    }
};
