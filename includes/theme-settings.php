<?php

declare(strict_types=1);

/**
 * Load theme settings from database and provide CSS variables
 */

function clms_get_theme_settings(PDO $pdo): array
{
    static $cached = null;
    
    if ($cached !== null) {
        return $cached;
    }
    
    $defaults = [
        'site_title' => 'Criminology LMS',
        'site_logo_url' => '',
        'primary_color' => '#800000',
        'secondary_color' => '#696cff',
        'sidebar_bg_color' => '#ffffff',
        'navbar_bg_color' => '#ffffff',
    ];
    
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('site_title', 'site_logo_url', 'primary_color', 'secondary_color', 'sidebar_bg_color', 'navbar_bg_color')");
        $rows = $stmt->fetchAll();
        
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            if (isset($defaults[$key])) {
                $defaults[$key] = (string) ($row['setting_value'] ?? $defaults[$key]);
            }
        }
    } catch (Throwable $e) {
        error_log('Failed to load theme settings: ' . $e->getMessage());
    }
    
    $cached = $defaults;
    return $cached;
}

function clms_render_theme_css(array $themeSettings): string
{
    $primary = htmlspecialchars($themeSettings['primary_color'], ENT_QUOTES, 'UTF-8');
    $secondary = htmlspecialchars($themeSettings['secondary_color'], ENT_QUOTES, 'UTF-8');
    $sidebarBg = htmlspecialchars($themeSettings['sidebar_bg_color'], ENT_QUOTES, 'UTF-8');
    $navbarBg = htmlspecialchars($themeSettings['navbar_bg_color'], ENT_QUOTES, 'UTF-8');
    
    // Generate color variations for gradients
    $primaryDark = clms_adjust_color_brightness($themeSettings['primary_color'], -15);
    $primaryLight = clms_adjust_color_brightness($themeSettings['primary_color'], 15);
    
    return <<<CSS
<style>
:root {
    --clms-primary-color: {$primary};
    --clms-primary-dark: {$primaryDark};
    --clms-primary-light: {$primaryLight};
    --clms-secondary-color: {$secondary};
    --clms-sidebar-bg: {$sidebarBg};
    --clms-navbar-bg: {$navbarBg};
}

.btn-primary,
.bg-primary {
    background-color: var(--clms-primary-color) !important;
    border-color: var(--clms-primary-color) !important;
}

.btn-primary:hover,
.btn-primary:focus {
    background-color: color-mix(in srgb, var(--clms-primary-color) 85%, black) !important;
    border-color: color-mix(in srgb, var(--clms-primary-color) 85%, black) !important;
}

.text-primary {
    color: var(--clms-primary-color) !important;
}

.bg-label-primary {
    background-color: color-mix(in srgb, var(--clms-primary-color) 12%, white) !important;
    color: var(--clms-primary-color) !important;
}

.badge.bg-label-primary {
    background-color: color-mix(in srgb, var(--clms-primary-color) 12%, white) !important;
    color: var(--clms-primary-color) !important;
}

.layout-menu {
    background-color: var(--clms-sidebar-bg) !important;
}

.layout-navbar {
    background-color: var(--clms-navbar-bg) !important;
}

.navbar-nav .nav-link:hover,
.navbar-nav .nav-link:focus {
    color: var(--clms-primary-color) !important;
}

.menu-link:hover,
.menu-link.active {
    background-color: color-mix(in srgb, var(--clms-primary-color) 8%, white) !important;
    color: var(--clms-primary-color) !important;
}

.form-check-input:checked {
    background-color: var(--clms-primary-color) !important;
    border-color: var(--clms-primary-color) !important;
}

.pagination .page-link.active,
.pagination .page-item.active .page-link {
    background-color: var(--clms-primary-color) !important;
    border-color: var(--clms-primary-color) !important;
}

a {
    color: var(--clms-primary-color);
}

a:hover {
    color: color-mix(in srgb, var(--clms-primary-color) 85%, black);
}
</style>
CSS;
}

function clms_adjust_color_brightness(string $hexColor, int $percent): string
{
    // Remove # if present
    $hex = ltrim($hexColor, '#');
    
    // Convert to RGB
    if (strlen($hex) === 3) {
        $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    
    // Adjust brightness
    $r = max(0, min(255, $r + ($r * $percent / 100)));
    $g = max(0, min(255, $g + ($g * $percent / 100)));
    $b = max(0, min(255, $b + ($b * $percent / 100)));
    
    // Convert back to hex
    return sprintf('#%02x%02x%02x', (int)$r, (int)$g, (int)$b);
}
