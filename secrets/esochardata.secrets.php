<?php
/**
 * Character-data DB (viewCharData / parsers). Point at same DB for local experiments.
 */
$uespEsoCharDataReadDBHost = getenv('UESP_MYSQL_HOST') ?: '127.0.0.1';
$uespEsoCharDataReadUser   = getenv('UESP_MYSQL_USER') ?: 'root';
$uespEsoCharDataReadPW     = getenv('UESP_MYSQL_PASSWORD') !== false ? getenv('UESP_MYSQL_PASSWORD') : '';
$uespEsoCharDataDatabase   = getenv('UESP_MYSQL_DATABASE') ?: 'esobuilddata';

$uespEsoCharDataWriteDBHost = $uespEsoCharDataReadDBHost;
$uespEsoCharDataWriteUser   = $uespEsoCharDataReadUser;
$uespEsoCharDataWritePW     = $uespEsoCharDataReadPW;
