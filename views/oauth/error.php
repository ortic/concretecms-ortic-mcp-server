<?php
defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var string $siteName
 * @var string $message
 */
$pageTitle = t('Cannot connect');
include __DIR__ . '/layout_start.php';
?>
<h1><?= t('This app cannot be connected') ?></h1>
<p><?= h($message) ?></p>
<?php include __DIR__ . '/layout_end.php'; ?>
