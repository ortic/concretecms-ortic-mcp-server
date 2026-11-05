<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>
<!DOCTYPE html>
<html lang="<?= h(\Localization::activeLanguage()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($pageTitle) ?> · <?= h($siteName) ?></title>
    <style>
        :root { --bg: #f4f5f7; --card: #fff; --text: #1d2330; --muted: #5d6677; --border: #dfe3ea; --accent: #0b5cad; --accent-text: #fff; --warn-bg: #fff6e5; --warn-border: #f0b44c; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #14171c; --card: #1d2128; --text: #e8eaee; --muted: #a3abb9; --border: #323844; --accent: #4c9be8; --accent-text: #0b1420; --warn-bg: #33280f; --warn-border: #a8772a; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        main { max-width: 480px; margin: 48px auto; padding: 0 16px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 28px; }
        .site { color: var(--muted); font-size: 14px; margin: 0 0 8px; }
        h1 { font-size: 22px; line-height: 1.3; margin: 0 0 16px; }
        p { margin: 0 0 16px; }
        ul { margin: 0 0 20px; padding-left: 20px; }
        li { margin-bottom: 6px; }
        .meta { font-size: 14px; color: var(--muted); border-top: 1px solid var(--border); padding-top: 16px; margin-top: 4px; }
        .meta strong { color: var(--text); }
        .warn { background: var(--warn-bg); border: 1px solid var(--warn-border); border-radius: 8px; padding: 12px 14px; margin: 0 0 20px; font-size: 14px; }
        .warn label { display: flex; gap: 10px; align-items: flex-start; font-weight: 600; font-size: 15px; cursor: pointer; }
        .warn input { margin-top: 4px; }
        .warn p { margin: 6px 0 0 26px; font-weight: normal; }
        .actions { display: flex; gap: 12px; margin-top: 20px; }
        button { flex: 1; font: inherit; font-weight: 600; padding: 10px 16px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text); cursor: pointer; }
        button.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
        a { color: var(--accent); }
    </style>
</head>
<body>
<main>
    <div class="card">
        <p class="site"><?= h($siteName) ?></p>
