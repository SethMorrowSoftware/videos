<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Comments</h2>
            <p class="card-subtitle">Review and moderate member discussion</p>
        </div>
        <div class="comments-mod-filters" id="commentsModFilters" role="tablist">
            <button type="button" class="filter-tab active" data-filter="all" role="tab" aria-selected="true">All</button>
            <button type="button" class="filter-tab" data-filter="recent" role="tab" aria-selected="false">Last 7 days</button>
            <button type="button" class="filter-tab" data-filter="reported" role="tab" aria-selected="false">
                Reported
                <span class="filter-tab-badge" id="reportedBadge" style="display:none;">0</span>
            </button>
            <button type="button" class="filter-tab" data-filter="hidden" role="tab" aria-selected="false">Hidden</button>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <div id="commentsModList" class="comments-mod-list">
            <div class="admin-table-empty" style="padding:40px;">Loading…</div>
        </div>
        <div class="admin-pagination" id="commentsModPagination"></div>
    </div>
</div>
