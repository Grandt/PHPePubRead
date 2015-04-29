<?php
session_start();

include_once("RelativePath.php");

$showChapter = 1;
$showToc = FALSE;

if ($_GET['file'] != "") {
	$tempFile = rawurldecode($_GET['file']);
	if (file_exists($tempFile) && is_file($tempFile)) {
		$file = $tempFile;
	}
}

if (!isset($file)) {
	die ("No file");
}
if ($_GET['show'] != "") {
	$showChapter = (int)$_GET['show'];
}
if ($_GET['showToc'] == "true") {
	$showToc = TRUE;
}

if ($_GET['reload'] == "true") {
	unset($_SESSION['chapters']);
	unset($chapters);
	unset($_SESSION['file']);
	$_GET['reload'] = "false";
}

if ($_GET['ext'] != "") {
	$ext = $_GET['ext'];
	if (preg_match('#https*://.+?\..+#i', $ext)) {
		if ($_GET['extok'] == "true") {
			header ("Location: " . $ext);
			exit;
		}

		print "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
		print "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\"\n";
		print "   \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n";
		print "<html xmlns=\"http://www.w3.org/1999/xhtml\">\n";
		?>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" type="text/css" href="style.css" />
<title>Test bench</title>
</head>
<body>
	<h1>Redirection alert</h1>
	<p>You are about to leave the epub reader</p>
	<p>Click on the link below if you are certain.</p>
	<p>
		<a href="scanbook.php?extok=true&ext=<?php echo $ext; ?>"><?php echo htmlspecialchars($ext); ?> </a>
	</p>
</body>
</html>
<?php
exit;
	}
}

$IDENTIFIER_EPUB = 'application/epub+zip';

$navAddr = "scanbook.php?file=" . rawurlencode($file);

