<?php

namespace Tnegeli\M2CliTools\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Swatches\Helper\Media as SwatchesMediaHelper;
use Magento\Swatches\Model\Swatch as SwatchesModel;


/**
 * Class CleanupUnusedSwatchesMedia
 *
 * Cleanup unused swatches media files
 */
class CleanupUnusedSwatchesMedia extends Command
{

    private $resource;
    private $filesystem;
    private $swatchesMediaHelper;
    private $state;

    public function __construct (
        Filesystem $filesystem,
        ResourceConnection $resource,
        SwatchesMediaHelper $mediaHelper,
        \Magento\Framework\App\State $state
    )
    {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        $this->swatchesMediaHelper = $mediaHelper;
        parent::__construct();
    }

    protected function configure ()
    {
        $this->setName( 'tnegeli:cleanup-unused-swatches-media' )
            ->setDescription( 'Cleans up unused swatches media from the filesystem.' )
            ->addOption( 'dry-run' )
            ->addOption( 'delete' );

        $help = "Find and backup or remove swatches media files, which are on your filesystem but are not used by any product.
Add the --dry-run option to just get the files that are unused.
Add the --delete option to delete the files, instead of doing a backup";

        $this->setHelp( $help );
    }

    protected function execute ( InputInterface $input, OutputInterface $output )
    {
        $isDryRun = $input->getOption( 'dry-run' );
        if (!$isDryRun) {
            $output->writeln( 'WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.' );
            $question = new ConfirmationQuestion( 'Are you sure you want to continue? [No] ', false );
            $this->questionHelper = $this->getHelper( 'question' );
            if (!$this->questionHelper->ask( $input, $output, $question )) {
                return Cli::RETURN_SUCCESS;
            }
        }

        $isDelete = $input->getOption( 'delete' );
        if ($isDelete) {
            $output->writeln( 'WARNING: this will delete unused media files from filesystem. If you want to backup unused files, remove --delete.' );
            $question = new ConfirmationQuestion( 'Are you sure you want to continue? [No] ', false );
            $this->questionHelper = $this->getHelper( 'question' );
            if (!$this->questionHelper->ask( $input, $output, $question )) {
                return Cli::RETURN_SUCCESS;
            }
        }

        $values = $this->getDbValues();
        echo "DB Values: " . print_r( $values, true ) . PHP_EOL;

        $filesize = 0;
        $countFiles = 0;
        $table = array();
        $mediaDirectory = $this->filesystem->getDirectoryRead( DirectoryList::MEDIA );
        $imageDir = rtrim( $mediaDirectory->getAbsolutePath(), "/" ) . DIRECTORY_SEPARATOR . SwatchesMediaHelper::SWATCH_MEDIA_PATH;
        $backupDir = $imageDir . DIRECTORY_SEPARATOR . 'unused_files_backup';
        $directoryIterator = new \RecursiveDirectoryIterator( $imageDir, \FilesystemIterator::SKIP_DOTS );
        foreach (new \RecursiveIteratorIterator( $directoryIterator ) as $file) {
            if (
                strpos( $file, "/.DS_Store" ) !== false ||
                strpos( $file, "/default" ) !== false ||
                strpos( $file, "/unused_files_backup" ) !== false ||
                strpos( $file, "/" . SwatchesModel::SWATCH_IMAGE_NAME ) ||
                strpos( $file, "/" . SwatchesModel::SWATCH_THUMBNAIL_NAME ) ||
                is_dir( $file )
            ) {
                continue;
            }

            $filePath = str_replace( $imageDir, "", $file );
            if (empty( $filePath )) continue;
            echo "Checking file for usage: " . $filePath . PHP_EOL;
            if (!in_array( $filePath, $values )) {
                $filesize += filesize( $file );
                $countFiles++;

                // we have to check the generated variants of the uploaded file as well
                $swatchImageFile = $imageDir . DIRECTORY_SEPARATOR . SwatchesModel::SWATCH_IMAGE_NAME . '/' . $this->swatchesMediaHelper->getFolderNameSize( SwatchesModel::SWATCH_IMAGE_NAME ) . $filePath;
                $swatchImageFilePath = str_replace( $imageDir, "", $swatchImageFile );
                echo "Checking swatch image file for usage: " . $swatchImageFilePath . PHP_EOL;
                $swatchThumbnailFile = $imageDir . DIRECTORY_SEPARATOR . SwatchesModel::SWATCH_THUMBNAIL_NAME . '/' . $this->swatchesMediaHelper->getFolderNameSize( SwatchesModel::SWATCH_THUMBNAIL_NAME ) . $filePath;
                $swatchThumbnailFilePath = str_replace( $imageDir, "", $swatchThumbnailFile );
                echo "Checking swatch thumbnail file for usage: " . $swatchThumbnailFilePath . PHP_EOL;

                if (!$isDryRun) {
                    $table[] = array( '## REMOVING: ' . $filePath . ' ##' );

                    if (is_file( $swatchImageFile )) {
                        $filesize += filesize( $swatchImageFile );
                        $countFiles++;
                        $table[] = array( '## REMOVING: ' . $swatchThumbnailFilePath . ' ##' );
                    }

                    if (is_file( $swatchThumbnailFile )) {
                        $filesize += filesize( $swatchThumbnailFile );
                        $countFiles++;
                        $table[] = array( '## REMOVING: ' . $swatchThumbnailFilePath . ' ##' );
                    }

                    if ($isDelete) {
                        unlink( $file );

                        if (is_file( $swatchImageFile )) {
                            unlink( $swatchImageFile );
                        }
                        if (is_file( $swatchThumbnailFile )) {
                            unlink( $swatchThumbnailFile );
                        }

                    } else {
                        $this->backupFile( $imageDir, $backupDir, $file );
                        if (is_file( $swatchImageFile )) {
                            $this->backupFile( $imageDir, $backupDir, $swatchImageFile );
                        }
                        if (is_file( $swatchThumbnailFile )) {
                            $this->backupFile( $imageDir, $backupDir, $swatchThumbnailFile );
                        }
                    }

                } else {
                    $table[] = array( '## REMOVING: ' . $filePath . ' ## -- DRY RUN' );
                    if (is_file( $swatchImageFile )) {
                        $table[] = array( '## REMOVING: ' . $swatchImageFilePath . ' ## -- DRY RUN' );
                    }
                    if (is_file( $swatchThumbnailFile )) {
                        $table[] = array( '## REMOVING: ' . $swatchThumbnailFilePath . ' ## -- DRY RUN' );
                    }
                }
            }
        }

        $headers = array();
        $headers[] = 'filepath';
        $this->getHelper( 'table' )
            ->setHeaders( $headers )
            ->setRows( $table )->render( $output );
        $output->writeln( "Found " . number_format( $filesize / 1024 / 1024, '2' ) . " MB unused images in " . $countFiles . " files" );
        if (!$isDelete && !$isDryRun) {
            $output->writeln( "Files were moved to the following backup location: " . $backupDir );
        }

        return Cli::RETURN_SUCCESS;
    }

    private function backupFile ( $imageDir, $backupDir, $file )
    {
        $newFile = str_replace( $imageDir, $backupDir, $file );
        if (!file_exists( dirname( $newFile ) )) {
            mkdir( dirname( $newFile ), 0777, true );
        }
        rename( $file, $newFile ) or die( 'Error on file backup from ' . $file . ' to ' . $newFile );
    }

    private function getDbValues ()
    {
        $mediaGallery = $this->resource->getConnection()->getTableName( 'eav_attribute_option_swatch' );
        $coreRead = $this->resource->getConnection( 'core_read' );
        $values = $coreRead->fetchCol( 'SELECT value FROM ' . $mediaGallery . ' WHERE value IS NOT NULL AND value NOT LIKE "#%"' );
        return $values;
    }

}
