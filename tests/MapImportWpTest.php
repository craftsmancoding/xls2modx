<?php

class MapImportWpTest extends PHPUnit_Framework_TestCase {

    public static $modx;

    public static function setUpBeforeClass()
    {
        // Find MODX: as long this script is inside a MODX webroot, it will run.
        $dir = '';
        if (!defined('MODX_CORE_PATH') && !defined('MODX_CONFIG_KEY')) {
            $max = 10;
            $i = 0;
            $dir = dirname(__FILE__);
            while(true) {
                if (file_exists($dir.'/config.core.php')) {
                    include $dir.'/config.core.php';
                    break;
                }
                $i++;
                $dir = dirname($dir);
                if ($i >= $max) {
                    print message("Could not find a valid MODX config.core.php file.\n"
                        ."Make sure your repo is inside a MODX webroot and try again.",'ERROR');
                    die(1);
                }
            }
        }
        // fire up MODX
        require_once dirname(dirname(__FILE__)) . '/vendor/autoload.php';
        require_once MODX_CORE_PATH.'config/'.MODX_CONFIG_KEY.'.inc.php';
        require_once MODX_CORE_PATH.'model/modx/modx.class.php';
        self::$modx = new modX();
        self::$modx->initialize('mgr');
    }


    public function testImport()
    {
        $xml = simplexml_load_file(dirname(__FILE__).'/sourcefiles/testsite.wordpress.xml');

        print_r($xml); exit;
        foreach ($xml->element as $el) {
            echo $el->name ."\n";
        }
    }
}
