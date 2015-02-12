<?php
/**
 * Find modx
 * @return modx
 */
function get_modx()
{
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
                print "Could not find a valid MODX config.core.php file.\n"
                    ."Make sure your repo is inside a MODX webroot and try again.";
                die(1);
            }
        }
    }
    if (!file_exists(MODX_CORE_PATH.'model/modx/modx.class.php')) {
        print message("modx.class.php not found at ".MODX_CORE_PATH,'ERROR');
        die(3);
    }
    // fire up MODX
    require_once MODX_CORE_PATH.'config/'.MODX_CONFIG_KEY.'.inc.php';
    require_once MODX_CORE_PATH.'model/modx/modx.class.php';
    return new modx();
}

/*EOF*/