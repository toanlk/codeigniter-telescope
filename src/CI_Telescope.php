<?php

namespace CI_Telescope;

defined('BASEPATH') or exit('No direct script access allowed');
defined('APPPATH') or exit('Not a Codeigniter Environment');

class CI_Telescope
{
    private $CI;

    private static $levelsIcon = [
        'INFO'  => 'glyphicon glyphicon-info-sign',
        'ERROR' => 'glyphicon glyphicon-warning-sign',
        'DEBUG' => 'glyphicon glyphicon-exclamation-sign',
        'ALL'   => 'glyphicon glyphicon-minus',
        'TRACE' => 'glyphicon glyphicon-info-sign',
        'FATAL' => 'glyphicon glyphicon-exclamation-sign',
    ];

    private static $levelClasses = [
        'INFO'  => 'info',
        'ERROR' => 'danger',
        'DEBUG' => 'warning',
        'ALL'   => 'muted',
        'TRACE' => 'trace',
        'FATAL' => 'fatal',
    ];

    const LOG_LINE_START_PATTERN = "/((INFO)|(ERROR)|(DEBUG)|(ALL)|(TRACE)|(FATAL))[\s\-\d:\.\/]+(-->)/";
    const LOG_DATE_PATTERN = ["/^((ERROR)|(INFO)|(DEBUG)|(ALL)|(TRACE)|(FATAL))\s\-\s/", "/\s(-->)/"];
    const LOG_LEVEL_PATTERN = "/^((ERROR)|(INFO)|(DEBUG)|(ALL)|(TRACE)|(FATAL))/";

    // this is the path (folder) on the system where the log files are stored
    private $logFolderPath;

    // this is the pattern to pick all log files in the $logFilePath
    private $logFilePattern;

    // this is a combination of the LOG_FOLDER_PATH and LOG_FILE_PATTERN
    private $fullLogFilePath = "";

    // these are the config keys expected in the config.php
    const LOG_FILE_PATTERN_CONFIG_KEY = "ci_telescope_log_file_pattern";
    const LOG_FOLDER_PATH_CONFIG_KEY = "ci_telescope_log_folder_path";

    /**
     * Here we define the paths for the view file
     * that's used by the library to present logs on the UI
     */
    private $LOG_VIEW_FILE_FOLDER = "";
    private $LOG_VIEW_FILE_NAME = "logs_view.php";
    private $LOG_VIEW_FILE_PATH = "";

    // this is the name of the view file passed to CI load->view()
    const CI_LOG_VIEW_FILE_PATH = "ci_telescope/logs_view";

    const MAX_LOG_SIZE = 52428800; // 50MB
    const MAX_STRING_LENGTH = 300; // 300 chars
    const MAX_LOG_LINE = 5000;

    /**
     * These are the constants representing the
     * various API commands there are
     */
    const API_QUERY_PARAM = "api";
    const API_FILE_QUERY_PARAM = "f";
    const API_LOG_STYLE_QUERY_PARAM = "sline";
    const API_CMD_LIST = "list";
    const API_CMD_VIEW = "view";
    const API_CMD_DELETE = "delete";

    // --------------------------------------------------------------------

    public function __construct()
    {
        $this->init();
    }

