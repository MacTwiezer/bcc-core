<?php
// Home (dashboard.php) ve Starred (starred.php) sayfalarının ORTAK kapanışı —
// bkz. src/partials/home_shell_top.php.
?>
    </main>
</div>

<script src="/assets/account-menu.js" defer></script>
<script src="/assets/dismissable-panel.js" defer></script>
<script src="/assets/home.js" defer></script>
<script>
(function () {
    var sidebar = document.getElementById('home-sidebar');
    var sidebarToggle = document.getElementById('home-sidebar-toggle');
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.toggle('is-collapsed');
    });
})();
</script>
</body>
</html>
