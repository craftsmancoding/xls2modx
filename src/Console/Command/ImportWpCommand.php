<?php namespace Xls2modx\Console\Command;
/**
 *
 */


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

class ImportWpCommand extends Command
{
    public $modx;

    public $data;
    public $valid_mappings = array(
        'post_types',
        'default_templates',
        'templates',
        'authors',
        'shortcodes',
        'fields'
    );

    protected function configure()
    {
        $this
            ->setName('import:wp')
            ->setDescription('Parse a WordPress XML export and import it into MODX as page resources, users, assets, and taxonomies')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to XML file.')
            ->addArgument('mapfile', InputArgument::OPTIONAL, 'Yaml file containing column mappings (generated via the map:importwp command)')
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
        }
        $map = array(
            'post_types'=>array(),
            'default_templates'=>array(),
            'templates'=>array(),
            'authors'=>array(),
            'fields'=>array(),
            'shortcodes' => array(), //  --> Snippets
            // TODO: Hardcoded-Values
        );

        if (!file_exists($mapfile))
        {
            $output->writeln('<error>File does not exist: '. $mapfile.'</error>');
            exit;
        }

        $yaml = new Parser();
        $map = $yaml->parse(file_get_contents($mapfile));
        $output->writeln('Importing file '.$source. ' using mappings contained in '.$mapfile);



        $WP = new \Xls2modx\Parser\WordPressXml();
        $this->data = $WP->parse($source);

        // Verify mappings
        $this->verifyMappings($map, $output);
        $this->verifyXML($output);

        // Check mapped authors: create any users if necessary (LOG THEM!)
        // Check mapped fields : create any TVs if necessary (LOG THEM!)
        // Import/Create any taxonomies/terms

        // Iterate over posts
        // build lookup table for URLs
        // check for custom fields containing multiple rows of data
        // add any page/term associations
        // Import assets

        // Replace hard-coded URLs with [[~123]] according to the lookup table
        // Replace [shortcodes] with Snippets
        // Replace any links to assets


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

        // Generate alias
        if (!$alias = $Resource->get('alias'))
        {
            $alias = $Resource->cleanAlias($Resource->get('pagetitle'));
            //$friendly_urls = $this->modx->getOption('friendly_urls');
            $aliasPath = $Resource->getAliasPath($alias, $Resource->toArray());
            $Resource->set('alias', $alias);
            $Resource->set('uri', $aliasPath);
//            print $alias ."\n";
//            print $aliasPath ."\n";
//            exit;
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

        if (isset($map['']))
        {

        }
    }

    public function verifyXML($output)
    {

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