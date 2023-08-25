<?php

namespace Tnegeli\M2CliTools\Console\Command;

use Magento\Catalog\Model\Indexer\Product\Flat\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;


/**
 * Class CleanupIllegalProductImageMarkersNonExistingFiles
 *
 * Cleanup illegal product image markers from database for which no files are available.
 */
class CleanupIllegalProductImageMarkersNonExistingFiles extends Command
{

    private $resource;
    private $filesystem;
    private $mediaDirectory;
    private $indexerFactory;


    public function __construct(
        \Magento\Indexer\Model\IndexerFactory $indexerFactory,
        Filesystem $filesystem,
        ResourceConnection $resource
    )
    {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        $this->indexerFactory = $indexerFactory;
        $this->mediaDirectory = $filesystem->getDirectoryRead(DirectoryList::MEDIA);
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('tnegeli:cleanup-illegal-product-image-markers-non-existing-files')
            ->setDescription('Cleanup illegal product image markers from database for which no files are available.')
            ->addOption('dry-run')
            ->addOption('delete');

        $help = "Cleanup illegal product image markers from database for which no files are available.'
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
                return Cli::RETURN_SUCCESS;
            }
        }

        $values = $this->getDbValues();
        if (count($values) == 0) {
            $output->writeln('You have no media gallery table entries.');
            return Cli::RETURN_SUCCESS;
        }

        $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $imageDir = rtrim($mediaDirectory->getAbsolutePath(),
                "/") . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'product';

        $imageMarkersToRemove = array();
        $imageMarkersToKeep = array();
        foreach ($values as $marker) {
            $filename = $marker['value'];
            if (!$this->fileExists($imageDir . $filename)) {
                $imageMarkersToRemove[$marker['value_id']] = $filename;
                $output->writeln("The following image marker has no existing file: " . print_r($marker, true));
            } else {
                $imageMarkersToKeep[$marker['value_id']] = $filename;
            }
        }

        if (count($imageMarkersToRemove) == 0) {
            $output->writeln("There are no image markers without a file. All is fine.");
            return Cli::RETURN_SUCCESS;
        }

        $output->writeln("The following items are left untouched: " . print_r($imageMarkersToKeep,
                true));

        if (!$isDryRun) {
            $coreWrite = $this->resource->getConnection('core_write');

            // smaller parts to respect a large amount of images to remove
            $imageMarkerChunksToRemove = array_chunk(array_keys($imageMarkersToRemove), 50);
            foreach ($imageMarkerChunksToRemove as $imageMarkerChunkToRemove) {
                $coreWrite->query($this->getImageMarkerDelete($imageMarkerChunkToRemove));
            }

            $this->reindexRequiredIndex($output);

            $output->writeln("Entries where removed from products.");
        } else {
            $output->writeln("The following items are save to be removed: " . print_r($imageMarkersToRemove,
                    true));
        }

        return Cli::RETURN_SUCCESS;
    }

    private function reindexRequiredIndex($output)
    {
        // catalog_product_flat is of interest to us
        $indexerIds = array(State::INDEXER_ID);

        foreach ($indexerIds as $indexerId) {
            $indexer = $this->indexerFactory->create();
            /* @var $indexer Indexer */
            $indexer->load($indexerId);

            if ($indexer->isScheduled()) {
                // if we are in update by schedule mode, an index:reset is required
                $indexer->getState()
                    ->setStatus(\Magento\Framework\Indexer\StateInterface::STATUS_INVALID)
                    ->save();
                $output->writeln("The following index was marked as invalid: " . State::INDEXER_ID);
            } else {
                // if we are in realtime mode, we can reindex directly
                $output->writeln("Start reindexing of: " . State::INDEXER_ID);
                $indexer->reindexAll();
                $output->writeln("The following index was rebuilt: " . State::INDEXER_ID);
            }
        }
    }

    private function getSelect()
    {
        $coreRead = $this->resource->getConnection('core_read');

        // get the internal id of the entity type product
        $eavEntityType = $this->resource->getConnection()->getTableName('eav_entity_type');
        $select = 'SELECT entity_type_id FROM ' . $eavEntityType . ' WHERE entity_type_code = "catalog_product"';
        $entityTypeId = $coreRead->fetchOne($select);

        // get the relevant attribute ids
        $eavAttribute = $this->resource->getConnection()->getTableName('eav_attribute');
        $select = 'SELECT attribute_id FROM ' . $eavAttribute . ' WHERE entity_type_id = ' . $entityTypeId . ' AND attribute_code IN ("image","small_image","thumbnail")';
        $imageAttributeIds = $coreRead->fetchCol($select);

        // get the image marker values
        $catalogProductEntityVarchar = $this->resource->getConnection()->getTableName('catalog_product_entity_varchar');
        $select = 'SELECT value_id, value FROM ' . $catalogProductEntityVarchar .
            ' WHERE attribute_id IN ("' . implode('","', $imageAttributeIds) . '") AND value != "no_selection" AND value IS NOT NULL';
        return $select;
    }

    private function fileExists($filename)
    {
        return $this->mediaDirectory->isFile($filename);
    }

    private function getImageMarkerDelete($valueIds)
    {
        $mediaGallery = $this->resource->getConnection()->getTableName('catalog_product_entity_varchar');
        $valueIds = implode(',', $valueIds);
        $delete = 'DELETE FROM ' . $mediaGallery . ' WHERE value_id IN (' . $valueIds . ')';
        return $delete;
    }

    private function getDbValues()
    {
        $coreRead = $this->resource->getConnection('core_read');
        $values = $coreRead->fetchAll($this->getSelect());
        return $values;
    }

}
