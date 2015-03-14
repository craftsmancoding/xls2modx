<?php namespace Xls2modx\Console\Command;
/**
 *
 */
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
//use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;

class MapImportCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('map:import')
            ->setDescription('Parse the indicated Excel file and generate a sample import.yml file that you can modify to define the mappings from this file\'s columns to MODX fields.')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to Excel file.')
            ->addArgument('destination', InputArgument::OPTIONAL, 'Destination file.', 'import.yml')
            ->addOption(
                'overwrite',
                'o',
                InputOption::VALUE_NONE,
                "Overwrite existing file?"
            )
            //    ->setHelp(file_get_contents(dirname(dirname(dirname(dirname(__FILE__)))) . '/docs/export.txt'))
        ;

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //$pkg_root_dir = $input->getArgument('pkg_root_dir');
        $source = $input->getArgument('source');
        $destination = $input->getArgument('destination');
        $overwrite = $input->getOption('overwrite');
        if (!file_exists($source))
        {
            $output->writeln('<error>File does not exist: '. $source.'</error>');
            exit;
            //throw new \Exception('File does not exist: '. $source);
        }
        if (file_exists($destination) && !$overwrite)
        {
            $output->writeln('<error>Destination file exists.  Will not overwrite unless forced (--overwrite)</error>');
            exit;
        }

        $output->writeln('Mapping file '.$source. ' ---> '.$destination);


        $objPHPExcel = \PHPExcel_IOFactory::load($source);

        $objWorksheet = $objPHPExcel->getActiveSheet();

        foreach ($objWorksheet->getRowIterator() as $col) {
            //if ($col->getRowIndex() <= $skip_rows) continue;
            $cellIterator = $col->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $col = array();       // these are columns! Oops.
            foreach ($cellIterator as $cell) {
                $col[] = $cell->getValue();
            }
            break;
        }

        $map = array();
        foreach ($col as $c)
        {
            $c = trim($c);
            if (!empty($c))
            {
                $map[$c] = '';
            }
        }

        $modx = get_modx();
        $modx->initialize('mgr');

        $page_cols = $modx->getFields('modResource');

        // Avoid stupidity
        unset($page_cols['id']);
        unset($page_cols['alias']);
        unset($page_cols['uri']);


        $hardcoded = array();
        foreach ($page_cols as $k => $v)
        {
            $hardcoded[$k] = '';
        }

        $dumper = new Dumper();

        $out = "# Mappings use a colon followed by a space (: ) to mark each key/value pair\n";
        $out = "# Format is {XLS-Column-Name} : {MODX-Field-Name}\n";
        $out .= "# There is a list of valid MODX field names in the 'Hardcoded-Values' section.\n";
        $out .= "# Any column without a mapping will be ignored and not included in the import.\n";
        $out .= "# If one XLS column needs to map to 2 or more MODX columns, use square-brackets to define an array.\n";
        $out .= $dumper->dump(array('xls2modx'=> $map), 2);
        $out .= "\n";
        $out .= "# Optionally define any Template Variables (TVs) here -- just uncomment the list to get started.\n";
        $out .= "# Use a hyphen and specify the unique TV name.\n";
        $out .= "# Once you have defined TVs here, you can use them in your column mappings.\n";
        $out .= "#TVs:\n";
        $out .= "#    - my_tv\n";
        $out .= "#    - other_tv\n";
        $out .= "\n";
        $out .= "# Configuration Settings:\n";
        $out .= "Config:\n";
        $out .= "    identifier: pagetitle  # columns(s) with unique values used to check if a row has already been imported.\n";
        $out .= "    update: true # if true, matching rows in the XLS will be updated in MODX on successive imports.\n";
        $out .= "\n";
        $out .= "# Hard-code values for any column here, e.g. if you want all imported records\n";
        $out .= "# to be children of the same parent or use the same template.\n";
        $out .= "# You can hard-code valid TVs too.\n";
        $out .= "# Blank values will take on the default values.\n";
        $out .= $dumper->dump(array('Hardcoded-Values' => $hardcoded), 2);

        //$out .= $dumper->dump(array('TVs' => array()), 2);
        file_put_contents($destination, $out);

        $output->writeln('<fg=green>Success!</fg=green>');
        $output->writeln('Edit the '.$destination.' to define the mapping from your XLS columns to the MODX fields.');
    }
}
/*EOF*/