<!DOCTYPE HTML>
<html>
	<head>
		<title>UESP:ESO Character Build Editor</title>
		<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
		<style type="text/css">
			body {
				background-color: #FBEFD5;
				font-family: sans-serif;
				font-size: 13px;
			}
		</style>
		<!-- Local + /_esolog_res/: same-origin so UESP/Cloudflare does not 403 on Referer http://127.0.0.1 -->
		<link rel="stylesheet" href="resources/esobuilddata.css" />
		<link rel="stylesheet" href="/_esolog_res/esoitemlink.css" />
		<link rel="stylesheet" href="/_esolog_res/esoitemlink_embed.css" />
		<link rel="stylesheet" href="/_esolog_res/esoItemSearchPopup.css" />
		<link rel="stylesheet" href="/_esolog_res/esoskills.css" />
		<link rel="stylesheet" href="/_esolog_res/esoskills_embed.css" />
		<link rel="stylesheet" href="/_esolog_res/esocp_simple_embed.css" />
		<link rel="stylesheet" href="resources/esoEditBuild.css" />
		<link rel="stylesheet" href="resources/esoEditBuild_embed.css" />
		<script type="text/javascript" src="resources/jquery-1.10.2.js"></script>
		<!-- esoskills.js esovsOnDocReady uses .draggable() / .droppable() (jQuery UI); without this, ready throws and esoEditBuild init never runs -->
		<script type="text/javascript" src="/_esolog_res/jquery-ui.min.js"></script>
		<script type="text/javascript" src="/_esolog_res/jquery.ui.touch-punch.min.js"></script>
		<script type="text/javascript" src="resources/jquery.tablesorter.min.js"></script>
		<script src="resources/json2.js"></script>
		<script src="/_esolog_res/esoitemlink.js"></script>
		<script src="/_esolog_res/esoskills.js"></script>
		<script src="/_esolog_res/esocp_simple.js"></script>
		<script type="text/javascript" src="resources/esobuilddata.js"></script>
		<!-- Character-data item tooltips (ShowEsoItemLinkPopup, OnEsoItemLinkEnter); not the same as esolog esoitemlink.js -->
		<script type="text/javascript" src="resources/esobuilddata_itemlink.js"></script>
		<script src="/_esolog_res/esoItemSearchPopup.js"></script>
		<script src="resources/esoEditBuild.js"></script>
		
	</head>
<body>
<?php

require_once("editBuild.class.php");


$buildDataEditor = new EsoBuildDataEditor();
print($buildDataEditor->GetOutputHtml());

?>

<script type="text/javascript">
	$(document).ready(function () {
		window.setTimeout(function () {
			if (typeof UpdateEsoCpData === 'function') UpdateEsoCpData();
			if (typeof UpdateEsoComputedStatsList === 'function') UpdateEsoComputedStatsList(true);
		}, 1000);
	});
</script>

<hr />
<div id='ecdFooter'>
Created and hosted by the <a href="http://www.uesp.net">UESP</a>.
</div>
</body>
</html>
