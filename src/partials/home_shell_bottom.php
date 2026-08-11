<?php
// Home (dashboard.php) ve Starred (starred.php) sayfalarının ORTAK kapanışı —
// bkz. src/partials/home_shell_top.php.
?>
    </main>
</div>

<script src="<?php echo bcc_asset_url('dismissable-panel.js'); ?>" defer></script>
<script src="<?php echo bcc_asset_url('account-menu.js'); ?>" defer></script>
<?php // global-search.js, dismissable-panel.js'ten SONRA (bcc_bindDismissable'ı
      // çağırıyor) ve home.js'ten ÖNCE (home.js'in silme işleyicisi onun açtığı
      // window.bcc_searchRemoveItem kancasını kullanıyor — defer sırası korunur). ?>
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
