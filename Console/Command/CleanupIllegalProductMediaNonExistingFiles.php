<?php

namespace Tnegeli\M2CliTools\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;


/**
 * Class CleanupIllegalProductMedia
 *
 * Cleanup illegal product media from database, which might break your image cache regeneration process
 */
class CleanupIllegalProductMediaNonExistingFiles extends Command
{

    private ResourceConnection $resource;
    private Filesystem $filesystem;
    private QuestionHelper $questionHelper;

    public function __construct(
        Filesystem         $filesystem,
        ResourceConnection $resource,
        QuestionHelper     $questionHelper,
    )
    {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tnegeli:cleanup-illegal-product-media-non-existing-files')
            ->setDescription('Cleanup illegal product media from database for which no files are available, which might break your image cache regeneration process.')
            ->addOption('dry-run')
            ->addOption('delete')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Remove the entries even if no file at all exists.');

        $help = "Cleanup illegal product media from database which have no files on the filesystem, which might break your image cache regeneration process.
Add the --dry-run option to just get the files that are unused.";

        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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
        if (!is_dir($imageDir)) {
            $output->writeln('<error>The media directory ' . $imageDir . ' does not exist. Aborting, otherwise all entries would be removed.</error>');
            return Cli::RETURN_FAILURE;
        }

        $mediaGalleryValuesToRemove = array();
        foreach ($values as $media) {
            $filename = $media['value'];
            if (!is_file($imageDir . $filename)) {
                $mediaGalleryValuesToRemove[$media['value_id']] = $filename;
                $output->writeln("No existing file for value_id " . $media['value_id'] . ": " . $filename);
            }
        }
        if (count($mediaGalleryValuesToRemove) == 0) {
            $output->writeln("There are no media gallery entries without a file. All is fine.");
            return Cli::RETURN_SUCCESS;
        }
        $output->writeln("Found " . count($mediaGalleryValuesToRemove) . " media gallery entries without a file.");
        if (count($mediaGalleryValuesToRemove) === count($values) && !$input->getOption('force')) {
            $message = 'No file exists for any of the entries. This usually means the media files are missing rather than the entries being illegal.';
            if ($isDryRun) {
                $output->writeln('<comment>WARNING: ' . $message . ' A real run will abort unless you add --force.</comment>');
                return Cli::RETURN_SUCCESS;
            } else {
                $output->writeln('<error>' . $message . ' Aborting. Add --force to remove them anyway.</error>');
                return Cli::RETURN_FAILURE;
            }
        }

        if (!$isDryRun) {
            $coreWrite = $this->resource->getConnection('core_write');

            // smaller parts to respect a large amount of entries to remove
            foreach (array_chunk(array_keys($mediaGalleryValuesToRemove), 50) as $valueIdChunk) {
                $coreWrite->query($this->getMediaGalleryDelete($valueIdChunk));
                $coreWrite->query($this->getMediaGalleryValueDelete($valueIdChunk));
                $coreWrite->query($this->getMediaGalleryValueToEntityDelete($valueIdChunk));
            }
            $output->writeln("Entries where removed from media gallery table. The command catalog:images:resize should work now.");
        } else {
            $output->writeln("The entries listed above are safe to be removed.");
        }

        return Cli::RETURN_SUCCESS;
    }

    private function getDbValues()
    {
        $coreRead = $this->resource->getConnection('core_read');
        /* select value_id from catalog_product_entity_media_gallery where value_id not in (select value_id from catalog_product_entity_media_gallery_value_to_entity); */
        $values = $coreRead->fetchAll($this->getSelect());
        return $values;
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

}
