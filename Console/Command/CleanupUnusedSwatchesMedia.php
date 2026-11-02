<?php

namespace Tnegeli\M2CliTools\Console\Command;

use FilesystemIterator;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem;
use Magento\Swatches\Helper\Media as SwatchesMediaHelper;
use Magento\Swatches\Model\Swatch as SwatchesModel;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;


/**
 * Class CleanupUnusedSwatchesMedia
 *
 * Cleanup unused swatches media files
 */
class CleanupUnusedSwatchesMedia extends Command
{

    private ResourceConnection $resource;
    private Filesystem $filesystem;
    private SwatchesMediaHelper $swatchesMediaHelper;
    private QuestionHelper $questionHelper;
    private State $appState;

    public function __construct(
        Filesystem          $filesystem,
        ResourceConnection  $resource,
        SwatchesMediaHelper $mediaHelper,
        QuestionHelper      $questionHelper,
        State               $appState,
    )
    {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        $this->swatchesMediaHelper = $mediaHelper;
        $this->questionHelper = $questionHelper;
        $this->appState = $appState;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tnegeli:cleanup-unused-swatches-media')
            ->setDescription('Cleans up unused swatches media from the filesystem.')
            ->addOption('dry-run')
            ->addOption('delete');

        $help = "Find and backup or remove swatches media files, which are on your filesystem but are not used by any product.
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
            $output->writeln('WARNING: this will delete unused media files from filesystem. If you want to backup unused files, remove --delete.');
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
        $imageDir = rtrim($mediaDirectory->getAbsolutePath(), "/") . DIRECTORY_SEPARATOR . SwatchesMediaHelper::SWATCH_MEDIA_PATH;
        if (!is_dir($imageDir)) {
            $output->writeln("The media directory " . $imageDir . " does not exist. Nothing to clean up.");
            return Cli::RETURN_SUCCESS;
        }

        $backupDir = $imageDir . DIRECTORY_SEPARATOR . 'unused_files_backup';

        // the swatch variants are generated on upload in the admin area, the sizes are read from view.xml
        $swatchImageFolder = $this->getFolderNameSize(SwatchesModel::SWATCH_IMAGE_NAME);
        $swatchThumbnailFolder = $this->getFolderNameSize(SwatchesModel::SWATCH_THUMBNAIL_NAME);
        $directoryIterator = new RecursiveDirectoryIterator($imageDir, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($directoryIterator) as $file) {
            if (
                strpos($file, "/.DS_Store") !== false ||
                strpos($file, "/default") !== false ||
                strpos($file, "/unused_files_backup") !== false ||
                strpos($file, "/" . SwatchesModel::SWATCH_IMAGE_NAME) ||
                strpos($file, "/" . SwatchesModel::SWATCH_THUMBNAIL_NAME) ||
                is_dir($file)
            ) {
                continue;
            }

            $filePath = str_replace($imageDir, "", $file);
            if (empty($filePath)) continue;
            $output->writeln("Checking file for usage: " . $filePath, OutputInterface::VERBOSITY_VERY_VERBOSE);
            if (!isset($values[$filePath])) {
                $filesize += filesize($file);
                $countFiles++;

                // we have to check the generated variants of the uploaded file as well
                $swatchImageFile = $imageDir . DIRECTORY_SEPARATOR . SwatchesModel::SWATCH_IMAGE_NAME . '/' . $swatchImageFolder . $filePath;
                $swatchImageFilePath = str_replace($imageDir, "", $swatchImageFile);
                $output->writeln("Checking swatch image file for usage: " . $swatchImageFilePath, OutputInterface::VERBOSITY_VERY_VERBOSE);
                $swatchThumbnailFile = $imageDir . DIRECTORY_SEPARATOR . SwatchesModel::SWATCH_THUMBNAIL_NAME . '/' . $swatchThumbnailFolder . $filePath;
                $swatchThumbnailFilePath = str_replace($imageDir, "", $swatchThumbnailFile);
                $output->writeln("Checking swatch thumbnail file for usage: " . $swatchThumbnailFilePath, OutputInterface::VERBOSITY_VERY_VERBOSE);

                if (!$isDryRun) {
                    $output->writeln('## REMOVING: ' . $filePath . ' ##');

                    if (is_file($swatchImageFile)) {
                        $filesize += filesize($swatchImageFile);
                        $countFiles++;
                        $output->writeln('## REMOVING: ' . $swatchImageFilePath . ' ##');
                    }

                    if (is_file($swatchThumbnailFile)) {
                        $filesize += filesize($swatchThumbnailFile);
                        $countFiles++;
                        $output->writeln('## REMOVING: ' . $swatchThumbnailFilePath . ' ##');
                    }

                    $filesToRemove = [$file];
                    if (is_file($swatchImageFile)) {
                        $filesToRemove[] = $swatchImageFile;
                    }
                    if (is_file($swatchThumbnailFile)) {
                        $filesToRemove[] = $swatchThumbnailFile;
                    }
                    foreach ($filesToRemove as $fileToRemove) {
                        try {
                            $relativeFile = $mediaWriteDirectory->getRelativePath((string)$fileToRemove);
                            if ($isDelete) {
                                $mediaWriteDirectory->delete($relativeFile);
                            } else {
                                $relativeBackupFile = $mediaWriteDirectory->getRelativePath(str_replace($imageDir, $backupDir, (string)$fileToRemove));
                                $mediaWriteDirectory->renameFile($relativeFile, $relativeBackupFile);
                            }
                        } catch (\Throwable $e) {
                            $output->writeln('<error>Error on removing ' . $fileToRemove . ': ' . $e->getMessage() . '</error>');
                            $countErrors++;
                        }
                    }

                } else {
                    $output->writeln('## REMOVING: ' . $filePath . ' ## -- DRY RUN');
                    if (is_file($swatchImageFile)) {
                        $output->writeln('## REMOVING: ' . $swatchImageFilePath . ' ## -- DRY RUN');
                    }
                    if (is_file($swatchThumbnailFile)) {
                        $output->writeln('## REMOVING: ' . $swatchThumbnailFilePath . ' ## -- DRY RUN');
                    }
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

    private function getFolderNameSize(string $swatchType): string
    {
        return $this->appState->emulateAreaCode(
            Area::AREA_ADMINHTML,
            [$this->swatchesMediaHelper, 'getFolderNameSize'],
            [$swatchType]
        );
    }

    private function getDbValues()
    {
        $mediaGallery = $this->resource->getConnection()->getTableName('eav_attribute_option_swatch');
        $coreRead = $this->resource->getConnection('core_read');
        $values = $coreRead->fetchCol('SELECT value FROM ' . $mediaGallery . ' WHERE value IS NOT NULL AND value NOT LIKE "#%"');
        return $values;
    }

}
