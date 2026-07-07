<?php
define('DOING_AJAX', true);
require_once('../../../wp-load.php');
$_GET['action'] = 'sbm_global_search';
$_GET['q'] = 'Fire watch';
$_REQUEST['action'] = 'sbm_global_search';
$admin = SBM()->admin;
$admin->handle_global_search();