    /**
     * Bootstrap the library
     * sets the configuration variables
     * @throws \Exception
     */
    private function init()
    {
        if (!function_exists("get_instance")) {
            throw new \Exception("This library works in a Codeigniter Project/Environment");
        }

        // initiate Codeigniter instance
        $this->CI = &get_instance();

        // configure the log folder path and the file pattern for all the logs in the folder
        $this->logFolderPath =  !is_null($this->CI->config->item(self::LOG_FOLDER_PATH_CONFIG_KEY)) ? rtrim($this->CI->config->item(self::LOG_FOLDER_PATH_CONFIG_KEY), "/") : rtrim(APPPATH, "/") . "/logs";
        $this->logFilePattern = !is_null($this->CI->config->item(self::LOG_FILE_PATTERN_CONFIG_KEY)) ? $this->CI->config->item(self::LOG_FILE_PATTERN_CONFIG_KEY) : "log-*.php";

        // concatenate to form Full Log Path
        $this->fullLogFilePath = $this->logFolderPath . "/" . $this->logFilePattern;

        // create the view file so that CI can find it
        // use VIEWPATH constant so the CI can find views location and this constant will be defined in index.php file.
        $this->LOG_VIEW_FILE_FOLDER = VIEWPATH . "ci_telescope";
        $this->LOG_VIEW_FILE_PATH = rtrim($this->LOG_VIEW_FILE_FOLDER) . "/" . $this->LOG_VIEW_FILE_NAME;
        if (!file_exists($this->LOG_VIEW_FILE_PATH)) {

            if (!is_dir($this->LOG_VIEW_FILE_FOLDER))
                mkdir($this->LOG_VIEW_FILE_FOLDER);

            file_put_contents($this->LOG_VIEW_FILE_PATH, file_get_contents($this->LOG_VIEW_FILE_NAME, FILE_USE_INCLUDE_PATH));
        }
    }

    // --------------------------------------------------------------------

    /*
     * This function will return the processed HTML page
     * and return it's content that can then be echoed
     *
     * @param $fileName optional base64_encoded filename of the log file to process.
     * @returns the parse view file content as a string that can be echoed
     * */
    public function show()
    {
        if (!is_null($this->CI->input->get("del"))) {
            $this->deleteFiles(base64_decode($this->CI->input->get("del")));
            redirect($this->CI->uri->uri_string());
            return;
        }

        // process download of log file command
        // if the supplied file exists, then perform download
        // otherwise, just ignore which will resolve to page reloading
        $dlFile = $this->CI->input->get("dl");
        if (!is_null($dlFile) && file_exists($this->logFolderPath . "/" . basename(base64_decode($dlFile)))) {
            $file = $this->logFolderPath . "/" . basename(base64_decode($dlFile));
            $this->downloadFile($file);
        }

        if (!is_null($this->CI->input->get(self::API_QUERY_PARAM))) {
            return $this->processAPIRequests($this->CI->input->get(self::API_QUERY_PARAM));
        }

        // auto refresh
        $auto_refresh =  $this->CI->input->get("auto_refresh");

        if (!empty($auto_refresh)) {
            if ($auto_refresh == 'off') {
                $auto_refresh = false;
            } else if ($auto_refresh == 'on') {
                $auto_refresh = true;
            } else {
                $auto_refresh = false;
            }
        }

        // it will either get the value of f or return null
        $fileName =  $this->CI->input->get("f");

        // get the log files from the log directory
        $files = $this->getFiles();

        // let's determine what the current log file is
        if (!is_null($fileName)) {
            $currentFile = $this->logFolderPath . "/" . basename(base64_decode($fileName));
        } else if (is_null($fileName) && !empty($files)) {
            $currentFile = $this->logFolderPath . "/" . $files[0];
        } else {
            $currentFile = null;
        }

        // if the resolved current file is too big
        // just trigger a download of the file
        // otherwise process its content as log

        $logs = [];

        if (!is_null($currentFile) && file_exists($currentFile)) {

            $fileSize = filesize($currentFile);

            if (is_int($fileSize) && $fileSize > self::MAX_LOG_SIZE) {
                // trigger a download of the current file instead
                $logs = null;
            } else {
                $logs = $this->processLogs($this->getLogs($currentFile));
            }
        }

        $data['lastModifiedTime'] = 0;
        if (!empty($files)) {
            $data['lastModifiedTime'] = $this->get_latest_modified($files);
        }

        $data['auto_refresh'] = $auto_refresh;
        $data['logs'] = $logs;
        $data['files'] =  !empty($files) ? $files : [];
        $data['currentFile'] = !is_null($currentFile) ? basename($currentFile) : "";
        return $this->CI->load->view(self::CI_LOG_VIEW_FILE_PATH, $data, true);
    }