if (!isset($_SESSION['chapters']) || $_SESSION['file'] != $file) {
	$files = array();
	$chapters = array();
	$headers = array();
	$css = array();
	$chaptersId = array();

	$zipArchive = zip_open($file);

	$zipEntry = zip_read($zipArchive);

	$name = zip_entry_name($zipEntry);
	$size = zip_entry_filesize($zipEntry);

	if ($name == "mimetype" && zip_entry_read($zipEntry, $size) == $IDENTIFIER_EPUB) {
		$files[$name] = $zipEntry;

		while($zipEntry = zip_read($zipArchive)) {
			if (zip_entry_filesize($zipEntry) > 0) {
				$files[zip_entry_name($zipEntry)] = $zipEntry;
			}
		}

		$compressed = 0;
		$uncompressed = 0;

		while (list($name, $zipEntry) = each($files)) {
			$compressed += zip_entry_compressedsize($zipEntry);
			$uncompressed += zip_entry_filesize($zipEntry);
		}

		$zipEntry = $files["META-INF/container.xml"];
		$container = readZipEntry($zipEntry);
		$xContainer = new SimpleXMLElement($container);
		$opfPath = $xContainer->rootfiles->rootfile['full-path'];
		$opfType = $xContainer->rootfiles->rootfile['media-type'];
		$bookRoot = dirname($opfPath) . "/";
		if ($bookRoot == "./") {
			$bookRoot = "";
		}


		// Read the OPF file:
		$opf = readZipEntry($files["$opfPath"]);

		$xOpf = new SimpleXMLElement($opf);

		$spine = $xOpf->spine;
		$spineIds = array();
		$spineIdOrder = array();

		$order = 0;
		foreach ($spine->itemref as $itemref) {
			$id = (string)$itemref['idref'];
			$spineIds[] = $id;
			$spineIdOrder[$id] = $order++;
		}

		$filesIds = array();
		$fileLocations = array();
		$fileTypes = array();

		$nonSpineIds = array();

		$manifest = $xOpf->manifest;
		foreach ($manifest->item as $item) {
			$id = (string)$item["id"];
			$href = (string)$item['href'];
			$filesIds[$id] = $href;
			$fileLocations[$href] = $id;
			$fileTypes[$id] = (string)$item['media-type'];

			if ($fileTypes[$id] == "text/css") {
				$cssData = readZipEntry($files[$bookRoot . $filesIds[$id]]);
				$css[$filesIds[$id]] = updateCSSLinks($cssData, $navAddr, $chapterDir);
			}

			if (!array_key_exists($id, $spineIdOrder)) {
				$nonSpineIds[$id] = $id;
			}
		}

		$chapterNum = 1;
		while (list($order, $itemref) = each($spineIds)) {
			if ($fileTypes[$itemref] == "application/xhtml+xml") {
				$chaptersId[$filesIds[$itemref]] = $chapterNum++;
			}
		}
		reset($spineIds);
		while (list($order, $itemref) = each($spineIds)) {
			if ($fileTypes[$itemref] == "application/xhtml+xml") {
				$chapterDir = dirname($filesIds[$itemref]);

				$chapter = readZipEntry($files[$bookRoot . $filesIds[$itemref]]);

				$headStart = strpos($chapter, "<head");
				$headStart = strpos($chapter, ">", $headStart) +1;
				$headEnd = strpos($chapter, "</head", $headStart);
				$head =  substr($chapter, $headStart, ($headEnd-$headStart));
				if (!preg_match('#<meta.+?http-equiv\s*=\s*"Content-Type#i', $head)) {
					$head = "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />\n" . $head;
				}

				$head = updateCSSLinks($head, $navAddr, $chapterDir);
				$head = updateLinks($head, $navAddr, $chapterDir, $chaptersId, $css);

				$headers[] = $head;

				$start = strpos($chapter, "<body");
				$start = strpos($chapter, ">", $start) +1;
				$end = strpos($chapter, "</body", $start);
				$chapter =  substr($chapter, $start, ($end-$start));

				$chapter = updateLinks($chapter, $navAddr, $chapterDir, $chaptersId, $css);
				$chapters[] = $chapter;
			}
		}
		reset($spineIds);

		// Read the NCX file:
		$ncxId = (string)$spine['toc'];
		$ncxPath = $bookRoot . $filesIds[$ncxId];

		$ncx = readZipEntry($files[$ncxPath]);
		$xNcx = new SimpleXMLElement($ncx);

		$bookTitle = (string)$xNcx->docTitle->text;
		$bookAuthor = (string)$xNcx->docAuthor->text;

		$navMap = $xNcx->navMap;
		$toc = updateLinks(parseNavMap($navMap), $navAddr, $chapterDir, $chaptersId, $css);

		zip_close($zipArchive);
		$_SESSION['bookRoot'] = $bookRoot;
		$_SESSION['file'] = $file;
		// 		$_SESSION['container'] = $container;
		// 		$_SESSION['xContainer'] = $xContainer;
		// 		$_SESSION['opfPath'] = $opfPath;
		// 		$_SESSION['opfType'] = $opfType;
		// 		$_SESSION['opf'] = $opf;
		// 		$_SESSION['xOpf'] = $xOpf;
		// 		$_SESSION['spine'] = $spine;
		// 		$_SESSION['spineIds'] = $spineIds;
		// 		$_SESSION['spineIdOrder'] = $spineIdOrder;
		// 		$_SESSION['manifest'] = $manifest;
		// 		$_SESSION['nonSpineIds'] = $nonSpineIds;
		$_SESSION['fileTypes'] = $fileTypes;
		$_SESSION['fileLocations'] = $fileLocations;
		$_SESSION['filesIds'] = $filesIds;
		$_SESSION['bookTitle'] = $bookTitle;
		$_SESSION['bookAuthor'] = $bookAuthor;
		// 		$_SESSION['ncxId'] = $ncxId;
		// 		$_SESSION['ncxPath'] = $ncxPath;
		// 		$_SESSION['xNcx'] = $xNcx;
		// 		$_SESSION['ncx'] = $ncx;
		//		$_SESSION['navMap'] = $navMap;
		$_SESSION['toc'] = $toc;
		// 		$_SESSION['spineIds'] = $spineIds;
		$_SESSION['chapters'] = $chapters;
		$_SESSION['headers'] = $headers;
		$_SESSION['css'] = $css;
		$_SESSION['chaptersId'] = $chaptersId;
	}  else {
		echo "<p>File '$file' is not an ePub file</p>\n";
	}
}

