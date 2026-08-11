(function () {
    // Set by SyncFabExtension from the admin's own link, so a site that
    // changes SyncAdmin.url_segment does not end up with a shortcut to a
    // page that no longer exists.
    var syncUrl = window.__sharedCmsSyncUrl || '/admin/sync/';

    function updateVisibility() {
        var fab = document.getElementById('cms-sync-fab');
        if (!fab) return;
        var here = window.location.pathname.replace(/\/+$/, '');
        var target = syncUrl.replace(/\/+$/, '');
        var onSync = here === target;
        fab.style.display = onSync ? 'none' : 'flex';
    }

    function navigate(e) {
        e.preventDefault();
        var $ = window.jQuery;
        if ($ && $('.cms-container').length) {
            $('.cms-container').entwine('ss').loadPanel(syncUrl);
        } else {
            window.location.href = syncUrl;
        }
    }

    function addFab() {
        if (document.getElementById('cms-sync-fab')) return;
        // Build using DOM APIs with no text nodes — prevents entwine's MutationObserver
        // from calling getAttribute() on text nodes and throwing TypeError.
        var a = document.createElement('a');
        a.id = 'cms-sync-fab';
        a.href = syncUrl;
        a.title = 'Sync Data';
        a.setAttribute('data-tooltip', 'Sync Data');
        a.addEventListener('click', navigate);
        var icon = document.createElement('span');
        icon.className = 'cms-sync-fab__icon font-icon-sync';
        a.appendChild(icon);
        document.body.appendChild(a);
        updateVisibility();
    }

    // Keep FAB visibility in sync across PJAX navigations
    var origPushState = history.pushState.bind(history);
    history.pushState = function () {
        origPushState.apply(this, arguments);
        updateVisibility();
    };
    window.addEventListener('popstate', updateVisibility);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addFab);
    } else {
        addFab();
    }
}());
