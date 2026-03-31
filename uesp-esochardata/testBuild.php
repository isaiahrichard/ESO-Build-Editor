<?php
// php -S: CDN proxy defaults off — UESP CDNs are behind Cloudflare; server-side curl/file_get_contents
// gets HTTP 403 (challenge). Browser requests to https://esoicons.uesp.net etc. usually succeed.
// Set ESO_LOCAL_UESP_CDN_PROXY=1 only if you have a fetch path that passes CF (rare for local dev).
if (getenv('ESO_LOCAL_UESP_CDN_PROXY') === false && PHP_SAPI === 'cli-server') {
	putenv('ESO_LOCAL_UESP_CDN_PROXY=0');
}
$esoLocalUespCdnProxy = getenv('ESO_LOCAL_UESP_CDN_PROXY') === '1';
?><!DOCTYPE HTML>
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
		<?php if (PHP_SAPI === 'cli-server') { ?>
		<!-- Same-origin /_esolog_api/ avoids browser CORS (live esolog only whitelists uesp.net). Router mode:
		     ESO_LOCAL_ESOLOG_API=local → sibling exportJson.php + YOUR MySQL (items must exist locally).
		     ESO_LOCAL_ESOLOG_API=proxy → server fetches https://esolog.uesp.net/… (no local mined tables needed if proxy works). -->
		<script>window.ESO_ESOLOG_API_BASE = "/_esolog_api";</script>
		<?php } ?>
		<?php if (!empty($esoLocalUespCdnProxy)) { ?>
		<script>window.ESO_ICON_URL = "/_uesp_cdn_proxy/esoicons";</script>
		<?php } ?>
		<script src="resources/esoEditBuild.js"></script>
		
	</head>
<body>
<?php
if (PHP_SAPI === 'cli-server') {
	$esoLocalEsologApi = getenv('ESO_LOCAL_ESOLOG_API');
	if ($esoLocalEsologApi === false || $esoLocalEsologApi === '') {
		$esoLocalEsologApi = 'local';
	}
	if ($esoLocalEsologApi === 'local') {
		echo '<div style="background:#fff3cd;border-bottom:1px solid #e0c96e;padding:8px 12px;font-size:12px;line-height:1.4;">';
		echo '<strong>Local item API:</strong> <code>/_esolog_api/</code> runs <code>exportJson.php</code> against <strong>your</strong> esolog MySQL. ';
		echo 'Equipping an item that has no row there returns no <code>minedItem</code> data, so sets and item-based stats do not update. ';
		echo 'To use live UESP item data instead, start the dev server with <code>ESO_LOCAL_ESOLOG_API=proxy</code> (see <code>scripts/run-local-server.sh</code>). ';
		echo 'If proxy returns 502, Cloudflare may be blocking server-side curl — import items or use a VPN/browser export.';
		echo '</div>';
	}
}

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
