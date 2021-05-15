<?php
namespace Variux\UpdateSKU\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBarFactory;
use Magento\Framework\App\Filesystem\DirectoryList;


/**
 * Class SomeCommand
 */
class UpdatedSKU extends Command
{
    const FILE_NAME = 'filename';
    const IMPORT_DIR = "import/";
    const CSV_DELIMITER = ",";
    const MESS_MISSING_FILE_NAME = "--filename is required.";
    const MESS_NOT_EXIST = "File does not exit.";
    const MESS_ERROR = "Some products were not updated, please check log file.";


    protected $_mediaPath = '';
    protected $_filesystem;
    protected $_fileDriver;
    protected $_csvParser;
    protected $_input;
    protected $_output;
    protected $_productRepository;
    protected $_productFactory;
    private $state;
    private $progressBarFactory;
    protected $_logger;

    public function __construct(
        ProgressBarFactory $progressBarFactory,
        \Magento\Framework\App\State $state,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Catalog\Model\Product $productRepository,
        \Magento\Framework\Filesystem\Driver\File $fileDriver,
        \Magento\Framework\File\Csv $csvParser,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Variux\UpdateSKU\Logger\Logger $logger
    )
    {
        $this->progressBarFactory = $progressBarFactory;
        $this->state = $state;
        $this->_filesystem = $filesystem;
        $this->_fileDriver = $fileDriver;
        $this->_csvParser = $csvParser;
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
            self::FILE_NAME,
            null,
            InputOption::VALUE_REQUIRED,
            'Name'
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


        $fileName = $input->getOption(self::FILE_NAME);
        if(!$fileName){
            $this->_output->writeln(self::MESS_MISSING_FILE_NAME);
            return;
        }

        $data = $this->getFileData($fileName);
        $this->updateSKU($data);
    }

    public function updateSKU($data) {
        $logMess = [];
        $ex = FALSE;
        $progressBar = $this->progressBarFactory->create(
            [
                'output' => $this->_output,
                'max' => count($data),
            ]
        );
        $progressBar->setFormat(
            '%current%/%max% [%bar%] %percent:3s%% %elapsed% %memory:6s%'
        );
        $this->_output->writeln('<info>Process starts.</info>');
        $progressBar->start();
        $success = 0;
        $failed = 0;
        foreach($data as $row){
            $progressBar->advance();
            $oldSku  = (isset($row[0])) ? $row[0] : "";
            $newSku  = (isset($row[1])) ? $row[1] : "";
            if($oldSku && $newSku) {
                if($productId = $this->_productRepository->getIdBySku($oldSku)){
                    try {
                        $product = $this->_productFactory->create()->load($productId);
                        $product->setSku($newSku);
                        $product->save();
                        $success++;
                        $mess = "\"{$oldSku}\" has been replaced by \"{$newSku}\"";
                        $this->_logger->info($mess);
                    }
                    catch (\Exception $e) {
                        $ex = TRUE;
                        $failed++;
                        $this->_logger->info("\"{$oldSku}\" \"{$e->getMessage()}\"");
                    }
                }else{
                    $ex = TRUE;
                    $failed++;
                    $mess = "\"{$oldSku}\" not found.";
                    $this->_logger->info($mess);
                }
            }
        }
        $progressBar->finish();
        $this->_output->write(PHP_EOL);
        $this->_output->writeln("Success: {$success}, Failed: {$failed}");
        $this->_output->writeln('<info>Process finished.</info>');
    }

    public function getFileData($fileName): array
    {
        $data = [];
        $mediaPath = $this->getMediaPath();
        $file = $mediaPath . self::IMPORT_DIR . $fileName;
        if ($this->_fileDriver->isExists($file)) {
            $this->_csvParser->setDelimiter(',');
            $data = $this->_csvParser->getData($file);
        }else{
            $this->_logger->info(self::MESS_NOT_EXIST);
            $this->_output->writeln(self::MESS_NOT_EXIST);
        }
        return $data;
    }

    public function getMediaPath(){
        return $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
    }

}