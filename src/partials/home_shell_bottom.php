    </main>
</div>

<script src="<?php echo bcc_asset_url('confirm-modal.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('dismissable-panel.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('account-menu.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('global-search.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('home.js'); ?>" defer></script>
<script>
(function () {
    var sidebar = document.getElementById('home-sidebar');
    var sidebarToggle = document.getElementById('home-sidebar-toggle');
    if (sidebar && sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            sidebar.classList.toggle('is-collapsed');
        });
    }
})();
</script>
</body>
</html>
