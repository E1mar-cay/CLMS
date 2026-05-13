<?php

declare(strict_types=1);

/**
 * Homepage hero carousel slides (managed from admin).
 */
function clms_ensure_landing_carousel_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS landing_carousel_slides (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                subtitle TEXT NOT NULL,
                button_label VARCHAR(120) NOT NULL DEFAULT \'\',
                button_url VARCHAR(500) NOT NULL DEFAULT \'\',
                image_url VARCHAR(500) NULL,
                kpi1_value VARCHAR(80) NULL,
                kpi1_label VARCHAR(80) NULL,
                kpi2_value VARCHAR(80) NULL,
                kpi2_label VARCHAR(80) NULL,
                kpi3_value VARCHAR(80) NULL,
                kpi3_label VARCHAR(80) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_landing_carousel_active_sort (is_active, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('clms_ensure_landing_carousel_schema: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string,mixed>>
 */
function clms_landing_carousel_active_slides(PDO $pdo): array
{
    clms_ensure_landing_carousel_schema($pdo);
    try {
        $stmt = $pdo->query(
            'SELECT id, title, subtitle, button_label, button_url, image_url,
                    kpi1_value, kpi1_label, kpi2_value, kpi2_label, kpi3_value, kpi3_label
             FROM landing_carousel_slides
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );

        return $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        error_log('clms_landing_carousel_active_slides: ' . $e->getMessage());

        return [];
    }
}
