<?php

namespace Tnegeli\M2CliTools\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;


class CleanupUnusedMedia extends Command
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
        $this->setName( 'tnegeli:cleanup-unused-media' )
            ->setDescription( 'Cleans up unused media from the filesystem.' )
            ->addOption( 'dry-run' );
    }

    protected function execute ( InputInterface $input, OutputInterface $output )
    {
        $filesize = 0;
        $countFiles = 0;
        $isDryRun = $input->getOption( 'dry-run' );

        if (!$isDryRun) {
            $output->writeln( 'WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.' );
            $question = new ConfirmationQuestion( 'Are you sure you want to continue? [No] ', false );
            $this->questionHelper = $this->getHelper( 'question' );
            if (!$this->questionHelper->ask( $input, $output, $question )) {
                return;
            }
        }

        $table = array();
        $mediaDirectory = $this->filesystem->getDirectoryRead( DirectoryList::MEDIA );
        $imageDir = $mediaDirectory->getAbsolutePath() . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'product';
        $mediaGallery = $this->resource->getConnection()->getTableName( 'catalog_product_entity_media_gallery' );
        $coreRead = $this->resource->getConnection( 'core_read' );
        $directoryIterator = new \RecursiveDirectoryIterator( $imageDir );

        $values = $coreRead->fetchCol( 'SELECT value FROM ' . $mediaGallery );

        foreach (new \RecursiveIteratorIterator( $directoryIterator ) as $file) {

            if (strpos( $file, "/cache" ) !== false ||
                strpos( $file, "/default" ) !== false ||
                strpos( $file, "/placeholder" ) !== false ||
                strpos( $file, "/watermark" ) !== false ||
                is_dir( $file )
            ) {
                continue;
            }

            $filePath = str_replace( $imageDir, "", $file );
            if (empty( $filePath )) continue;

            if (array_search( $filePath, $values ) !== false) {
                $filesize += filesize( $file );
                $countFiles++;
                if (!$isDryRun) {
                    $table[] = array( '## REMOVING: ' . $filePath . ' ##' );
                    unlink( $file );
                } else {
                    $table[] = array( '## REMOVING: ' . $filePath . ' ## -- DRY RUN' );
                }
            }
        }

        $headers = array();
        $headers[] = 'filepath';
        $this->getHelper( 'table' )
            ->setHeaders( $headers )
            ->setRows( $table )->render( $output );
        $output->writeln( "Found " . number_format( $filesize / 1024 / 1024, '2' ) . " MB unused images in " . $countFiles . " files" );
    }

}