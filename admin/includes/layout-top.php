<?php

declare(strict_types=1);

if (!isset($pageTitle) || !is_string($pageTitle)) {
    $pageTitle = 'Admin | CLMS';
}

$activeAdminPage = $activeAdminPage ?? 'dashboard';

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
<?php require __DIR__ . '/sidebar.php'; ?>

        <div class="layout-page">
<?php require dirname(__DIR__, 2) . '/includes/navbar.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
