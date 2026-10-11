// Progressive enhancement: native selects remain the form/Alpine source of truth.
// Keyboard behavior follows WAI's select-only combobox pattern.
export function initializeBrandedSelects() {
    const controls = new Map();
    let nextId = 0;
    let opened = null;

    function enhance(select) {
        if (controls.has(select) || select.multiple || select.size > 1) return;
        const id = `brand-select-${++nextId}`;
        const wrapper = document.createElement('div');
        wrapper.className = `brand-select ${select.className.replace('form-select', '')}`;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'brand-select-trigger';
        button.setAttribute('role', 'combobox');
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', `${id}-options`);
        const label = select.labels?.[0];
        const labelText = label ? Array.from(label.childNodes).filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent).join(' ').trim() : '';
        const name = select.getAttribute('aria-label') || labelText || select.name || 'Choose an option';
        button.setAttribute('aria-label', name);
        button.setAttribute('aria-required', String(select.required));
        const valueLabel = document.createElement('span');
        valueLabel.className = 'brand-select-value';
        button.append(valueLabel);
        const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        chevron.setAttribute('viewBox', '0 0 24 24');
        chevron.setAttribute('aria-hidden', 'true');
        chevron.innerHTML = '<path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        button.append(chevron);
        const menu = document.createElement('div');
        menu.id = `${id}-options`;
        menu.className = 'brand-select-menu';
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('aria-label', `${name} options`);
        menu.hidden = true;
        // The popup lives outside its form/card; selecting an option must not
        // trigger Alpine's click.outside and dismiss an unsaved cell editor.
        menu.addEventListener('click', event => event.stopPropagation());
        select.before(wrapper);
        wrapper.append(select, button);
        document.body.append(menu);
        select.classList.add('brand-select-native');
        select.setAttribute('aria-hidden', 'true');
        select.tabIndex = -1;
        let active = select.selectedIndex;
        let optionSignature = '';
        let typed = '';
        let lastTyped = 0;
        const enabled = option => !option.disabled && !option.closest('optgroup')?.disabled;

        function sync() {
            const text = select.selectedOptions[0]?.textContent.trim() || 'Choose an option';
            if (valueLabel.textContent !== text) valueLabel.textContent = text;
            button.title = text;
            button.disabled = select.disabled;
            button.setAttribute('aria-required', String(select.required));
            const signature = JSON.stringify(Array.from(select.options, option => [option.textContent, option.value, enabled(option)]));
            if (signature !== optionSignature) {
                optionSignature = signature;
                menu.replaceChildren();
                Array.from(select.options).forEach((option, index) => {
                    const item = document.createElement('div');
                    item.id = `${id}-option-${index}`;
                    item.className = 'brand-select-option';
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-disabled', String(!enabled(option)));
                    item.textContent = option.textContent.trim();
                    item.addEventListener('pointermove', () => { if (enabled(option)) activate(index, false); });
                    item.addEventListener('pointerdown', event => event.preventDefault());
                    item.addEventListener('click', () => { if (enabled(option)) { active = index; commit(); button.focus(); } });
                    menu.append(item);
                });
            }
            Array.from(menu.children).forEach((item, index) => item.setAttribute('aria-selected', String(index === select.selectedIndex)));
        }

        function activate(index, scroll = true) {
            active = index;
            Array.from(menu.children).forEach((item, i) => item.classList.toggle('is-active', i === active));
            const item = menu.children[active];
            if (item) {
                button.setAttribute('aria-activedescendant', item.id);
                if (scroll) item.scrollIntoView({ block: 'nearest' });
            }
        }

        function close() {
            menu.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            button.removeAttribute('aria-activedescendant');
            if (opened === control) opened = null;
            typed = '';
        }

        function open() {
            if (select.disabled || select.options.length === 0) return;
            if (opened && opened !== control) opened.close();
            sync();
            const rect = button.getBoundingClientRect();
            const width = Math.min(Math.max(rect.width, 240), window.innerWidth - 24);
            const below = window.innerHeight - rect.bottom - 16;
            const above = rect.top - 16;
            const useAbove = below < 180 && above > below;
            const height = Math.min(320, Math.max(80, useAbove ? above : below));
            menu.style.width = `${width}px`;
            menu.style.maxHeight = `${height}px`;
            menu.style.left = `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`;
            menu.style.top = useAbove ? 'auto' : `${rect.bottom + 6}px`;
            menu.style.bottom = useAbove ? `${window.innerHeight - rect.top + 6}px` : 'auto';
            menu.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            opened = control;
            const first = Array.from(select.options).findIndex(enabled);
            activate(select.selectedIndex >= 0 && enabled(select.options[select.selectedIndex]) ? select.selectedIndex : first);
        }

        function commit() {
            const option = select.options[active];
            if (!option || !enabled(option)) { close(); return; }
            const changed = select.selectedIndex !== active;
            select.selectedIndex = active;
            close();
            sync();
            button.removeAttribute('aria-invalid');
            if (changed) {
                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function move(direction, edge = false) {
            const indexes = Array.from(select.options).map((option, index) => enabled(option) ? index : -1).filter(index => index >= 0);
            if (!indexes.length) return;
            const current = indexes.indexOf(active);
            const target = edge ? (direction > 0 ? indexes.length - 1 : 0) : Math.max(0, Math.min(indexes.length - 1, current + direction));
            activate(indexes[target]);
        }

        button.addEventListener('click', event => { event.preventDefault(); menu.hidden ? open() : close(); });
        button.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !menu.hidden) { event.preventDefault(); event.stopPropagation(); close(); return; }
            if (event.key === 'Tab') { if (!menu.hidden) commit(); return; }
            if (['ArrowDown', 'ArrowUp', 'Home', 'End', 'PageDown', 'PageUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                const wasOpen = !menu.hidden;
                if (!wasOpen) open();
                if (event.key === 'Enter' || event.key === ' ') { if (wasOpen) commit(); }
                else if (event.altKey && event.key === 'ArrowUp' && wasOpen) commit();
                else if (event.key === 'Home' || event.key === 'End') move(event.key === 'End' ? 1 : -1, true);
                else if (wasOpen) move(event.key.includes('Down') ? (event.key === 'PageDown' ? 10 : 1) : (event.key === 'PageUp' ? -10 : -1));
                return;
            }
            if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                if (menu.hidden) open();
                const now = Date.now();
                typed = now - lastTyped > 700 ? event.key.toLowerCase() : typed + event.key.toLowerCase();
                lastTyped = now;
                const repeating = typed.split('').every(char => char === typed[0]);
                const prefix = repeating ? typed[0] : typed;
                const options = Array.from(select.options);
                const start = repeating ? active + 1 : 0;
                for (let offset = 0; offset < options.length; offset++) {
                    const index = (start + offset) % options.length;
                    if (enabled(options[index]) && options[index].textContent.trim().toLowerCase().startsWith(prefix)) { activate(index); break; }
                }
            }
        });
        button.addEventListener('blur', event => { if (!menu.contains(event.relatedTarget)) close(); });
        select.addEventListener('change', sync);
        select.addEventListener('focus', () => button.focus());
        select.addEventListener('invalid', () => { button.setAttribute('aria-invalid', 'true'); button.focus(); });
        select.form?.addEventListener('reset', () => setTimeout(sync, 0));
        const optionsObserver = new MutationObserver(sync);
        optionsObserver.observe(select, { attributes: true, childList: true, subtree: true, characterData: true });
        const control = { select, button, menu, close, sync, destroy() { close(); optionsObserver.disconnect(); menu.remove(); } };
        controls.set(select, control);
        sync();
    }

    const scan = () => {
        for (const [select, control] of controls) {
            if (!select.isConnected) { control.destroy(); controls.delete(select); }
        }
        document.querySelectorAll('[data-branded-dropdowns] select').forEach(enhance);
    };
    scan();
    let scheduled = false;
    new MutationObserver(() => {
        if (scheduled) return;
        scheduled = true;
        queueMicrotask(() => { scheduled = false; scan(); });
    }).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('pointerdown', event => {
        if (opened && !opened.menu.contains(event.target) && !opened.button.contains(event.target)) opened.close();
    });
    document.addEventListener('branded-select-sync', () => controls.forEach(control => control.sync()));
    window.addEventListener('resize', () => opened?.close());
    window.addEventListener('scroll', event => { if (opened && !opened.menu.contains(event.target)) opened.close(); }, true);
}
