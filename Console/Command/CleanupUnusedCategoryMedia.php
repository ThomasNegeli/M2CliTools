<?php

namespace Tnegeli\M2CliTools\Console\Command;

use FilesystemIterator;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;


/**
 * Class CleanupUnusedCategoryMedia
 *
 * Cleanup unused category media files
 */
class CleanupUnusedCategoryMedia extends Command
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
        $this->setName('tnegeli:cleanup-unused-category-media')
            ->setDescription('Cleans up unused category media from the filesystem.')
            ->addOption('dry-run')
            ->addOption('delete');

        $help = "Find and backup or remove product media files, which are on your filesystem but are not used by any product.
Add the --dry-run option to just get the files that are unused.
Add the --delete option to delete the files, instead of doing a backup";

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

        $isDelete = $input->getOption('delete');
        if ($isDelete) {
            $output->writeln('WARNING: this will delete unused media files from Filesystem. If you want to backup unused files, remove --delete.');
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);
            $this->questionHelper = $this->getHelper('question');
            if (!$this->questionHelper->ask($input, $output, $question)) {
                return Cli::RETURN_SUCCESS;
            }
        }

        $values = $this->getDbValues();
        $output->writeln('Found ' . count($values) . ' media entries in database.');
        foreach ($values as $value) {
            $output->writeln('DB value: ' . $value, OutputInterface::VERBOSITY_DEBUG);
        }
        $values = array_fill_keys($values, true);

        $filesize = 0;
        $countFiles = 0;
        $countErrors = 0;
        $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $mediaWriteDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $imageDir = rtrim($mediaDirectory->getAbsolutePath(), "/") . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'category';
        if (!is_dir($imageDir)) {
            $output->writeln("The media directory " . $imageDir . " does not exist. Nothing to clean up.");
            return Cli::RETURN_SUCCESS;
        }

        $backupDir = $imageDir . DIRECTORY_SEPARATOR . 'unused_files_backup';
        $directoryIterator = new RecursiveDirectoryIterator($imageDir, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($directoryIterator) as $file) {

            if (
                strpos($file, "/.DS_Store") !== false ||
                strpos($file, "/cache") !== false ||
                strpos($file, "/default") !== false ||
                strpos($file, "/placeholder") !== false ||
                strpos($file, "/watermark") !== false ||
                strpos($file, "/unused_files_backup") !== false ||
                is_dir($file)
            ) {
                continue;
            }

            $filePath = ltrim(str_replace($imageDir, "", $file), "/");
            if (empty($filePath)) continue;
            $output->writeln("Checking file for usage: " . $filePath, OutputInterface::VERBOSITY_VERY_VERBOSE);
            if (!isset($values[$filePath])) {
                $filesize += filesize($file);
                $countFiles++;
                if (!$isDryRun) {
                    $output->writeln('## REMOVING: ' . $filePath . ' ##');
                    try {
                        $relativeFile = $mediaWriteDirectory->getRelativePath((string)$file);
                        if ($isDelete) {
                            $mediaWriteDirectory->delete($relativeFile);
                        } else {
                            $relativeBackupFile = $mediaWriteDirectory->getRelativePath(str_replace($imageDir, $backupDir, (string)$file));
                            $mediaWriteDirectory->renameFile($relativeFile, $relativeBackupFile);
                        }
                    } catch (\Throwable $e) {
                        $output->writeln('<error>Error on removing ' . $file . ': ' . $e->getMessage() . '</error>');
                        $countErrors++;
                    }
                } else {
                    $output->writeln('## REMOVING: ' . $filePath . ' ## -- DRY RUN');
                }
            }
        }
        $output->writeln("Found " . number_format($filesize / 1024 / 1024, 2) . " MB unused images in " . $countFiles . " files");
        if (!$isDelete && !$isDryRun) {
            $output->writeln("Files were moved to the following backup location: " . $backupDir);
        }
        if ($countErrors > 0) {
            $output->writeln('<error>' . $countErrors . ' files could not be removed, see errors above.</error>');
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    private function getDbValues()
    {
        $eavAttributeTable = $this->resource->getConnection()->getTableName('eav_attribute');
        $eavEntityTypeTable = $this->resource->getConnection()->getTableName('eav_entity_type');
        $coreRead = $this->resource->getConnection('core_read');
        $entityTypeId = $coreRead->fetchOne('SELECT entity_type_id FROM ' . $eavEntityTypeTable . ' WHERE entity_type_code = "catalog_category"');
        $attributeId = $coreRead->fetchOne('SELECT attribute_id FROM ' . $eavAttributeTable . ' WHERE entity_type_id = "' . $entityTypeId . '" AND attribute_code = "image"');
        $categoryVarcharTable = $this->resource->getConnection()->getTableName('catalog_category_entity_varchar');
        $values = $coreRead->fetchCol('SELECT value FROM ' . $categoryVarcharTable . ' WHERE attribute_id = ' . $attributeId . ' AND value IS NOT NULL');

        // newer Magento versions store the path relative to the base url, e.g. /media/catalog/category/image.jpg
        return array_map(
            static fn($value) => preg_replace('#^/?(pub/)?media/catalog/category/#', '', (string)$value),
            $values
        );
    }

}
