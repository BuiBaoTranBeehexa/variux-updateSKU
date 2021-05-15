<?php
namespace Variux\UpdateSKU\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Class SomeCommand
 */
class UpdatedSKU extends Command
{
    const FILENAME = 'FileName';
    const IMPORT_DIRECTORY = 'import/';

    protected $_mediaPath = '';
    protected $_filesystem;
    protected $_input;
    protected $_output;
    protected $_productRepository;
    protected $_productFactory;
    private $state;
    protected $_logger;

    public function __construct(
        \Magento\Framework\App\State $state,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Catalog\Model\Product $productRepository,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Variux\UpdateSKU\Logger\Logger $logger
    )
    {
        $this->state = $state;
        $this->_filesystem = $filesystem;
        $this->_mediaPath = $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        $this->_productRepository = $productRepository;
        $this->_productFactory = $productFactory;
        $this->_logger = $logger;

        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('sku:replace');
        $this->setDescription('Updating old SKU to new SKU form CSV file.');

        $this->addOption(
            self::FILENAME,
            null,
            InputOption::VALUE_REQUIRED,
            'FileName'
        );
 
        parent::configure();
    }

    /**
     * Execute the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return null|int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        $this->_input = $input;
        $this->_output = $output;

        $mediaPath = $this->_mediaPath . self::IMPORT_DIRECTORY;

        $FileName = $input->getOption(self::FILENAME);

        $filePath = $mediaPath . $FileName;

        if(file_exists($filePath)) {

            // $this->_output->writeln("Prepare Update SKU from {$FileName}. Please waiting a few minutes");

            $this->updateSKU($filePath);

        } else {
            $this->_output->writeln("<error>File " . $FileName . " does not exist. Please check the File again!</error>");
        }
    }

    public function updateSKU($filePath) {

        $mediaPath = $this->_mediaPath . self::IMPORT_DIRECTORY;

        if (($handle = fopen($filePath, "r"))) {
            $row = 1;

            $logMess = [];
            $ex = FALSE;
            while (($data = fgetcsv($handle, 0, ","))) {
                if ($row > 1) {
                    $oldSku = $data[0];
                    $newSku = $data[1];

                    $productId = $this->_productRepository->getIdBySku($oldSku);

                    if($productId){
                        try {
                            $product = $this->_productFactory->create()->load($productId);
                            $product->setSku($newSku);
                            $product->save();
                            $this->_output->writeln("\"{$oldSku}\" has been replaced by \"{$newSku}\"");
                            $logMess[] =  "\"{$oldSku}\" has been replaced by \"{$newSku}\"";
                        }
                        catch (\Exception $e) {
                            $ex = TRUE;
                            $logMess[] = "\"{$oldSku}\" \"{$e->getMessage()}\"";
                        }
                    }else{
                        $ex = TRUE;
                        $this->_output->writeln("\"{$oldSku}\" not found.");
                        $logMess[] = "\"{$oldSku}\" not found.";
                    }
                }
                $row++;
            }
            foreach($logMess as $mess){
                $this->_logger->info($mess);
            }
            if($ex){
                $this->_output->writeln("Some products were not updated, please check log file.");
            }
            
            fclose($handle);
        }
    }

}