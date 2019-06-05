<?php

namespace Tnegeli\M2CliTools\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;


/**
 * Class CleanupIllegalProductMedia
 *
 * Cleanup illegal product media from database, which might break your image cache regeneration process
 */
class CleanupIllegalProductMediaNonExistingFiles extends Command
{

    private $resource;
    private $filesystem;

    public function __construct(
        Filesystem $filesystem,
        ResourceConnection $resource
    ) {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('tnegeli:cleanup-illegal-product-media-non-existing-files')
            ->setDescription('Cleanup illegal product media from database for which no files are available, which might break your image cache regeneration process.')
            ->addOption('dry-run')
            ->addOption('delete');

        $help = "Cleanup illegal product media from database which have no files on the filesystem, which might break your image cache regeneration process.
Add the --dry-run option to just get the files that are unused.";

        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $isDryRun = $input->getOption('dry-run');
        if (!$isDryRun) {
            $output->writeln('WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.');
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);
            $this->questionHelper = $this->getHelper('question');
            if (!$this->questionHelper->ask($input, $output, $question)) {
                return;
            }
        }

        $values = $this->getDbValues();
        if (count($values) == 0) {
            $output->writeln('You have no media gallery table entries.');
            return;
        }

        $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $imageDir = rtrim($mediaDirectory->getAbsolutePath(),
                "/") . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'product';

        $mediaGalleryValuesToRemove = array();
        foreach ($values as $media) {
            $filename = $media['value'];
            if (!is_file($imageDir . $filename)) {
                $mediaGalleryValuesToRemove[$media['value_id']] = $filename;
                $output->writeln("The following item has no existing file: " . print_r($media, true));
            }
        }
        if (count($mediaGalleryValuesToRemove) == 0) {
            $output->writeln("There are no media gallery entries without a file. All is fine.");
            return;
        }

        if (!$isDryRun) {
            $coreWrite = $this->resource->getConnection('core_write');
            $coreWrite->query($this->getMediaGalleryDelete(array_keys($mediaGalleryValuesToRemove)));
            $coreWrite->query($this->getMediaGalleryValueDelete(array_keys($mediaGalleryValuesToRemove)));
            $coreWrite->query($this->getMediaGalleryValueToEntityDelete(array_keys($mediaGalleryValuesToRemove)));
            $output->writeln("Entries where removed from media gallery table. The command catalog:images:resize should work now.");
        } else {
            $output->writeln("The following items are save to be removed: " . print_r($mediaGalleryValuesToRemove,
                    true));
        }
    }

    private function getSelect()
    {
        $mediaGallery = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery');
        $select = 'SELECT value_id, value FROM ' . $mediaGallery;
        return $select;
    }

    private function getMediaGalleryDelete($valueIds)
    {
        $mediaGallery = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery');
        $valueIds = implode(',', $valueIds);
        $delete = 'DELETE FROM ' . $mediaGallery . ' WHERE value_id IN (' . $valueIds . ')';
        return $delete;
    }

    private function getMediaGalleryValueDelete($valueIds)
    {
        $mediaGalleryValue = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery_value');
        $valueIds = implode(',', $valueIds);
        $delete = 'DELETE FROM ' . $mediaGalleryValue . ' WHERE value_id IN (' . $valueIds . ')';
        return $delete;
    }

    private function getMediaGalleryValueToEntityDelete($valueIds)
    {
        $mediaGalleryValueToEntity = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $valueIds = implode(',', $valueIds);
        $delete = 'DELETE FROM ' . $mediaGalleryValueToEntity . ' WHERE value_id IN (' . $valueIds . ')';
        return $delete;
    }

    private function getDbValues()
    {
        $coreRead = $this->resource->getConnection('core_read');
        /* select value_id from catalog_product_entity_media_gallery where value_id not in (select value_id from catalog_product_entity_media_gallery_value_to_entity); */
        $values = $coreRead->fetchAll($this->getSelect());
        return $values;
    }

}