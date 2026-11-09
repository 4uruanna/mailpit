/*
 * Script du Dashboard (servi par GET /v1/app/dashboard.js, seul script autorisé par la CSP).
 *
 *  - <form data-confirm="Message ?"> : demande confirmation avant l'envoi.
 *    Dans le message, {n} est remplacé par le nombre de cases « ids[] » cochées dans le formulaire.
 *  - <form data-selection> : formulaire à cases à cocher « ids[] ». La case [data-select-all]
 *    coche ou décoche toutes les autres ; [data-selection-count] affiche le nombre de cases
 *    cochées ; les boutons [data-needs-selection] sont désactivés tant que rien n'est coché.
 */
'use strict';

(function () {
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

    document.querySelectorAll('form[data-selection]').forEach(refresh);
})();
