<?php
/**
 * Mise en page commune du Dashboard.
 *
 * @var string $content HTML de la page (déjà échappé)
 * @var string|null $title
 * @var string $page nom du gabarit affiché
 * @var string|null $admin
 * @var array{type: string, message: string}|null $flash
 * @var string $csrf
 * @var callable $e
 * @var callable $url
 */
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php if ($page === 'status'): ?>
<?php /* Rechargement chaque minute, marqué ?auto=1 : il ne prolonge pas la session (DashboardMiddleware). */ ?>
<meta http-equiv="refresh" content="60; url=<?= $e($url('/v1/app/status?auto=1')) ?>">
<?php endif; ?>
<?php /* Sans « defer » : le thème choisi (clair ou sombre) est appliqué avant le premier affichage. */ ?>
<script src="<?= $e($url('/v1/app/dashboard.js')) ?>"></script>
<title><?= $e(($title !== null ? $title . ' · ' : '') . 'Amnesic Mail') ?></title>
<style>
/* Thème : celui du système par défaut ; <html data-theme="light|dark"> si choisi avec le bouton du header (dashboard.js). */
:root {
    color-scheme: light;
    --bg: #f6f7f9; --surface: #fff; --text: #1d2330; --muted: #667085; --border: #e3e6eb;
    --accent: #2f6fde; --accent-text: #fff; --danger: #c43d3d; --ok-bg: #e8f5ec; --ok-text: #1e6b3a;
    --err-bg: #fdecec; --err-text: #9b2c2c; --code-bg: #f0f2f5; --warn-bg: #fff4e0; --warn-text: #8a4b00;
    --icon-moon: block; --icon-sun: none;
}
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        color-scheme: dark;
        --bg: #12151b; --surface: #1b1f27; --text: #e6e9ef; --muted: #98a1b2; --border: #2c323d;
        --accent: #5b8ff0; --accent-text: #0b1020; --danger: #ef6b6b; --ok-bg: #173323; --ok-text: #8fdcaa;
        --err-bg: #3a1d1d; --err-text: #f4a9a9; --code-bg: #232833; --warn-bg: #3a2a12; --warn-text: #f5c97a;
        --icon-moon: none; --icon-sun: block;
    }
}
:root[data-theme="dark"] {
    color-scheme: dark;
    --bg: #12151b; --surface: #1b1f27; --text: #e6e9ef; --muted: #98a1b2; --border: #2c323d;
    --accent: #5b8ff0; --accent-text: #0b1020; --danger: #ef6b6b; --ok-bg: #173323; --ok-text: #8fdcaa;
    --err-bg: #3a1d1d; --err-text: #f4a9a9; --code-bg: #232833; --warn-bg: #3a2a12; --warn-text: #f5c97a;
    --icon-moon: none; --icon-sun: block;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
header { background: var(--surface); border-bottom: 1px solid var(--border); }
header .bar { max-width: 1200px; margin: 0 auto; padding: 0 16px; display: flex; align-items: center; gap: 24px; min-height: 56px; flex-wrap: wrap; }
header .brand { font-weight: 700; color: var(--text); }
header nav { display: flex; gap: 4px; flex: 1; }
header nav a { padding: 6px 12px; border-radius: 6px; color: var(--muted); }
header nav a.active { background: var(--code-bg); color: var(--text); }
header .user { display: flex; align-items: center; gap: 12px; color: var(--muted); }
button.theme-toggle { display: inline-flex; padding: 5px; border-color: var(--border); background: none; color: var(--muted); font-weight: normal; }
button.theme-toggle:hover { color: var(--text); border-color: var(--muted); }
button.theme-toggle svg { width: 18px; height: 18px; }
button.theme-toggle .moon { display: var(--icon-moon); }
button.theme-toggle .sun { display: var(--icon-sun); }
main { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
h1 { font-size: 22px; margin: 0 0 16px; }
h2 { font-size: 17px; margin: 28px 0 12px; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }
.narrow { max-width: 380px; margin: 64px auto; }
.muted { color: var(--muted); }
.flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
.flash.success { background: var(--ok-bg); color: var(--ok-text); }
.flash.error { background: var(--err-bg); color: var(--err-text); }
label { display: block; font-weight: 600; margin: 12px 0 4px; }
input[type=text], input[type=password], input[type=email] {
    width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px;
    background: var(--bg); color: var(--text); font: inherit;
}
button, .button {
    display: inline-block; padding: 8px 14px; border: 1px solid var(--accent); border-radius: 6px;
    background: var(--accent); color: var(--accent-text); font: inherit; font-weight: 600; cursor: pointer;
}
button.link { background: none; border: none; color: var(--accent); padding: 0; font-weight: normal; }
button.danger { background: none; border-color: var(--danger); color: var(--danger); padding: 4px 10px; font-weight: normal; }
button.danger:hover:not(:disabled) { background: var(--danger); color: var(--surface); }
button:disabled { opacity: .45; cursor: not-allowed; }
.toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
.title-row h1 { margin: 0; overflow-wrap: anywhere; }
.title-row form { margin: 0; }
th.check, td.check { width: 1%; padding-right: 0; }
th.check input, td.check input { width: 16px; height: 16px; margin: 3px 0 0; cursor: pointer; }
tr.selected td { background: var(--code-bg); }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 20px; overflow-x: auto; }
.tabs a { padding: 10px 14px; color: var(--muted); border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
.tabs a:hover { color: var(--text); text-decoration: none; }
.tabs a.active { color: var(--text); border-bottom-color: var(--accent); font-weight: 600; }
.content-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 28px 0 12px; flex-wrap: wrap; }
.content-head h2 { margin: 0; }
.switch { display: inline-flex; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }
.switch a { padding: 5px 14px; color: var(--muted); font-size: 14px; }
.switch a + a { border-left: 1px solid var(--border); }
.switch a:hover { text-decoration: none; color: var(--text); }
.switch a.active { background: var(--accent); color: var(--accent-text); }
form.inline { display: inline; margin: 0; }
.row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
.row > .grow { flex: 1; min-width: 220px; }
.table-wrap { overflow-x: auto; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
th { font-size: 13px; color: var(--muted); font-weight: 600; white-space: nowrap; }
tr:last-child td { border-bottom: none; }
td.nowrap { white-space: nowrap; }
.subject { font-weight: 600; }
.snippet { color: var(--muted); font-size: 13px; max-width: 520px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tag { display: inline-block; font-size: 12px; padding: 1px 8px; border-radius: 10px; background: var(--code-bg); color: var(--muted); }
.tag.warn { background: var(--warn-bg); color: var(--warn-text); font-weight: 600; }
.flash.warn { background: var(--warn-bg); color: var(--warn-text); }
.flash.warn .tag.warn { background: var(--surface); margin-right: 4px; }
code, .code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--code-bg); padding: 2px 6px; border-radius: 4px; font-size: 13px; }
.secret { display: block; padding: 12px; margin: 8px 0; word-break: break-all; user-select: all; font-size: 14px; }
.pager { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; gap: 8px; }
.empty { padding: 32px; text-align: center; color: var(--muted); }
dl.meta { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; }
dl.meta dt { color: var(--muted); }
dl.meta dd { margin: 0; overflow-wrap: anywhere; }
.filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
select { padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); font: inherit; }
.dropdown { position: relative; }
.dropdown > summary {
    list-style: none; cursor: pointer; padding: 8px 32px 8px 12px; border: 1px solid var(--border); border-radius: 6px;
    background: var(--surface); min-width: 220px; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; position: relative;
}
.dropdown > summary::-webkit-details-marker { display: none; }
.dropdown > summary::after { content: "▾"; position: absolute; right: 12px; color: var(--muted); }
.dropdown[open] > summary { border-color: var(--accent); }
.dropdown .menu {
    position: absolute; z-index: 10; top: calc(100% + 4px); left: 0; width: max-content; min-width: 100%; max-width: min(480px, calc(100vw - 32px));
    max-height: 320px; overflow-y: auto; padding: 6px; background: var(--surface); border: 1px solid var(--border);
    border-radius: 8px; box-shadow: 0 8px 24px rgba(0, 0, 0, .15);
}
.dropdown .option { display: flex; gap: 10px; align-items: center; font-weight: normal; margin: 0; padding: 6px 8px; border-radius: 4px; cursor: pointer; }
.dropdown .option:hover { background: var(--code-bg); }
.dropdown .option .grow { flex: 1; overflow-wrap: anywhere; }
a.subject { color: var(--text); }
.button.small { padding: 4px 10px; font-weight: normal; font-size: 13px; }
a.button:hover { text-decoration: none; }
.actions { margin: 0 0 8px; }
iframe.mail-html { display: block; width: 100%; height: 640px; resize: vertical; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
pre.mail-text { white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
details.block { margin-top: 20px; }
details.block > summary { cursor: pointer; font-weight: 600; margin-bottom: 8px; }
details.block pre.mail-text { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
table.headers th { width: 1%; color: var(--muted); font-weight: 600; font-size: 13px; }
table.headers td { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; overflow-wrap: anywhere; }
.badge { display: inline-block; padding: 3px 12px; border-radius: 12px; font-weight: 600; }
.badge.ok { background: var(--ok-bg); color: var(--ok-text); }
.badge.down { background: var(--err-bg); color: var(--err-text); }
.badge.none { background: var(--code-bg); color: var(--muted); }
.status-card p.status { font-size: 17px; margin: 0 0 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
svg.chart { display: block; width: 100%; height: auto; }
svg.chart .grid, svg.chart .baseline { stroke: var(--border); stroke-width: 1; }
svg.chart .baseline { stroke: var(--muted); }
svg.chart .axis { fill: var(--muted); font-size: 12px; font-family: inherit; }
svg.chart .bar, .swatch.bar { fill: var(--accent); background: var(--accent); }
svg.chart .peak, .swatch.peak { fill: var(--accent); background: var(--accent); opacity: .3; }
svg.chart .down, .swatch.down { fill: var(--danger); background: var(--danger); opacity: .35; }
svg.chart .unknown, .swatch.unknown { fill: var(--muted); background: var(--muted); }
.legend { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 12px 0 0; font-size: 13px; }
.legend span { display: inline-flex; align-items: center; gap: 6px; }
.swatch { display: inline-block; width: 12px; height: 12px; border-radius: 2px; }
</style>
</head>
<body>
<?php if ($admin !== null): ?>
<header>
    <div class="bar">
        <a class="brand" href="<?= $e($url('/v1/app/messages')) ?>">Amnesic Mail</a>
        <nav>
            <a href="<?= $e($url('/v1/app/messages')) ?>" class="<?= in_array($page, ['messages', 'message'], true) ? 'active' : '' ?>">Messages</a>
            <a href="<?= $e($url('/v1/app/tokens')) ?>" class="<?= in_array($page, ['tokens', 'token'], true) ? 'active' : '' ?>">Tokens</a>
            <a href="<?= $e($url('/v1/app/status')) ?>" class="<?= $page === 'status' ? 'active' : '' ?>">Statut</a>
        </nav>
        <div class="user">
            <?php /* Bascule clair / sombre (dashboard.js) : la lune en thème clair, le soleil en thème sombre. */ ?>
            <button type="button" class="theme-toggle" data-theme-toggle aria-label="Mode sombre" aria-pressed="false" title="Passer en mode sombre">
                <svg class="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>
                </svg>
                <svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="12" cy="12" r="4"/>
                    <path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8"/>
                </svg>
            </button>
            <span><?= $e($admin) ?></span>
            <form class="inline" method="post" action="<?= $e($url('/v1/app/signout')) ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <button type="submit" class="link">Déconnexion</button>
            </form>
        </div>
    </div>
</header>
<?php endif; ?>
<main>
<?php if ($flash !== null && $page !== 'signin'): ?>
    <div class="flash <?= $e($flash['type']) ?>" role="status"><?= $e($flash['message']) ?></div>
<?php endif; ?>
<?= $content ?>
</main>
</body>
</html>
