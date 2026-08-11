<div class="sync-admin" data-base-url="$Link">
    <header class="sync-admin__header">
        <h2>Data Synchronisation</h2>
        <p>Fetch fresh data from external sources and update the website database.</p>
    </header>

<%-- The cards are built from the configured modes, so a site that syncs
     something new describes it in YAML and this screen keeps up. --%>
<% loop $Modes %>
    <% if $Mode == 'all' %>
    <div class="sync-admin__cards sync-admin__cards--single">
        <div class="sync-admin__card sync-admin__card--primary">
            <span class="sync-admin__primary-icon font-icon-sync" aria-hidden="true"></span>
            <h3>Sync Everything</h3>
            <p>Download fresh data from all sources and sync it to the database in one fully automated run &mdash; no manual steps required.</p>
            <% if $Datasets %><ul class="sync-admin__tags"><% loop $DatasetList %><li>$Value</li><% end_loop %></ul><% end_if %>
            <button type="button"
                    class="btn sync-admin__trigger sync-admin__primary-btn"
                    data-mode="all"
                    data-title="Syncing Everything">
                Sync Everything
            </button>
        </div>
    </div>

    <div class="sync-admin__divider"><span>or run steps individually</span></div>
    <% end_if %>
<% end_loop %>

    <div class="sync-admin__cards">
<% loop $Modes %>
    <% if $Mode != 'all' %>
        <div class="sync-admin__card">
            <div class="sync-admin__card-icon"><span class="font-icon-database" aria-hidden="true"></span></div>
            <h3>$Title</h3>
            <p>$Description</p>
            <% if $Datasets %><em>$Datasets</em><% end_if %>
            <button type="button"
                    class="btn sync-admin__trigger sync-admin__secondary-btn"
                    data-mode="$Mode"
                    data-title="$Title">
                Start $Title
            </button>
        </div>
    <% end_if %>
<% end_loop %>
    </div>
</div>

<%-- Floating status chip — visible while modal is hidden during an active run --%>
<button type="button"
        class="sync-admin__chip"
        id="sync-chip"
        hidden
        aria-label="Show sync progress">
    <span class="sync-admin__chip-pulse" aria-hidden="true"></span>
    <span class="sync-admin__chip-label" id="sync-chip-label">Running&hellip;</span>
    <span class="sync-admin__chip-arrow" aria-hidden="true">&#x2934;</span>
</button>

<div class="sync-admin__overlay"
     id="sync-overlay"
     hidden
     role="dialog"
     aria-modal="true"
     aria-labelledby="sync-modal-title">
    <div class="sync-admin__modal" role="document">
        <header class="sync-admin__modal-header">
            <div class="sync-admin__modal-titlebar">
                <span class="sync-admin__modal-icon font-icon-sync" aria-hidden="true"></span>
                <div>
                    <h3 id="sync-modal-title">Running&hellip;</h3>
                    <span class="sync-admin__modal-subtitle" id="sync-modal-subtitle">Live progress</span>
                </div>
            </div>
            <div class="sync-admin__modal-actions">
                <button type="button"
                        class="sync-admin__modal-iconbtn"
                        id="sync-modal-minimize"
                        aria-label="Minimize">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M6 13h12v-2H6z"/></svg>
                </button>
                <button type="button"
                        class="sync-admin__modal-iconbtn"
                        id="sync-modal-close"
                        hidden
                        aria-label="Close">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg>
                </button>
            </div>
        </header>

        <div class="sync-admin__log"
             id="sync-log"
             role="log"
             aria-live="polite"
             aria-atomic="false"></div>

        <footer class="sync-admin__modal-footer">
            <div class="sync-admin__stats">
                <span class="sync-admin__stat" id="sync-stat-events">0 events</span>
                <span class="sync-admin__stat" id="sync-stat-elapsed">00:00</span>
            </div>
            <div class="sync-admin__spinner" id="sync-spinner">
                <div class="sync-admin__spinner-dot"></div>
                <div class="sync-admin__spinner-dot"></div>
                <div class="sync-admin__spinner-dot"></div>
                <span class="sync-admin__spinner-label">Running, please wait&hellip;</span>
            </div>
            <span class="sync-admin__status" id="sync-status" hidden></span>
        </footer>
    </div>
</div>
