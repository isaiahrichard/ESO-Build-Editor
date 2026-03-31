<?php
/**
 * Build / character data DB (saved builds). Same server as rules is fine for local dev.
 */
$uespEsoBuildDataReadDBHost = getenv('UESP_MYSQL_HOST') ?: '127.0.0.1';
$uespEsoBuildDataReadUser   = getenv('UESP_MYSQL_USER') ?: 'root';
$uespEsoBuildDataReadPW     = getenv('UESP_MYSQL_PASSWORD') !== false ? getenv('UESP_MYSQL_PASSWORD') : '';
$uespEsoBuildDataDatabase   = getenv('UESP_MYSQL_DATABASE') ?: 'esobuilddata';

$uespEsoBuildDataWriteDBHost = $uespEsoBuildDataReadDBHost;
$uespEsoBuildDataWriteUser   = $uespEsoBuildDataReadUser;
$uespEsoBuildDataWritePW     = $uespEsoBuildDataReadPW;
