# CLI Tools for Magento 2

Various CLI Tools for Magento 2

## Available commands:

```
php bin/magento tnegeli:cleanup-unused-product-media
```
Use this command to backup (or delete) unused product media from filesystem.

You can use the --dry-run option to just test the result.

You can use the --delete option to remove files, instead of doing a backup.


```
php bin/magento tnegeli:cleanup-unused-category-media
```
Use this command to backup (or delete) unused category media from filesystem.

You can use the --dry-run option to just test the result.

You can use the --delete option to remove files, instead of doing a backup.

```
php bin/magento tnegeli:cleanup-unused-swatches-media
```
Use this command to backup (or delete) unused swatches media from filesystem.

You can use the --dry-run option to just test the result.

You can use the --delete option to remove files, instead of doing a backup.

```
php bin/magento tnegeli:cleanup-illegal-product-media
```
Use this command to identify and remove illegal entries in the media gallery database table, which might break catalog:images:resize process.

You can use the --dry-run option to just test the result and give you a list of value_id entries from the media gallery table which are illegal.

```
php bin/magento tnegeli:cleanup-illegal-product-media-non-existing-files
```
Use this command to identify and remove illegal entries in the media gallery database table that have no files on the filesystem, which might break catalog:images:resize process.

You can use the --dry-run option to just test the result and give you a list of value_id entries from the media gallery table which are illegal.

```
php bin/magento tnegeli:cleanup-illegal-product-image-markers-non-existing-files
```
Each product is checked for the attribute values of
* image
* small_image
* thumbnail

If a product references a file that does not exist, the reference is removed.

You can use the --dry-run option to just test your database.
