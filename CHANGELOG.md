# Changelog

## 2.0.0

### Breaking changes

* Requires PHP 8.2 or newer and Magento 2.4.7 or newer.
* Commands exit with code 1 if files could not be removed or a safety check aborts the run.
* `tnegeli:cleanup-illegal-product-media-non-existing-files` and
  `tnegeli:cleanup-illegal-product-image-markers-non-existing-files` abort if the product media directory does not
  exist, or if not a single referenced file exists. Use the new `--force` option to remove the entries anyway.
* Output is written line by line instead of a table and full array dumps. Details are available via `-v`, `-vv`
  and `-vvv`.

### Added

* `--force` option for the commands listed above.
* Support for table prefixes.

### Fixed

* `tnegeli:cleanup-unused-category-media` treated almost all category images as unused, because newer Magento
  versions store the image path as `/media/catalog/category/<file>`.
* `tnegeli:cleanup-unused-swatches-media` crashed with "Area code is not set" as soon as swatch files existed, and
  crashed on output because the removed `table` helper was used.
* `tnegeli:cleanup-unused-swatches-media` listed the thumbnail instead of the swatch image variant.
* Commands for unused media crashed if the media directory does not exist.
* A failing file removal no longer aborts the whole run. The error is reported and the run continues.
* `tnegeli:cleanup-illegal-product-media-non-existing-files` deletes in chunks of 50 entries.
* Large amounts of media files no longer slow down the usage check or exhaust memory with output.
* Declared the dependencies on Magento_Catalog, Magento_Indexer and Magento_Swatches.
