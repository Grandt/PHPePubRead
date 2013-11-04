<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <title>ePub decode and read test</title>
    </head>
    <body>
		<?php
		echo "<h1>ePub decode and read test</h1>\n";
		
		//$iter = new DirectoryIterator("./books");
		$iter = scandir("./books");
		asort($iter);
		foreach ($iter as $file) {
			$di = pathinfo($file);
			if (strtolower($di['extension']) == "epub") {
				echo "<p>$file<br />\n - <a href=\"scanbook.php?file=books/$file\">Read</a> or <a href=\"books/$file\">Download</a> </p>\n";
			}
		}

		?>
		<hr />
		<p><a href="PHPEPubRead-src.zip">Source Code</a></p>
    </body>
</html>
