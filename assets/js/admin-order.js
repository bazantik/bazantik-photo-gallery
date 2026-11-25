(function ($) {
    'use strict';

    const $form = $('#bazantik-order-form');
    const $list = $('#bazantik-order-list');
    const $input = $('#bazantik-order-input');
    const $mode = $('#bazantik-order-mode');
    const $manual = $('#bazantik-order-manual');
    const $save = $('#bazantik-order-save');
    const $nested = $('#bazantik-order-nested');
    const cfg = window.bazantikOrder || {};

    let orders = {};
    try { orders = JSON.parse($form.attr('data-orders') || '{}'); } catch (e) {}
    const savedMode = $form.attr('data-mode') || '';
    const savedManual = (orders.manual || []).join(',');
    // Ruční pořadí, které se uloží; náhled neručních režimů ho nemění
    let manualKeys = (orders.manual || []).slice();
    let submitting = false;

    function currentKeys() {
        const keys = [];
        $list.children('li').each(function () { keys.push($(this).attr('data-key')); });
        return keys;
    }

    function isDirty() {
        const mode = $mode.val();
        if (mode !== savedMode) return true;
        return mode === 'manual' && manualKeys.join(',') !== savedManual;
    }

    function refresh() {
        const manual = $mode.val() === 'manual';
        $manual.toggleClass('is-auto', !manual);
        $list.toggleClass('is-readonly', !manual);
        if ($list.data('ui-sortable')) $list.sortable(manual ? 'enable' : 'disable');
        $input.val(manualKeys.join(','));
        const dirty = isDirty();
        $save.prop('disabled', !dirty);
        // Přenos na vnořené složky je možný jen z uloženého stavu, ne uprostřed úprav
        if (dirty) $nested.prop('disabled', true);
    }

    function showOrder(keys) {
        const byKey = {};
        $list.children('li').each(function () { byKey[$(this).attr('data-key')] = this; });
        keys.forEach(function (k) { if (byKey[k]) $list.append(byKey[k]); });
    }

    $mode.on('change', function () {
        const mode = $mode.val();
        if (mode === 'manual') showOrder(manualKeys);
        else showOrder(orders[mode] || []);
        refresh();
    });

    // Výběr složky se načte hned; neuložené změny pořadí se musí potvrdit, jinak se výběr vrátí
    const $folder = $('#bazantik-order-level');
    $folder.on('change', function () {
        if (isDirty() && !window.confirm(cfg.confirmSwitch)) {
            $folder.val($folder.find('option[selected]').val());
            return;
        }
        submitting = true;
        this.form.submit();
    });

    $form.on('submit', function () { submitting = true; });
    $nested.on('click', function (e) {
        if (!window.confirm(cfg.confirmNested)) {
            e.preventDefault();
            return;
        }
        submitting = true;
    });
    $('#bazantik-order-nested-form').on('submit', function () { submitting = true; });

    // Nahlášení neuložené změny při odchodu ze stránky nebo přepnutí úrovně (text určuje prohlížeč)
    window.addEventListener('beforeunload', function (e) {
        if (submitting || !isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
    });

    if (!$list.length) {
        refresh();
        return;
    }

    // Šipky jsou záložní ovládání pro dotyk a klávesnici; tažení je jen za úchyt, aby tlačítka dostala nativní klepnutí
    function arrow(dir) {
        const label = dir === 'up' ? cfg.up : cfg.down;
        return $('<button type="button" class="button bazantik-order-move"></button>')
            .attr({ 'aria-label': label, title: label })
            .append('<span class="dashicons dashicons-arrow-' + dir + '-alt2" aria-hidden="true"></span>')
            .on('click', function () {
                const $li = $(this).closest('li');
                if (dir === 'up') $li.prev('li').before($li);
                else $li.next('li').after($li);
                manualKeys = currentKeys();
                refresh();
                $(this).trigger('focus');
            });
    }

    $list.children('li').each(function () {
        $(this).append(arrow('up'), arrow('down'));
    });

    $list.sortable({
        handle: '.bazantik-order-handle',
        axis: 'y',
        tolerance: 'pointer',
        placeholder: 'bazantik-order-placeholder',
        update: function () {
            manualKeys = currentKeys();
            refresh();
        }
    });

    refresh();
})(jQuery);
