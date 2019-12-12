# Codeigniter Telescope

Install
-----------------
Install via composer
```
composer require toanlk/codeigniter_telescope
```

Autoload
-----------------
Edit application/config/config.php:
```
$config['composer_autoload'] = FALSE;
↓
$config['composer_autoload'] = realpath(APPPATH . '../vendor/autoload.php');
```

The folder path for log files can be configured by adding clv_log_folder_path to Codeigniter config.php file e.g.
```
$config['ci_telescope_log_folder_path'] = STORAGE_PATH.'/storage/logs/';
```

Integration
-----------------
All that is required is to execute the show() method in a Controller that is mapped to a route:

A typical Controller (Logs.php) will have the following content:
```
class Logs extends CI_Controller
{
    private $logViewer;

    public function __construct()
	{
        parent::__construct(); 
        $this->load->helper('url');
        $this->logViewer = new \CI_Telescope\CI_Telescope();
    }

	public function index()
	{
        echo $this->logViewer->show();
    }

    // --------------------------------------------------------------------

    public function get_last_logs()
    {
        echo $this->logViewer->get_last_logs();
    }
}
```