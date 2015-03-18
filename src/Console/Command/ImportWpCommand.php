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
    public $map;

    public $valid_mappings = array(
        'post_types',
        'default_templates',
        'templates',
        'authors',
        'shortcodes',
        'fields'
    );

    public $xml_nodes = array('version','base_url','authors','posts');

    // Lookups: name --> id (cut down on redundant lookups)
    public $cat_lookup = array();
    public $tag_lookup = array();
    public $auth_lookup = array();
    public $url_lookup = array();
    public $wp_modx_ids_lookup = array(); // this also gives us a record of all MODX page ids created/updated

    public $tvs;

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


        $this->testPreReqs($output);
        $core_path = $this->modx->getOption('taxonomies.core_path', null, MODX_CORE_PATH.'components/taxonomies/');
        include_once $core_path .'vendor/autoload.php';

        // Have you moved the WordPress assets into the directory?
        // yes/no
        $this->resource_cols = $this->modx->getFields('modResource');

        $source = $input->getArgument('source');
        $mapfile = $input->getArgument('mapfile');

        if (!file_exists($source))
        {
            $output->writeln('<error>File does not exist: '. $source.'</error>');
            exit;
        }

        $this->map = array(
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
        $this->map = $yaml->parse(file_get_contents($mapfile));
        $output->writeln('Importing file '.$source. ' using mappings contained in '.$mapfile);


        $WP = new \Xls2modx\Parser\WordPressXml();
        $this->data = $WP->parse($source);
//print_r($this->data); exit;
        // Verify mappings
        $this->verifyMappings($this->map, $output);
        $this->verifyXML($this->data, $output);

        $this->createCategories($output);
        $this->createTags($output);
        $this->createAuthors($output);

        // Iterate over posts
        $this->createContent($output);

        exit;
        // Check mapped fields : create any TVs if necessary (LOG THEM!)
        // check for custom fields containing multiple rows of data
        // add any page/term associations
        // Import assets

        // Replace hard-coded URLs with [[~123]] according to the lookup table
        // Replace [shortcodes] with Snippets
        // Replace any links to assets


    }

    /**
     * Make sure prereq's are installed
     * @param $output
     */
    public function testPreReqs($output)
    {
        $failed = false;
        if (!$S = $this->modx->getObject('modSystemSetting', array('key'=>'assman.class_keys')))
        {
            $output->writeln('<error>The Asset Manager Extra must be installed.</error>');
            $failed = true;
        }
        if (!$S = $this->modx->getObject('modSystemSetting', array('key'=>'taxonomies.default_taxonomy_template')))
        {
            $output->writeln('<error>The Taxonomies Extra must be installed.</error>');
            $failed = true;
        }
        // Lunchbox
        if ($failed)
        {
            exit;
        }
    }

    /**
     *  Array
    (
    [term_id] => 2
    [category_nicename] => dork
    [category_parent] =>
    [cat_name] => Dork
    [category_description] =>
    )
     * @throws \Exception
     */
    public function createCategories($output)
    {
        // Find parent Taxonomy
        if (!$Tax = $this->modx->getObject('Taxonomy', array('alias'=>'categories')))
        {
            //throw new \Exception('Taxonomy for "categories" not found.');
            $Tax = $this->modx->newObject('Taxonomy');

            $Tax->set('pagetitle', 'Categories');
            $Tax->set('menutitle', 'Categories');
            $Tax->set('alias', 'categories');
            $Tax->set('published', true);
            $Tax->set('template', $this->modx->getOption('taxonomies.default_taxonomy_template', null, $this->modx->getOption('default_template')));

            $Tax->save();
            $output->writeln('Creating parent taxonomy: categories');
        }
        else
        {
            $output->writeln('Found existing taxonomy: categories');
        }

        foreach ($this->data['categories'] as $c)
        {
            // Warning: this may fail if there are both a TERM and a CATEGORY using the same alias slug!
            if (!$Term = $this->modx->getObject('Term', array('alias' => $c['category_nicename'])))
            {
                $Term = $this->modx->newObject('Term');
                if ($c['category_parent'])
                {
                    if ($ParentTerm = $this->modx->getObject('Term', array('alias' => $c['category_parent'],'parent'=>$Tax->get('id'))))
                    {
                        $Term->set('parent', $ParentTerm->get('id'));
                    }
                }
                else
                {
                    $Term->set('parent', $Tax->get('id'));
                }
                $Term->set('pagetitle', $c['cat_name']);
                $Term->set('menutitle', $c['cat_name']);
                $Term->set('description', $c['category_description']);
                $Term->set('alias', $c['category_nicename']);
                $Term->set('published', true);
                $Term->set('template', $this->modx->getOption('taxonomies.default_term_template', null, $this->modx->getOption('default_template')));

                if (!$Term->save())
                {
                    $output->writeln('<error>Could not create category term: '.$c['cat_name'].'</error>');
                }
                else
                {
                    $output->writeln('Creating category term: '.$c['cat_name']);
                }

            }
            else
            {
                $output->writeln('Found existing term: '.$c['category_nicename']);
            }

            $this->cat_lookup[ $c['category_nicename'] ] = $Term->get('id');
        }
    }


    /**
     * Array
    (
    [term_id] => 5
    [tag_slug] => random
    [tag_name] => random
    [tag_description] =>
    )
     * @param $output
     */
    public function createTags($output)
    {
        // Find parent Taxonomy
        if (!$Tax = $this->modx->getObject('Taxonomy', array('alias'=>'tags')))
        {
            //throw new \Exception('Taxonomy for "categories" not found.');
            $Tax = $this->modx->newObject('Taxonomy');

            $Tax->set('pagetitle', 'Tags');
            $Tax->set('menutitle', 'Tags');
            $Tax->set('alias', 'tags');
            $Tax->set('published', true);
            $Tax->set('template', $this->modx->getOption('taxonomies.default_taxonomy_template', null, $this->modx->getOption('default_template')));

            $Tax->save();
            $output->writeln('Creating parent taxonomy: tags');
        }
        else
        {
            $output->writeln('Parent taxonomy found: tags');
        }

        foreach ($this->data['tags'] as $t)
        {
            // Warning: this may fail if there are both a TERM and a CATEGORY using the same alias slug!
            if (!$Term = $this->modx->getObject('Term', array('alias' => $t['tag_slug'])))
            {
                $Term = $this->modx->newObject('Term');

                $Term->set('parent', $Tax->get('id'));
                $Term->set('pagetitle', $t['tag_name']);
                $Term->set('menutitle', $t['tag_name']);
                $Term->set('description', $t['tag_description']);
                $Term->set('alias', $t['tag_slug']);
                $Term->set('published', true);
                $Term->set('template', $this->modx->getOption('taxonomies.default_term_template', null, $this->modx->getOption('default_template')));

                $Term->save();
                $output->writeln('Creating tag term: '.$t['tag_name']);

            }
            else
            {
                $output->writeln('Found existing term: '.$t['tag_name']);
            }

            $this->tag_lookup[ $t['tag_slug'] ] = $Term->get('id');
        }


    }

    /**
     * [admin] => Array
    (
    [author_id] => 1
    [author_login] => admin
    [author_email] => everett@fireproofsocks.com
    [author_display_name] => admin
    [author_first_name] =>
    [author_last_name] =>
    )
     * @param $output
     */
    public function createAuthors($output)
    {
        foreach ($this->data['authors'] as $username => $a)
        {
            if (!$U = $this->modx->getObject('modUser', array('username'=>$a['author_login'])))
            {
                $U = $this->modx->newObject('modUser');
                $P = $this->modx->newObject('modUserProfile');
                $U->set('username', $a['author_login']);
                $P->set('email', $a['author_email']);
                $P->set('fullname', trim($a['author_first_name'].' '.$a['author_last_name']));
                $U->addOne($P);
                $U->save();

                $output->writeln('Creating user: '.$a['author_login']);
            }
            else
            {
                $output->writeln('Found existing user: '.$a['author_login']);
            }

            $this->auth_lookup[ $a['author_login'] ] = $U->get('id');
        }
    }


    public function createContent($output)
    {
        foreach ($this->data['posts'] as $p) {

            if (!$P = $this->modx->getObject('modResource', array('alias' => $p['post_name']))) {
                $P = $this->modx->newObject('modResource');
                $P->set('alias', $p['post_name']);
            }

//            foreach ($this->map['fields'] as $wp => $mx)
//            {
//                if (!$mx)
//                {
//                    continue;
//                }
//                if (is_array($mx))
//                {
//                    foreach ($mx as $m)
//                    {
//                        $P->set($m, $p[ $wp ]);
//                    }
//                }
//                else
//                {
//                    $P->set($mx, $p[ $wp ]);
//                }
//            }

            if ($p['post_type'] == 'attachment')
            {
                // TODO: create asset!
                $this->createAsset($p, $output);
                continue;
            }

            $P->set('pagetitle', $p['post_title']);
            $P->set('longtitle', $p['post_title']);
            $P->set('introtext', $p['post_excerpt']);
            $P->set('createdby', $this->auth_lookup[ $p['post_author'] ]);
            $P->set('createdon', strtotime($p['post_date_gmt']));
            if ($p['post_parent'] && isset($this->wp_modx_ids_lookup[ $p['post_parent'] ]))
            {
                $P->set('parent', $this->wp_modx_ids_lookup[ $p['post_parent'] ]);
            }
            if (isset($this->map['post_types'][ $p['post_type'] ]) && $this->map['post_types'][ $p['post_type'] ])
            {
                $P->set('class_key', $this->map['post_types'][ $p['post_type'] ]);
            }

            if (isset($this->map['default_templates'][ $p['post_type'] ]) && $this->map['default_templates'][ $p['post_type'] ])
            {
                // We may override this later via the custom fields
                $P->set('template', $this->map['default_templates'][ $p['post_type'] ]);
            }
            else
            {
                $P->set('template', $this->modx->getOption('default_template'));
            }
            if ($p['status'] == 'publish')
            {
                $P->set('published', true);
            }


            // Content
            $P->set('content', $this->massageContent($p['post_content'], $output));



            $P->save();
            $output->writeln('Creating/Updating page: '.$P->get('pagetitle').' ('.$P->get('id').')');

            $this->addTVs($P, $p, $output);
            $this->addPageTerms($P, $p, $output);

            $this->url_lookup[ $p['guid'] ] = $P->get('id');
            $this->wp_modx_ids_lookup[ $p['post_id'] ] = $P->get('id');
        }


    }

    /**
     * Using Asset Manager, create the asset record (must have moved stuff into place first?)
     */
    public function createAsset($a, $output)
    {
        //print_r($a); exit;
        // Normalize
        foreach ($a['postmeta'] as $m)
        {
            $a[ $m['key'] ] = $m['value'];
        }

        $stub = $a['_wp_attached_file'];

        if (!$Asset = $this->modx->getObject('Asset', array('stub'=>$stub)))
        {
            $Asset = $this->modx->newObject('Asset');
            $Asset->set('stub', $stub);
        }



        $meta = unserialize($a['_wp_attachment_metadata']);

        // Find Content type
//        Array
//     * (
//     *   [name] => example.pdf
//    *   [type] => application/pdf
//    *   [tmp_name] => /tmp/path/somewhere/phpkAYQwR
//    *   [error] => 0
//    *   [size] => 2109
//    *)
        $path = $this->modx->getOption('assets_path') . $this->modx->getOption('assman.library_path').$stub;
        // Recalculate height and width so we can get the mime-type (and just in case WP was wrong)
        $mediainfo = $Asset->getMediaInfo($path);
        $height = ($mediainfo['height']) ? $mediainfo['height'] : $meta['height'];
        $width = ($mediainfo['width']) ? $mediainfo['width'] : $meta['width'];
        $mime = $mediainfo['mime'];
        $CT = $Asset->getContentType(array('name'=>$meta['file'],'type'=>$mime,'tmp_name'=>$path));
        $Asset->set('content_type_id', $CT->get('id'));
        $Asset->set('width', $width);
        $Asset->set('height', $height);
        $Asset->set('meta', $meta['image_meta']);
        $Asset->set('alt', $a['post_content']);
        $Asset->set('sig', md5_file($path));
        // $Asset->set('size', ???);  // OH NOES!
        $Asset->set('user_id', $this->auth_lookup[ $a['post_author'] ]);

        $Asset->save();

    }

    /**
     * Handle shortcodes, nl2br
     */
    public function massageContent($content, $output)
    {
        foreach($this->map['shortcodes'] as $shortcode => $snippet)
        {
            // What about [shortcode] long stuff in here...[/shortcode] ?? // TODO
            $content = str_replace($shortcode,$snippet, $content);
        }
        $content = nl2br($content);
    }

    /**
     * Associate a page with any terms
     * [terms] => Array
    (
    [0] => Array
    (
    [name] => random
    [slug] => random
    [domain] => post_tag
    )

    [1] => Array
    (
    [name] => Uncategorized
    [slug] => uncategorized
    [domain] => category
    )

    )
     */
    public function addPageTerms($Page, $data, $output)
    {
        if (!$data['terms'])
        {
            return;
        }
        $termids = array();
        foreach ($data['terms'] as $t)
        {
            if (isset($this->tag_lookup[$t['slug']]))
            {
                $termids[] = $this->tag_lookup[$t['slug']];
            }
            elseif (isset($this->cat_lookup[$t['slug']]))
            {
                $termids[] = $this->cat_lookup[$t['slug']];
            }
            else {
                $output->writeln('<error>Unknown taxonomical term: '.$t['slug'].'</error>');
            }
        }

        if ($termids)
        {
            $T = new \Taxonomies\Base($this->modx);
            $T->dictatePageTerms($Page->get('id'), $termids);
            $output->writeln('Added terms to page '. $Page->get('id').': '. implode(',',$termids));
        }
    }

    public function addTVs($Page, $data, $output)
    {
        //print_r($data); exit;
        if (empty($data['postmeta']))
        {
            return;
        }
        $fields = array();
        foreach ($data['postmeta'] as $m)
        {
            if ($m['key'][0] == '_')
            {
                continue; // todo... keep custom fields with the underscore prefix
            }
            $fields[ $m['key'] ][] = $m['value'];
        }

        // If a page at any time stores more than one value for a field, it should trigger the TV to be a listbox-multiple
        foreach ($fields as $name => $value)
        {
            if (count($value) == 1)
            {
                // Is JSON encoded?
                // TODO: read the cctm.json file
                $decode = json_decode($value[0]);
                if (is_array($decode))
                {
                    $fields[$name] = $decode;
                }
            }
            if (count($fields[$name]) > 1)
            {
                $this->tvs[$name]['type'] = 'listbox-multiple';
                if (isset($this->tvs[$name]['values'])) {
                    $this->tvs[$name]['values'] = array_unique(array_merge($this->tvs[$name]['values'], $values));
                }
                else
                {
                    $this->tvs[$name]['values'] = $values;
                }
            }
        }

        foreach ($fields as $name => $value)
        {
            if (!$tv = $this->modx->getObject('modTemplateVar',$name))
            {
                $output->writeln('Creating new TV: '.$name);
                $tv = $this->modx->newObject('modTemplateVar');
                $tv->set('name', $name);
                $tv->set('caption', $name);
                $tv->set('type', 'text'); // or listbox-multiple
            }

            if ($this->tvs[$name]['type'] == 'listbox-multiple')
            {
                $output->writeln('Multiple values detected. Setting TV to listbox-multiple: '.$name);
                $tv->set('elements', implode('||', $this->tvs[$name]['values']));
            }
            $tv->save();
            // Add to template
            if (!$TVT = $this->modx->getObject('modTemplateVarTemplate', array('tmplvarid'=>$tv->get('id'),'templateid'=>$Page->get('template'))))
            {
                $TVT = $this->modx->newObject('modTemplateVarTemplate');
                $TVT->set('tmplvarid', $tv->get('id'));
                $TVT->set('templateid', $Page->get('template'));
            }
            $Page->setTVValue($name, $value);
        }

    }


    public function verifyMappings($map, $output)
    {

        foreach ($map as $k => $v)
        {
            if (!in_array($k, $this->valid_mappings))
            {
                throw new \Exception('Invalid mapping YML. Unrecognized node '. $k);
            }
        }
    }

    public function verifyXML($data, $output)
    {
        foreach ($this->xml_nodes as $n)
        {
            if (!isset($data[$n]))
            {
                throw new \Exception('Invalid XML: missing node '. $n);
            }
        }
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