    // --------------------------------------------------------------------

    private function processAPIRequests($command)
    {
        if ($command === self::API_CMD_LIST) {
            // respond with a list of all the files
            $response["status"] = true;
            $response["log_files"] = self::getFilesBase64Encoded();
        } else if ($command === self::API_CMD_VIEW) {
            // respond to view the logs of a particular file
            $file = $this->CI->input->get(self::API_FILE_QUERY_PARAM);
            $response["log_files"] = self::getFilesBase64Encoded();

            if (is_null($file) || empty($file)) {
                $response["status"] = false;
                $response["error"]["message"] = "Invalid File Name Supplied: [" . json_encode($file) . "]";
                $response["error"]["code"] = 400;
            } else {
                $singleLine = $this->CI->input->get(self::API_LOG_STYLE_QUERY_PARAM);
                $singleLine = !is_null($singleLine) && ($singleLine === true || $singleLine === "true" || $singleLine === "1") ? true : false;
                $logs = $this->processLogsForAPI($file, $singleLine);
                $response["status"] = true;
                $response["logs"] = $logs;
            }
        } else if ($command === self::API_CMD_DELETE) {

            $file = $this->CI->input->get(self::API_FILE_QUERY_PARAM);

            if (is_null($file)) {
                $response["status"] = false;
                $response["error"]["message"] = "NULL value is not allowed for file param";
                $response["error"]["code"] = 400;
            } else {

                // decode file if necessary
                $fileExists = false;

                if ($file !== "all") {
                    $file = basename(base64_decode($file));
                    $fileExists = file_exists($this->logFolderPath . "/" . $file);
                } else {
                    // check if the directory exists
                    $fileExists = file_exists($this->logFolderPath);
                }

                if ($fileExists) {
                    $this->deleteFiles($file);
                    $response["status"] = true;
                    $response["message"] = "File [" . $file . "] deleted";
                } else {
                    $response["status"] = false;
                    $response["error"]["message"] = "File does not exist";
                    $response["error"]["code"] = 404;
                }
            }
        } else {
            $response["status"] = false;
            $response["error"]["message"] = "Unsupported Query Command [" . $command . "]";
            $response["error"]["code"] = 400;
        }

        //convert response to json and respond
        header("Content-Type: application/json");
        if (!$response["status"]) {
            //set a generic bad request code
            http_response_code(400);
        } else {
            http_response_code(200);
        }
        return json_encode($response);
    }

    // --------------------------------------------------------------------

    /*
     * This function will process the logs. Extract the log level, icon class and other information
     * from each line of log and then arrange them in another array that is returned to the view for processing
     *
     * @params logs. The raw logs as read from the log file
     * @return array. An [[], [], [] ...] where each element is a processed log line
     * */
    private function processLogs($logs)
    {
        if (is_null($logs)) {
            return null;
        }

        $superLog = [];

        // newest first
        $logs = array_reverse($logs);

        foreach ($logs as $k => $log) {

            if ($k > self::MAX_LOG_LINE) {
                break;
            }

            // get the log line Start
            $logLineStart = $this->getLogLineStart($log);

            if (!empty($logLineStart)) {
                // this is actually the start of a new log and not just another line from previous log
                $level = $this->getLogLevel($logLineStart);
                $data = [
                    "level" => $level,
                    "date" => $this->getLogDate($logLineStart),
                    "icon" => self::$levelsIcon[$level],
                    "class" => self::$levelClasses[$level],
                ];

                $logMessage = preg_replace(self::LOG_LINE_START_PATTERN, '', $log);

                if (strlen($logMessage) > self::MAX_STRING_LENGTH) {
                    $data['content'] = substr($logMessage, 0, self::MAX_STRING_LENGTH);
                    $data["extra"] = substr($logMessage, (self::MAX_STRING_LENGTH + 1));
                } else {
                    $data["content"] = $logMessage;
                }

                array_push($superLog, $data);
            } else if (!empty($superLog)) {
                // this log line is a continuation of previous log line
                // so let's add them as extra
                $prevLog = $superLog[count($superLog) - 1];
                $extra = (array_key_exists("extra", $prevLog)) ? $prevLog["extra"] : "";
                $prevLog["extra"] = $extra . "<br>" . $log;
                $superLog[count($superLog) - 1] = $prevLog;
            } else {
                //this means the file has content that are not logged
                //using log_message()
                //they may be sensitive! so we are just skipping this
                //other we could have just insert them like this
                //               array_push($superLog, [
                //                   "level" => "INFO",
                //                   "date" => "",
                //                   "icon" => self::$levelsIcon["INFO"],
                //                   "class" => self::$levelClasses["INFO"],
                //                   "content" => $log
                //               ]);
            }
        }

        return $superLog;
    }

