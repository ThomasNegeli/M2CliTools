<?php

namespace Tnegeli\M2CliTools\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;


/**
 * Class CleanupUnusedCategoryMedia
 *
 * Cleanup unused category media files
 */
class CleanupUnusedCategoryMedia extends Command
{

    private $resource;
    private $filesystem;

    public function __construct (
        Filesystem $filesystem,
        ResourceConnection $resource
    )
    {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        parent::__construct();
    }

    protected function configure ()
    {
        $this->setName( 'tnegeli:cleanup-unused-category-media' )
            ->setDescription( 'Cleans up unused category media from the filesystem.' )
            ->addOption( 'dry-run' )
            ->addOption( 'delete' );

        $help = "Find and backup or remove product media files, which are on your filesystem but are not used by any product.
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
            $output->writeln( 'WARNING: this will delete unused media files from Filesystem. If you want to backup unused files, remove --delete.' );
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
        $imageDir = rtrim( $mediaDirectory->getAbsolutePath(), "/" ) . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'category';
        $backupDir = $imageDir . DIRECTORY_SEPARATOR . 'unused_files_backup';
        $directoryIterator = new \RecursiveDirectoryIterator( $imageDir, \FilesystemIterator::SKIP_DOTS );
        foreach (new \RecursiveIteratorIterator( $directoryIterator ) as $file) {

            if (
                strpos( $file, "/.DS_Store" ) !== false ||
                strpos( $file, "/cache" ) !== false ||
                strpos( $file, "/default" ) !== false ||
                strpos( $file, "/placeholder" ) !== false ||
                strpos( $file, "/watermark" ) !== false ||
                strpos( $file, "/unused_files_backup" ) !== false ||
                is_dir( $file )
            ) {
                continue;
            }

            $filePath = ltrim( str_replace( $imageDir, "", $file ), "/" );
            if (empty( $filePath )) continue;
            echo "Checking file for usage: " . $filePath . PHP_EOL;
            if (!in_array( $filePath, $values )) {
                $filesize += filesize( $file );
                $countFiles++;
                if (!$isDryRun) {
                    $table[] = array( '## REMOVING: ' . $filePath . ' ##' );
                    if ($isDelete) {
                        unlink( $file );
                    } else {
                        $newFile = str_replace( $imageDir, $backupDir, $file );
                        if (!file_exists( dirname( $newFile ) )) {
                            mkdir( dirname( $newFile ), 0777, true );
                        }
                        rename( $file, $newFile ) or die( 'Error on file backup from ' . $file . ' to ' . $newFile );
                    }
                } else {
                    $table[] = array( '## REMOVING: ' . $filePath . ' ## -- DRY RUN' );
                }
            }
        }

        $headers = array();
        $headers[] = 'filepath';
        $symTable = new Table($output);
        $symTable->setHeaders( $headers );
        $symTable->setRows( $table );
        $symTable->render();
        $output->writeln( "Found " . number_format( $filesize / 1024 / 1024, '2' ) . " MB unused images in " . $countFiles . " files" );
        if (!$isDelete && !$isDryRun) {
            $output->writeln( "Files were moved to the following backup location: " . $backupDir );
        }

        return Cli::RETURN_SUCCESS;
    }

    private function getDbValues ()
    {
        $eavAttributeTable = $this->resource->getConnection()->getTableName( 'eav_attribute' );
        $eavEntityTypeTable = $this->resource->getConnection()->getTableName( 'eav_entity_type' );
        $coreRead = $this->resource->getConnection( 'core_read' );
        $entityTypeId = $coreRead->fetchOne( 'SELECT entity_type_id FROM ' . $eavEntityTypeTable . ' WHERE entity_type_code = "catalog_category"' );
        $attributeId = $coreRead->fetchOne( 'SELECT attribute_id FROM ' . $eavAttributeTable . ' WHERE entity_type_id = "' . $entityTypeId . '" AND attribute_code = "image"' );
        $values = $coreRead->fetchCol( 'SELECT value FROM catalog_category_entity_varchar WHERE attribute_id = ' . $attributeId . ' AND value IS NOT NULL' );
        return $values;
    }

}