if (isset($_SESSION['chapters'])) {
	$bookRoot = $_SESSION['bookRoot'];
	$file = $_SESSION['file'];
	// 	$container = $_SESSION['container'];
	// 	$xContainer = $_SESSION['xContainer'];
	// 	$opfPath = $_SESSION['opfPath'];
	// 	$opfType = $_SESSION['opfType'];
	// 	$opf = $_SESSION['opf'];
	// 	$xOpf = $_SESSION['xOpf'];
	// 	$spine = $_SESSION['spine'];
	// 	$spineIds = $_SESSION['spineIds'];
	// 	$spineIdOrder = $_SESSION['spineIdOrder'];
	// 	$manifest = $_SESSION['manifest'];
	// 	$nonSpineIds = $_SESSION['nonSpineIds'];
	$fileTypes = $_SESSION['fileTypes'];
	$fileLocations = $_SESSION['fileLocations'];
	$filesIds = $_SESSION['filesIds'];
	$bookTitle = $_SESSION['bookTitle'];
	$bookAuthor = $_SESSION['bookAuthor'];
	// 	$ncxId = $_SESSION['ncxId'];
	// 	$ncxPath = $_SESSION['ncxPath'];
	// 	$xNcx = $_SESSION['xNcx'];
	// 	$ncx = $_SESSION['ncx'];
	// 	$navMap = $_SESSION['navMap'];
	$toc = $_SESSION['toc'];
	// 	$spineIds = $_SESSION['spineIds'];
	$chapters = $_SESSION['chapters'];
	$headers = $_SESSION['headers'];
	$css = $_SESSION['css'];
	$chaptersId = $_SESSION['chaptersId'];

	if (isset($ext)) {
		$refId = $fileLocations[$ext];
		$refType = $fileTypes[$refId];

		if(preg_match('#^image/.+#i', $refType)) {
			$zipArchive = zip_open($file);

			while($zipEntry = zip_read($zipArchive)) {
				if (zip_entry_filesize($zipEntry) > 0) {

					//echo "<!-- $refType \nname: ". zip_entry_name($zipEntry) ."\nname2: ". $bookRoot . $ext ."-->\n";

					if (zip_entry_name($zipEntry) == ($bookRoot . $ext)) {
						header("Pragma: public"); // required
						header("Expires: 0");
						header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
						header("Cache-Control: private",false); // required for certain browsers
						header("Content-Type: $refType");
						header("Content-Disposition: attachment; filename=\"".$ext."\";" );
						header("Content-Transfer-Encoding: binary");
						header("Content-Length: " . zip_entry_filesize($zipEntry));
						ob_clean();
						flush();
						echo readZipEntry($zipEntry);
					}
				}

			}
			zip_close($zipArchive);
			exit;
		} else if (isset($css[$ext])) {
			$cssData = $css[$ext];

			header("Pragma: public"); // required
			header("Expires: 0");
			header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
			header("Cache-Control: private",false); // required for certain browsers
			header("Content-Type: test/css");
			header("Content-Disposition: attachment; filename=\"".$ext."\";" );
			header("Content-Transfer-Encoding: binary");
			header("Content-Length: " . strlen($cssData));
			ob_clean();
			flush();
			echo $cssData;
		}
	} else {
		print "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\"\n   \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n<html xmlns=\"http://www.w3.org/1999/xhtml\">\n<head>\n";
		print $headers[$showChapter-1];
		print "\n<link rel=\"stylesheet\" type=\"text/css\" href=\"style.css\" />\n</head>\n<body>\n";

		$nav = "\n<p class=\"nav\" style=\"background-color: #eeeeee;\">";
		if ($showChapter > 1) {
			$nav .= "<a href=\"" . $navAddr . "&show=" . "1\">&nbsp;|&lt; &lt;&nbsp;</a> &nbsp; ";
			$nav .= "<a href=\"" . $navAddr . "&show=" . ($showChapter-1) . "\">&nbsp;&nbsp;&lt;&nbsp;&nbsp;</a> &nbsp; ";
		} else {
			$nav .= "&nbsp;|&lt; &lt;&nbsp; &nbsp; &nbsp;&nbsp;&lt;&nbsp;&nbsp; &nbsp; ";
		}
		if ($showChapter < sizeof($chapters)) {
			$nav .= "<a href=\"" . $navAddr . "&show=" . ($showChapter+1) . "\">&nbsp;&nbsp;&gt;&nbsp;&nbsp;</a> &nbsp; ";
			$nav .= "<a href=\"" . $navAddr . "&show=" . sizeof($chapters) . "\">&nbsp;&gt; &gt;|&nbsp;</a>";
		} else {
			$nav .= "&nbsp;&nbsp;&gt;&nbsp;&nbsp; &nbsp; &nbsp;&gt; &gt;|&nbsp;";
		}

		$nav .= " &nbsp; <a href=\"" . $navAddr . "&show=" . $showChapter . ($showToc ? "" : "&showToc=true") . "\">Table of Contents</a>";
		$nav .= " &nbsp; <a href=\"" . $navAddr . "&show=" . $showChapter . "&reload=true\">Reload</a></p>\n";

		if ($showChapter < 1 || $showChapter > sizeof($chapters)) {
			$showChapter = 1;
		}

		echo $nav;
		echo "<div class='epubbody' id='epubbody'>";
		if ($showToc) {
			echo $toc;
		} else {
		    echo $chapters[$showChapter-1];
		}
		echo "\n</div>\n";
		echo $nav;
	}
}