    // --------------------------------------------------------------------

    /**
     * This function will extract the logs in the supplied
     * fileName
     * @param      $fileNameInBase64
     * @param bool $singleLine
     * @return array|null
     * @internal param $logs
     */
    private function processLogsForAPI($fileNameInBase64, $singleLine = false)
    {
        $logs = null;

        // let's prepare the log file name sent from the client
        $currentFile = $this->prepareRawFileName($fileNameInBase64);

        // if the resolved current file is too big
        // just return null
        // otherwise process its content as log
        if (!is_null($currentFile)) {

            $fileSize = filesize($currentFile);

            if (is_int($fileSize) && $fileSize > self::MAX_LOG_SIZE) {
                // trigger a download of the current file instead
                $logs = null;
            } else {
                $logs =  $this->getLogsForAPI($currentFile, $singleLine);
            }
        }

        return $logs;
    }

    // --------------------------------------------------------------------

    /*
     * extract the log level from the logLine
     * @param $logLineStart - The single line that is the start of log line.
     * extracted by getLogLineStart()
     *
     * @return log level e.g. ERROR, DEBUG, INFO
     * */
    private function getLogLevel($logLineStart)
    {
        preg_match(self::LOG_LEVEL_PATTERN, $logLineStart, $matches);
        return $matches[0];
    }

    private function getLogDate($logLineStart)
    {
        return preg_replace(self::LOG_DATE_PATTERN, '', $logLineStart);
    }

    private function getLogLineStart($logLine)
    {
        preg_match(self::LOG_LINE_START_PATTERN, $logLine, $matches);
        if (!empty($matches)) {
            return $matches[0];
        }
        return "";
    }

