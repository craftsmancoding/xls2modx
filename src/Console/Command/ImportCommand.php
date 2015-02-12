<?php
/**
 *
 */
namespace Xls2modx\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

class ImportCommand extends Command
{
    public $modx;
    public $resource_cols;
    public $tvname_to_id = array();

    protected function configure()
    {
        $this
            ->setName('import')
            ->setDescription('Parse the indicated .xls or .xlsx file and import it into MODX as page resources')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to Excel file.')
            ->addArgument('mapfile', InputArgument::OPTIONAL, 'Yaml file containing column mappings (generated via the map command)')
//            ->addOption(
//                'skip_rows',
//                's',
//                InputOption::VALUE_OPTIONAL,
//                "How many rows should be skipped (including the header row) before the real data starts?",
//                1
//            )
            //    ->setHelp(file_get_contents(dirname(dirname(dirname(dirname(__FILE__)))) . '/docs/export.txt'))
        ;

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->modx = get_modx();
        $this->modx->initialize('mgr');
        $this->resource_cols = $this->modx->getFields('modResource');

        $source = $input->getArgument('source');
        $mapfile = $input->getArgument('mapfile');

        if (!file_exists($source))
        {
            $output->writeln('<error>File does not exist: '. $source.'</error>');
            exit;
            //throw new \Exception('File does not exist: '. $source);
        }
        $map = array(
            'xls2modx'=>array(),
            'TVs'=>array(),
            'Config'=>array(),
            'Hardcoded-Values'=>array()
        );
        if ($mapfile)
        {
            if (!file_exists($mapfile))
            {
                $output->writeln('<error>File does not exist: '. $mapfile.'</error>');
                exit;
                //throw new \Exception('File does not exist: '. $source);
            }

            $yaml = new Parser();
            $map = $yaml->parse(file_get_contents($mapfile));
            $output->writeln('Importing file '.$source. ' using mappings contained in '.$mapfile);
        }
        else
        {
            $output->writeln('Importing file '.$source. ' with no mappings.');
        }

//print_r($map); exit;
        // Create any TVs?
        if (isset($map['TVs']))
        {
            $this->createTVs($map['TVs'], $output);
        }

        // Verify mappings
        $this->verifyMappings($map, $output);

        // Map TV names to IDs
        $this->mapTvNames2id();

        $objPHPExcel = \PHPExcel_IOFactory::load($source);

        $objWorksheet = $objPHPExcel->getActiveSheet();

        $headers = array();
        foreach ($objWorksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            if ($row->getRowIndex() <= 1)
            {
                // Get Headers
                foreach ($cellIterator as $cell) {
                    $headers[] = $cell->getValue();
                }
                continue;
            }

            $vals = array();
            foreach ($cellIterator as $cell) {
                $vals[] = $cell->getValue();
            }
            // We gotta have something in the first column
            if (empty($vals[0])) {
                continue;
            }
            $this->createModxResource($vals,$headers,$map, $output);
        }
    }

