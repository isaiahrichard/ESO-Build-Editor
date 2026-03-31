-- One-time patch if your DB was created before logInfo was added to seed.sql.
-- Example: docker exec -i eso-build-editor-mysql mysql -uroot -pesobuildlocal esobuilddata < uesp-esochardata/sql/add_loginfo_local.sql

USE `esobuilddata`;

CREATE TABLE IF NOT EXISTS `logInfo` (
  `id` TINYTEXT NOT NULL,
  `value` TINYTEXT NOT NULL,
  PRIMARY KEY (`id`(63))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `logInfo` (`id`, `value`) VALUES
  ('lastCPUpdate', 'local seed'),
  ('lastSkillUpdate', 'local seed')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
