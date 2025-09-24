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
    const updateSelect = (select, options, allLabel) => {
        const current = select.value;
        // Remove all options.
        while (select.options.length > 0) {
            select.remove(0);
        }
        // Always include an 'All' empty option first.
        const allOpt = new Option(allLabel || M.util.get_string('all'), '');
        select.add(allOpt);
        let hasCurrent = false;
        options.forEach(o => {
            const opt = new Option(o.label, o.value);
            if (o.slug) {
                opt.dataset.slug = o.slug;
            }
            if (o.title) {
                opt.dataset.title = o.title;
            }
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
    const fetchAndRefresh = (baseurlEl, keyIdEl, keyCredEl, selectEl, allLabel, labelEl, slugEl) => {
        const baseurl = baseurlEl.value.trim();
        const keyidentity = keyIdEl ? keyIdEl.value : '';
        const keycredential = keyCredEl ? keyCredEl.value : '';

        if (!baseurl) {
            updateSelect(selectEl, [], allLabel);
            selectEl.disabled = true;
            if (labelEl) labelEl.value = '';
            if (slugEl) slugEl.value = '';
            return;
        }

        selectEl.disabled = true;

        Ajax.call([{
            methodname: 'repository_omeka_list_sites',
            args: { baseurl, keyidentity, keycredential },
        }])[0].then(result => {
            updateSelect(selectEl, result.options || [], allLabel);
            selectEl.disabled = false;
            // Update hidden fields with current selection.
            if (labelEl || slugEl) {
                const opt = selectEl.options[selectEl.selectedIndex];
                if (labelEl) labelEl.value = opt ? opt.text : '';
                if (slugEl) slugEl.value = opt && opt.dataset ? (opt.dataset.slug || '') : '';
            }
        }).catch(Notification.exception);
    };

    /**
     * Init.
     */
    const init = (allLabel) => {
        const baseurlEl = document.getElementById('id_baseurl');
        const keyIdEl = document.getElementById('id_keyidentity');
        const keyCredEl = document.getElementById('id_keycredential');
        const selectEl = document.getElementById('id_siteid');
        const siteLabelEl = document.getElementById('id_sitelabel');
        const siteSlugEl = document.getElementById('id_siteslug');

        if (!baseurlEl || !selectEl) {
            return;
        }

        // Initial load on page ready.
        fetchAndRefresh(baseurlEl, keyIdEl, keyCredEl, selectEl, allLabel, siteLabelEl, siteSlugEl);

        // React to changes with debounce.
        const handler = debounce(() => fetchAndRefresh(baseurlEl, keyIdEl, keyCredEl, selectEl, allLabel, siteLabelEl, siteSlugEl), 500);
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

        // Keep hidden fields updated when user changes the selection.
        selectEl.addEventListener('change', () => {
            const opt = selectEl.options[selectEl.selectedIndex];
            if (siteLabelEl) siteLabelEl.value = opt ? opt.text : '';
            if (siteSlugEl) siteSlugEl.value = opt && opt.dataset ? (opt.dataset.slug || '') : '';
        });
    };

    return { init };
});
