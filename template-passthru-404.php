<?php
	header("HTTP/1.1 404 Not Found");
?>
<!DOCTYPE html>
<html>
<head>
<title>Not Found</title>
</head>

<body>
<h1>Not Found</h1>

<p><code><?=$_SERVER['REQUEST_URI']?></code> is not a
resource available on this server.</p>
</body>
</html>

