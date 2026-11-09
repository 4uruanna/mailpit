/*
 * Script du Dashboard (servi par GET /v1/app/dashboard.js, seul script autorisé par la CSP).
 * Chargé sans « defer » dans le <head> : le thème est appliqué avant le premier affichage de la page
 * (pas de flash clair puis sombre). Le reste attend que la page soit chargée.
 *
 *  - Thème clair / sombre : celui du système par défaut (prefers-color-scheme). Le bouton
 *    [data-theme-toggle] du header passe de l'un à l'autre. Le choix est mis sur <html data-theme="…">
 *    (règles CSS de layout.php) et gardé par le navigateur (localStorage), pour toutes les pages
 *    et les autres onglets.
 *  - <form data-confirm="Message ?"> : demande confirmation avant l'envoi.
 *    Dans le message, {n} est remplacé par le nombre de cases « ids[] » cochées dans le formulaire.
 *  - <form data-selection> : formulaire à cases à cocher « ids[] ». La case [data-select-all]
 *    coche ou décoche toutes les autres ; [data-selection-count] affiche le nombre de cases
 *    cochées ; les boutons [data-needs-selection] sont désactivés tant que rien n'est coché.
 *    Avec autocomplete="off", le navigateur ne recoche pas les cases au retour sur la page.
 */
'use strict';

(function () {
    var THEME_KEY = 'dashboard-theme';
    var root = document.documentElement;
    var systemDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    /** Thème choisi avec le bouton : « light », « dark », ou null (celui du système). */
    function savedTheme() {
        try {
            var theme = window.localStorage.getItem(THEME_KEY);
            return theme === 'light' || theme === 'dark' ? theme : null;
        } catch (error) {
            return null; // stockage bloqué par le navigateur
        }
    }

    /** Thème affiché : le choix de <html data-theme>, sinon celui du système. */
    function currentTheme() {
        var theme = root.getAttribute('data-theme');
        if (theme === 'light' || theme === 'dark') {
            return theme;
        }
        return systemDark !== null && systemDark.matches ? 'dark' : 'light';
    }

    function refreshThemeToggles() {
        var dark = currentTheme() === 'dark';
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
            button.title = dark ? 'Passer en mode clair' : 'Passer en mode sombre';
        });
    }

    function applyTheme(theme) {
        if (theme === null) {
            root.removeAttribute('data-theme');
        } else {
            root.setAttribute('data-theme', theme);
        }
        refreshThemeToggles();
    }

    applyTheme(savedTheme());

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest ? event.target.closest('[data-theme-toggle]') : null;
        if (!toggle) {
            return;
        }
        var theme = currentTheme() === 'dark' ? 'light' : 'dark';
        applyTheme(theme);
        try {
            window.localStorage.setItem(THEME_KEY, theme);
        } catch (error) {
            // Stockage bloqué : le choix vaut pour cette page seulement.
        }
    });

    // Choix fait dans un autre onglet.
    window.addEventListener('storage', function (event) {
        if (event.key === THEME_KEY || event.key === null) {
            applyTheme(savedTheme());
        }
    });

    // Thème du système changé : le bouton suit, si aucun thème n'a été choisi.
    if (systemDark !== null && systemDark.addEventListener) {
        systemDark.addEventListener('change', refreshThemeToggles);
    }

    function boxes(form) {
        return Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"][name="ids[]"]'));
    }

    function checkedCount(form) {
        return boxes(form).filter(function (box) { return box.checked; }).length;
    }

    function refresh(form) {
        var all = boxes(form);
        var count = checkedCount(form);

        form.querySelectorAll('[data-selection-count]').forEach(function (element) {
            element.textContent = String(count);
        });
        form.querySelectorAll('[data-needs-selection]').forEach(function (button) {
            button.disabled = count === 0;
        });
        form.querySelectorAll('[data-select-all]').forEach(function (selectAll) {
            selectAll.checked = all.length > 0 && count === all.length;
            selectAll.indeterminate = count > 0 && count < all.length;
        });
        all.forEach(function (box) {
            var row = box.closest('tr');
            if (row) {
                row.classList.toggle('selected', box.checked);
            }
        });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        var form = target.form;
        if (!form || !form.hasAttribute('data-selection')) {
            return;
        }
        if (target.hasAttribute('data-select-all')) {
            boxes(form).forEach(function (box) { box.checked = target.checked; });
        }
        refresh(form);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (!message) {
            return;
        }
        if (form.hasAttribute('data-selection')) {
            var count = checkedCount(form);
            if (count === 0) {
                event.preventDefault();
                window.alert('Sélectionnez au moins un mail.');
                return;
            }
            message = message.replace('{n}', String(count));
        }
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    function init() {
        refreshThemeToggles();
        document.querySelectorAll('form[data-selection]').forEach(refresh);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Retour sur la page (bouton Précédent, rechargement) : le navigateur peut remettre l'état des
    // cases après init(), sans événement « change ». Sans cette mise à jour, la case de l'en-tête,
    // le compteur et le bouton Supprimer ne correspondraient plus aux cases cochées.
    window.addEventListener('pageshow', init);
})();
