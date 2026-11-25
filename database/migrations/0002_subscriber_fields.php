<?php
/**
 * 0002: subscribers get name / numeric status / track, lists get custom field definitions.
 *  - first_name + last_name  -> name
 *  - status subscribed/unsubscribed/bounced/complained -> 1 / 2 / 0 / 0
 *  - track = 1 for existing rows (they were tracked before this change)
 *  - list_fields rebuilt from the keys found in subscribers.fields
 */
use App\Core\DB;

$pdo = DB::pdo();
$cols = fn(string $t) => array_column(DB::all("SHOW COLUMNS FROM `$t`"), 'Field');

$pdo->exec("CREATE TABLE IF NOT EXISTS list_fields (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id INT UNSIGNED NOT NULL,
    field_key VARCHAR(64) NOT NULL,
    label VARCHAR(150) NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_list_fields_key (list_id, field_key),
    CONSTRAINT fk_list_fields_list FOREIGN KEY (list_id) REFERENCES lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (in_array('first_name', $cols('subscribers'), true)) {
    $pdo->exec("ALTER TABLE subscribers
        ADD COLUMN name VARCHAR(190) NULL AFTER email,
        ADD COLUMN status_new TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER fields,
        ADD COLUMN track TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status_new");
    $pdo->exec("UPDATE subscribers SET
        name = NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''),
        status_new = CASE status WHEN 'subscribed' THEN 1 WHEN 'unsubscribed' THEN 2 ELSE 0 END,
        track = 1");
    $pdo->exec('ALTER TABLE subscribers DROP INDEX idx_subscribers_status, DROP COLUMN first_name, DROP COLUMN last_name, DROP COLUMN status');
    $pdo->exec("ALTER TABLE subscribers
        CHANGE status_new status TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = disabled, 1 = enabled, 2 = unsubscribed',
        MODIFY track TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 = opens/clicks tracked, 0 = not tracked',
        ADD INDEX idx_subscribers_status (list_id, status)");
}

if (in_array('first_name', $cols('campaign_recipients'), true)) {
    $pdo->exec('ALTER TABLE campaign_recipients ADD COLUMN name VARCHAR(190) NULL AFTER email,
        ADD COLUMN track TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER fields');
    $pdo->exec("UPDATE campaign_recipients SET name = NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), track = 1");
    $pdo->exec('ALTER TABLE campaign_recipients DROP COLUMN first_name, DROP COLUMN last_name');
}

// Field definitions from the existing JSON data
foreach (DB::column('SELECT id FROM lists') as $listId) {
    $keys = [];
    foreach (DB::column('SELECT fields FROM subscribers WHERE list_id = ? AND fields IS NOT NULL', [$listId]) as $json) {
        foreach (array_keys(json_decode((string) $json, true) ?: []) as $k) {
            $keys[$k] = true;
        }
    }
    App\Services\ListFields::ensure((int) $listId, array_combine(array_keys($keys), array_keys($keys)));
}
