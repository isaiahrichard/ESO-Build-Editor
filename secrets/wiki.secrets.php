<?php
/**
 * MediaWiki session DB (saveBuild.php / UespMysqlSession). Local stub: same MySQL as build data.
 * Saving builds may still need MW session tables; use testBuild.php for editor-only use.
 */
$UESP_SERVER_DB1 = getenv('UESP_MYSQL_HOST') ?: '127.0.0.1';
$uespWikiDB      = getenv('UESP_MYSQL_DATABASE') ?: 'esobuilddata';
$uespWikiUser    = getenv('UESP_MYSQL_USER') ?: 'root';
$uespWikiPW      = getenv('UESP_MYSQL_PASSWORD') !== false ? getenv('UESP_MYSQL_PASSWORD') : '';
