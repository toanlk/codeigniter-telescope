# Codeigniter Telescope

Install (Codeigniter)
-----------------
Install via composer
```
composer require toanlk/codeigniter_telescope
```

Composer Autoload
-----------------
Edit application/config/config.php:
```
$config['composer_autoload'] = FALSE;
↓
$config['composer_autoload'] = realpath(APPPATH . '../vendor/autoload.php');
```

The folder path for log files can be configured by adding clv_log_folder_path to Codeigniter config.php file e.g.
```
$config["ci_telescope_log_folder_path"] = STORAGE_PATH.'/storage/logs/';
```

Controller Integration for Browser Display
-----------------
All that is required is to execute the showLogs() method in a Controller that is mapped to a route:

A typical Controller (LogViewerController.php) will have the following content:
```
private $logViewer;

public function __construct() {
    parent::__construct(); 
    $this->logViewer = new \CI_Telescope\CI_Telescope();
    //...
}

public function index() {
    echo $this->logViewer->show();
    return;
}
```