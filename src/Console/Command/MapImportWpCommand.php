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

class MapImportWpCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('map:importwp')
            ->setDescription('Parse the indicated WordPress XML dump file and generate a sample importwp.yml file that you can modify to define the how the WordPress file should be imported, including templates, post-types, and media assets.')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to WordPress XML dump file.')
            ->addArgument('destination', InputArgument::OPTIONAL, 'Destination file.', 'importwp.yml')
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

        $output->writeln('Parsing file for mappings '.$source. ' ---> '.$destination);

        $WP = new \Xls2modx\Parser\WordPressXml();
        $data = $WP->parse($source);

        $post_types = array();
        $default_templates = array();
        $templates = array();
        $authors = array();
        $shortcodes = array();
        $fields = array(
            'post_title' => ['pagetitle','description'],
            'post_content' => 'content',
            'post_excerpt' => 'introtext',
            'post_date_gmt' => 'createdon',
            'post_name' => 'alias',
            //'status' => 'published' // special
            'post_parent' => 'parent',
            'menu_order' => 'menuindex',
            //'post_type' => 'class_key' // special
            // guid // special
        );

        foreach ($data['posts'] as $p)
        {
            if ($p['post_type'] != 'attachment') {
                $post_types[$p['post_type']] = 'modDocument';
                $default_templates[ $p['post_type'] ] = (int) $this->modx->getOption('default_template');
            }


            preg_match_all('/\[\w+.*\]/Ui', $p['post_content'], $matches);
            if (!empty($matches[0]))
            {
                //print_r($matches); exit;
                foreach ($matches[0] as $m)
                {
                    $shortcodes[ $m ] = '';
                }
            }

            $authors[ $p['post_author'] ] = $p['post_author'];

            if ($p['postmeta'])
            {
                foreach ($p['postmeta'] as $field)
                {
                    if ($field['key'] == '_wp_page_template')
                    {
                        if ($field['value'] != 'default')
                        {
                            $templates[ $field['value'] ] = (int) $this->modx->getOption('default_template');
                        }
                    }
                    elseif ($field['key'][0] != '_')
                    {
                        $fields[ $field['key'] ] = $field['key'];
                    }
                }
            }
        }

        $dumper = new Dumper();

        $out  = "# ------ POST TYPES -----------------------------------------------------------------------------------\n";
        $out .= "# Mappings use a colon followed by a space (: ) to mark each key/value pair\n";
        $out .= "# Format is wordpress-post-type: MODX-class_key\n";
        $out .= "# By default, modDocument is assumed.\n";
        $out .= $dumper->dump(array('post_types'=> $post_types), 2);
        $out .= "\n";
        $out .= "# ------ DEFAULT TEMPLATES ----------------------------------------------------------------------------\n";
        $out .= "# WordPress use of custom post-types is more common than custom MODX resource classes (CRCs).\n";
        $out .= "# Instead, MODX often uses specific templates to represent specific types of content.\n";
        $out .= "# Here you can map the WordPress post-type to a MODX template id.  If no default template is defined\n";
        $out .= "# for a post-type, the MODX default template will be assumed.\n";
        $out .= "# Format is wp-post-type: modx-template-id\n";
        $out .= $dumper->dump(array('default_templates'=> $default_templates), 2);
        $out .= "\n";
        $out .= "# ------ TEMPLATES ------------------------------------------------------------------------------------\n";
        $out .= "# WordPress pages (not posts) can use specific templates.  WordPress specifies files as custom templates.\n";
        $out .= "# If you want to map these template files to specific MODX templates, enter the MODX template ids here.\n";
        $out .= "# If no mappings are provided, the default templates defined above will be used.\n";
        $out .= "# Format is wp-template-file.php: modx-template-id\n";
        $out .= $dumper->dump(array('templates' => $templates), 2);
        $out .= "\n";
        $out .= "# ------ AUTHORS --------------------------------------------------------------------------------------\n";
        $out .= "# You can preserve authorship credits by migrating users. If you list a username that does not exist in\n";
        $out .= "# MODX, a 'stub' user will be created: the record will exist, but the user will not be able to log in.\n";
        $out .= "# If pages are attributed to an author that is not imported, the MODX Default Admin user will be assumed.\n";
        $out .= "# Format is wp-username: modx-username\n";
        $out .= $dumper->dump(array('authors' => $authors), 2);
        $out .= "\n";
        $out .= "# ------ SHORTCODES -----------------------------------------------------------------------------------\n";
        $out .= "# Content in WordPress may contain [shorcodes] which are analogous to MODX Snippets.  You should define\n";
        $out .= "# a MODX Snippet which should replace each shortcode instance.  Keep in mind that adapting code in the\n";
        $out .= "# shortcodes may not be trivial!  The shortcode calls are listed here.\n";
        $out .= "# Format is [wp_shortcode instance=\"x\"]: [[modxSnippet? &instance=`x`]]\n";
        $out .= "\n";
        $out .= $dumper->dump(array('shortcodes' => $shortcodes), 2);
        $out .= "# ------ SETTINGS -------------------------------------------------------------------------------------\n";
        $out .= "# Some general settings here\n";
        $out .= "\n";
        $out .= "settings:\n";
        $out .= "    # Should newlines in WP post content be converted to <br/> tags?\n";
        $out .= "    nl2br: true";
        // Not a good idea
//        $out .= "# ------ FIELDS ---------------------------------------------------------------------------------------\n";
//        $out .= "# This section controls how WordPress fields are translated into MODX fields.  A sample is generated for\n";
//        $out .= "# you, but you may wish to review the custom fields --> Template Variables.\n";
//        $out .= "# Format is wp-fieldname: modx-fieldname\n";
//        $out .= $dumper->dump(array('fields' => $fields), 2);

        file_put_contents($destination, $out);

        $output->writeln('<fg=green>Success!</fg=green>');
        $output->writeln('Edit the '.$destination.' to define the mapping from your XLS columns to the MODX fields.');
    }


    public function parseItem($mvalues)
    {
        for ($i=0; $i < count($mvalues); $i++) {
            $mol[$mvalues[$i]['tag']] = $mvalues[$i]['value'];
        }
        return $mol;
    }
}
/*EOF*/