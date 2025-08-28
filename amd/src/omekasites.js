// SPDX-License-Identifier: GPL-3.0-or-later
// Live refresh of Omeka-S sites select when baseurl / keys change.

define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {

    /**
     * Debounce helper.
     */
    const debounce = (fn, delay = 400) => {
        let t = null;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(null, args), delay);
        };
    };

    /**
     * Update the site select options.
     */
    const updateSelect = (select, options) => {
        const current = select.value;
        // Remove all options.
        while (select.options.length > 0) {
            select.remove(0);
        }
        // If no options: add placeholder.
        if (!options.length) {
            const opt = new Option(M.util.get_string('choosedots', 'moodle'), '');
            select.add(opt);
            return;
        }
        let hasCurrent = false;
        options.forEach(o => {
            const opt = new Option(o.label, o.value);
            if (String(o.value) === String(current)) {
                hasCurrent = true;
            }
            select.add(opt);
        });
        if (hasCurrent) {
            select.value = current;
        }
    };

    /**
     * Fetch sites via WS and refresh select.
     */
    const fetchAndRefresh = (baseurlEl, keyIdEl, keyCredEl, selectEl) => {
        const baseurl = baseurlEl.value.trim();
        const keyidentity = keyIdEl ? keyIdEl.value : '';
        const keycredential = keyCredEl ? keyCredEl.value : '';

        if (!baseurl) {
            updateSelect(selectEl, []);
            selectEl.disabled = true;
            return;
        }

        selectEl.disabled = true;

        Ajax.call([{
            methodname: 'repository_omeka_list_sites',
            args: { baseurl, keyidentity, keycredential },
        }])[0].then(result => {
            updateSelect(selectEl, result.options || []);
            selectEl.disabled = false;
        }).catch(Notification.exception);
    };

    /**
     * Init.
     */
    const init = () => {
        const baseurlEl = document.getElementById('id_baseurl');
        const keyIdEl = document.getElementById('id_keyidentity');
        const keyCredEl = document.getElementById('id_keycredential');
        const selectEl = document.getElementById('id_siteid');

        if (!baseurlEl || !selectEl) {
            return;
        }

        // Initial load on page ready.
        fetchAndRefresh(baseurlEl, keyIdEl, keyCredEl, selectEl);

        // React to changes with debounce.
        const handler = debounce(() => fetchAndRefresh(baseurlEl, keyIdEl, keyCredEl, selectEl), 500);
        baseurlEl.addEventListener('change', handler);
        baseurlEl.addEventListener('blur', handler);
        if (keyIdEl) {
            keyIdEl.addEventListener('change', handler);
            keyIdEl.addEventListener('blur', handler);
        }
        if (keyCredEl) {
            keyCredEl.addEventListener('change', handler);
            keyCredEl.addEventListener('blur', handler);
        }
    };

    return { init };
});