    /*
     * returns an array of the file contents
     * each element in the array is a line
     * in the underlying log file
     * @returns array | each line of file contents is an entry in the returned array.
     * @params complete fileName
     * */
    private function getLogs($fileName)
    {
        $size = filesize($fileName);
        if (!$size || $size > self::MAX_LOG_SIZE)
            return null;
        return file($fileName, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }

    /**
     * This function will get the contents of the log
     * file as a string. It will first check for the
     * size of the file before attempting to get the contents.
     *
     * By default it will return all the log contents as an array where the
     * elements of the array is the individual lines of the files
     * otherwise, it will return all file content as a single string with each line ending
     * in line break character "\n"
     * @param      $fileName
     * @param bool $singleLine
     * @return bool|string
     */
    private function getLogsForAPI($fileName, $singleLine = false)
    {
        $size = filesize($fileName);
        if (!$size || $size > self::MAX_LOG_SIZE)
            return "File Size too Large. Please download it locally";

        return (!$singleLine) ? file($fileName, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : file_get_contents($fileName);
    }

    // --------------------------------------------------------------------

    /*
     * This will get all the files in the logs folder
     * It will reverse the files fetched and
     * make sure the latest log file is in the first index
     *
     * @param boolean. If true returns the basename of the files otherwise full path
     * @returns array of file
     * */
    private function getFiles($basename = true)
    {
        $files = glob($this->fullLogFilePath);
        $files = !is_array($files) ? [] : $files;

        $files = array_reverse($files);
        $files = array_filter($files, 'is_file');
        if ($basename && is_array($files)) {
            foreach ($files as $k => $file) {
                $files[$k] = basename($file);
            }
        }

        return array_values($files);
    }

    /**
     * This function will return an array of available log
     * files
     * The array will contain the base64encoded name
     * as well as the real name of the file
     * @return array
     * @internal param bool $appendURL
     * @internal param bool $basename
     */
    private function getFilesBase64Encoded()
    {
        $files = glob($this->fullLogFilePath);
        $files = !is_array($files) ? [] : $files;

        $files = array_reverse($files);
        $files = array_filter($files, 'is_file');

        $finalFiles = [];

        // if we're to return the base name of the files
        // let's do that here
        foreach ($files as $file) {
            array_push($finalFiles, ["file_b64" => base64_encode(basename($file)), "file_name" => basename($file)]);
        }

        return $finalFiles;
    }

    /*
     * Delete one or more log file in the logs directory
     * @param filename. It can be all - to delete all log files - or specific for a file
     * */
    private function deleteFiles($fileName)
    {
        if ($fileName == "all") {
            $files = glob($this->fullLogFilePath);
            $files = !is_array($files) ? [] : $files;
            array_map("unlink", $files);
        } else {
            unlink($this->logFolderPath . "/" . basename($fileName));
        }
        return;
    }

    /*
     * Download a particular file to local disk
     * This should only be called if the file exists
     * hence, the file exist check has ot be done by the caller
     * @param $fileName the complete file path
     * */
    private function downloadFile($file)
    {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    // --------------------------------------------------------------------

    /**
     * This function will take in the raw file
     * name as sent from the browser/client
     * and append the LOG_FOLDER_PREFIX and decode it from base64
     * @param $fileNameInBase64
     * @return null|string
     * @internal param $fileName
     */
    private function prepareRawFileName($fileNameInBase64)
    {
        // let's determine what the current log file is
        if (!is_null($fileNameInBase64) && !empty($fileNameInBase64)) {
            $currentFile = $this->logFolderPath . "/" . basename(base64_decode($fileNameInBase64));
        } else {
            $currentFile = null;
        }

        return $currentFile;
    }

    // --------------------------------------------------------------------

    function get_latest_modified($files)
    {
        $lastModifiedTime = 0;
        foreach ($files as $file) {
            $currentModifiedTime = $this->get_last_modified($file);
            if ($currentModifiedTime > $lastModifiedTime) {
                $lastModifiedTime = $currentModifiedTime;
            }
        }

        return $lastModifiedTime;
    }

    // --------------------------------------------------------------------

    public function get_last_modified($file_name)
    {
        $full_path = $this->logFolderPath . "/" . $file_name;
        if (file_exists($full_path)) {
            return filemtime($full_path);
        }

        return 0;
    }

    // --------------------------------------------------------------------

    public function get_last_logs()
    {
        $data = ['is_modified' => false];

        $file_name = $this->CI->input->get('f');
        $last_updated = $this->CI->input->get('t');

        $files = $this->getFiles();
        $latest_modified = $this->get_latest_modified($files);

        if ($last_updated != $latest_modified) {
            $logs = [];

            if (!empty($file_name)) {
                $currentFile = base64_decode($file_name);;
            } else {
                $currentFile = $files[0];
            }

            $currentFile = $this->logFolderPath . "/" . $currentFile;
            
            if (file_exists($currentFile)) {
                $fileSize = filesize($currentFile);

                if (is_int($fileSize) && $fileSize > self::MAX_LOG_SIZE) {
                    // trigger a download of the current file instead
                    $logs = null;
                } else {
                    $logs = $this->processLogs($this->getLogs($currentFile));
                }
            }

            $data = ['logs' => $logs, 'is_modified' => true, 'last_modified_time' => $latest_modified];
        }

        echo json_encode($data);
    }
}
