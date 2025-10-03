<?php
session_destroy();
?>
<script>
    window.location.href = 'login.php';
</script>
<noscript>
    <meta http-equiv="refresh" content="0; url=login.php">
</noscript>
<div class="content-header">
    <h2>Logging Out...</h2>
    <p>If you are not redirected automatically, <a href="login.php">click here</a>.</p>
</div>