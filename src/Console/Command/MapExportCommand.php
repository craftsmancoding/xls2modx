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

class MapExportCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('map:export')
            ->setDescription('Generate an export.yml file to be used by the export command: this maps current MODX fields and TVs to column names in the export file.')
            ->addArgument('destination', InputArgument::OPTIONAL, 'Destination file.', 'export.yml')
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
        $this->modx = get_modx();
        $this->modx->initialize('mgr');

        //$pkg_root_dir = $input->getArgument('pkg_root_dir');
        //$source = $input->getArgument('source');
        $destination = $input->getArgument('destination');
        $overwrite = $input->getOption('overwrite');

        if (file_exists($destination) && !$overwrite)
        {
            $output->writeln('<error>Destination file exists.  Will not overwrite unless forced (--overwrite)</error>');
            exit;
        }

        $output->writeln('Generating map file for MODX site ---> '.$destination);

        $this->resource_cols = $this->modx->getFields('modResource');

        $tvs = array();
        if ($TVs = $this->modx->getCollection('modTemplateVar'))
        {
            foreach ($TVs as $t)
            {
                $tvs[ $t->get('name') ] = true;
            }
        }

        $map = array();
        foreach ($this->resource_cols as $k => $v)
        {
            $map[$k] = $k;
        }
        foreach ($tvs as $k => $v)
        {
            $map[$k] = $k;
        }


        $dumper = new Dumper();

        $out = "# Mappings use a colon followed by a space (: ) to mark each key/value pair\n";
        $out .= "# Format is {MODX-Field-Name}: {XLS-Column-Name}\n";
        $out .= "# If you don't wish to export a field, delete that row from this config file,\n";
        $out .= "# comment it out out using a '#', or leave the XLS column name blank.\n";
        $out .= "# If a single MODX field should be written to multiple columns, use square brackets\n";
        $out .= "# to define an array, e.g. pagetitle: [columnone,columntwo].\n";
        $out .= $dumper->dump(array('modx2xls'=> $map), 2); // inverse of the xls2modx command
        $out .= "\n";
        $out .= "# If you want to add additional columns to your XLS export, you may hard-code\n";
        $out .= "# values here in the format {XLS-Column-Name}: {Hard-coded-value}.\n";
        $out .= "Hardcoded-Values:\n";
        //$out .= $dumper->dump(array('Hardcoded-Values' => array()), 2);

        //$out .= $dumper->dump(array('TVs' => array()), 2);
        file_put_contents($destination, $out);

        $output->writeln('<fg=green>Success!</fg=green>');
        $output->writeln('Edit the '.$destination.' to customize the mapping from MODX to your XLS file.');
    }
}
/*EOF*/