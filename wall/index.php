<?php
echo "<h1>Hello from Render USA!</h1>";
echo "Curl 状态: " . (extension_loaded('curl') ? '🟢 已启用' : '🔴 未启用') . "<br>";
echo "Mbstring 状态: " . (extension_loaded('mbstring') ? '🟢 已启用' : '🔴 未启用') . "<br>";
?>
