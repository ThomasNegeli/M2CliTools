<?php

namespace Tnegeli\M2CliTools\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class CleanupUnusedProductMedia
 *
 * Cleanup unused product media files
 */
class CleanupUnusedProductMedia extends Command
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
        $this->setName('tnegeli:cleanup-unused-product-media')
            ->setDescription('Cleans up unused product media from the filesystem.')
            ->addOption('dry-run')
            ->addOption('delete');

        $help = "Find and backup or remove product media files, which are on your filesystem but are not used by any product.
Add the --dry-run option to just get the files that are unused.
Add the --delete option to delete the files, instead of doing a backup";

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

        $isDelete = $input->getOption('delete');
        if ($isDelete) {
            $output->writeln('WARNING: this will delete unused media files from filesystem. If you want to backup unused files, remove --delete.');
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);
            $this->questionHelper = $this->getHelper('question');
            if (!$this->questionHelper->ask($input, $output, $question)) {
                return Cli::RETURN_SUCCESS;
            }
        }

        $values = $this->getDbValues();
        echo "DB Values: " . print_r($values, true) . PHP_EOL;

        $filesize = 0;
        $countFiles = 0;
        $table = [];
        $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $imageDir = rtrim($mediaDirectory->getAbsolutePath(), "/") . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'product';
        $backupDir = $imageDir . DIRECTORY_SEPARATOR . 'unused_files_backup';
        $directoryIterator = new \RecursiveDirectoryIterator($imageDir, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($directoryIterator) as $file) {
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

            $filePath = str_replace($imageDir, "", $file);
            if (empty($filePath)) {
                continue;
            }
            echo "Checking file for usage: " . $filePath . PHP_EOL;
            if (!in_array($filePath, $values)) {
                $filesize += filesize($file);
                $countFiles++;
                if (!$isDryRun) {
                    $table[] = [ '## REMOVING: ' . $filePath . ' ##' ];
                    if ($isDelete) {
                        unlink($file);
                    } else {
                        $newFile = str_replace($imageDir, $backupDir, $file);
                        if (!file_exists(dirname($newFile))) {
                            mkdir(dirname($newFile), 0777, true);
                        }
                        rename($file, $newFile) or die('Error on file backup from ' . $file . ' to ' . $newFile);
                    }
                } else {
                    $table[] = [ '## REMOVING: ' . $filePath . ' ## -- DRY RUN' ];
                }
            }
        }

        $headers = [];
        $headers[] = 'filepath';

        $newtable = new Table($output);
        $newtable->setHeaders($headers)
            ->setRows($table)
            ->render($output);

        $output->writeln("Found " . number_format($filesize / 1024 / 1024, '2') . " MB unused images in " . $countFiles . " files");
        if (!$isDelete && !$isDryRun) {
            $output->writeln("Files were moved to the following backup location: " . $backupDir);
        }

        return Cli::RETURN_SUCCESS;
    }

    private function getDbValues()
    {
        $mediaGallery = $this->resource->getConnection()->getTableName('catalog_product_entity_media_gallery');
        $coreRead = $this->resource->getConnection('core_read');
        $values = $coreRead->fetchCol('SELECT value FROM ' . $mediaGallery);
        return $values;
    }
}
