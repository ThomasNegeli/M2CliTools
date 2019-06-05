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
class CleanupIllegalProductMedia extends Command
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
        $this->setName('tnegeli:cleanup-illegal-product-media')
            ->setDescription('Cleanup illegal product media from database, which might break your image cache regeneration process.')
            ->addOption('dry-run')
            ->addOption('delete');

        $help = "Cleanup illegal product media from database, which might break your image cache regeneration process.
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
            $output->writeln('There are no illegal values in media gallery table.');
            return;
        }
        echo "The following entries in " . $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery') . " are illegal: " . print_r($values,
                true) . PHP_EOL;

        if (!$isDryRun) {
            $coreWrite = $this->resource->getConnection('core_write');
            $coreWrite->query($this->getDelete());
            $output->writeln("Entries where removed from media gallery table. The command catalog:images:resize should work now.");
        }
    }

    private function getSelect()
    {
        $mediaGallery = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery');
        $mediaGalleryValueToEntity = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $select = 'SELECT value_id FROM ' . $mediaGallery . ' WHERE value_id NOT IN (SELECT value_id FROM ' . $mediaGalleryValueToEntity . ')';
        return $select;
    }

    private function getDelete()
    {
        $mediaGallery = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery');
        $mediaGalleryValueToEntity = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $delete = 'DELETE FROM ' . $mediaGallery . ' WHERE value_id NOT IN (SELECT value_id FROM ' . $mediaGalleryValueToEntity . ')';
        return $delete;
    }


    private function getDbValues()
    {
        $coreRead = $this->resource->getConnection('core_read');
        /* select value_id from catalog_product_entity_media_gallery where value_id not in (select value_id from catalog_product_entity_media_gallery_value_to_entity); */
        $values = $coreRead->fetchCol($this->getSelect());
        return $values;
    }

}