    public function createModxResource($vals,$headers,$fullmap, $output)
    {
        //$R = $this->modx->newObject('modResource');
        $data = array();
        $tvdata = array();
        $map = $fullmap['xls2modx'];
        foreach ($vals as $i => $v)
        {
            //print_r($headers); exit;
            //print $v; exit;
            if (isset($map[ $headers[$i] ]))
            {
                if (empty($map[ $headers[$i] ])) continue;

                if (!is_array($map[ $headers[$i] ]))
                {
                    $columns = array($map[ $headers[$i] ]);
                }
                else
                {
                    $columns = $map[ $headers[$i] ];
                }

                foreach ($columns as $col)
                {
                    // Regular resource col
                    if (array_key_exists($col, $this->resource_cols))
                    {
                        $data[ $col ] = $v; // TODO: do some crunching before simple handoff
                    }
                    // TV
                    else
                    {
                        $tvdata[ $col ] = $v;
                    }
                }
            }
        }
        foreach($fullmap['Hardcoded-Values'] as $k => $v)
        {
            if (empty($v)) continue;
            if (array_key_exists($col, $this->resource_cols))
            {
                $data[ $k ] = $v;
            }
            // TV
            else
            {
                $tvdata[ $k ] = $v;
            }
        }
//        print_r($data);
//        print_r($tvdata);
//        exit;
        // Record Exists?
        $idcol = $fullmap['Config']['identifier'];
        if (!$Resource = $this->modx->getObject('modResource', array($idcol => $data[$idcol])))
        {
            $Resource = $this->modx->newObject('modResource');
        }
        foreach ($data as $k => $v)
        {
            // TODO : check for overwrite
            $Resource->set($k, $v);
        }
        if(!$Resource->save())
        {
            $output->writeln('<error>There was a problem creating Resource</error>');
            return;
        }
        $output->writeln('<info>Created resource '.$data[$idcol].'</info>');

        foreach($tvdata as $name => $val)
        {
            if (!isset($this->tvname_to_id[$name]))
            {
                $output->writeln('<error>TV not defined: '.$name.'</error>');
                continue;
            }
            $tmplvarid = $this->tvname_to_id[$name];

            if (!$TVR = $this->modx->getObject('modTemplateVarResource', array('contentid'=>$Resource->get('id'), 'tmplvarid'=>$tmplvarid)))
            {
                $TVR = $this->modx->newObject('modTemplateVarResource');
                $TVR->set('contentid', $Resource->get('id'));
                $TVR->set('tmplvarid', $tmplvarid);
            }
            $TVR->set('value', $val);

            if (!$TVR->save())
            {
                $output->writeln('<error>Error saving TV: '.$name.'</error>');
                continue;
            }
        }

//        print_r($data);
//        print_r($tvdata);

    }

    public function createTVs($list,$output)
    {
        foreach ($list as $l)
        {
            if ($TV = $this->modx->getObject('modTemplateVar', array('name'=>$l)))
            {
                $output->writeln('TV '.$l.' already exists. Skipping...');
                continue;
            }

            $TV = $this->modx->newObject('modTemplateVar');
            $TV->set('name', $l);

            if (!$TV->save())
            {
                $output->writeln('<error>There was a problem creating TV '.$l.'</error>');
            }
            else
            {
                $output->writeln('<info>TV '.$l.' created.</info>');
            }
        }
    }

    public function verifyMappings($map, $output)
    {
        if (empty($map))
        {
            return;
        }
        $valid_cols = $this->resource_cols;
        //print_r($valid_cols); exit;
        $mapped_cols = array();

        if ($TVs = $this->modx->getCollection('modTemplateVar'))
        {
            foreach ($TVs as $t)
            {
                $valid_cols[ $t->get('name') ] = true;
            }
        }

        if (isset($map['xls2modx']))
        {

            foreach($map['xls2modx'] as $xls => $modx)
            {
                if (empty($modx)) continue; // unmapped.
                if (is_array($modx))
                {

                    foreach($modx as $m)
                    {
                        if (!array_key_exists(trim($m),$valid_cols))
                        {
                            $output->writeln('<error>Invalid Column: '.$m.'! Mapped columns must be valid columns from modx_site_content or they must be defined as TVs.</error>');
                            exit;
                        }
                        $mapped_cols[] = trim($m);
                    }
                }
                else
                {
                    if (!array_key_exists(trim($modx),$valid_cols))
                    {
                        $output->writeln('<error>Invalid Column: '.$modx.'! Mapped columns must be valid columns from modx_site_content or they must be defined as TVs.</error>');
                        exit;
                    }
                    $mapped_cols[] = trim($modx);
                }
            }
        }

        if (isset($map['Config']))
        {
            if (isset($map['Config']['identifier']))
            {
                if (is_array($map['Config']['identifier']))
                {
                    // TODO: compound keys
                }
                if(!in_array($map['Config']['identifier'], $mapped_cols))
                {
                    $output->writeln('<error>Unmapped Column: '.$map['Config']['identifier'].'! Your identifying column must be mapped, otherwise we cannot check for duplicates.</error>');
                    exit;
                }
            }
        }

        $output->writeln('<fg=green>Mappings valid!</fg=green>');
        $output->writeln('Mapped columns: '.print_r($mapped_cols,true));
    }

    public function mapTvNames2id()
    {
        if ($TVs = $this->modx->getCollection('modTemplateVar'))
        {
            foreach ($TVs as $t)
            {
                $this->tvname_to_id[ $t->get('name') ] = $t->get('id');
            }
        }
    }
}
/*EOF*/