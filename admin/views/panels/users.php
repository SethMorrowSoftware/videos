<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Users</h2>
            <p class="card-subtitle">Members of your film club</p>
        </div>
        <div class="users-toolbar">
            <input type="search" class="form-input users-search" id="usersSearchInput" placeholder="Search username or email...">
            <select class="form-input users-role-filter" id="usersRoleFilter">
                <option value="all">All roles</option>
                <option value="admin">Admins</option>
                <option value="editor">Editors</option>
                <option value="viewer">Viewers</option>
            </select>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="admin-table-wrap">
            <table class="admin-table" id="usersTable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th>Last seen</th>
                        <th class="num">Comments</th>
                        <th class="num">Bookmarks</th>
                        <th class="num">Watches</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <tr><td colspan="7" class="admin-table-empty">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="admin-pagination" id="usersPagination"></div>
    </div>
</div>
