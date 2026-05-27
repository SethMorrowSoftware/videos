<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Comments</h2>
            <p class="card-subtitle">Review and moderate member discussion</p>
        </div>
        <div class="comments-mod-filters" id="commentsModFilters" role="tablist" aria-label="Filter comments">
            <button type="button" class="filter-tab active" data-filter="all" role="tab" id="modFilterTabAll" aria-selected="true" aria-controls="commentsModList">All</button>
            <button type="button" class="filter-tab" data-filter="recent" role="tab" id="modFilterTabRecent" aria-selected="false" aria-controls="commentsModList">Last 7 days</button>
            <button type="button" class="filter-tab" data-filter="reported" role="tab" id="modFilterTabReported" aria-selected="false" aria-controls="commentsModList">
                Reported
                <span class="filter-tab-badge" id="reportedBadge" style="display:none;">0</span>
            </button>
            <button type="button" class="filter-tab" data-filter="hidden" role="tab" id="modFilterTabHidden" aria-selected="false" aria-controls="commentsModList">Hidden</button>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <div id="commentsModList" class="comments-mod-list" role="tabpanel" aria-live="polite">
            <div class="admin-table-empty" style="padding:40px;">Loading…</div>
        </div>
        <div class="admin-pagination" id="commentsModPagination"></div>
    </div>
</div>
