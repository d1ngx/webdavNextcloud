-- Kodbox WebDAV Nextcloud-compatible sync metadata.
-- Reference dictionary: https://doc.kodcloud.com/v2/#/help/mysql
--
-- The base Kodbox schema already provides io_source_meta:
--   sourceID bigint unsigned
--   `key` varchar(255)
--   `value` text
--   unique key sourceID_key (sourceID, `key`(200))
--
-- No new table is required. The plugin stores directory/file WebDAV
-- version markers as io_source_meta.key = 'webdavEtag'.

-- Ensure the lookup path used by PROPFIND ETag generation is indexed.
-- Safe to run repeatedly; existing installations usually already have it.
SET @sql_webdav_idx_key = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `io_source_meta` ADD INDEX `key` (`key`(200))',
    'SELECT ''io_source_meta.key index exists'' AS message'
  )
  FROM `information_schema`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'io_source_meta'
    AND `INDEX_NAME` = 'key'
);
PREPARE stmt_webdav_idx_key FROM @sql_webdav_idx_key;
EXECUTE stmt_webdav_idx_key;
DEALLOCATE PREPARE stmt_webdav_idx_key;

-- Ensure one metadata value per source/key.
-- Safe to run repeatedly; existing installations usually already have it.
SET @sql_webdav_idx_source_key = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `io_source_meta` ADD UNIQUE `sourceID_key` (`sourceID`, `key`(200))',
    'SELECT ''io_source_meta.sourceID_key unique index exists'' AS message'
  )
  FROM `information_schema`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'io_source_meta'
    AND `INDEX_NAME` = 'sourceID_key'
);
PREPARE stmt_webdav_idx_source_key FROM @sql_webdav_idx_source_key;
EXECUTE stmt_webdav_idx_source_key;
DEALLOCATE PREPARE stmt_webdav_idx_source_key;

-- Optional: initialize existing normal sources with a stable marker.
-- The code can also lazily create/update this value on the first write.
INSERT INTO `io_source_meta` (`sourceID`, `key`, `value`, `createTime`, `modifyTime`)
SELECT s.`sourceID`, 'webdavEtag', CONCAT(s.`modifyTime`, '.', s.`sourceID`), UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `io_source` AS s
LEFT JOIN `io_source_meta` AS m
  ON m.`sourceID` = s.`sourceID` AND m.`key` = 'webdavEtag'
WHERE s.`isDelete` = 0 AND m.`id` IS NULL;