function parseNavMap($navMap, $level = 0) {
	$indent = str_repeat("    ", $level);
	$nav = $indent . "<ul>\n";

	foreach ($navMap->navPoint as $item) {
		$id = (string)$item["id"];
		$label = (string)$item->navLabel->text;
		$src =  (string)$item->content["src"];
		$nav .= $indent . "  <li><a href=\"" . $bookRoot . $src . "\">$label</a></li>\n";

		if ((bool)$item->navPoint == TRUE) {
			$nav .= parseNavMap($item, $level+1);
		}
	}
	$nav .= $indent . "</ul>\n";
	return $nav;
}

function readZipEntry($zipEntry) {
	return zip_entry_read($zipEntry, zip_entry_filesize($zipEntry));
}

function updateLinks($chapter, $navAddr, $chapterDir, $chaptersId, $css) {
	preg_match_all('#\s+src\s*=\s*"(.+?)"#im', $chapter, $links, PREG_SET_ORDER);

	$itemCount = count($links);
	for ($idx = 0; $idx < $itemCount; $idx++) {
		$link = $links[$idx];
		if (preg_match('#https*://.+?\..+#i', $link[1])) {
			$chapter = str_replace($link[0], " src=\".\"", $chapter);
		} else {
			$refFile = RelativePath::pathJoin($chapterDir, $link[1]);
			$chapter = str_replace($link[0], " src=\"" . $navAddr . "&ext=" . rawurlencode($refFile) . "\"", $chapter);
		}
	}

	preg_match_all('#\s+href\s*=\s*"(.+?)"#im', $chapter, $links, PREG_SET_ORDER);

	$itemCount = count($links);
	for ($idx = 0; $idx < $itemCount; $idx++) {
		$link = $links[$idx];
		if (preg_match('#https*://.+?\..+#i', $link[1])) {
			//$chapter = str_replace($link[0], " href=\"scanbook.php?ext=" . rawurlencode($link[1]) . "\"", $chapter);
		} else {
			$refFile = RelativePath::pathJoin($chapterDir, $link[1]);
			$id = "";
			if (strpos($refFile, "#") > 0) {
				$array = split("#", $refFile);
				$refFile = $array[0];
				$id = "#" . $array[1];
			}

			if (isset($chaptersId[$refFile])) {
				$chapter = str_replace($link[0], " href=\"" . $navAddr  . "&show=" . $chaptersId[$refFile] . $id . "\"", $chapter);
			} else if (isset($css[$refFile])) {
				$chapter = str_replace($link[0], " href=\"" . $navAddr  . "&ext=" . rawurlencode($refFile) . $id . "\"", $chapter);
			} else {
				$chapter = str_replace($link[0], " href=\"scanbook.php?ext=" . rawurlencode($refFile) . $id . "\"", $chapter);
			}
		}
	}

	return $chapter;
}

function updateCSSLinks($cssData, $navAddr, $chapterDir) {
	preg_match_all('#url\s*\([\'\"\s]*(.+?)[\'\"\s]*\)#im', $cssData, $links, PREG_SET_ORDER);

	$itemCount = count($links);
	for ($idx = 0; $idx < $itemCount; $idx++) {
		$link = $links[$idx];
		if (preg_match('#https*://.+?\..+#i', $link[1])) {
			$chapter = str_replace($link[0], " src=\".\"", $chapter);
		} else {
			$refFile = RelativePath::pathJoin($chapterDir, $link[1]);
			$chapter = str_replace($link[0], " src=\"" . $navAddr . "&ext=" . rawurlencode($refFile) . "\"", $chapter);
		}
	}

	return $cssData;
}
?>
</body>
</html>
